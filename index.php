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

Auth::requireLogin();                 // login gate (bridged to FormFlow admin_users)

$isApi = isset($_GET['api']) || isset($_POST['api']);
Auth::requireSuperadmin($isApi);      // eGradeBook is superadmin-only (403 otherwise)

$db = new Database();                 // egradebook_db + FORMFLOW/ATTENDANCE bridge + timezone
Schema::migrate($db);                 // idempotent CREATE TABLE + inline migrations

/* ── API LAYER — any ?api= request returns JSON and exits ── */
if ($isApi) {
    header('Content-Type: application/json');
    $api = $_POST['api'] ?? $_GET['api'];
    try {
        (new Router($db, Auth::ownerId()))->dispatch((string)$api);
    } catch (\Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    $db->close();
    exit;
}

/* ── Non-API GET → render the grading sheet page ── */
require APP_ROOT . '/app/Views/sheet.php';
