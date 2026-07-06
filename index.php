<?php

require_once 'inc/auth.php';   // login gate (bridged to FormFlow admin_users)
require_once 'inc/db.php';     // $conn (egradebook_db) + FORMFLOW_DB/ATTENDANCE_DB bridge + timezone

$__isSuperadmin = (($_SESSION['admin_role'] ?? '') === 'superadmin');
if (!$__isSuperadmin) {
    if (isset($_GET['api']) || isset($_POST['api'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Superadmin only.']);
        exit;
    }
    // page load, not superadmin → access denied (this is a standalone app, no other dashboard)
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Access Denied</title>'
        . '<link rel="stylesheet" href="assets/css/global.css"></head>'
        . '<body class="bg-glow" style="display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;">'
        . '<div><h2>Access denied</h2><p style="color:var(--muted);">Only superadmins have access to eGradeBook.</p>'
        . '<a href="inc/logout.php" class="btn btn-ghost btn-sm">Logout</a></div></body></html>';
    exit;
}

/* ATTENDANCE_DB / ATTENDANCE_TABLE — already defined in inc/db.php (bridge config) */

/* ============================================================
   MANUAL ACTIVITY TABLES (self-contained — gaya ng db.php)
   • grade_activities       → custom columns (Recitation, Project, etc.)
   • grade_activity_scores  → manual score per student per activity
   ============================================================ */
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
$hasSort = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_activities' AND COLUMN_NAME = 'sort_order'"
);
if ($hasSort && ($sr = $hasSort->fetch_assoc()) && (int)$sr['c'] === 0) {
    $conn->query("ALTER TABLE grade_activities ADD COLUMN sort_order INT NOT NULL DEFAULT 0");
}

/* Migration: weight (%) column for weighted (Excel-style) coursework */
$hasWt = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_activities' AND COLUMN_NAME = 'weight'"
);
if ($hasWt && ($wr = $hasWt->fetch_assoc()) && (int)$wr['c'] === 0) {
    $conn->query("ALTER TABLE grade_activities ADD COLUMN weight DECIMAL(6,2) NOT NULL DEFAULT 0");
}

