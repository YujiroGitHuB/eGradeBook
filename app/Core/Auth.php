<?php

namespace App\Core;

/* ============================================================
   Auth — session gate (bridged to FormFlow admin_users) + the
   superadmin access model. eGradeBook is superadmin-only; login
   itself lives in App\Controllers\AuthController / App\Models\UserRepo.
   ============================================================ */
class Auth
{
    /* Simulan ang session na may pinatibay na cookie. Dapat MAUNA ito sa
       session_start(), dahil doon pa lang ipinapalabas ang cookie.

       Bakit SameSite: bawat `?api=` na nagsusulat ay session cookie lang ang
       pinagbabatayan — walang CSRF token kahit saan. Ang `Strict` ang humaharang
       sa cross-site na POST, kaya hindi na mapapakilos ng ibang site ang browser
       mo para magsulat dito. Naka-`Lax` na ang default ng mga bagong browser,
       pero default iyon ng browser at hindi pahayag ng app — ito ang nagpapahayag.
       (Kung sakaling ilagay sa MAGKAIBANG domain ang FormFlow at eGradeBook,
       gawing 'Lax' ito para hindi maputol ang paglipat mula sa isa papunta sa isa;
       sa iisang host — gaya ng XAMPP — walang epekto ang pagkakaiba.)

       use_strict_mode = huwag tanggapin ang session id na hindi galing dito
       (panangga sa session fixation, kapares ng session_regenerate_id sa login). */
    public static function start(): void
    {
        if (session_status() !== PHP_SESSION_NONE) return;

        $p = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $p['lifetime'],
            /* pinapanatili ang path/domain na naka-configure na — ang pagpapalit
               nito ay puwedeng bumangga sa session cookie ng FormFlow sa iisang host */
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }

    /* Gates every page. Ang isang ?api= request ay HINDI dapat i-redirect:
       susundan ito ng fetch(), matatanggap ang HTML ng login page, at ang
       parseApiResponse() ay magpapakita ng unang 200 karakter ng HTML bilang
       mensahe ng error. 401 JSON ang ipinapadala para malaman ng client na
       nag-expire ang session at masabi ito nang maayos. */
    public static function requireLogin(bool $isApi = false): void
    {
        self::start();
        if (!empty($_SESSION['admin_id'])) return;

        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'auth'    => false,
                'message' => 'Your session expired. Please log in again.',
            ]);
            exit;
        }
        header('Location: login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
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
