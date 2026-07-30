<?php

namespace App\Core;

/* ============================================================
   Auth — session gate (bridged to FormFlow admin_users) + the
   superadmin access model. eGradeBook is superadmin-only; login
   itself lives in App\Controllers\AuthController / App\Models\UserRepo.
   ============================================================ */
class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
    }

    /* Redirect to login if there is no session (gates every page). */
    public static function requireLogin(): void
    {
        self::start();
        if (empty($_SESSION['admin_id'])) {
            header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }

    public static function isSuperadmin(): bool
    {
        return (($_SESSION['admin_role'] ?? '') === 'superadmin');
    }

    /* index.php requires superadmin for both page loads and API calls.
       Non-superadmins get a 403 (JSON for the API, an HTML page otherwise). */
    public static function requireSuperadmin(bool $isApi): void
    {
        if (self::isSuperadmin()) return;

        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Access denied. Superadmin only.']);
            exit;
        }
        // page load, not superadmin → access denied (this is a standalone app, no other dashboard)
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied</title>'
            . '<link rel="stylesheet" href="assets/css/global.css"></head>'
            . '<body class="bg-glow" style="display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;">'
            . '<div><h2>Access denied</h2><p style="color:var(--muted);">Only superadmins have access to eGradeBook.</p>'
            . '<a href="inc/logout.php" class="btn btn-ghost btn-sm">Logout</a></div></body></html>';
        exit;
    }

    public static function ownerId(): int
    {
        return intval($_SESSION['admin_id'] ?? 0);
    }
}
