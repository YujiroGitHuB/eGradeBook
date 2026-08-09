<?php
/* ============================================================
   inc/db.php — eGradeBook (standalone app) · BRIDGE CONFIG ONLY

   Ito na LANG ang tahanan ng cross-database bridge constants.
   Ang mismong koneksyon (mysqli) at query helpers ay nasa
   App\Core\Database na ngayon (OOP). Config-only ang file na ito
   para iisang lugar pa rin ang bridge assumptions — gaya ng dati.

   This app has its OWN database (DB_NAME) for the grading tables,
   but it bridges (cross-database query) into FormFlow's database
   (forms/form_responses scores AND the shared admin_users login)
   and the attendance database (roster).

   ASSUMPTION: the three databases are still on ONE MySQL server
   (formflow_db, the attendance DB, and the grading DB) — so the
   `database_name`.`table` cross-db queries work using a SINGLE
   mysqli connection. If you move eGradeBook to a DIFFERENT physical
   DB server, the direct SQL joins here won't work — you'll need a
   different approach (e.g. REST API bridge into FormFlow, or DB
   replication). See App\Models\RosterRepo / FormRepo / UserRepo.
   ============================================================ */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');

// ── eGradeBook's own database ─────────────────────
define('DB_NAME', 'egradebook_db');

// ── Bridged/external databases (same MySQL server) ───────
define('FORMFLOW_DB', 'formflow_db');            // admin_users, forms, form_questions, form_responses
define('ATTENDANCE_DB', 'bcc_qr_attendance_db'); // roster + attendance scans
define('ATTENDANCE_TABLE', 'students_tbl');

// ── Optional: link back to the main FormFlow app (leave '' to hide the link) ──
define('FORMFLOW_APP_URL', '');

/* ── Saan hinahain ang FormFlow sa WEB (hindi sa DB) ──────────────
   Ang profile photo ay nakatago sa formflow_db.admin_users.avatar bilang
   path na RELATIBO sa sariling folder ng FormFlow (hal.
   "uploads/avatars/a1b2c3.jpg") — nasa disk ng FormFlow ang file mismo,
   hindi rito. Ang bridge natin ay SQL lang; hindi nito naaabot ang mga
   file, kaya kailangan ng URL na maituturo ng browser.

   Ang default ay para sa magkatabing deploy: /FormFlow at /eGradeBook sa
   iisang web root, kaya mula sa /eGradeBook/index.php ay tumatama ang
   "../FormFlow/". Palitan ng ganap na URL kung ibang lugar ang FormFlow.
   Ang '' ay nagtatago ng larawan at ibabalik ang dating icon. */
define('FORMFLOW_WEB_BASE', '../FormFlow/');