/* Migration: linked (master-score) mode — one value shared by every student */
$hasLinked = $conn->query(
    "SELECT COUNT(*) c FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'grade_activities' AND COLUMN_NAME = 'linked'"
);
if ($hasLinked && ($lr = $hasLinked->fetch_assoc()) && (int)$lr['c'] === 0) {
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
$colExists = function ($table, $col) use ($conn) {
    $r = $conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'");
    return $r && ($x = $r->fetch_assoc()) && (int)$x['c'] > 0;
};
if (!$colExists('grade_settings', 'term_mode')) {
    $conn->query("ALTER TABLE grade_settings ADD COLUMN term_mode TINYINT(1) NOT NULL DEFAULT 0");
}
if (!$colExists('grade_activities', 'term')) {
    $conn->query("ALTER TABLE grade_activities ADD COLUMN term VARCHAR(10) NOT NULL DEFAULT ''");
}
if (!$colExists('grade_activities', 'category_id')) {
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
    PRIMARY KEY (owner_id, section, form_id)
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

/* Default PH college scale (min raw score -> equivalent point). Seeded once,
   per teacher, the first time their bands are read while the table is empty. */
$DEFAULT_EQUIV = [
    [96, 1.00], [94, 1.25], [91, 1.50], [88, 1.75], [85, 2.00],
    [82, 2.25], [79, 2.50], [76, 2.75], [75, 3.00],
];

/* ============================================================
   API LAYER
   ============================================================ */
if (isset($_GET['api']) || isset($_POST['api'])) {
    header('Content-Type: application/json');
    $api      = $_POST['api'] ?? $_GET['api'];
    $admin_id = intval($_SESSION['admin_id']);

    /* helper: ensure the activity belongs to the logged-in teacher */
    $ownsActivity = function ($activity_id) use ($conn, $admin_id) {
        $activity_id = intval($activity_id);
        $r = $conn->query("SELECT id FROM grade_activities WHERE id=$activity_id AND owner_id=$admin_id LIMIT 1");
        return $r && $r->num_rows > 0;
    };

    /* helper: ensure the FormFlow form belongs to the logged-in teacher (the
       form itself lives in FormFlow; we only guard who may attach grade_form_meta) */
    $ownsForm = function ($form_id) use ($conn, $admin_id) {
        $form_id = intval($form_id);
        $r = $conn->query("SELECT id FROM " . FORMFLOW_DB . ".forms WHERE id=$form_id AND owner_id=$admin_id LIMIT 1");
        return $r && $r->num_rows > 0;
    };

    /* helper: does the column exist in the table? (to stay safe with penalty_score) */
    $hasCol = function ($table, $col) use ($conn) {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($col);
        $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $r && $r->num_rows > 0;
    };

    /* helper: this teacher's transmutation bands, highest min first.
       Seeds the default PH scale on first use (empty table). */
    $loadEquiv = function () use ($conn, $admin_id, $DEFAULT_EQUIV) {
        $rows = [];
        $r = $conn->query("SELECT min_score, point FROM grade_transmute WHERE owner_id=$admin_id ORDER BY min_score DESC");
        if ($r) while ($x = $r->fetch_assoc()) $rows[] = ['min' => (float)$x['min_score'], 'point' => (float)$x['point']];
        if (!$rows) {
            $ins = $conn->prepare("INSERT INTO grade_transmute (owner_id, min_score, point) VALUES (?, ?, ?)");
            foreach ($DEFAULT_EQUIV as $b) {
                $mn = $b[0]; $pt = $b[1];
                $ins->bind_param('idd', $admin_id, $mn, $pt);
                $ins->execute();
            }
            $ins->close();
            foreach ($DEFAULT_EQUIV as $b) $rows[] = ['min' => (float)$b[0], 'point' => (float)$b[1]];
        }
        return $rows;
    };

    try {
        switch ($api) {

            /* ── LIST SECTIONS (from attendance DB) ───────────── */
            case 'sections':
                $sql = "SELECT section, course, COUNT(*) AS cnt
                        FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . "
                        WHERE section IS NOT NULL AND section <> ''
                        GROUP BY section, course
                        ORDER BY course, section";
                $res = $conn->query($sql);
                if (!$res) throw new Exception('Cannot access attendance DB: ' . $conn->error);
                $sections = [];
                while ($row = $res->fetch_assoc()) {
                    $sections[] = [
                        'section' => $row['section'],
                        'course'  => $row['course'],
                        'count'   => (int)$row['cnt'],
                    ];
                }
                echo json_encode(['success' => true, 'sections' => $sections]);
                break;

            /* ── MY SECTIONS (only those where this teacher has activities) ─ */
            case 'my_sections':
                $mine = [];
                $rm = $conn->prepare("SELECT DISTINCT section FROM grade_activities WHERE owner_id = ? ORDER BY section");
                $rm->bind_param('i', $admin_id);
                $rm->execute();
                $rr = $rm->get_result();
                while ($x = $rr->fetch_assoc()) $mine[] = $x['section'];
                $rm->close();
                echo json_encode(['success' => true, 'sections' => $mine]);
                break;

            /* ── LIST PINNED SECTIONS (this teacher's chosen subset) ─ */
            case 'pinned_sections':
                $pinned = [];
                $rp = $conn->prepare("SELECT section FROM grade_pinned_sections WHERE owner_id = ?");
                $rp->bind_param('i', $admin_id);
                $rp->execute();
                $rr = $rp->get_result();
                while ($row = $rr->fetch_assoc()) $pinned[] = $row['section'];
                echo json_encode(['success' => true, 'pinned' => $pinned]);
                break;

            /* ── SAVE PINNED SECTIONS (replace the whole set) ─────── */
            case 'save_pinned_sections':
                $raw  = $_POST['sections'] ?? '[]';
                $list = json_decode($raw, true);
                if (!is_array($list)) $list = [];

                $conn->begin_transaction();
                try {
                    $del = $conn->prepare("DELETE FROM grade_pinned_sections WHERE owner_id = ?");
                    $del->bind_param('i', $admin_id);
                    $del->execute();

                    if ($list) {
                        $ins = $conn->prepare("INSERT IGNORE INTO grade_pinned_sections (owner_id, section) VALUES (?, ?)");
                        foreach ($list as $sec) {
                            $sec = trim((string)$sec);
                            if ($sec === '') continue;
                            $ins->bind_param('is', $admin_id, $sec);
                            $ins->execute();
                        }
                    }
                    $conn->commit();
                    echo json_encode(['success' => true, 'count' => count($list)]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Could not save: ' . $e->getMessage()]);
                }
                break;

            /* ── BUILD SHEET (students × columns × scores) ───────── */
            /* Columns = FORMS (auto, read-only) + ACTIVITIES (manual). */
            /* Each column has a unique `key`: forms='f'+id, activities='a'+id */
            case 'sheet':
                $section = trim($_GET['section'] ?? '');
                if ($section === '') {
                    echo json_encode(['success' => false, 'message' => 'Please select a section first.']);
                    break;
                }

                /* 1) ROSTER — students in the selected section */
                $stmt = $conn->prepare(
                    "SELECT student_no, fullname, course, section
                     FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . "
                     WHERE section = ?
                     ORDER BY fullname"
                );
                if (!$stmt) throw new Exception('Attendance query error: ' . $conn->error);
                $stmt->bind_param('s', $section);
                $stmt->execute();
                $rs       = $stmt->get_result();
                $students = [];
                $rosterNo = [];
                while ($r = $rs->fetch_assoc()) {
                    $students[] = $r;
                    $rosterNo[] = $r['student_no'];
                }
                $stmt->close();

                $columns = [];   // unified list of columns
                $scores  = [];   // scores[student_no][key] = {...}

                /* escape roster student_no for IN(...) */
                $noList = '';
                if ($rosterNo) {
                    $esc    = array_map(fn($s) => "'" . $conn->real_escape_string($s) . "'", $rosterNo);
                    $noList = implode(',', $esc);
                }

                /* 2) FORM COLUMNS — owned by the logged-in teacher, filtered by section */
                $section_esc = $conn->real_escape_string($section);
                $fres = $conn->query(
                    "SELECT DISTINCT f.id, f.title, f.accent_color, f.created_at
                     FROM " . FORMFLOW_DB . ".forms f
                     WHERE f.owner_id = $admin_id
                     AND f.id IN (
                         SELECT DISTINCT form_id FROM " . FORMFLOW_DB . ".form_responses
                         WHERE section = '$section_esc'
                     )
                     ORDER BY f.created_at ASC"
                );
                $formMap = [];   // form_id => index in $columns
                $formIds = [];
                while ($f = $fres->fetch_assoc()) {
                    $key = 'f' . $f['id'];
                    $columns[$key] = [
                        'key'         => $key,
                        'type'        => 'form',
                        'id'          => (int)$f['id'],
                        'title'       => $f['title'],
                        'max'         => 0,
                        'responded'   => 0,
                        /* eGradeBook-side overlay (grade_form_meta) — defaults;
                           filled in below once the roster/activities are loaded */
                        'weight'      => 0.0,
                        'term'        => '',
                        'category_id' => null,
                        'sort_order'  => 0,
                    ];
                    $formMap[(int)$f['id']] = $key;
                    $formIds[] = (int)$f['id'];
                }

                if ($formIds) {
                    $idList = implode(',', $formIds);

                    /* MAX per form — total points of questions */
                    $mq = $conn->query(
                        "SELECT form_id, COALESCE(SUM(points),0) AS pts
                         FROM " . FORMFLOW_DB . ".form_questions WHERE form_id IN ($idList) GROUP BY form_id"
                    );
                    while ($m = $mq->fetch_assoc()) {
                        $k = $formMap[(int)$m['form_id']] ?? null;
                        if ($k) $columns[$k]['max'] = (int)$m['pts'];
                    }

                    /* SCORES — latest response per (form, student) in the roster */
                    $hasPenalty = hasCol($conn, FORMFLOW_DB, 'form_responses', 'penalty_score');
                    $penSel     = $hasPenalty ? ', penalty_score' : '';

                    if ($noList) {
                        $sq = $conn->query(
                            "SELECT form_id, student_no, score, max_score, submitted_at $penSel
                             FROM " . FORMFLOW_DB . ".form_responses
                             WHERE form_id IN ($idList) AND student_no IN ($noList)
                             ORDER BY submitted_at ASC"
                        );
                        while ($s = $sq->fetch_assoc()) {
                            $k   = $formMap[(int)$s['form_id']] ?? null;
                            if (!$k) continue;
                            $sno = $s['student_no'];
                            $pen = $hasPenalty ? (int)($s['penalty_score'] ?? 0) : 0;
                            $eff = max(0, (int)$s['score'] - $pen);
                            if (($columns[$k]['max'] ?? 0) === 0 && (int)$s['max_score'] > 0)
                                $columns[$k]['max'] = (int)$s['max_score'];

                            $isNew = !isset($scores[$sno][$k]);
                            $scores[$sno][$k] = [
                                'score'   => $eff,
                                'raw'     => (int)$s['score'],
                                'penalty' => $pen,
                                'max'     => (int)$s['max_score'],
                                'at'      => $s['submitted_at'],
                            ];
                            if ($isNew) $columns[$k]['responded']++;
                        }
                    }
                }

                /* 3) ACTIVITY COLUMNS — manual, per owner + section */
                $stmtA = $conn->prepare(
                    "SELECT id, title, max_points, weight, term, category_id, linked, sort_order FROM grade_activities
                     WHERE owner_id = ? AND section = ? ORDER BY sort_order ASC, created_at ASC"
                );
                $stmtA->bind_param('is', $admin_id, $section);
                $stmtA->execute();
                $ares = $stmtA->get_result();
                $actMap = [];
                $actIds = [];
                while ($a = $ares->fetch_assoc()) {
                    $key = 'a' . $a['id'];
                    $columns[$key] = [
                        'key'         => $key,
                        'type'        => 'activity',
                        'id'          => (int)$a['id'],
                        'title'       => $a['title'],
                        'max'         => (int)$a['max_points'],
                        'weight'      => (float)$a['weight'],
                        'term'        => $a['term'],
                        'category_id' => $a['category_id'] !== null ? (int)$a['category_id'] : null,
                        'linked'      => ((int)$a['linked'] === 1) ? 1 : 0,   // sync same-score mode
                        'sort_order'  => (int)$a['sort_order'],
                        'responded'   => 0,
                    ];
                    $actMap[(int)$a['id']] = $key;
                    $actIds[] = (int)$a['id'];
                }
                $stmtA->close();

                /* FORM META overlay — term / category / weight / order that the
                   teacher assigned to each form column (grade_form_meta). Merge
                   into the form columns built earlier so they behave like
                   activities in term mode / weighted grading / reordering. */
                if ($formIds) {
                    $fmStmt = $conn->prepare(
                        "SELECT form_id, term, category_id, weight, sort_order FROM grade_form_meta
                         WHERE owner_id = ? AND section = ?"
                    );
                    $fmStmt->bind_param('is', $admin_id, $section);
                    $fmStmt->execute();
                    $fmRes = $fmStmt->get_result();
                    while ($fm = $fmRes->fetch_assoc()) {
                        $k = $formMap[(int)$fm['form_id']] ?? null;
                        if (!$k || !isset($columns[$k])) continue;
                        $columns[$k]['weight']      = (float)$fm['weight'];
                        $columns[$k]['term']        = $fm['term'];
                        $columns[$k]['category_id'] = $fm['category_id'] !== null ? (int)$fm['category_id'] : null;
                        $columns[$k]['sort_order']  = (int)$fm['sort_order'];
                    }
                    $fmStmt->close();
                }

                /* Unified column order — sort forms + activities together by
                   sort_order. Stable tiebreak on the natural build order (forms
                   first, then activities) so untouched sheets look unchanged. */
                $__ci = 0;
                foreach ($columns as $__k => &$__c) { $__c['_ord'] = $__ci++; }
                unset($__c);
                uasort($columns, function ($a, $b) {
                    $sa = $a['sort_order'] ?? 0;
                    $sb = $b['sort_order'] ?? 0;
                    if ($sa !== $sb) return $sa <=> $sb;
                    return ($a['_ord'] ?? 0) <=> ($b['_ord'] ?? 0);
                });
                foreach ($columns as &$__c2) { unset($__c2['_ord']); }
                unset($__c2);

                if ($actIds && $noList) {
                    $aIdList = implode(',', $actIds);
                    $asq = $conn->query(
                        "SELECT activity_id, student_no, score
                         FROM grade_activity_scores
                         WHERE activity_id IN ($aIdList) AND student_no IN ($noList)
                           AND score IS NOT NULL"
                    );
                    while ($r = $asq->fetch_assoc()) {
                        $k   = $actMap[(int)$r['activity_id']] ?? null;
                        if (!$k) continue;
                        $sno = $r['student_no'];
                        $scores[$sno][$k] = [
                            'score'   => (int)$r['score'],
                            'raw'     => (int)$r['score'],
                            'penalty' => 0,
                            'max'     => $columns[$k]['max'],
                            'at'      => null,
                        ];
                        $columns[$k]['responded']++;
                    }
                }

                /* transmutation table passed to the client — teacher's own bands
                   (global; used by BOTH flat Final grade and term Equivalent) */
                $sheetEquiv = $loadEquiv();

                /* per-section setting: term_mode */
                $useDefense = true;
                $termMode   = false;
                $stmtS = $conn->prepare("SELECT use_defense, term_mode FROM grade_settings WHERE owner_id=? AND section=? LIMIT 1");
                $stmtS->bind_param('is', $admin_id, $section);
                $stmtS->execute();
                $sres = $stmtS->get_result();
                if ($srow = $sres->fetch_assoc()) {
                    $useDefense = (int)$srow['use_defense'] === 1;
                    $termMode   = (int)$srow['term_mode'] === 1;
                }
                $stmtS->close();

                /* categories (for term-based grading) */
                $categories = [];
                $stmtC = $conn->prepare(
                    "SELECT id, term, name, weight, sort_order FROM grade_categories
                     WHERE owner_id=? AND section=? ORDER BY term ASC, sort_order ASC, id ASC"
                );
                $stmtC->bind_param('is', $admin_id, $section);
                $stmtC->execute();
                $cres = $stmtC->get_result();
                while ($c = $cres->fetch_assoc()) {
                    $categories[] = [
                        'id'     => (int)$c['id'],
                        'term'   => $c['term'],
                        'name'   => $c['name'],
                        'weight' => (float)$c['weight'],
                    ];
                }
                $stmtC->close();

                /* per-student final status overrides (INC / DRP / W) for this section */
                $statuses = [];
                $stmtS = $conn->prepare("SELECT student_no, status FROM grade_student_status WHERE owner_id = ? AND section = ?");
                $stmtS->bind_param('is', $admin_id, $section);
                $stmtS->execute();
                $rs = $stmtS->get_result();
                while ($x = $rs->fetch_assoc()) $statuses[$x['student_no']] = $x['status'];
                $stmtS->close();

                echo json_encode([
                    'success'     => true,
                    'section'     => $section,
                    'students'    => $students,
                    'columns'     => array_values($columns),
                    'scores'      => $scores,
                    'grade_equiv' => $sheetEquiv,   // for client-side Final grade transmutation
                    'use_defense' => $useDefense,
                    'term_mode'   => $termMode,
                    'categories'  => $categories,
                    'statuses'    => $statuses,
                ]);
                break;

            /* ── ADD ACTIVITY (manual column) ────────────────────── */
            case 'add_activity':
                $section = trim($_POST['section'] ?? '');
                $title   = trim($_POST['title'] ?? '');
                $maxPts  = max(1, intval($_POST['max_points'] ?? 100));
                $term    = in_array($_POST['term'] ?? '', ['midterm', 'final'], true) ? $_POST['term'] : '';
                $catId   = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? intval($_POST['category_id']) : null;
                if ($section === '' || $title === '') {
                    echo json_encode(['success' => false, 'message' => 'Section and activity name are required.']);
                    break;
                }
                $stmt = $conn->prepare(
                    "INSERT INTO grade_activities (owner_id, section, title, max_points, term, category_id)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $stmt->bind_param('issisi', $admin_id, $section, $title, $maxPts, $term, $catId);
                if (!$stmt->execute()) throw new Exception('Insert failed: ' . $conn->error);
                $newId = $stmt->insert_id;
                $stmt->close();
                echo json_encode([
                    'success'  => true,
                    'activity' => [
                        'key' => 'a' . $newId,
                        'type' => 'activity',
                        'id'  => $newId,
                        'title' => $title,
                        'max' => $maxPts,
                        'weight' => 0,
                        'term' => $term,
                        'category_id' => $catId,
                        'responded' => 0,
                    ],
                ]);
                break;

            /* ── EDIT ACTIVITY (rename + change max points) ──────── */
            case 'edit_activity':
                $aid    = intval($_POST['activity_id'] ?? 0);
                $title  = trim($_POST['title'] ?? '');
                $maxPts = max(1, intval($_POST['max_points'] ?? 100));
                $weight = max(0, (float)($_POST['weight'] ?? 0));
                $eTerm  = array_key_exists('term', $_POST) ? (in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '') : null;
                $eCat   = array_key_exists('category_id', $_POST) ? ($_POST['category_id'] === '' ? null : intval($_POST['category_id'])) : false;
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                if ($title === '') {
                    echo json_encode(['success' => false, 'message' => 'Activity name is required.']);
                    break;
                }
                $stmt = $conn->prepare("UPDATE grade_activities SET title=?, max_points=?, weight=? WHERE id=?");
                $stmt->bind_param('sidi', $title, $maxPts, $weight, $aid);
                if (!$stmt->execute()) throw new Exception('Update failed: ' . $conn->error);
                $stmt->close();
                /* update the term / category if provided (separate so the old caller isn't broken) */
                if ($eTerm !== null) {
                    $st2 = $conn->prepare("UPDATE grade_activities SET term=? WHERE id=?");
                    $st2->bind_param('si', $eTerm, $aid);
                    $st2->execute();
                    $st2->close();
                }
                if ($eCat !== false) {
                    $st3 = $conn->prepare("UPDATE grade_activities SET category_id=? WHERE id=?");
                    $st3->bind_param('ii', $eCat, $aid);
                    $st3->execute();
                    $st3->close();
                }

                /* when max is lowered, clamp scores that exceeded it and report
                   which ones changed so the frontend can sync (totals/%) */
                $clamped = [];
                $res = $conn->query("SELECT student_no FROM grade_activity_scores WHERE activity_id=$aid AND score > $maxPts");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $clamped[] = ['student_no' => $row['student_no'], 'score' => $maxPts];
                    }
                    $res->free();
                }
                if ($clamped) {
                    $conn->query("UPDATE grade_activity_scores SET score=$maxPts WHERE activity_id=$aid AND score > $maxPts");
                }

                echo json_encode([
                    'success'  => true,
                    'activity' => ['id' => $aid, 'title' => $title, 'max' => $maxPts, 'weight' => $weight],
                    'clamped'  => $clamped,
                ]);
                break;

            /* ── DELETE ACTIVITY ─────────────────────────────────── */
            case 'delete_activity':
                $aid = intval($_POST['activity_id'] ?? 0);
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                $conn->query("DELETE FROM grade_activities WHERE id=$aid"); // cascades scores
                echo json_encode(['success' => true]);
                break;

            /* ── SAVE ACTIVITY SCORE (per student, upsert) ───────── */
            case 'save_activity_score':
                $aid = intval($_POST['activity_id'] ?? 0);
                $sno = trim($_POST['student_no'] ?? '');
                $raw = $_POST['score'] ?? '';
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                if ($sno === '') {
                    echo json_encode(['success' => false, 'message' => 'Missing student.']);
                    break;
                }
                /* clamp to 0..max_points; blank = delete (NULL) */
                $maxRow = $conn->query("SELECT max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
                $maxPts = (int)($maxRow['max_points'] ?? 100);

                if ($raw === '' || $raw === null) {
                    $stmt = $conn->prepare("DELETE FROM grade_activity_scores WHERE activity_id=? AND student_no=?");
                    $stmt->bind_param('is', $aid, $sno);
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['success' => true, 'cleared' => true]);
                    break;
                }
                $score = max(0, min($maxPts, intval($raw)));
                $stmt = $conn->prepare(
                    "INSERT INTO grade_activity_scores (activity_id, student_no, score)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score)"
                );
                $stmt->bind_param('isi', $aid, $sno, $score);
                if (!$stmt->execute()) throw new Exception('Save failed: ' . $conn->error);
                $stmt->close();
                echo json_encode(['success' => true, 'score' => $score]);
                break;

            /* ── TOGGLE sync (same-score) mode for an activity ───────
               linked=1 → editing a cell also updates every cell that had
               the same value (handled client-side + sync_activity_score). */
            case 'set_linked_activity':
                $aid    = intval($_POST['activity_id'] ?? 0);
                $linked = (($_POST['linked'] ?? '0') === '1' || ($_POST['linked'] ?? '') === 'true') ? 1 : 0;
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                $stmt = $conn->prepare("UPDATE grade_activities SET linked=? WHERE id=? AND owner_id=?");
                $stmt->bind_param('iii', $linked, $aid, $admin_id);
                if (!$stmt->execute()) throw new Exception('Save failed: ' . $conn->error);
                $stmt->close();
                echo json_encode(['success' => true, 'linked' => $linked]);
                break;

            /* ── SYNC same scores — set every cell equal to from_score
                  to to_score, within one activity (sweeps the "twins"). ── */
            case 'sync_activity_score':
                $aid  = intval($_POST['activity_id'] ?? 0);
                $from = $_POST['from_score'] ?? '';
                $to   = $_POST['to_score'] ?? '';
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                if ($from === '' || $to === '' || $from === null || $to === null) {
                    echo json_encode(['success' => true, 'updated' => 0]);
                    break;
                }
                $maxRow = $conn->query("SELECT max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
                $maxPts = (int)($maxRow['max_points'] ?? 100);
                $fromV  = intval($from);
                $toV    = max(0, min($maxPts, intval($to)));
                $stmt = $conn->prepare(
                    "UPDATE grade_activity_scores SET score=? WHERE activity_id=? AND score=?"
                );
                $stmt->bind_param('iii', $toV, $aid, $fromV);
                if (!$stmt->execute()) throw new Exception('Sync failed: ' . $conn->error);
                $updated = $stmt->affected_rows;
                $stmt->close();
                echo json_encode(['success' => true, 'updated' => $updated, 'from' => $fromV, 'to' => $toV]);
                break;

            /* ── BULK FILL — same score to all/empty of an activity ── */
            case 'bulk_fill_activity':
                $aid  = intval($_POST['activity_id'] ?? 0);
                $raw  = $_POST['score'] ?? '';
                $mode = $_POST['mode'] ?? 'all';
                if (!in_array($mode, ['all', 'empty', 'selected'], true)) $mode = 'all';
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                $act = $conn->query("SELECT section, max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
                if (!$act) {
                    echo json_encode(['success' => false, 'message' => 'Activity not found.']);
                    break;
                }
                $bSection = $act['section'];
                $bMax     = (int)$act['max_points'];

                /* blank = delete ALL scores in this activity */
                if ($raw === '' || $raw === null) {
                    $stmt = $conn->prepare("DELETE FROM grade_activity_scores WHERE activity_id=?");
                    $stmt->bind_param('i', $aid);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    echo json_encode(['success' => true, 'cleared' => true, 'applied' => $affected]);
                    break;
                }

                $bScore = max(0, min($bMax, intval($raw)));

                /* roster of the activity's section */
                $stmt = $conn->prepare(
                    "SELECT student_no FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . " WHERE section=?"
                );
                $stmt->bind_param('s', $bSection);
                $stmt->execute();
                $rrs  = $stmt->get_result();
                $snos = [];
                while ($rr = $rrs->fetch_assoc()) $snos[] = $rr['student_no'];
                $stmt->close();

                if (!$snos) {
                    echo json_encode(['success' => true, 'applied' => 0, 'score' => $bScore]);
                    break;
                }

                /* those that already have scores (for 'empty' mode) */
                $existing = [];
                if ($mode === 'empty') {
                    $er = $conn->query("SELECT student_no FROM grade_activity_scores WHERE activity_id=$aid");
                    while ($e = $er->fetch_assoc()) $existing[$e['student_no']] = true;
                }

                /* selected students (for 'selected' mode) */
                $selSet = [];
                if ($mode === 'selected') {
                    $dec = json_decode($_POST['students'] ?? '[]', true);
                    if (is_array($dec)) {
                        foreach ($dec as $sn) $selSet[(string)$sn] = true;
                    }
                }

                $ins = $conn->prepare(
                    "INSERT INTO grade_activity_scores (activity_id, student_no, score)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score)"
                );
                $applied = 0;
                foreach ($snos as $s) {
                    if ($mode === 'empty' && isset($existing[$s])) continue;
                    if ($mode === 'selected' && !isset($selSet[$s])) continue;
                    $ins->bind_param('isi', $aid, $s, $bScore);
                    $ins->execute();
                    $applied++;
                }
                $ins->close();

                echo json_encode(['success' => true, 'score' => $bScore, 'applied' => $applied, 'mode' => $mode]);
                break;

            /* ── IMPORT CSV — scores per student_no for one activity (empty-only) ── */
            case 'import_activity_scores':
                $aid = intval($_POST['activity_id'] ?? 0);
                if (!$ownsActivity($aid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                $act = $conn->query("SELECT section, max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
                if (!$act) {
                    echo json_encode(['success' => false, 'message' => 'Activity not found.']);
                    break;
                }
                $iSection = $act['section'];
                $iMax     = (int)$act['max_points'];

                $map = json_decode($_POST['scores'] ?? '{}', true);
                if (!is_array($map) || !$map) {
                    echo json_encode(['success' => false, 'message' => 'No valid rows found in the CSV.']);
                    break;
                }

                /* overwrite existing scores? default = true (from the Import modal checkbox) */
                $overwrite = !isset($_POST['overwrite']) || $_POST['overwrite'] === '1' || $_POST['overwrite'] === 'true';

                /* roster of the section */
                $roster = [];
                $stmt = $conn->prepare(
                    "SELECT student_no FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . " WHERE section=?"
                );
                $stmt->bind_param('s', $iSection);
                $stmt->execute();
                $rrs = $stmt->get_result();
                while ($rr = $rrs->fetch_assoc()) $roster[(string)$rr['student_no']] = true;
                $stmt->close();

                /* those that already have scores (for empty-only mode) */
                $existing = [];
                if (!$overwrite) {
                    $er = $conn->query("SELECT student_no FROM grade_activity_scores WHERE activity_id=$aid");
                    while ($e = $er->fetch_assoc()) $existing[(string)$e['student_no']] = true;
                }

                $ins = $conn->prepare(
                    "INSERT INTO grade_activity_scores (activity_id, student_no, score)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE score = VALUES(score)"
                );
                $applied = 0;
                $skipped = 0;
                $unmatched = 0;
                $invalid = 0;
                foreach ($map as $sno => $sc) {
                    $sno = (string)$sno;
                    if (!isset($roster[$sno])) {
                        $unmatched++;
                        continue;
                    }
                    if (!$overwrite && isset($existing[$sno])) {
                        $skipped++;
                        continue;
                    }   // only empty-only mode skips
                    /* validate rather than silently clamp — out-of-range = the teacher's typo,
                       so it's reported back (invalid) instead of being quietly changed */
                    if (!is_numeric($sc)) { $invalid++; continue; }
                    $val = (int)round((float)$sc);
                    if ($val < 0 || $val > $iMax) { $invalid++; continue; }
                    $ins->bind_param('isi', $aid, $sno, $val);
                    $ins->execute();
                    $applied++;
                }
                $ins->close();

                echo json_encode([
                    'success'   => true,
                    'applied'   => $applied,
                    'skipped'   => $skipped,     // already has a score (blanks-only mode)
                    'unmatched' => $unmatched,   // no match in the section roster
                    'invalid'   => $invalid,     // non-numeric or out of 0..max
                ]);
                break;

            /* ── REORDER — new ordering of activity columns (drag) ── */
            case 'reorder_activities':
                $order = json_decode($_POST['order'] ?? '[]', true);
                if (!is_array($order) || !$order) {
                    echo json_encode(['success' => false, 'message' => 'No order provided.']);
                    break;
                }
                $stmt = $conn->prepare("UPDATE grade_activities SET sort_order=? WHERE id=? AND owner_id=?");
                $pos = 1;
                foreach ($order as $aid) {
                    $aid = intval($aid);
                    $stmt->bind_param('iii', $pos, $aid, $admin_id);
                    $stmt->execute();
                    $pos++;
                }
                $stmt->close();
                echo json_encode(['success' => true]);
                break;

            /* ── SET FORM META (term / category / weight for a form column) ──
               Upserts the eGradeBook overlay for a FormFlow form. Only the keys
               present in the request are changed, so callers can update one
               field at a time (mirrors edit_activity's partial-update style). */
            case 'set_form_meta':
                $section = trim($_POST['section'] ?? '');
                $fid     = intval($_POST['form_id'] ?? 0);
                if ($section === '' || !$fid) {
                    echo json_encode(['success' => false, 'message' => 'Missing form or section.']);
                    break;
                }
                if (!$ownsForm($fid)) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                /* make sure a row exists first (defaults), then patch fields */
                $ins = $conn->prepare(
                    "INSERT IGNORE INTO grade_form_meta (owner_id, section, form_id) VALUES (?, ?, ?)"
                );
                $ins->bind_param('isi', $admin_id, $section, $fid);
                $ins->execute();
                $ins->close();

                if (array_key_exists('term', $_POST)) {
                    $fTerm = in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '';
                    /* changing the term clears the category (its options depend on term) */
                    $u = $conn->prepare("UPDATE grade_form_meta SET term=?, category_id=NULL WHERE owner_id=? AND section=? AND form_id=?");
                    $u->bind_param('siss', $fTerm, $admin_id, $section, $fid);
                    $u->execute();
                    $u->close();
                }
                if (array_key_exists('category_id', $_POST)) {
                    $fCat = ($_POST['category_id'] === '' ? null : intval($_POST['category_id']));
                    $u = $conn->prepare("UPDATE grade_form_meta SET category_id=? WHERE owner_id=? AND section=? AND form_id=?");
                    $u->bind_param('iiss', $fCat, $admin_id, $section, $fid);
                    $u->execute();
                    $u->close();
                }
                if (array_key_exists('weight', $_POST)) {
                    $fWt = max(0, (float)$_POST['weight']);
                    $u = $conn->prepare("UPDATE grade_form_meta SET weight=? WHERE owner_id=? AND section=? AND form_id=?");
                    $u->bind_param('diss', $fWt, $admin_id, $section, $fid);
                    $u->execute();
                    $u->close();
                }
                echo json_encode(['success' => true]);
                break;

            /* ── REORDER COLUMNS (unified: activities + form columns) ──
               `order` is a JSON array of column keys ("a12","f7",…) in the new
               left-to-right order. Activities persist to grade_activities;
               forms to grade_form_meta. One shared position counter keeps the
               two tables on a single ordering scale. */
            case 'reorder_columns':
                $section = trim($_POST['section'] ?? '');
                $order   = json_decode($_POST['order'] ?? '[]', true);
                if ($section === '' || !is_array($order) || !$order) {
                    echo json_encode(['success' => false, 'message' => 'No order provided.']);
                    break;
                }
                $uAct = $conn->prepare("UPDATE grade_activities SET sort_order=? WHERE id=? AND owner_id=?");
                $uFrm = $conn->prepare(
                    "INSERT INTO grade_form_meta (owner_id, section, form_id, sort_order) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
                );
                $pos = 1;
                foreach ($order as $ck) {
                    $ck = (string)$ck;
                    $id = intval(substr($ck, 1));
                    if ($id <= 0) { $pos++; continue; }
                    if ($ck[0] === 'a') {
                        $uAct->bind_param('iii', $pos, $id, $admin_id);
                        $uAct->execute();
                    } elseif ($ck[0] === 'f') {
                        $uFrm->bind_param('isii', $admin_id, $section, $id, $pos);
                        $uFrm->execute();
                    }
                    $pos++;
                }
                $uAct->close();
                $uFrm->close();
                echo json_encode(['success' => true]);
                break;

            /* ── SET use_defense toggle for the section ── */
            case 'set_use_defense':
                $section = trim($_POST['section'] ?? '');
                $useDef  = (($_POST['value'] ?? '1') === '1' || ($_POST['value'] ?? '') === 'true') ? 1 : 0;
                if ($section === '') {
                    echo json_encode(['success' => false, 'message' => 'No section.']);
                    break;
                }
                $stmt = $conn->prepare(
                    "INSERT INTO grade_settings (owner_id, section, use_defense) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE use_defense = VALUES(use_defense)"
                );
                $stmt->bind_param('isi', $admin_id, $section, $useDef);
                $stmt->execute();
                $stmt->close();
                echo json_encode(['success' => true, 'use_defense' => (bool)$useDef]);
                break;

            /* ── TERM MODE toggle (Option B — Midterm/Final grading) ── */
            case 'set_term_mode':
                $section = trim($_POST['section'] ?? '');
                $tm = (($_POST['value'] ?? '0') === '1' || ($_POST['value'] ?? '') === 'true') ? 1 : 0;
                if ($section === '') {
                    echo json_encode(['success' => false, 'message' => 'No section.']);
                    break;
                }
                $stmt = $conn->prepare(
                    "INSERT INTO grade_settings (owner_id, section, term_mode) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE term_mode = VALUES(term_mode)"
                );
                $stmt->bind_param('isi', $admin_id, $section, $tm);
                $stmt->execute();
                $stmt->close();

                /* first enable → seed the default categories (from Excel) */
                if ($tm === 1) {
                    $chk = $conn->query("SELECT COUNT(*) c FROM grade_categories WHERE owner_id=$admin_id AND section='" . $conn->real_escape_string($section) . "'");
                    if ($chk && ($cc = $chk->fetch_assoc()) && (int)$cc['c'] === 0) {
                        $defaults = [['Quiz', 20], ['Activity', 30], ['Attendance', 10], ['Exam', 40]];
                        $ins = $conn->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                        foreach (['midterm', 'final'] as $tname) {
                            $so = 0;
                            foreach ($defaults as $d) {
                                $ins->bind_param('isssdi', $admin_id, $section, $tname, $d[0], $d[1], $so);
                                $ins->execute();
                                $so++;
                            }
                        }
                        $ins->close();
                    }
                }
                echo json_encode(['success' => true, 'term_mode' => (bool)$tm]);
                break;

            /* ── GET TRANSMUTATION BANDS (global, this teacher) ── */
            case 'get_transmute':
                echo json_encode(['success' => true, 'grade_equiv' => $loadEquiv()]);
                break;

            /* ── SAVE TRANSMUTATION BANDS (replace the whole set) ── */
            case 'save_transmute':
                $raw  = $_POST['bands'] ?? '[]';
                $list = json_decode($raw, true);
                if (!is_array($list)) $list = [];

                /* clean + validate: min 0–100, point 1.00–5.00, dedup by min */
                $clean = [];
                foreach ($list as $b) {
                    if (!is_array($b)) continue;
                    $mn = isset($b['min'])   ? (float)$b['min']   : null;
                    $pt = isset($b['point']) ? (float)$b['point'] : null;
                    if ($mn === null || $pt === null) continue;
                    if ($mn < 0 || $mn > 100) continue;
                    if ($pt < 1 || $pt > 5)   continue;
                    $mn = round($mn, 2); $pt = round($pt, 2);
                    $clean[(string)$mn] = ['min' => $mn, 'point' => $pt];   // last write per min wins
                }
                $clean = array_values($clean);
                usort($clean, fn($a, $b) => $b['min'] <=> $a['min']);       // highest min first

                if (!$clean) {
                    echo json_encode(['success' => false, 'message' => 'Add at least one valid band (min 0–100, point 1.00–5.00).']);
                    break;
                }

                $conn->begin_transaction();
                try {
                    $del = $conn->prepare("DELETE FROM grade_transmute WHERE owner_id = ?");
                    $del->bind_param('i', $admin_id);
                    $del->execute();
                    $ins = $conn->prepare("INSERT INTO grade_transmute (owner_id, min_score, point) VALUES (?, ?, ?)");
                    foreach ($clean as $b) {
                        $mn = $b['min']; $pt = $b['point'];
                        $ins->bind_param('idd', $admin_id, $mn, $pt);
                        $ins->execute();
                    }
                    $ins->close();
                    $conn->commit();
                    echo json_encode(['success' => true, 'grade_equiv' => $clean]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Could not save: ' . $e->getMessage()]);
                }
                break;

            /* ── SET PER-STUDENT FINAL STATUS (INC / DRP / W, or '' to clear) ── */
            case 'set_student_status':
                $section = trim($_POST['section'] ?? '');
                $sno     = trim($_POST['student_no'] ?? '');
                $status  = strtoupper(trim($_POST['status'] ?? ''));
                if ($section === '' || $sno === '') {
                    echo json_encode(['success' => false, 'message' => 'Missing section or student.']);
                    break;
                }
                $allowed = ['INC', 'DRP', 'W'];
                if ($status !== '' && !in_array($status, $allowed, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                    break;
                }
                if ($status === '') {
                    $st = $conn->prepare("DELETE FROM grade_student_status WHERE owner_id = ? AND section = ? AND student_no = ?");
                    $st->bind_param('iss', $admin_id, $section, $sno);
                } else {
                    $st = $conn->prepare("INSERT INTO grade_student_status (owner_id, section, student_no, status)
                                          VALUES (?, ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE status = VALUES(status)");
                    $st->bind_param('isss', $admin_id, $section, $sno, $status);
                }
                $st->execute();
                $st->close();
                echo json_encode(['success' => true, 'status' => $status]);
                break;

            /* ── BULK SET STATUS for many students (INC / DRP / W, or '' to clear) ── */
            case 'set_students_status':
                $section = trim($_POST['section'] ?? '');
                $status  = strtoupper(trim($_POST['status'] ?? ''));
                $snos    = json_decode($_POST['students'] ?? '[]', true);
                if (!is_array($snos)) $snos = [];
                $allowed = ['INC', 'DRP', 'W'];
                if ($section === '' || !$snos) {
                    echo json_encode(['success' => false, 'message' => 'Missing section or students.']);
                    break;
                }
                if ($status !== '' && !in_array($status, $allowed, true)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid status.']);
                    break;
                }
                $conn->begin_transaction();
                try {
                    if ($status === '') {
                        $del = $conn->prepare("DELETE FROM grade_student_status WHERE owner_id = ? AND section = ? AND student_no = ?");
                        foreach ($snos as $sno) {
                            $sno = trim((string)$sno);
                            if ($sno === '') continue;
                            $del->bind_param('iss', $admin_id, $section, $sno);
                            $del->execute();
                        }
                        $del->close();
                    } else {
                        $ins = $conn->prepare("INSERT INTO grade_student_status (owner_id, section, student_no, status)
                                               VALUES (?, ?, ?, ?)
                                               ON DUPLICATE KEY UPDATE status = VALUES(status)");
                        foreach ($snos as $sno) {
                            $sno = trim((string)$sno);
                            if ($sno === '') continue;
                            $ins->bind_param('isss', $admin_id, $section, $sno, $status);
                            $ins->execute();
                        }
                        $ins->close();
                    }
                    $conn->commit();
                    echo json_encode(['success' => true, 'status' => $status, 'count' => count($snos)]);
                } catch (Exception $e) {
                    $conn->rollback();
                    echo json_encode(['success' => false, 'message' => 'Could not save: ' . $e->getMessage()]);
                }
                break;

            /* ── SAVE CATEGORY (add or update) ── */
            case 'save_category':
                $cid     = intval($_POST['id'] ?? 0);
                $section = trim($_POST['section'] ?? '');
                $term    = in_array($_POST['term'] ?? '', ['midterm', 'final'], true) ? $_POST['term'] : '';
                $name    = trim($_POST['name'] ?? '');
                $weight  = max(0, (float)($_POST['weight'] ?? 0));
                if ($cid > 0) {
                    $stmt = $conn->prepare("UPDATE grade_categories SET name=?, weight=? WHERE id=? AND owner_id=?");
                    $stmt->bind_param('sdii', $name, $weight, $cid, $admin_id);
                    $stmt->execute();
                    $stmt->close();
                    echo json_encode(['success' => true, 'id' => $cid]);
                } else {
                    if ($section === '' || $term === '' || $name === '') {
                        echo json_encode(['success' => false, 'message' => 'Missing category info.']);
                        break;
                    }
                    $stmt = $conn->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param('isssd', $admin_id, $section, $term, $name, $weight);
                    $stmt->execute();
                    $newId = $stmt->insert_id;
                    $stmt->close();
                    echo json_encode(['success' => true, 'id' => $newId]);
                }
                break;

            /* ── DELETE CATEGORY (unassign the activities first) ── */
            case 'delete_category':
                $cid = intval($_POST['id'] ?? 0);
                if ($cid <= 0) {
                    echo json_encode(['success' => false, 'message' => 'No id.']);
                    break;
                }
                $chk = $conn->query("SELECT owner_id FROM grade_categories WHERE id=$cid");
                if (!$chk || !($cr = $chk->fetch_assoc()) || (int)$cr['owner_id'] !== $admin_id) {
                    echo json_encode(['success' => false, 'message' => 'Not allowed.']);
                    break;
                }
                $conn->query("UPDATE grade_activities SET category_id=NULL WHERE category_id=$cid AND owner_id=$admin_id");
                $conn->query("UPDATE grade_form_meta SET category_id=NULL WHERE category_id=$cid AND owner_id=$admin_id");
                $conn->query("DELETE FROM grade_categories WHERE id=$cid AND owner_id=$admin_id");
                echo json_encode(['success' => true]);
                break;


            /* ── COPY ACTIVITIES from another section (structure only, no scores) ── */
            case 'copy_activities':
                $toSection   = trim($_POST['to_section'] ?? '');
                $fromSection = trim($_POST['from_section'] ?? '');
                $inclSet     = (($_POST['include_settings'] ?? '1') === '1' || ($_POST['include_settings'] ?? '') === 'true');
                if ($toSection === '' || $fromSection === '') {
                    echo json_encode(['success' => false, 'message' => 'Both sections are required.']);
                    break;
                }
                if ($toSection === $fromSection) {
                    echo json_encode(['success' => false, 'message' => 'Source and target sections are the same.']);
                    break;
                }

                /* source activities owned by this teacher */
                $src = $conn->prepare(
                    "SELECT title, max_points, weight, term, category_id, sort_order
                     FROM grade_activities WHERE owner_id=? AND section=? ORDER BY sort_order, id"
                );
                $src->bind_param('is', $admin_id, $fromSection);
                $src->execute();
                $srcRes = $src->get_result();
                $srcActs = [];
                while ($row = $srcRes->fetch_assoc()) $srcActs[] = $row;
                $src->close();
                if (!$srcActs && !$inclSet) {
                    echo json_encode(['success' => true, 'copied' => 0, 'skipped' => 0, 'cats' => 0, 'message' => 'That section has no activities to copy.']);
                    break;
                }
                /* NOTE: if $srcActs is empty but $inclSet is on, we still continue so the
                   grade setup (categories, weights, term mode) gets copied. */

                /* existing target titles (lowercased) → skip duplicates */
                $existing = [];
                $ex = $conn->prepare("SELECT title FROM grade_activities WHERE owner_id=? AND section=?");
                $ex->bind_param('is', $admin_id, $toSection);
                $ex->execute();
                $exRes = $ex->get_result();
                while ($er = $exRes->fetch_assoc()) $existing[strtolower(trim($er['title']))] = true;
                $ex->close();

                /* base sort_order in target (append after existing columns) */
                $mq = $conn->prepare("SELECT COALESCE(MAX(sort_order),-1) m FROM grade_activities WHERE owner_id=? AND section=?");
                $mq->bind_param('is', $admin_id, $toSection);
                $mq->execute();
                $mr = $mq->get_result()->fetch_assoc();
                $mq->close();
                $so = ((int)$mr['m']) + 1;

                /* optional: copy categories (merge by term+name) and mirror settings */
                $catMap = [];
                $catsCopied = 0;
                if ($inclSet) {
                    $tgtCats = [];
                    $tc = $conn->prepare("SELECT id, term, name FROM grade_categories WHERE owner_id=? AND section=?");
                    $tc->bind_param('is', $admin_id, $toSection);
                    $tc->execute();
                    $tcRes = $tc->get_result();
                    while ($t = $tcRes->fetch_assoc()) $tgtCats[$t['term'] . '|' . strtolower(trim($t['name']))] = (int)$t['id'];
                    $tc->close();

                    $sc = $conn->prepare("SELECT id, term, name, weight, sort_order FROM grade_categories WHERE owner_id=? AND section=? ORDER BY sort_order, id");
                    $sc->bind_param('is', $admin_id, $fromSection);
                    $sc->execute();
                    $scRes = $sc->get_result();
                    $insCat = $conn->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
                    while ($cat = $scRes->fetch_assoc()) {
                        $ckey = $cat['term'] . '|' . strtolower(trim($cat['name']));
                        if (isset($tgtCats[$ckey])) {
                            $catMap[(int)$cat['id']] = $tgtCats[$ckey];
                        } else {
                            $insCat->bind_param('isssdi', $admin_id, $toSection, $cat['term'], $cat['name'], $cat['weight'], $cat['sort_order']);
                            $insCat->execute();
                            $newCat = $insCat->insert_id;
                            $catMap[(int)$cat['id']] = $newCat;
                            $tgtCats[$ckey] = $newCat;
                            $catsCopied++;
                        }
                    }
                    $insCat->close();
                    $sc->close();
                }

                /* insert copied activity columns */
                $ins = $conn->prepare(
                    "INSERT INTO grade_activities (owner_id, section, title, max_points, weight, term, category_id, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $copied = 0; $skipped = 0;
                foreach ($srcActs as $a) {
                    $key = strtolower(trim($a['title']));
                    if (isset($existing[$key])) { $skipped++; continue; }
                    $term  = $inclSet ? ($a['term'] ?? '') : '';
                    $catId = null;
                    if ($inclSet && $a['category_id'] !== null && isset($catMap[(int)$a['category_id']])) {
                        $catId = $catMap[(int)$a['category_id']];
                    }
                    $mx = max(1, (int)$a['max_points']);
                    $wt = (float)$a['weight'];
                    $ins->bind_param('issidsii', $admin_id, $toSection, $a['title'], $mx, $wt, $term, $catId, $so);
                    $ins->execute();
                    $existing[$key] = true;
                    $copied++; $so++;
                }
                $ins->close();

                /* mirror grading settings, non-destructively (never disables an existing term mode) */
                if ($inclSet) {
                    $srcTm = 0; $srcUd = 1; $tgtTm = 0; $tgtUd = null;
                    $gs = $conn->query("SELECT use_defense, term_mode FROM grade_settings WHERE owner_id=$admin_id AND section='" . $conn->real_escape_string($fromSection) . "' LIMIT 1");
                    if ($gs && ($g = $gs->fetch_assoc())) { $srcTm = (int)$g['term_mode']; $srcUd = (int)$g['use_defense']; }
                    $gt = $conn->query("SELECT use_defense, term_mode FROM grade_settings WHERE owner_id=$admin_id AND section='" . $conn->real_escape_string($toSection) . "' LIMIT 1");
                    if ($gt && ($g2 = $gt->fetch_assoc())) { $tgtTm = (int)$g2['term_mode']; $tgtUd = (int)$g2['use_defense']; }
                    $newTm = ($srcTm === 1 || $tgtTm === 1) ? 1 : 0;
                    $newUd = ($tgtUd === null) ? $srcUd : $tgtUd;
                    $up = $conn->prepare(
                        "INSERT INTO grade_settings (owner_id, section, use_defense, term_mode) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE use_defense=VALUES(use_defense), term_mode=VALUES(term_mode)"
                    );
                    $up->bind_param('isii', $admin_id, $toSection, $newUd, $newTm);
                    $up->execute();
                    $up->close();
                }

                $parts = [];
                $parts[] = "Copied $copied activit" . ($copied === 1 ? 'y' : 'ies');
                if ($skipped > 0) $parts[] = "skipped $skipped duplicate" . ($skipped === 1 ? '' : 's');
                if ($inclSet && $catsCopied > 0) $parts[] = "$catsCopied categor" . ($catsCopied === 1 ? 'y' : 'ies');
                $msg = implode(', ', $parts) . '.';
                /* nothing new landed but the grade setup was still applied */
                if ($inclSet && $copied === 0 && $catsCopied === 0) {
                    $msg = 'Grade setup applied'
                         . ($skipped > 0 ? " ($skipped activit" . ($skipped === 1 ? 'y' : 'ies') . ' already existed)' : '')
                         . '.';
                }
                echo json_encode([
                    'success'  => true,
                    'copied'   => $copied,
                    'skipped'  => $skipped,
                    'cats'     => $catsCopied,
                    'settings' => $inclSet ? 1 : 0,
                    'message'  => $msg
                ]);
                break;

            default:
                echo json_encode(['success' => false, 'message' => 'Unknown api: ' . $api]);
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    $conn->close();
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        if (localStorage.getItem("ff_theme") === "light") document.documentElement.classList.add("preload-light");
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eGradeBook</title>
    <?php include __DIR__ . "/components/favico.php" ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/global.css?v=<?= filemtime('assets/css/global.css') ?>">
    <link rel="stylesheet" href="assets/css/grades.css?v=<?= filemtime('assets/css/grades.css') ?>">
</head>

<body class="bg-glow">

    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <img src="assets/images/logo.png" width="35px" height="35px" alt="eGradeBook">
            eGradeBook
        </a>
        <div class="navbar-right">
            <?php if (FORMFLOW_APP_URL !== ''): ?>
                <a href="<?= htmlspecialchars(FORMFLOW_APP_URL) ?>" class="btn btn-ghost btn-sm"><i class="bi bi-box-arrow-up-left"></i> <span class="btn-label">FormFlow</span></a>
            <?php endif; ?>
            <button class="theme-toggle" title="Toggle theme"><i class="bi bi-sun-fill"></i></button>
            <div class="profile" id="profileDropdown">
                <button class="profile-trigger" id="profileTrigger" aria-haspopup="true" aria-expanded="false">
                    <i class="bi bi-person-circle profile-avatar"></i>
                    <span class="profile-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></span>
                    <i class="bi bi-chevron-down profile-chev"></i>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-head">
                        <i class="bi bi-person-circle"></i>
                        <div class="profile-menu-meta">
                            <div class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                            <div class="profile-menu-sub">Signed in</div>
                        </div>
                    </div>
                    <div class="profile-menu-divider"></div>
                    <a href="inc/logout.php" onclick="confirmLogout(this.href);return false;" class="profile-menu-item danger" role="menuitem"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
        <button class="nav-hamburger" id="navHamburger" aria-label="Menu"><span></span><span></span><span></span></button>
    </nav>

    <div class="nav-mobile-menu" id="navMobileMenu">
        <div class="menu-user">
            <i class="bi bi-person-circle" style="color:var(--accent);"></i>
            <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?>
        </div>
        <div class="menu-divider"></div>
        <?php if (FORMFLOW_APP_URL !== ''): ?>
            <a href="<?= htmlspecialchars(FORMFLOW_APP_URL) ?>"><i class="bi bi-box-arrow-up-left"></i> FormFlow</a>
            <div class="menu-divider"></div>
        <?php endif; ?>
        <button class="menu-theme-toggle"><i class="bi bi-sun-fill"></i><span>Light Mode</span></button>
        <div class="menu-divider"></div>
        <a href="inc/logout.php" onclick="confirmLogout(this.href);return false;" class="menu-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
    <div class="nav-backdrop" id="navBackdrop"></div>

    <main>
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
            <div class="page-title">
                <h1 style="margin:0;">Grading Sheet</h1>
                <p style="margin:.3rem 0 0;color:var(--muted);">Students from attendance, scores from your forms</p>
            </div>
            <div class="gs-toolbar" style="display:flex;gap:.5rem;">
                <button class="btn btn-ghost btn-sm" id="btnAddActivity" title="Select a section first"><i class="bi bi-plus-circle"></i> <span class="btn-label">Add Activity</span></button>
                <div class="gs-more" id="gsMore">
                    <button class="btn btn-ghost btn-sm gs-more-trigger" id="btnMore" aria-haspopup="true" aria-expanded="false"><i class="bi bi-three-dots"></i> <span class="btn-label">More</span> <i class="bi bi-chevron-down gs-more-chev"></i></button>
                    <div class="gs-more-menu" id="moreMenu" role="menu">
                        <div class="gs-more-label">Setup</div>
                        <button class="profile-menu-item" id="btnCopyFrom" role="menuitem" title="Copy activity setup from another section"><i class="bi bi-copy"></i> Copy from…</button>
                        <button class="profile-menu-item" id="btnImport" role="menuitem"><i class="bi bi-upload"></i> Import CSV</button>
                        <div class="profile-menu-divider"></div>
                        <div class="gs-more-label">Grading</div>
                        <button class="profile-menu-item" id="btnTransmute" role="menuitem"><i class="bi bi-arrow-left-right"></i> Transmutation</button>
                        <div class="profile-menu-divider"></div>
                        <div class="gs-more-label">Output</div>
                        <button class="profile-menu-item" id="btnBackup" role="menuitem"><i class="bi bi-file-earmark-excel"></i> Backup all (Excel)</button>
                        <button class="profile-menu-item" id="btnPdfSection" role="menuitem" title="PDF of the current section's grades"><i class="bi bi-file-earmark-pdf"></i> Export section (PDF)</button>
                        <button class="profile-menu-item" id="btnPdfAll" role="menuitem" title="One combined PDF of every section's grades"><i class="bi bi-file-earmark-pdf-fill"></i> Export all (PDF)</button>
                        <button class="profile-menu-item" id="btnPrint" role="menuitem"><i class="bi bi-printer"></i> Print</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" id="btnExport"><i class="bi bi-filetype-csv"></i> <span class="btn-label">Export CSV</span></button>
            </div>
        </div>

        <!-- Stats -->
        <div class="gs-stats" id="gsStats" style="display:none;">
            <div class="gs-stat">
                <div class="v" id="stStudents">—</div>
                <div class="l">Students</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stAssess">—</div>
                <div class="l">Assessments</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stAvg">—</div>
                <div class="l">Class Average</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stPassRate">—</div>
                <div class="l">Pass Rate</div>
            </div>
        </div>

        <!-- Column picker -->
        <div class="gs-columns" id="gsColumns" style="display:none;">
            <h4><i class="bi bi-ui-checks"></i> Select assessments to include (columns)</h4>
            <div class="gs-coltags" id="colTags"></div>
        </div>

        <!-- Controls -->
        <div class="gs-controls">
            <div class="gs-field">
                <label for="selSection" style="display:flex;align-items:center;gap:.5rem;justify-content:space-between;">
                    <span>Section / Class</span>
                    <span class="pin-controls">
                        <button type="button" id="pinViewToggle" class="pin-chip" title="Switch between your pinned sections and all sections">My sections</button>
                        <button type="button" id="btnManageSections" class="pin-gear" title="Choose which sections to show"><i class="bi bi-gear"></i></button>
                    </span>
                </label>
                <select id="selSection">
                    <option value="">Loading sections…</option>
                </select>
            </div>
            <div class="gs-field">
                <label for="txtSearch">Search student</label>
                <input type="text" id="txtSearch" placeholder="Name or student no.">
            </div>
            <div class="gs-field">
                <label for="numPass">Passing %</label>
                <input type="number" id="numPass" value="75" min="0" max="100">
            </div>
            <div class="gs-spacer"></div>
            <div class="gs-field" style="justify-content:flex-end;">
                <label class="gs-check" title="Count students who did not take it as 0">
                    <input type="checkbox" id="chkMissingZero" checked>
                    Count missing as 0
                </label>
                <label class="gs-check" title="Excel-style: Midterm + Final terms with weighted categories, averaged">
                    <input type="checkbox" id="chkTermMode">
                    Term grading
                </label>
                <button class="btn btn-ghost btn-sm" id="btnGradeSetup" style="display:none;"><i class="bi bi-sliders"></i> Grade setup</button>
            </div>
        </div>

        <!-- Bulk selection bar (appears when students are selected) -->
        <div id="selBar" class="sel-bar" style="display:none;">
            <span class="sel-bar-count"><i class="bi bi-check2-square"></i> <b id="selBarCount">0</b> selected</span>
            <span class="sel-bar-sep"></span>
            <span class="sel-bar-lbl">Set status:</span>
            <button class="sel-st-btn" data-status="INC">INC</button>
            <button class="sel-st-btn" data-status="DRP">DRP</button>
            <button class="sel-st-btn" data-status="W">W</button>
            <button class="sel-st-btn sel-st-clear" data-status="">Clear status</button>
            <button class="sel-bar-x" id="selBarClear" title="Clear selection"><i class="bi bi-x-lg"></i></button>
        </div>

        <!-- Table -->
        <div id="gsArea">
            <div class="gs-empty">
                <i class="bi bi-table"></i>
                Select a section above to view the grading sheet.
            </div>
        </div>
    </main>

    <!-- Delete Activity Modal -->
    <div class="modal-backdrop" id="delActModal">
        <div class="modal">
            <h3><i class="bi bi-trash3" style="color:var(--danger);margin-right:8px;"></i>Delete Activity</h3>
            <p id="delActText">This will permanently delete the activity and all its scores. This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-ghost" id="delActCancel">Cancel</button>
                <button class="btn btn-danger" id="delActConfirm"><i class="bi bi-trash3"></i> Delete</button>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="actModal">
        <div class="modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-plus-lg"></i></div>
                <div>
                    <h3 class="gs-mtitle">Add activity</h3>
                    <p class="gs-msub">A manual column you score by hand, e.g. Recitation or Seatwork.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:1.1rem;margin:1.25rem 0 0;">
                <div class="gs-field">
                    <label for="actTitle">Activity name</label>
                    <input type="text" id="actTitle" placeholder="e.g. Recitation 1" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:.6rem .85rem;font-size:.9rem;outline:none;">
                </div>
                <div class="gs-field">
                    <label for="actMax">Max points</label>
                    <input type="number" id="actMax" value="100" min="1" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:.6rem .85rem;font-size:.9rem;outline:none;width:120px;">
                </div>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="actCancel">Cancel</button>
                <button class="btn btn-primary" id="actSave"><i class="bi bi-check-lg"></i> Add column</button>
            </div>
        </div>
    </div>

    <!-- Bulk Fill Modal -->
    <div class="modal-backdrop" id="bulkFillModal">
        <div class="modal">
            <h3><i class="bi bi-arrow-bar-down" style="color:var(--accent);margin-right:8px;"></i>Fill Column</h3>
            <p style="color:var(--muted);margin-top:-.3rem;">Apply the same score to students in <b id="bulkFillCol">this activity</b>.</p>
            <div style="display:flex;flex-direction:column;gap:.8rem;margin:1rem 0;">
                <div class="gs-field">
                    <label for="bulkFillScore">Score <span id="bulkFillMax" style="color:var(--muted);font-weight:400;"></span></label>
                    <input type="number" id="bulkFillScore" min="0" placeholder="e.g. 80" style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;width:120px;">
                </div>
                <div class="gs-field">
                    <label>Apply to</label>
                    <label class="bulk-opt"><input type="radio" name="bulkScope" value="empty" checked> Only students without a score <span class="bulk-note">(keeps your entered scores)</span></label>
                    <label class="bulk-opt" id="bulkScopeSelWrap" style="display:none;"><input type="radio" name="bulkScope" value="selected"> Only selected students <span class="bulk-note" id="bulkSelCount"></span></label>
                    <label class="bulk-opt"><input type="radio" name="bulkScope" value="all"> All students <span class="bulk-note">(overwrites existing scores)</span></label>
                </div>
                <p id="bulkFillErr" class="bulk-err" style="display:none;"></p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" id="bulkFillCancel">Cancel</button>
                <button class="btn btn-primary" id="bulkFillApply"><i class="bi bi-check-lg"></i> Apply</button>
            </div>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div class="modal-backdrop" id="importModal">
        <div class="modal imp-modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-filetype-csv"></i></div>
                <div>
                    <h3 class="gs-mtitle">Import scores from CSV</h3>
                    <p class="gs-msub">Match students by number, fill one activity.</p>
                </div>
            </div>

            <div class="imp-body">
                <div class="gs-field">
                    <label for="importActivity">Activity</label>
                    <div class="imp-select-wrap">
                        <select id="importActivity" class="imp-select"></select>
                        <i class="bi bi-chevron-down imp-select-chev"></i>
                    </div>
                </div>

                <div class="gs-field">
                    <label for="importFile">CSV file</label>
                    <input type="file" id="importFile" accept=".csv,text/csv" class="imp-file-input">
                    <label class="imp-drop" id="importDrop" for="importFile">
                        <i class="bi bi-cloud-arrow-up imp-drop-ic"></i>
                        <span class="imp-drop-main">Drop a CSV here, or <span class="imp-drop-link">browse</span></span>
                        <span class="imp-drop-hint">Two columns: student number, score</span>
                    </label>
                    <div class="imp-file-sel" id="importFileSel">
                        <i class="bi bi-check-circle-fill imp-file-check"></i>
                        <div class="imp-file-meta">
                            <div class="imp-file-name" id="importFileName">file.csv</div>
                            <p class="bulk-note imp-file-info" id="importInfo"></p>
                        </div>
                        <button type="button" class="imp-file-remove" id="importFileRemove" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <small class="imp-drop-foot">Header row optional. <a href="#" id="importSample">Download sample</a></small>
                    <p id="importErr" class="bulk-err" style="display:none;"></p>
                    <div id="importPreview" class="imp-preview" style="display:none;"></div>
                </div>

                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Overwrite existing scores</span>
                        <span class="imp-switch-sub">Off = fill only blank cells.</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="importOverwrite" checked>
                        <span class="imp-switch-track"></span>
                    </span>
                </label>

                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Cap scores above max</span>
                        <span class="imp-switch-sub">On = clamp over-max scores down to the max instead of flagging them.</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="importCapMax">
                        <span class="imp-switch-track"></span>
                    </span>
                </label>
            </div>

            <div class="modal-actions">
                <button class="btn btn-ghost" id="importCancel">Cancel</button>
                <button class="btn btn-primary" id="importApply" disabled><i class="bi bi-upload"></i> Import scores</button>
            </div>
        </div>
    </div>

    <!-- Copy From Section Modal -->
    <div class="modal-backdrop" id="copyModal">
        <div class="modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-copy"></i></div>
                <div>
                    <h3 class="gs-mtitle">Copy setup from another section</h3>
                    <p class="gs-msub">Reuse activity columns you already built. Scores are never copied.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:1.1rem;margin:1.25rem 0 0;">
                <div class="gs-field">
                    <label for="copyFromSection">Copy from section</label>
                    <div class="imp-select-wrap">
                        <select id="copyFromSection" class="imp-select"><option value="">— Select a section —</option></select>
                        <i class="bi bi-chevron-down imp-select-chev"></i>
                    </div>
                    <p class="bulk-note" id="copyTargetNote" style="margin:.35rem 0 0;"></p>
                </div>
                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Also copy categories &amp; grading settings</span>
                        <span class="imp-switch-sub">Off = copy activity columns only (flat).</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="copyIncludeSettings" checked>
                        <span class="imp-switch-track"></span>
                    </span>
                </label>
                <p class="bulk-note" style="margin:0;"><i class="bi bi-info-circle"></i> Activities with a name already in this section are skipped, so re-copying is safe.</p>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="copyCancel">Cancel</button>
                <button class="btn btn-primary" id="copyApply" disabled><i class="bi bi-copy"></i> Copy setup</button>
            </div>
        </div>
    </div>

    <!-- Manage Sections Modal (pick which sections to show) -->
    <div class="modal-backdrop" id="pinModal">
        <div class="modal gs-maccent" style="max-width:560px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-pin-angle"></i></div>
                <div>
                    <h3 class="gs-mtitle">Choose your sections</h3>
                    <p class="gs-msub">Only the sections you check will appear in the picker.</p>
                </div>
            </div>
            <div style="margin:1.1rem 0 0;">
                <div class="gs-field">
                    <input type="text" id="pinSearch" placeholder="Search section or course…">
                </div>
                <div class="pin-toolbar">
                    <button type="button" class="btn btn-ghost btn-sm" id="pinSelectAll"><i class="bi bi-check2-all"></i> Select all</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="pinClearAll"><i class="bi bi-x-lg"></i> Clear</button>
                    <span class="pin-count" id="pinCount">0 selected</span>
                </div>
                <div class="pin-list" id="pinList"></div>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="pinCancel">Cancel</button>
                <button class="btn btn-primary" id="pinSave"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- Grade Setup Modal (Option B — categories per term) -->
    <div class="modal-backdrop" id="setupModal">
        <div class="modal" style="max-width:560px;">
            <h3><i class="bi bi-sliders" style="color:var(--accent);margin-right:8px;"></i>Grade Setup — Categories &amp; Weights</h3>
            <p style="color:var(--muted);margin-top:-.3rem;">Define categories per term. Each term's weights should total <b>100%</b>. Assign activities to a term &amp; category in their column header.</p>
            <div id="setupBody" style="display:flex;flex-direction:column;gap:1rem;margin:1rem 0;max-height:52vh;overflow-y:auto;overflow-x:hidden;"></div>
            <div class="modal-actions">
                <button class="btn btn-primary" id="setupClose"><i class="bi bi-check-lg"></i> Done</button>
            </div>
        </div>
    </div>

    <!-- Transmutation Modal (global bands: raw score → 1.00–5.00 equivalent) -->
    <div class="modal-backdrop" id="tmModal">
        <div class="modal gs-maccent" style="max-width:520px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-arrow-left-right"></i></div>
                <div>
                    <h3 class="gs-mtitle">Transmutation table</h3>
                    <p class="gs-msub">Raw score cutoffs mapped to the 1.00–5.00 equivalent. Used by every section, in both flat &amp; term grading.</p>
                </div>
            </div>
            <div class="tm-legend">
                <span>Min raw score</span>
                <span>Equivalent</span>
            </div>
            <div id="tmBody" class="tm-body"></div>
            <p class="bulk-note" id="tmHint" style="margin:.5rem 0 0;"><i class="bi bi-info-circle"></i> A student gets a point once their grade reaches its min. Anything below the lowest band = Failed (5.00).</p>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost btn-sm" id="tmAddBand" style="margin-right:auto;"><i class="bi bi-plus"></i> Add band</button>
                <button class="btn btn-ghost" id="tmCancel">Cancel</button>
                <button class="btn btn-primary" id="tmSave"><i class="bi bi-check-lg"></i> Save table</button>
            </div>
        </div>
    </div>

    <!-- Per-student grade breakdown -->
    <div class="modal-backdrop" id="breakdownModal">
        <div class="modal gs-maccent" style="max-width:520px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-calculator"></i></div>
                <div>
                    <h3 class="gs-mtitle" id="bdName">Student</h3>
                    <p class="gs-msub" id="bdSno"></p>
                </div>
            </div>
            <div id="bdBody" class="bd-body"></div>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost" id="bdClose"><i class="bi bi-x-lg"></i> Close</button>
                <button class="btn btn-primary" id="bdPdf"><i class="bi bi-file-earmark-pdf"></i> Save PDF</button>
            </div>
        </div>
    </div>

    <?php include __DIR__ . "/components/footer.php"; ?>

    <script src="assets/js/global.js?v=<?= filemtime('assets/js/global.js') ?>"></script>
    <script src="assets/js/grades.js?v=<?= filemtime('assets/js/grades.js') ?>"></script>
</body>

</html>