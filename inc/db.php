<?php
/* ============================================================
   inc/db.php — eGradeBook (standalone app)

   This app has its OWN database (GRADING_DB) for the
   grading tables, but it bridges (cross-database query) into
   FormFlow's database (for forms/form_responses scores AND
   the shared admin_users login) and the attendance database (roster).

   ASSUMPTION: the three databases are still on ONE MySQL server
   (formflow_db, the attendance DB, and the new grading DB) —
   so the `database_name`.`table` cross-db queries work using
   one single mysqli connection. If you move eGradeBook
   to a different PHYSICAL DB server, the direct SQL
   join/queries here won't work — you'll need a different approach (e.g. REST
   API bridge into FormFlow, or DB replication).
   ============================================================ */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── eGradeBook's own database ─────────────────────
define('DB_NAME', 'egradebook_db');

// ── Bridged/external databases (same MySQL server) ───────
define('FORMFLOW_DB', 'formflow_db');          // admin_users, forms, form_questions, form_responses
define('ATTENDANCE_DB', 'bcc_qr_attendance_db'); // roster
define('ATTENDANCE_TABLE', 'students_tbl');

// ── Optional: link back to the main FormFlow app (leave '' to hide the link) ──
define('FORMFLOW_APP_URL', '');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DB connection failed: ' . $conn->connect_error
    ]);
    exit;
}

// Auto-create eGradeBook's own database
$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

if (!$conn->select_db(DB_NAME)) {
    echo json_encode(['success' => false, 'message' => 'Cannot select DB: ' . $conn->error]);
    exit;
}

$conn->set_charset('utf8mb4');

// ── TIMEZONE — Philippine Standard Time (UTC+8) ──────────────
date_default_timezone_set('Asia/Manila');
$conn->query("SET time_zone = '+08:00'");

// ── Quick check: are the bridged databases accessible? ────────
// (We don't exit if missing — but we flag it in /index.php
// when the API is called, so the user clearly sees what's wrong.)
function gradingSystem_canAccess($conn, $dbName)
{
    $r = $conn->query("SHOW DATABASES LIKE '" . $conn->real_escape_string($dbName) . "'");
    return $r && $r->num_rows > 0;
}

// ── SAFE COLUMN/TABLE HELPERS ────────────────────────────────
function addColIfMissing($conn, $table, $column, $definition)
{
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$r || $r->num_rows === 0) return; // table doesn't exist yet, skip
    $r2 = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($r2 && $r2->num_rows === 0) {
        $conn->query("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

/* does the column exist in the table? optional $db for cross-db tables
   (e.g. hasCol($conn, FORMFLOW_DB, 'form_responses', 'penalty_score')) */
function hasCol($conn, $db, $table, $col)
{
    $d = $conn->real_escape_string($db);
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `$d`.`$t` LIKE '$c'");
    return $r && $r->num_rows > 0;
}
