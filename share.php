<?php

/* ============================================================
   share.php — PUBLIC na view ng isang Class ranking (share.php?t=<token>).

   Ang IKATLONG entry point, at ang tanging walang login: HINDI ito
   nagsisimula ng session (walang Auth::start), kaya ang estudyanteng
   may naka-log in na FormFlow ay walang makukuhang kahit ano rito maliban
   sa laman ng link. Ang token (128-bit na random) ang tanging susi.

   Read-only. Ang ipinapakita ay ang snapshot na iniimbak ng ShareRepo —
   nilinis at na-filter na roon (walang student no., walang INC/DRP/W,
   walang grade kapag naka-off), kaya walang desisyong pang-privacy dito.
   ============================================================ */

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Database;
use App\Models\ShareRepo;

/* Hindi dapat ma-index, ma-cache, o mai-embed sa ibang site; at ang token ay
   hindi dapat tumagas sa Referer kapag may pinindot na link palabas. */
$nonce = base64_encode(random_bytes(12));
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self'; "
    . "style-src 'self' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    . "font-src https://fonts.gstatic.com https://cdn.jsdelivr.net; "
    . "script-src 'nonce-{$nonce}'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");

$token = (string)($_GET['t'] ?? '');
$share = null;
$unavailable = false;

if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    try {
        $db = new Database();
        $share = ShareRepo::findPublic($db, $token);
        $db->close();
    } catch (\Throwable $e) {
        error_log('eGradeBook share.php failed: ' . $e);
        $unavailable = true;
    }
}

/* Iisang sagot para sa "walang ganitong link", "na-revoke", at "nag-expire":
   hindi dapat malaman ng nanghuhula kung alin sa tatlo. */
if (!$share) http_response_code($unavailable ? 503 : 404);

require APP_ROOT . '/app/Views/share.php';
