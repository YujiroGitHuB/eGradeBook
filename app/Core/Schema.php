<?php

namespace App\Core;

/* ============================================================
   Schema — idempotent bootstrap of eGradeBook's OWN tables plus a
   series of inline migrations (checked via information_schema).
   New columns/tables get added HERE, not in a separate migration
   system. Production tables already exist, so everything must be
   idempotent.

   TUMATAKBO ITO SA BAWAT REQUEST — kasama ang bawat ?api= — kaya
   naka-gate ito sa isang version marker. Ang buong trabaho ay mga
   25 tawag ng colExists(), at ang bawat isa ay tumatanong sa
   information_schema: ~10.8 ms kada isa sa MariaDB 10.4, o mga
   285 ms kada request. Ibig sabihin, bawat pag-save ng score ay
   nagbabayad ng 285 ms bago pa magsimula ang tunay na trabaho.

   Ang marker ay ang FILEMTIME NG FILE NA ITO — hindi manu-manong
   numero. Kaya sa oras na may mag-edit ng Schema.php, kusang
   nawawalan ng bisa ang marker at muling tumatakbo ang migrations.
   Walang dapat tandaang i-bump, kaya walang panganib na malimutan
   (kapareho ng filemtime cache-busting ng CSS/JS ng app na ito).
   ============================================================ */
class Schema
{
    private const VERSION_TABLE = 'grade_schema_version';

    /* Ang bersyon ng schema = huling pagkakabago ng file na ito. */
    private static function wantedVersion(): string
    {
        $t = @filemtime(__FILE__);
        return $t ? (string)$t : 'unknown';
    }

    /* Nakatalang bersyon, o null kung wala pa ang table (unang takbo).
       Sa PHP 8.1+ ay nag-e-exception ang mysqli sa palyadong query,
       kaya kailangan ang catch para sa nawawalang table. */
    private static function recordedVersion(Database $db): ?string
    {
        try {
            $r = $db->conn->query("SELECT version FROM `" . self::VERSION_TABLE . "` WHERE lock_id=1");
            if (!$r) return null;
            $row = $r->fetch_assoc();
            return $row ? (string)$row['version'] : null;
        } catch (\Throwable $e) {
            return null;   // wala pa ang table
        }
    }

