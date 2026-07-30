<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ActivityRepo;
use App\Models\ScoreRepo;
use App\Models\RosterRepo;
use App\Models\ClassRepo;

/* Manual activity columns + their scores: CRUD, bulk fill, CSV import,
   linked (same-score) mode, reorder, and copy-from-section. */
class ActivityController extends Controller
{
    /* ── ADD ACTIVITY (manual column) ── */
    public function add(): void
    {
        $scope   = $this->classScope();
        $title   = trim($_POST['title'] ?? '');
        $maxPts  = max(1, intval($_POST['max_points'] ?? 100));
        $term    = in_array($_POST['term'] ?? '', ['midterm', 'final'], true) ? $_POST['term'] : '';
        $catId   = isset($_POST['category_id']) && $_POST['category_id'] !== '' ? intval($_POST['category_id']) : null;
        if (!$scope->hasSection() || $title === '') {
            $this->fail('Section and activity name are required.');
            return;
        }
        $newId = (new ActivityRepo($this->db, $this->ownerId))->add($scope, $title, $maxPts, $term, $catId);
        /* make sure this class shows in the Class dropdown (no-op for legacy) */
        (new ClassRepo($this->db, $this->ownerId))->register($scope->section, $scope->schoolYear, $scope->semester, $scope->subject);
        $this->ok([
            'activity' => [
                'key'         => 'a' . $newId,
                'type'        => 'activity',
                'id'          => $newId,
                'title'       => $title,
                'max'         => $maxPts,
                'weight'      => 0,
                'term'        => $term,
                'category_id' => $catId,
                'responded'   => 0,
            ],
        ]);
    }

    /* ── EDIT ACTIVITY (rename + change max points / weight / term / category) ── */
    public function edit(): void
    {
        $aid    = intval($_POST['activity_id'] ?? 0);
        $title  = trim($_POST['title'] ?? '');
        $maxPts = max(1, intval($_POST['max_points'] ?? 100));
        $weight = max(0, (float)($_POST['weight'] ?? 0));
        $eTerm  = array_key_exists('term', $_POST) ? (in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '') : null;
        $eCat   = array_key_exists('category_id', $_POST) ? ($_POST['category_id'] === '' ? null : intval($_POST['category_id'])) : false;

        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        if ($title === '') {
            $this->fail('Activity name is required.');
            return;
        }
        $repo->updateBasics($aid, $title, $maxPts, $weight);
        /* update the term / category if provided (separate so the old caller isn't broken) */
        if ($eTerm !== null) $repo->updateTerm($aid, $eTerm);
        if ($eCat !== false) $repo->updateCategory($aid, $eCat);

        /* when max is lowered, clamp scores that exceeded it and report which changed */
        $clamped = $repo->clampScoresOverMax($aid, $maxPts);

        $this->ok([
            'activity' => ['id' => $aid, 'title' => $title, 'max' => $maxPts, 'weight' => $weight],
            'clamped'  => $clamped,
        ]);
    }

    /* ── DELETE ACTIVITY ── */
    public function delete(): void
    {
        $aid  = intval($_POST['activity_id'] ?? 0);
        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        $repo->delete($aid); // cascades scores
        $this->ok();
    }

    /* ── SAVE ACTIVITY SCORE (per student, upsert) ── */
    public function saveScore(): void
    {
        $aid = intval($_POST['activity_id'] ?? 0);
        $sno = trim($_POST['student_no'] ?? '');
        $raw = $_POST['score'] ?? '';

        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        if ($sno === '') {
            $this->fail('Missing student.');
            return;
        }
        $maxPts = $repo->maxPoints($aid);
        $scoreRepo = new ScoreRepo($this->db);

        /* clamp to 0..max_points; blank = delete (NULL) */
        if ($raw === '' || $raw === null) {
            $scoreRepo->deleteForStudent($aid, $sno);
            $this->ok(['cleared' => true]);
            return;
        }
        $score = max(0, min($maxPts, intval($raw)));
        $scoreRepo->upsert($aid, $sno, $score);
        $this->ok(['score' => $score]);
    }

