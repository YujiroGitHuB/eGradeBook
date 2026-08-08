<?php

/* ============================================================
   index.php — FRONT CONTROLLER (thin)

   Boot → auth gate → superadmin gate → schema bootstrap → then either
   route an ?api= request to a controller (JSON) or render the grading
   sheet view. The whole app used to live in this one file; it is now
   split into app/Core, app/Models, app/Controllers and app/Views. The
   ?api= contract is UNCHANGED, so assets/js/grades.js (which only ever
   calls index.php) needs no edits.
   ============================================================ */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Schema;
use App\Core\Router;

/* Alamin muna kung API bago ang mga gate: ang isang ?api= request ay dapat
   makatanggap ng JSON (401/403/500), hindi ng redirect o HTML page. */
$isApi = isset($_GET['api']) || isset($_POST['api']);

Auth::requireLogin($isApi);           // login gate (bridged to FormFlow admin_users)
Auth::requireSuperadmin($isApi);      // eGradeBook is superadmin-only (403 otherwise)

try {
    $db = new Database();             // egradebook_db + FORMFLOW/ATTENDANCE bridge + timezone
    Schema::migrate($db);             // gated by Schema.php's filemtime; see Schema::migrate()
} catch (\Throwable $e) {
    /* Hindi maabot ang DB. Dating JSON ang isinusuka nito kahit page load,
       kaya blangkong pahina na may JSON blob ang nakikita ng guro. */
    error_log('eGradeBook boot failed: ' . $e);
    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['success' => false, 'message' => 'The database is unavailable. Please try again shortly.']);
    } else {
        http_response_code(503);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database unavailable</title>'
            . '<link rel="stylesheet" href="assets/css/global.css"></head>'
            . '<body class="bg-glow" style="display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;">'
            . '<div><h2>Database unavailable</h2><p style="color:var(--muted);">eGradeBook could not reach MySQL. '
            . 'Check that the server is running, then reload.</p></div></body></html>';
    }
    exit;
}

/* ── API LAYER — any ?api= request returns JSON and exits ── */
if ($isApi) {
    header('Content-Type: application/json');
    $api = $_POST['api'] ?? $_GET['api'];
    try {
        (new Router($db, Auth::ownerId()))->dispatch((string)$api);
    } catch (\Throwable $e) {
        /* Ang hilaw na $e->getMessage() ay nauuwi sa hilaw na SQL/path text sa
           mukha ng guro (hal. "Duplicate entry '230-…' for key 'PRIMARY'").
           Sa log iyon; sa user ay isang mababasang pangungusap. */
        error_log('eGradeBook API error [' . $api . ']: ' . $e);
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Something went wrong on the server. Please try again.']);
    }
    $db->close();
    exit;
}

/* ── Non-API GET → render the grading sheet page ── */
require APP_ROOT . '/app/Views/sheet.php';