    /* Gate: laktawan ang lahat kung tugma ang naitalang bersyon.
       Isang mabilis na SELECT (~0.2 ms) kapalit ng ~285 ms. */
    public static function migrate(Database $db): void
    {
        $want = self::wantedVersion();
        if (self::recordedVersion($db) === $want) return;

        self::runAll($db);

        $conn = $db->conn;
        $conn->query("CREATE TABLE IF NOT EXISTS `" . self::VERSION_TABLE . "` (
            lock_id TINYINT NOT NULL PRIMARY KEY,
            version VARCHAR(32) NOT NULL
        )");
        $stmt = $conn->prepare("INSERT INTO `" . self::VERSION_TABLE . "` (lock_id, version) VALUES (1, ?)
            ON DUPLICATE KEY UPDATE version = VALUES(version)");
        $stmt->bind_param('s', $want);
        $stmt->execute();
        $stmt->close();
    }

    /* Ang buong dating laman ng migrate(). Tumatakbo lang kapag
       nagbago ang Schema.php (o sa kauna-unahang takbo). */
    private static function runAll(Database $db): void
    {
        $conn = $db->conn;

        /* ── MANUAL ACTIVITY TABLES ──────────────────────────────
           • grade_activities       → custom columns (Recitation, Project, etc.)
           • grade_activity_scores  → manual score per student per activity  */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_activities (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            owner_id    INT NOT NULL,
            section     VARCHAR(20) NOT NULL,
            title       VARCHAR(120) NOT NULL,
            max_points  INT NOT NULL DEFAULT 100,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_owner_section (owner_id, section)
        )");
        $conn->query("CREATE TABLE IF NOT EXISTS grade_activity_scores (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            activity_id INT NOT NULL,
            student_no  VARCHAR(20) NOT NULL,
            score       INT DEFAULT NULL,
            UNIQUE KEY uniq_act_student (activity_id, student_no),
            FOREIGN KEY (activity_id) REFERENCES grade_activities(id) ON DELETE CASCADE
        )");

        /* Migration: sort_order column for drag-reorder of activity columns */
        if (!$db->colExists('grade_activities', 'sort_order')) {
            $conn->query("ALTER TABLE grade_activities ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
        }

        /* Migration: weight (%) column for weighted (Excel-style) coursework */
        if (!$db->colExists('grade_activities', 'weight')) {
            $conn->query("ALTER TABLE grade_activities ADD COLUMN weight DECIMAL(6,2) NOT NULL DEFAULT 0");
        }

        /* Migration: linked (master-score) mode — one value shared by every student */
        if (!$db->colExists('grade_activities', 'linked')) {
            $conn->query("ALTER TABLE grade_activities ADD COLUMN linked TINYINT(1) NOT NULL DEFAULT 0");
            $conn->query("ALTER TABLE grade_activities ADD COLUMN linked_score INT DEFAULT NULL");
        }

        /* Per-section settings (e.g. use_defense toggle) */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_settings (
            owner_id    INT NOT NULL,
            section     VARCHAR(20) NOT NULL,
            use_defense TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (owner_id, section)
        )");

        /* Pinned sections — the subset of sections a teacher wants visible in the picker */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_pinned_sections (
            owner_id    INT NOT NULL,
            section     VARCHAR(20) NOT NULL,
            PRIMARY KEY (owner_id, section)
        )");

        /* Option B — term-based (Midterm/Final) weighted-by-category grading */
        if (!$db->colExists('grade_settings', 'term_mode')) {
            $conn->query("ALTER TABLE grade_settings ADD COLUMN term_mode TINYINT(1) NOT NULL DEFAULT 0");
        }
        if (!$db->colExists('grade_activities', 'term')) {
            $conn->query("ALTER TABLE grade_activities ADD COLUMN term VARCHAR(10) NOT NULL DEFAULT ''");
        }
        if (!$db->colExists('grade_activities', 'category_id')) {
            $conn->query("ALTER TABLE grade_activities ADD COLUMN category_id INT DEFAULT NULL");
        }
        $conn->query("CREATE TABLE IF NOT EXISTS grade_categories (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            owner_id   INT NOT NULL,
            section    VARCHAR(20) NOT NULL,
            term       VARCHAR(10) NOT NULL,
            name       VARCHAR(60) NOT NULL,
            weight     DECIMAL(6,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            INDEX idx_owner_section (owner_id, section)
        )");

        /* Form-column grading metadata — para ma-treat ang FormFlow form columns
           gaya ng manual activities (term/category para sa term mode, weight para sa
           weighted flat average, at drag-reorder position). Ang mismong form (title,
           points, sagot) ay NASA FormFlow pa rin — ito ay overlay LANG na pag-aari ng
           eGradeBook, kaya hindi nasisira ang cross-DB bridge. Keyed per teacher +
           section + form (kagaya ng scoping ng grade_activities / grade_categories). */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_form_meta (
            owner_id    INT NOT NULL,
            section     VARCHAR(20) NOT NULL,
            form_id     INT NOT NULL,
            term        VARCHAR(10) NOT NULL DEFAULT '',
            category_id INT DEFAULT NULL,
            weight      DECIMAL(6,2) NOT NULL DEFAULT 0,
            sort_order  INT NOT NULL DEFAULT 0,
            hidden      TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (owner_id, section, form_id)
        )");

        /* Attendance overlay — one AUTO "Attendance" column per section, computed live
           from the QR attendance scans (ATTENDANCE_DB.attendance_tbl). Present = the
           student has >=1 scan on a session date; % = present / total session dates. The
           score is READ-ONLY (owned by the attendance app, same bridge assumption as
           the roster); this table stores ONLY the eGradeBook grading overlay — an
           `enabled` toggle plus term/category/weight/order — so the attendance column
           can join weighted / term-mode grading exactly like a FormFlow form column.
           Keyed per teacher + section (kagaya ng scoping ng grade_form_meta). */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_attendance_meta (
            owner_id    INT NOT NULL,
            section     VARCHAR(20) NOT NULL,
            enabled     TINYINT(1) NOT NULL DEFAULT 0,
            term        VARCHAR(10) NOT NULL DEFAULT '',
            category_id INT DEFAULT NULL,
            weight      DECIMAL(6,2) NOT NULL DEFAULT 0,
            sort_order  INT NOT NULL DEFAULT 0,
            midterm_end       DATE DEFAULT NULL,
            final_category_id INT DEFAULT NULL,
            final_weight      DECIMAL(6,2) NOT NULL DEFAULT 0,
            final_sort_order  INT NOT NULL DEFAULT 0,
            PRIMARY KEY (owner_id, section)
        )");

        /* Transmutation bands — GLOBAL per teacher (one table used by every section,
           both flat and term grading). Editable from the Transmutation modal. */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_transmute (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            owner_id  INT NOT NULL,
            min_score DECIMAL(5,2) NOT NULL,
            point     DECIMAL(4,2) NOT NULL,
            INDEX idx_owner (owner_id)
        )");

        /* Per-student final status override (INC / DRP / W) — per section, per teacher.
           Overlay only; does not touch scores. Empty/absent = Auto (computed grade). */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_student_status (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            owner_id   INT NOT NULL,
            section    VARCHAR(20) NOT NULL,
            student_no VARCHAR(50) NOT NULL,
            status     VARCHAR(8) NOT NULL,
            UNIQUE KEY uniq_owner_sec_student (owner_id, section, student_no)
        )");

        /* Migration: widen `status` so it can hold a teacher's CUSTOM final-status
           label (e.g. "OJT", "Transferred"), not just INC/DRP/W. Was VARCHAR(8). */
        $ssLen = $conn->query("SELECT CHARACTER_MAXIMUM_LENGTH len FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='grade_student_status' AND COLUMN_NAME='status'");
        if ($ssLen && ($x = $ssLen->fetch_assoc()) && (int)$x['len'] < 24) {
            $conn->query("ALTER TABLE grade_student_status MODIFY status VARCHAR(24) NOT NULL");
        }

        /* ============================================================
           CLASS SCOPING (school_year + semester + subject) — Phase 0.
           Ginagawa nitong yunit ang isang "class" = (owner_id, school_year,
           semester, section, subject), hindi na section lang. Additive at
           idempotent: ang mga existing rows ay default sa LEGACY class
           ('', '', '') sa pamamagitan ng column default, kaya patuloy na
           lumo-load ang kasalukuyang gradebooks (walang hiwalay na back-fill).
           grade_transmute (global per teacher) at grade_pinned_sections
           (section-picker convenience) ay SADYANG hindi ginagalaw.
           Tingnan ang docs/class-scoping-plan.md. ============================ */
        $classTables = [
            'grade_activities', 'grade_categories', 'grade_settings',
            'grade_form_meta', 'grade_attendance_meta', 'grade_student_status',
        ];
        foreach ($classTables as $t) {
            if (!$db->colExists($t, 'school_year')) $conn->query("ALTER TABLE `$t` ADD COLUMN school_year VARCHAR(9) NOT NULL DEFAULT ''");
            if (!$db->colExists($t, 'semester'))    $conn->query("ALTER TABLE `$t` ADD COLUMN semester VARCHAR(8) NOT NULL DEFAULT ''");
            if (!$db->colExists($t, 'subject'))     $conn->query("ALTER TABLE `$t` ADD COLUMN subject VARCHAR(120) NOT NULL DEFAULT ''");
        }

        /* Extend the keys so a class is unique per (…, school_year, semester,
           subject). Guarded by whether the key already contains `subject`, so
           re-running is safe. Existing rows all carry '' for the new columns,
           so no key collisions with the old (owner_id, section[, form_id]) rows.
           grade_student_status keeps its `id` PRIMARY KEY — only its UNIQUE
           index changes. */
        if (!self::keyHasColumn($db, 'grade_settings', 'PRIMARY', 'subject')) {
            $conn->query("ALTER TABLE grade_settings DROP PRIMARY KEY,
                ADD PRIMARY KEY (owner_id, section, school_year, semester, subject)");
        }
        if (!self::keyHasColumn($db, 'grade_form_meta', 'PRIMARY', 'subject')) {
            $conn->query("ALTER TABLE grade_form_meta DROP PRIMARY KEY,
                ADD PRIMARY KEY (owner_id, section, form_id, school_year, semester, subject)");
        }
        if (!self::keyHasColumn($db, 'grade_attendance_meta', 'PRIMARY', 'subject')) {
            $conn->query("ALTER TABLE grade_attendance_meta DROP PRIMARY KEY,
                ADD PRIMARY KEY (owner_id, section, school_year, semester, subject)");
        }
        if (!self::keyHasColumn($db, 'grade_student_status', 'uniq_owner_sec_student', 'subject')) {
            $conn->query("ALTER TABLE grade_student_status DROP INDEX uniq_owner_sec_student,
                ADD UNIQUE KEY uniq_owner_sec_student (owner_id, section, student_no, school_year, semester, subject)");
        }

        /* Per-class HIDE ng isang form column. Ang FormFlow ay walang konsepto ng
           subject — `section` lang ang alam ng forms / form_responses — kaya ang
           auto-discovery ng form columns ay per-SECTION. Kapag maraming subject ang
           isang section (hal. BSIT-1A / Programming at BSIT-1A / Networking), lalabas
           ang form ng ibang subject sa bawat klase at PAPASOK pa sa coursework —
           maling grado. Walang upstream data na pang-filter, kaya eGradeBook-side ang
           tanging solusyon: ang guro ang nagtatago ng hindi kabilang na form, at
           dahil class-scoped na ang PK ng talahanayang ito, per-class ang tago.
           Default 0 = nakikita, kaya WALANG nagbabago sa mga dati nang sheet. */
        if (!$db->colExists('grade_form_meta', 'hidden')) {
            $conn->query("ALTER TABLE grade_form_meta ADD COLUMN hidden TINYINT(1) NOT NULL DEFAULT 0");
        }

        /* HATI NG ATTENDANCE SA MIDTERM AT FINAL.
           Isang auto attendance column lang dati kada klase, at ISA lang ang
           `term` nito — kaya kapag naka-term mode, kailangang pumili ang guro:
           Midterm O Final, hindi pwedeng pareho. Mas malala: walang date filter
           ang bilang ng sessions (COUNT(DISTINCT `date`) ng buong section), kaya
           kahit itakda mo sa Midterm, kasama pa rin ang mga iskan sa panahon ng
           Finals — patuloy na nagbabago ang Midterm grade hanggang katapusan ng
           semestre. Walang term/period column ang attendance_tbl na masasandalan,
           kaya ang guro ang magsasabi kung kailan natapos ang Midterm:
             • `midterm_end` — huling araw ng Midterm. NULL/blangko = WALANG hati,
               kaya hindi nagbabago ang gawi ng lahat ng dati nang sheet.
             • `final_*` — hiwalay na overlay (category/weight/order) para sa
               kalahating Final; ang lumang `category_id`/`weight`/`sort_order`
               ang sa Midterm. Fixed na ang `term` ng dalawang column kapag hati
               (midterm/final), kaya walang bagong `term` column dito.
           Hati lang kapag naka-term mode — walang saysay ang dalawang column
           kung walang Midterm/Final na gradong binubuo. */
        if (!$db->colExists('grade_attendance_meta', 'midterm_end')) {
            $conn->query("ALTER TABLE grade_attendance_meta ADD COLUMN midterm_end DATE DEFAULT NULL");
        }
        if (!$db->colExists('grade_attendance_meta', 'final_category_id')) {
            $conn->query("ALTER TABLE grade_attendance_meta ADD COLUMN final_category_id INT DEFAULT NULL");
        }
        if (!$db->colExists('grade_attendance_meta', 'final_weight')) {
            $conn->query("ALTER TABLE grade_attendance_meta ADD COLUMN final_weight DECIMAL(6,2) NOT NULL DEFAULT 0");
        }
        if (!$db->colExists('grade_attendance_meta', 'final_sort_order')) {
            $conn->query("ALTER TABLE grade_attendance_meta ADD COLUMN final_sort_order INT NOT NULL DEFAULT 0");
        }

        /* Pag-aangkin ng isang form sa ISANG subject — minsanan lang, pang-habambuhay.
           Kapasares ng `hidden` sa itaas: ang `hidden` ay per-klase (kailangang ulitin
           kada bagong semestre/taon), samantalang ito ay per (owner, section, form) —
           kaya awtomatiko nang nakatago ang form sa LAHAT ng klase ng section na iba
           ang subject, kasama ang mga klaseng gagawin pa lang. SADYANG hindi
           class-scoped, gaya ng grade_transmute at grade_pinned_sections.
           Blangkong subject = hindi inaangkin = lumalabas kahit saan (dating gawi).
           Ang per-klaseng `hidden` ay laging nananaig bilang override. */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_form_subject (
            owner_id INT NOT NULL,
            section  VARCHAR(20) NOT NULL,
            form_id  INT NOT NULL,
            subject  VARCHAR(120) NOT NULL DEFAULT '',
            PRIMARY KEY (owner_id, section, form_id)
        )");

        /* Roster snapshot (Phase 3) — freezes (student_no, fullname, course) per
           NON-legacy class so a class's students never disappear if the upstream
           roster (ATTENDANCE_DB.students_tbl) later changes. Topped up from the
           live roster on each sheet read; see App\Models\RosterRepo::rosterForClass().
           The legacy class ('', '', '') is never snapshotted — it always uses the
           live roster (unchanged behaviour). */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_roster_snapshot (
            owner_id    INT NOT NULL,
            school_year VARCHAR(9)   NOT NULL DEFAULT '',
            semester    VARCHAR(8)   NOT NULL DEFAULT '',
            section     VARCHAR(20)  NOT NULL,
            subject     VARCHAR(120) NOT NULL DEFAULT '',
            student_no  VARCHAR(50)  NOT NULL,
            fullname    VARCHAR(150) NOT NULL DEFAULT '',
            course      VARCHAR(100) NOT NULL DEFAULT '',
            PRIMARY KEY (owner_id, school_year, semester, section, subject, student_no)
        )");

        /* Class registry (Phase 3+) — the named classes a teacher created for a
           section, so they show up in the Class dropdown even before they have
           any activities. Auto-registered when an activity is added, and the
           `classes` list also unions in any class already present in
           grade_activities. Keyed per (owner, section, school_year, semester,
           subject); the legacy class ('', '', '') is never registered. */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_classes (
            owner_id    INT NOT NULL,
            section     VARCHAR(20)  NOT NULL,
            school_year VARCHAR(9)   NOT NULL DEFAULT '',
            semester    VARCHAR(8)   NOT NULL DEFAULT '',
            subject     VARCHAR(120) NOT NULL DEFAULT '',
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (owner_id, section, school_year, semester, subject)
        )");

        /* ── APP ACCESS ALLOWLIST ────────────────────────────────
           Kung sinong account ng FormFlow ang makakapasok sa eGradeBook.

           Bakit kailangan ito: IISANG PHP session ang FormFlow at eGradeBook
           sa iisang host (parehong PHPSESSID sa path '/', at parehong-pareho
           ang mga key na `admin_id` / `admin_role`). Kaya ang sinumang naka-
           login sa FormFlow ay pasado na sa Auth::requireLogin() dito — hindi
           na sila kailangang dumaan sa login.php natin. Kung basta aalisin ang
           superadmin gate, LAHAT ng FormFlow account ay may eGradeBook agad,
           pati ang mga gagawin pa lang sa hinaharap para sa ibang layunin.
           Ang talaang ito ang nagpapasya, hindi ang pagkakaroon ng account.

           SADYANG hindi ito owner-scoped — hindi ito datos ng isang guro kundi
           talaan ng buong app (gaya ng grade_schema_version). Ang superadmin
           ay LAGING pasado kahit wala rito, kaya walang paraang ma-lock out
           ang sarili (tingnan ang Auth::requireAccess). */
        /* Ulo ng mga PDF report — pangalan ng paaralan, departamento, pamagat,
           guro, at footer note. Isa kada guro (kaya owner_id ang PK), hindi
           kada klase: iisang paaralan at iisang pirma ang gagamitin sa bawat
           section at bawat semestre, kaya nakakapagod na ipatipa ito nang
           paulit-ulit. Ang blangkong field ay TINATANGGAL sa PDF, hindi
           ipinapakitang walang laman — kaya ang gurong hindi ito hinawakan
           kailanman ay makakakuha ng eksaktong lumang anyo. */
        $conn->query("CREATE TABLE IF NOT EXISTS grade_report_header (
            owner_id   INT NOT NULL PRIMARY KEY,
            school     VARCHAR(150) NOT NULL DEFAULT '',
            department VARCHAR(150) NOT NULL DEFAULT '',
            title      VARCHAR(120) NOT NULL DEFAULT '',
            faculty    VARCHAR(150) NOT NULL DEFAULT '',
            note       VARCHAR(255) NOT NULL DEFAULT '',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        /* Banner ng letterhead — naka-imbak bilang data URI, hindi bilang file.
           Walang upload handling ang eGradeBook (at walang masusulatang folder
           sa karaniwang shared host), kaya ang browser ang nagpapaliit at
           nag-e-encode; teksto na lang ang dumarating dito. Sapat ang
           MEDIUMTEXT (16MB) — pinuputol naman ng kliyente sa ~1600px ang lapad.
           Ang banner_w/h ay ang sukat PAGKATAPOS ng paliit: kailangan ng jsPDF
           ang ratio para hindi mabanat ang larawan. */
        if (!$db->colExists('grade_report_header', 'banner')) {
            $conn->query("ALTER TABLE grade_report_header ADD COLUMN banner MEDIUMTEXT NULL");
        }
        if (!$db->colExists('grade_report_header', 'banner_w')) {
            $conn->query("ALTER TABLE grade_report_header ADD COLUMN banner_w INT NOT NULL DEFAULT 0");
        }
        if (!$db->colExists('grade_report_header', 'banner_h')) {
            $conn->query("ALTER TABLE grade_report_header ADD COLUMN banner_h INT NOT NULL DEFAULT 0");
        }

        $conn->query("CREATE TABLE IF NOT EXISTS grade_app_access (
            admin_id   INT NOT NULL,
            granted_by INT NOT NULL DEFAULT 0,
            granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (admin_id)
        )");
    }

    /* Is $col part of the named index ($index; use 'PRIMARY' for the primary
       key) on $table, in the current DB? Guards the idempotent key changes. */
    private static function keyHasColumn(Database $db, string $table, string $index, string $col): bool
    {
        $r = $db->conn->query("SELECT COUNT(*) c FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'
            AND INDEX_NAME='$index' AND COLUMN_NAME='$col'");
        return $r && ($x = $r->fetch_assoc()) && (int)$x['c'] > 0;
    }
}