    /* ── TOGGLE sync (same-score) mode for an activity ── */
    public function setLinked(): void
    {
        $aid    = intval($_POST['activity_id'] ?? 0);
        $linked = (($_POST['linked'] ?? '0') === '1' || ($_POST['linked'] ?? '') === 'true') ? 1 : 0;
        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        $repo->setLinked($aid, $linked);
        $this->ok(['linked' => $linked]);
    }

    /* ── SYNC same scores — set every cell equal to from_score to to_score ── */
    public function syncScore(): void
    {
        $aid  = intval($_POST['activity_id'] ?? 0);
        $from = $_POST['from_score'] ?? '';
        $to   = $_POST['to_score'] ?? '';
        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        if ($from === '' || $to === '' || $from === null || $to === null) {
            $this->ok(['updated' => 0]);
            return;
        }
        $maxPts = $repo->maxPoints($aid);
        $fromV  = intval($from);
        $toV    = max(0, min($maxPts, intval($to)));
        $updated = (new ScoreRepo($this->db))->syncValue($aid, $fromV, $toV);
        $this->ok(['updated' => $updated, 'from' => $fromV, 'to' => $toV]);
    }

    /* ── BULK FILL — same score to all/empty/selected of an activity ── */
    public function bulkFill(): void
    {
        $aid  = intval($_POST['activity_id'] ?? 0);
        $raw  = $_POST['score'] ?? '';
        $mode = $_POST['mode'] ?? 'all';
        if (!in_array($mode, ['all', 'empty', 'selected'], true)) $mode = 'all';

        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        $act = $repo->sectionAndMax($aid);
        if (!$act) {
            $this->fail('Activity not found.');
            return;
        }
        $bSection = $act['section'];
        $bMax     = (int)$act['max_points'];
        $scoreRepo = new ScoreRepo($this->db);

        /* blank = delete ALL scores in this activity */
        if ($raw === '' || $raw === null) {
            $affected = $scoreRepo->deleteAll($aid);
            $this->ok(['cleared' => true, 'applied' => $affected]);
            return;
        }

        $bScore = max(0, min($bMax, intval($raw)));

        /* roster of the activity's section */
        $snos = (new RosterRepo($this->db))->studentNos($bSection);
        if (!$snos) {
            $this->ok(['applied' => 0, 'score' => $bScore]);
            return;
        }

        /* those that already have scores (for 'empty' mode) */
        $existing = $mode === 'empty' ? $scoreRepo->studentsWithScore($aid) : [];

        /* selected students (for 'selected' mode) */
        $selSet = [];
        if ($mode === 'selected') {
            $dec = json_decode($_POST['students'] ?? '[]', true);
            if (is_array($dec)) {
                foreach ($dec as $sn) $selSet[(string)$sn] = true;
            }
        }

        /* build the target map (student_no => score) with the same filtering */
        $target = [];
        foreach ($snos as $s) {
            if ($mode === 'empty' && isset($existing[$s])) continue;
            if ($mode === 'selected' && !isset($selSet[$s])) continue;
            $target[(string)$s] = $bScore;
        }
        $applied = $scoreRepo->applyScores($aid, $target);

        $this->ok(['score' => $bScore, 'applied' => $applied, 'mode' => $mode]);
    }

    /* ── IMPORT CSV — scores per student_no for one activity ── */
    public function importScores(): void
    {
        $aid  = intval($_POST['activity_id'] ?? 0);
        $repo = new ActivityRepo($this->db, $this->ownerId);
        if (!$repo->owns($aid)) {
            $this->fail('Not allowed.');
            return;
        }
        $act = $repo->sectionAndMax($aid);
        if (!$act) {
            $this->fail('Activity not found.');
            return;
        }
        $iSection = $act['section'];
        $iMax     = (int)$act['max_points'];

        $map = json_decode($_POST['scores'] ?? '{}', true);
        if (!is_array($map) || !$map) {
            $this->fail('No valid rows found in the CSV.');
            return;
        }

        /* overwrite existing scores? default = true (from the Import modal checkbox) */
        $overwrite = !isset($_POST['overwrite']) || $_POST['overwrite'] === '1' || $_POST['overwrite'] === 'true';

        /* roster of the section */
        $roster = [];
        foreach ((new RosterRepo($this->db))->studentNos($iSection) as $rn) $roster[(string)$rn] = true;

        /* those that already have scores (for empty-only mode) */
        $scoreRepo = new ScoreRepo($this->db);
        $existing  = $overwrite ? [] : $scoreRepo->studentsWithScore($aid);

        $toApply   = [];
        $applied   = 0;
        $skipped   = 0;
        $unmatched = 0;
        $invalid   = 0;
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
            if (!is_numeric($sc)) {
                $invalid++;
                continue;
            }
            $val = (int)round((float)$sc);
            if ($val < 0 || $val > $iMax) {
                $invalid++;
                continue;
            }
            $toApply[$sno] = $val;
            $applied++;
        }
        $scoreRepo->applyScores($aid, $toApply);

