<?php
// auth.php — include this at top of any page that needs a logged-in user.
//
// LEGACY SHIM. Ang tunay na gate ay App\Core\Auth (tingnan ang index.php at
// login.php); wala nang nag-i-include nito ngayon. Nananatili ito para hindi
// masira ang anumang lumang page na baka tumawag pa rin dito — at para
// tiyaking DUMADAAN pa rin iyon sa parehong pinatibay na session cookie
// (SameSite/HttpOnly/strict mode). Huwag nang dagdagan ng sariling
// session_start() dito: iisa lang dapat ang lugar na nagsisimula ng session.
require_once __DIR__ . '/../app/bootstrap.php';

App\Core\Auth::requireLogin();
