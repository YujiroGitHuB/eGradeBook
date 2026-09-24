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

   The bridges are read as `database_name`.`table` SQL. With the
   *_DB_USER settings blank (XAMPP) that is ONE mysqli connection
   whose user can read all three databases. On Hostinger each
   database has exactly one user, so FORMFLOW_DB_USER /
   ATTENDANCE_DB_USER give each bridge its own login and its own
   connection (App\Core\Database::formflow() / attendance()). Either
   way no single query names two databases, so the bridge no longer
   depends on one user seeing all three. See App\Models\RosterRepo /
   FormRepo / UserRepo.

   ── SAAN GALING ANG HALAGA ────────────────────────────────────
   Tatlong antas, mula sa pinakamalakas:

     1. inc/config.local.php  — legacy; kung nag-define na ito, iyon
        ang mananaig (plain define(), kaya panalo sa eg_define).
     2. .env sa project root  — ito na ang paraan; kaparehong
        format at kaparehong reader (App\Core\Env) ng FormFlow.
        Nilo-load ng app/bootstrap.php bago pa marating ang file
        na ito.
     3. Ang mga default sa ibaba — XAMPP (localhost/root/walang
        password). Iyon ang dahilan kaya gumagana pa rin ang app
        nang walang anumang config file.

   Ang .env at ang config.local.php ay PAREHONG wala sa Git
   (.gitignore) at wala sa deploy (.deployignore), kaya hindi
   sila napapatungan ng push at hindi lumalabas sa repo.
   Template: .env.example
   ============================================================ */

use App\Core\Env;

/* Legacy: mga server na nauna pa sa .env. Puwede nang burahin ang
   file na iyon kapag nailipat na ang lahat sa .env. */
if (is_file(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

/* Maliit na helper: define lang kung wala pang naunang nagtakda
   (i.e. hindi ito galing sa config.local.php). */
if (!function_exists('eg_define')) {
    function eg_define(string $name, $value): void
    {
        if (!defined($name)) define($name, $value);
    }
}

eg_define('DB_HOST', Env::get('DB_HOST', 'localhost'));
eg_define('DB_USER', Env::get('DB_USER', 'root'));
eg_define('DB_PASS', Env::get('DB_PASS', ''));

// ── eGradeBook's own database ─────────────────────
eg_define('DB_NAME', Env::get('DB_NAME', 'egradebook_db'));

/* Gumawa ba ng database kung wala pa? Sa XAMPP, oo — iyon ang
   dahilan kaya "buksan mo lang" ang buong setup. Sa shared hosting
   (Hostinger/InfinityFree) ay WALANG CREATE DATABASE privilege ang
   MySQL user: ang control panel ang gumagawa nito, at ang query ay
   tahimik na babagsak kada request. DB_AUTO_CREATE=false doon. */
eg_define('DB_AUTO_CREATE', Env::bool('DB_AUTO_CREATE', true));

// ── Bridged/external databases (same MySQL server) ───────
eg_define('FORMFLOW_DB', Env::get('FORMFLOW_DB', 'formflow_db'));            // admin_users, forms, form_questions, form_responses
eg_define('ATTENDANCE_DB', Env::get('ATTENDANCE_DB', 'bcc_qr_attendance_db')); // roster + attendance scans
eg_define('ATTENDANCE_TABLE', Env::get('ATTENDANCE_TABLE', 'students_tbl'));

/* ── HIWALAY na login para sa bawat bridge — IWANANG BLANGKO sa XAMPP ──
   Blangko = binabasa ang database na iyon gamit ang DB_USER sa itaas (ang
   dating cross-database query sa iisang koneksyon; gumagana dahil nababasa
   ng root ang lahat).

   KAILANGAN sa Hostinger: iisang user lang ang bawat database doon, at hindi
   ito mabibigyan ng pangalawang database — kaya tinatanggihan ang user ng
   eGradeBook sa mga table ng FormFlow at ng attendance, at pati ang LOGIN ay
   babagsak. Ilagay dito ang sariling user/password ng database na iyon — ang
   mismong ginagamit ng FormFlow at ng bccsasqr. BUMABASA LANG ang eGradeBook
   sa mga iyon. Kapareho ng ROSTER_DB_USER ng FormFlow. Tingnan ang
   App\Core\Database::formflow() / attendance(). */
eg_define('FORMFLOW_DB_USER', Env::get('FORMFLOW_DB_USER', ''));
eg_define('FORMFLOW_DB_PASS', Env::get('FORMFLOW_DB_PASS', ''));
eg_define('FORMFLOW_DB_HOST', Env::get('FORMFLOW_DB_HOST', ''));      // blangko = DB_HOST
eg_define('ATTENDANCE_DB_USER', Env::get('ATTENDANCE_DB_USER', ''));
eg_define('ATTENDANCE_DB_PASS', Env::get('ATTENDANCE_DB_PASS', ''));
eg_define('ATTENDANCE_DB_HOST', Env::get('ATTENDANCE_DB_HOST', ''));  // blangko = DB_HOST

// ── Optional: link back to the main FormFlow app (leave '' to hide the link) ──
eg_define('FORMFLOW_APP_URL', Env::get('FORMFLOW_APP_URL', ''));

/* ── Saan hinahain ang FormFlow sa WEB (hindi sa DB) ──────────────
   Ang profile photo ay nakatago sa formflow_db.admin_users.avatar bilang
   path na RELATIBO sa sariling folder ng FormFlow (hal.
   "uploads/avatars/a1b2c3.jpg") — nasa disk ng FormFlow ang file mismo,
   hindi rito. Ang bridge natin ay SQL lang; hindi nito naaabot ang mga
   file, kaya kailangan ng URL na maituturo ng browser.

   Ang default ay para sa magkatabing deploy: /FormFlow at /eGradeBook sa
   iisang web root, kaya mula sa /eGradeBook/index.php ay tumatama ang
   "../FormFlow/". Palitan ng ganap na URL kung ibang lugar ang FormFlow.
   Ang '' ay nagtatago ng larawan at ibabalik ang dating icon.

   PAANO ITO PATAYIN MULA SA .env: ang Env::get() ay ibinabalik ang
   DEFAULT kapag blangko ang halaga (ganoon din ang FormFlow), kaya ang
   "FORMFLOW_WEB_BASE=" o "=''" ay hindi makakapagpatay ng larawan —
   babalik lang ito sa "../FormFlow/". Kaya may sentinel: ang `off` o
   `none` ay nangangahulugang literal na blangko. Mas mabuti ito kaysa
   baguhin ang Env para tanggapin ang blangko: iisang klase iyon na
   kapareho ng sa FormFlow, at ayaw nating maghiwalay sila. */
$ffBase = (string) Env::get('FORMFLOW_WEB_BASE', '../FormFlow/');
if (in_array(strtolower(trim($ffBase)), ['off', 'none'], true)) $ffBase = '';
eg_define('FORMFLOW_WEB_BASE', $ffBase);

/* Timezone — Asia/Manila (UTC+8) ang app. Nakatakda rito PERO
   ipinapatupad sa App\Core\Database, na siyang nagse-set ng PHP at
   ng MySQL session nang sabay. Huwag mag-set ng timezone sa iba. */
eg_define('APP_TIMEZONE', Env::get('APP_TIMEZONE', 'Asia/Manila'));
