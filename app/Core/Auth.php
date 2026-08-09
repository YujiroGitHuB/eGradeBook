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

    /* Ang access gate ng index.php, para sa page load at sa ?api= (403).

       BAKIT HINDI SAPAT ANG "may account ka sa FormFlow": iisang PHP session
       ang dalawang app sa iisang host — parehong PHPSESSID sa path '/', at
       sinusulat ng login.php ng FormFlow ang parehong `admin_id` / `admin_role`.
       Kaya ang naka-login sa FormFlow ay pasado na sa requireLogin() dito nang
       hindi man lang dumadaan sa login.php natin. Kung ang pagkakaroon ng
       account ang magiging batayan, ang bawat FormFlow account — pati ang
       gagawin pa lang para sa ibang layunin — ay may eGradeBook agad. Kaya
       tahasang talaan ang nagpapasya: grade_app_access.

       LAGING pasado ang superadmin, kahit wala sa talaan. Iyon ang nagsisiguro
       na hindi kailanman mai-lock out ng namamahala ang sarili niya — walang
       pagkakataong walang natitirang makakapasok para magbigay muli ng access. */
    public static function requireAccess(\App\Core\Database $db, bool $isApi): void
    {
        if (self::isSuperadmin()) return;
        if ((new \App\Models\AccessRepo($db))->has(self::ownerId())) return;

        if ($isApi) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You do not have access to eGradeBook. Ask a superadmin to grant it.']);
            exit;
        }
        // page load — hindi ito kulang na login, kaya hindi login.php ang sagot
        http_response_code(403);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied</title>'
            . '<link rel="stylesheet" href="assets/css/global.css"></head>'
            . '<body class="bg-glow" style="display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;">'
            . '<div><h2>Access denied</h2><p style="color:var(--muted);">Your account does not have access to eGradeBook yet.<br>Ask a superadmin to grant it.</p>'
            . '<a href="inc/logout.php" class="btn btn-ghost btn-sm">Logout</a></div></body></html>';
        exit;
    }

    public static function ownerId(): int
    {
        return intval($_SESSION['admin_id'] ?? 0);
    }

    /* URL ng profile photo ng nakalog-in ('' = wala, gamitin ang icon).

       Ang halagang ito ay HINDI atin — galing ito sa admin_users ng FormFlow at
       tuwiran itong napupunta sa isang src="" attribute. Kaya path lang na
       relatibo ang tinatanggap: anumang may scheme (`javascript:`, `data:`),
       nagsisimula sa dalawang slash (`//ibang-site.com`), o may `..` ay
       tinatanggihan — hindi ito dapat makaturo palabas ng FormFlow. Pareho ito
       ng pag-iingat na ginagawa ng AuthController::safeNext sa `next=`. */
    public static function avatarUrl(): string
    {
        $a = trim((string)($_SESSION['admin_avatar'] ?? ''));
        if ($a === '' || FORMFLOW_WEB_BASE === '') return '';
        if (strpos($a, '..') !== false) return '';
        if (strpos($a, '\\') !== false) return '';
        if (strncmp($a, '//', 2) === 0) return '';
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $a)) return '';
        return FORMFLOW_WEB_BASE . ltrim($a, '/');
    }
}
