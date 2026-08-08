<?php
// logout.php — burahin ang session at ibalik sa login.
// Dumadaan sa App\Core\Auth::start() sa halip na sariling session_start() para
// iisa lang ang lugar na nagbubukas ng session (doon naka-set ang SameSite /
// HttpOnly / strict mode). Mahalaga rin ito kapag walang session: ang hubad na
// session_start() ay gagawa ng bagong cookie na WALANG mga flag na iyon bago pa
// ito burahin.
require_once __DIR__ . '/../app/bootstrap.php';

App\Core\Auth::start();
$_SESSION = [];

// burahin din ang mismong cookie, hindi lang ang laman ng session
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);
}

session_destroy();
header('Location: ../login.php');
exit;
