<?php
/* ============================================================
   app/bootstrap.php — autoloader + shared boot

   Standalone app: WALANG Composer, kaya hand-rolled PSR-4 autoloader
   ang gamit para mapanatili ang "drop the folder into htdocs" deploy
   model (no `composer install` needed on the server). Maps the
   `App\` namespace to this app/ directory.
   ============================================================ */

define('APP_ROOT', dirname(__DIR__)); // project root (one level up from app/)

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $relative = substr($class, strlen($prefix));                 // e.g. Core\Database
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) require $file;
});

require_once APP_ROOT . '/inc/db.php'; // bridge config constants (DB_*, FORMFLOW_DB, ATTENDANCE_DB, ...)
