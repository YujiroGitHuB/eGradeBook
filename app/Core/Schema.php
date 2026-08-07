<?php

namespace App\Core;

/* ============================================================
   Schema — idempotent bootstrap of eGradeBook's OWN tables plus a
   series of inline migrations (checked via information_schema).
   Runs on every request, gaya ng dati sa taas ng index.php. New
   columns/tables get added HERE, not in a separate migration system.
   Production tables already exist, so everything must be idempotent.
   ============================================================ */
class Schema
{
    public static function migrate(Database $db): void
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