        $this->ok([
            'applied'   => $applied,
            'skipped'   => $skipped,     // already has a score (blanks-only mode)
            'unmatched' => $unmatched,   // no match in the section roster
            'invalid'   => $invalid,     // non-numeric or out of 0..max
        ]);
    }

    /* ── REORDER — new ordering of activity columns (legacy, activities only) ── */
    public function reorder(): void
    {
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!is_array($order) || !$order) {
            $this->fail('No order provided.');
            return;
        }
        (new ActivityRepo($this->db, $this->ownerId))->reorder($order);
        $this->ok();
    }

    /* ── COPY ACTIVITIES from another section (structure only, no scores) ──
       Kept as one orchestration here because it spans activities +
       categories + settings with interdependent id maps. */
    public function copy(): void
    {
        $conn     = $this->db->conn;
        $admin_id = $this->ownerId;

        /* copy runs WITHIN the current class period/subject (both sections share
           the request's school_year/semester/subject); only the section differs. */
        $scope = $this->classScope();
        $sy    = $scope->schoolYear;
        $sem   = $scope->semester;
        $subj  = $scope->subject;

        $toSection   = trim($_POST['to_section'] ?? '');
        $fromSection = trim($_POST['from_section'] ?? '');
        $inclSet     = (($_POST['include_settings'] ?? '1') === '1' || ($_POST['include_settings'] ?? '') === 'true');
        if ($toSection === '' || $fromSection === '') {
            $this->fail('Both sections are required.');
            return;
        }
        if ($toSection === $fromSection) {
            $this->fail('Source and target sections are the same.');
            return;
        }

        /* source activities owned by this teacher */
        $src = $conn->prepare(
            "SELECT title, max_points, weight, term, category_id, sort_order
             FROM grade_activities WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? ORDER BY sort_order, id"
        );
        $src->bind_param('issss', $admin_id, $fromSection, $sy, $sem, $subj);
        $src->execute();
        $srcRes = $src->get_result();
        $srcActs = [];
        while ($row = $srcRes->fetch_assoc()) $srcActs[] = $row;
        $src->close();
        if (!$srcActs && !$inclSet) {
            $this->ok(['copied' => 0, 'skipped' => 0, 'cats' => 0, 'message' => 'That section has no activities to copy.']);
            return;
        }
        /* NOTE: if $srcActs is empty but $inclSet is on, we still continue so the
           grade setup (categories, weights, term mode) gets copied. */

        /* existing target titles (lowercased) → skip duplicates */
        $existing = [];
        $ex = $conn->prepare("SELECT title FROM grade_activities WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $ex->bind_param('issss', $admin_id, $toSection, $sy, $sem, $subj);
        $ex->execute();
        $exRes = $ex->get_result();
        while ($er = $exRes->fetch_assoc()) $existing[strtolower(trim($er['title']))] = true;
        $ex->close();

        /* base sort_order in target (append after existing columns) */
        $mq = $conn->prepare("SELECT COALESCE(MAX(sort_order),-1) m FROM grade_activities WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $mq->bind_param('issss', $admin_id, $toSection, $sy, $sem, $subj);
        $mq->execute();
        $mr = $mq->get_result()->fetch_assoc();
        $mq->close();
        $so = ((int)$mr['m']) + 1;

        /* optional: copy categories (merge by term+name) and mirror settings */
        $catMap = [];
        $catsCopied = 0;
        if ($inclSet) {
            $tgtCats = [];
            $tc = $conn->prepare("SELECT id, term, name FROM grade_categories WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
            $tc->bind_param('issss', $admin_id, $toSection, $sy, $sem, $subj);
            $tc->execute();
            $tcRes = $tc->get_result();
            while ($t = $tcRes->fetch_assoc()) $tgtCats[$t['term'] . '|' . strtolower(trim($t['name']))] = (int)$t['id'];
            $tc->close();

            $sc = $conn->prepare("SELECT id, term, name, weight, sort_order FROM grade_categories WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? ORDER BY sort_order, id");
            $sc->bind_param('issss', $admin_id, $fromSection, $sy, $sem, $subj);
            $sc->execute();
            $scRes = $sc->get_result();
            $insCat = $conn->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight, sort_order, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            while ($cat = $scRes->fetch_assoc()) {
                $ckey = $cat['term'] . '|' . strtolower(trim($cat['name']));
                if (isset($tgtCats[$ckey])) {
                    $catMap[(int)$cat['id']] = $tgtCats[$ckey];
                } else {
                    $insCat->bind_param('isssdisss', $admin_id, $toSection, $cat['term'], $cat['name'], $cat['weight'], $cat['sort_order'], $sy, $sem, $subj);
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
            "INSERT INTO grade_activities (owner_id, section, title, max_points, weight, term, category_id, sort_order, school_year, semester, subject)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $copied = 0;
        $skipped = 0;
        foreach ($srcActs as $a) {
            $key = strtolower(trim($a['title']));
            if (isset($existing[$key])) {
                $skipped++;
                continue;
            }
            $term  = $inclSet ? ($a['term'] ?? '') : '';
            $catId = null;
            if ($inclSet && $a['category_id'] !== null && isset($catMap[(int)$a['category_id']])) {
                $catId = $catMap[(int)$a['category_id']];
            }
            $mx = max(1, (int)$a['max_points']);
            $wt = (float)$a['weight'];
            $ins->bind_param('issidsiisss', $admin_id, $toSection, $a['title'], $mx, $wt, $term, $catId, $so, $sy, $sem, $subj);
            $ins->execute();
            $existing[$key] = true;
            $copied++;
            $so++;
        }
        $ins->close();

        /* mirror grading settings, non-destructively (never disables an existing term mode) */
        if ($inclSet) {
            $srcTm = 0;
            $srcUd = 1;
            $tgtTm = 0;
            $tgtUd = null;
            $syE = $conn->real_escape_string($sy);
            $semE = $conn->real_escape_string($sem);
            $subjE = $conn->real_escape_string($subj);
            $scopeSql = " AND school_year='$syE' AND semester='$semE' AND subject='$subjE'";
            $gs = $conn->query("SELECT use_defense, term_mode FROM grade_settings WHERE owner_id=$admin_id AND section='" . $conn->real_escape_string($fromSection) . "'$scopeSql LIMIT 1");
            if ($gs && ($g = $gs->fetch_assoc())) {
                $srcTm = (int)$g['term_mode'];
                $srcUd = (int)$g['use_defense'];
            }
            $gt = $conn->query("SELECT use_defense, term_mode FROM grade_settings WHERE owner_id=$admin_id AND section='" . $conn->real_escape_string($toSection) . "'$scopeSql LIMIT 1");
            if ($gt && ($g2 = $gt->fetch_assoc())) {
                $tgtTm = (int)$g2['term_mode'];
                $tgtUd = (int)$g2['use_defense'];
            }
            $newTm = ($srcTm === 1 || $tgtTm === 1) ? 1 : 0;
            $newUd = ($tgtUd === null) ? $srcUd : $tgtUd;
            $up = $conn->prepare(
                "INSERT INTO grade_settings (owner_id, section, use_defense, term_mode, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE use_defense=VALUES(use_defense), term_mode=VALUES(term_mode)"
            );
            $up->bind_param('isiisss', $admin_id, $toSection, $newUd, $newTm, $sy, $sem, $subj);
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
        $this->ok([
            'copied'   => $copied,
            'skipped'  => $skipped,
            'cats'     => $catsCopied,
            'settings' => $inclSet ? 1 : 0,
            'message'  => $msg,
        ]);
    }
}
