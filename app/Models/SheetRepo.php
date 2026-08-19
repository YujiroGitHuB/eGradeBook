<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* ============================================================
   SheetRepo — the composite READ model behind the `sheet` action.
   Assembles the whole grading matrix:
     • students  → roster (RosterRepo → attendance DB)
     • columns   → FormFlow form columns + manual activities + the
                   optional auto attendance column, unified by sort_order
     • scores    → scores[student_no][key] = {...}
   plus the transmutation bands, per-section settings, categories, and
   status overrides. This is the one read that necessarily spans all
   three bridged databases, so the cross-DB SQL is kept here intact.
   Each column has a unique `key`: forms='f'+id, activities='a'+id,
   attendance='att' — plus 'attf' para sa kalahating Final kapag hati ang
   attendance sa Midterm/Final (tingnan ang `midterm_end`).
   ============================================================ */
class SheetRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    public function build(ClassScope $c): array
    {
        $conn = $this->db->conn;
        $admin_id = $this->ownerId;
        $section = $c->section;
        $sy   = $c->schoolYear;
        $sem  = $c->semester;
        $subj = $c->subject;

        /* 1) ROSTER — students in the class. Legacy class = live roster (as
           before); a non-legacy class reads its snapshot so its students persist
           even if the upstream roster later changes (see RosterRepo). */
        $students = (new RosterRepo($this->db))->rosterForClass($c, $this->ownerId);
        $rosterNo = [];
        foreach ($students as $r) $rosterNo[] = $r['student_no'];

        $columns = [];   // unified list of columns
        $scores  = [];   // scores[student_no][key] = {...}

        /* escape roster student_no for IN(...) */
        $noList = '';
        if ($rosterNo) {
            $esc    = array_map(fn($s) => "'" . $conn->real_escape_string($s) . "'", $rosterNo);
            $noList = implode(',', $esc);
        }

        /* 2) FORM COLUMNS — owned by the logged-in teacher, filtered by section.
           Per-SECTION lang ang filter na ito dahil walang `subject` ang FormFlow
           (wala sa `forms`, wala rin sa `form_responses`) — walang upstream data
           na pang-scope sa isang subject. Kaya kapag maraming klase ang isang
           section, lalabas ang LAHAT ng form nito sa bawat klase. Ang panlunas ay
           ang per-class na `hidden` flag sa grade_form_meta: itinatago ng guro ang
           form na hindi kabilang sa klaseng ito, at hindi na ito nagiging column
           (kaya hindi rin papasok sa coursework). Hindi ito binubura sa FormFlow.

           DALAWANG ANTAS ang pagtatago, at ganito ang pagkakasunod:
             1. grade_form_subject — inaangkin ang form ng ISANG subject; awtomatiko
                itong nakatago sa lahat ng klaseng iba ang subject, PATI sa mga
                klaseng gagawin pa lang (minsanang desisyon).
             2. grade_form_meta.hidden — per-klaseng override; laging nananaig,
                kapwa sa pagtatago at sa pagpapakita.
           Ang legacy class (blangkong subject) ay hindi kailanman naaapektuhan ng
           antas 1 — nakikita nito ang lahat maliban sa tahasang itinago. */
        $hiddenIds = [];
        $hStmt = $conn->prepare(
            "SELECT form_id FROM grade_form_meta
             WHERE owner_id = ? AND section = ? AND school_year = ? AND semester = ? AND subject = ?
               AND hidden = 1"
        );
        $hStmt->bind_param('issss', $admin_id, $section, $sy, $sem, $subj);
        $hStmt->execute();
        $hRes = $hStmt->get_result();
        while ($h = $hRes->fetch_assoc()) $hiddenIds[(int)$h['form_id']] = true;
        $hStmt->close();

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
        /* Antas 1: kanino inaangkin ang bawat form ng section (minsanang desisyon,
           hindi class-scoped kaya tumatalab din sa mga susunod na klase). */
        $claim = (new FormSubjectRepo($this->db, $this->ownerId))->mapForSection($section);

        $formMap = [];   // form_id => index in $columns
        $formIds = [];
        $hiddenForms = [];   // {id,title,subject,reason} — para may maipakita't maibalik ang UI
        while ($f = $fres->fetch_assoc()) {
            $fid   = (int)$f['id'];
            $owned = $claim[$fid] ?? '';
            /* Tahasang itinago sa klaseng ito ang laging nananaig. Kung hindi, ang
               pag-angkin ang magpapasya — pero ang legacy class (blangkong subject)
               ay hindi kailanman apektado nito, kaya buo ang dating gawi. */
            $reason = '';
            if (isset($hiddenIds[$fid]))                                    $reason = 'manual';
            elseif ($owned !== '' && $subj !== '' && $owned !== $subj)      $reason = 'subject';
            /* Nakatago — hindi na ginagawang column (kaya hindi papasok sa grado),
               pero ipinapasa pa rin sa client para may makita't maibalik ang guro. */
            if ($reason !== '') {
                $hiddenForms[] = [
                    'id'      => $fid,
                    'title'   => $f['title'],
                    'subject' => $owned,
                    'reason'  => $reason,
                ];
                continue;
            }
            $key = 'f' . $f['id'];
            $columns[$key] = [
                'key'         => $key,
                'type'        => 'form',
                'id'          => (int)$f['id'],
                'title'       => $f['title'],
                'max'         => 0,
                'responded'   => 0,
                /* subject na nag-aangkin sa form na ito ('' = walang nag-aangkin) */
                'owned_subject' => $owned,
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
            $hasPenalty = $this->db->hasCol('form_responses', 'penalty_score', FORMFLOW_DB);
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

        /* 3) ACTIVITY COLUMNS — manual, per owner + class */
        $stmtA = $conn->prepare(
            "SELECT id, title, max_points, weight, term, category_id, linked, sort_order FROM grade_activities
             WHERE owner_id = ? AND section = ? AND school_year = ? AND semester = ? AND subject = ?
             ORDER BY sort_order ASC, created_at ASC"
        );
        $stmtA->bind_param('issss', $admin_id, $section, $sy, $sem, $subj);
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
                 WHERE owner_id = ? AND section = ? AND school_year = ? AND semester = ? AND subject = ?"
            );
            $fmStmt->bind_param('issss', $admin_id, $section, $sy, $sem, $subj);
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

        /* ATTENDANCE COLUMN (auto) — computed from the QR attendance
           scans. Only built when the teacher enabled it for this section
           (grade_attendance_meta.enabled). Present = the student has a
           scan on a session date; the column's `max` is the number of
           distinct session dates. Read-only score; the overlay (term /
           category / weight / order) makes it behave like a form column.
           Section-scoped like the roster query — see the note below. */
        /* per-class setting: term_mode + use_defense. Binabasa BAGO ang
           attendance block dahil ang term_mode ang nagsasabi kung dapat
           bang hatiin ang attendance sa Midterm at Final. */
        $settings = (new SettingsRepo($this->db, $this->ownerId))->forSheet($c);

        $attEnabled = false;
        $attCutoff  = '';
        $attMeta = (new AttendanceRepo($this->db, $this->ownerId))->metaForSheet($c);
        if ($attMeta && (int)$attMeta['enabled'] === 1) {
            $attEnabled = true;
            /* Sessions are counted per SECTION (same as the roster). When the
               class carries a subject, we also filter the scans by that subject
               (attendance_tbl records `subject`); the legacy class (subject='')
               pools all subjects like before. */
            $attSubjFilter = ($subj !== '') ? " AND subject='" . $conn->real_escape_string($subj) . "'" : '';

            /* HATI SA MIDTERM/FINAL. Walang term/period column ang
               attendance_tbl na masasandalan, kaya ang petsa lang ang batayan:
               `midterm_end` ang huling araw ng Midterm, itinatakda ng guro.
               Hati LAMANG kapag naka-term mode at may petsa — kung wala, iisang
               column na bumibilang ng lahat ng session: ang dating gawi nang
               eksakto, kaya walang nagbabago sa mga umiiral nang sheet. */
            $attCutoff = trim((string)($attMeta['midterm_end'] ?? ''));
            $split  = ((int)$settings['term_mode'] === 1 && $attCutoff !== '');
            $cutEsc = $conn->real_escape_string($attCutoff);

            /* Isang attendance column: bilangin ang mga session (at ang dalo ng
               bawat estudyante) sa loob ng ibinigay na saklaw ng petsa. */
            $buildAtt = function (string $key, string $title, string $dateFilter, string $term,
                                  $catId, float $weight, int $sortOrder, bool $termLocked)
                        use ($conn, $section_esc, $attSubjFilter, $noList, $students, &$columns, &$scores) {
                $totalSessions = 0;
                $sq = $conn->query("SELECT COUNT(DISTINCT `date`) c FROM " . ATTENDANCE_DB . ".attendance_tbl
                    WHERE section='$section_esc'$attSubjFilter$dateFilter");
                if ($sq && ($sx = $sq->fetch_assoc())) $totalSessions = (int)$sx['c'];

                /* present (distinct dates) per rostered student */
                $present = [];
                if ($noList) {
                    $pq = $conn->query(
                        "SELECT student_no, COUNT(DISTINCT `date`) c FROM " . ATTENDANCE_DB . ".attendance_tbl
                         WHERE section='$section_esc'$attSubjFilter$dateFilter AND student_no IN ($noList) GROUP BY student_no"
                    );
                    if ($pq) while ($pr = $pq->fetch_assoc()) $present[(string)$pr['student_no']] = (int)$pr['c'];
                }

                $columns[$key] = [
                    'key'         => $key,
                    'type'        => 'attendance',
                    'id'          => 0,
                    'title'       => $title,
                    'max'         => $totalSessions,
                    'weight'      => $weight,
                    'term'        => $term,
                    'category_id' => $catId !== null ? (int)$catId : null,
                    'sort_order'  => $sortOrder,
                    'responded'   => 0,
                    /* Kapag hati, ang PETSA ang nagtatakda ng term — hindi
                       dropdown; ipinapakita na lang itong teksto ng grades.js. */
                    'term_locked' => $termLocked,
                ];
                /* every rostered student gets a value (absent = 0), so
                   attendance counts as 0 — not "ungraded" — the whole point
                   of an attendance grade. Skipped when there are no sessions
                   yet so an empty attendance column can't zero everyone out. */
                if ($totalSessions > 0) {
                    foreach ($students as $stu) {
                        $sno = (string)$stu['student_no'];
                        $p   = $present[$sno] ?? 0;
                        $scores[$sno][$key] = [
                            'score'   => $p,
                            'raw'     => $p,
                            'penalty' => 0,
                            'max'     => $totalSessions,
                            'at'      => null,
                        ];
                        if ($p > 0) $columns[$key]['responded']++;
                    }
                }
            };

            if ($split) {
                /* `att` = Midterm (hanggang cutoff), `attf` = Final (pagkatapos
                   nito). Fixed ang term ng dalawa, kaya hindi ginagamit ang
                   lumang `term` column habang hati. */
                $buildAtt('att',  'Attendance (Midterm)', " AND `date` <= '$cutEsc'", 'midterm',
                          $attMeta['category_id'], (float)$attMeta['weight'],
                          (int)$attMeta['sort_order'], true);
                $buildAtt('attf', 'Attendance (Final)',   " AND `date` > '$cutEsc'",  'final',
                          $attMeta['final_category_id'], (float)$attMeta['final_weight'],
                          (int)$attMeta['final_sort_order'], true);
            } else {
                $buildAtt('att', 'Attendance', '', (string)$attMeta['term'],
                          $attMeta['category_id'], (float)$attMeta['weight'],
                          (int)$attMeta['sort_order'], false);
            }
        }

        /* Unified column order — sort forms + activities together by
           sort_order. Stable tiebreak on the natural build order (forms
           first, then activities) so untouched sheets look unchanged. */
        $ci = 0;
        foreach ($columns as $k => &$col) {
            $col['_ord'] = $ci++;
        }
        unset($col);
        uasort($columns, function ($a, $b) {
            $sa = $a['sort_order'] ?? 0;
            $sb = $b['sort_order'] ?? 0;
            if ($sa !== $sb) return $sa <=> $sb;
            return ($a['_ord'] ?? 0) <=> ($b['_ord'] ?? 0);
        });
        foreach ($columns as &$col2) {
            unset($col2['_ord']);
        }
        unset($col2);

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
        $sheetEquiv = (new TransmuteRepo($this->db, $this->ownerId))->load();

        /* categories (for term-based grading) */
        $categories = (new CategoryRepo($this->db, $this->ownerId))->forSheet($c);

        /* per-student final status overrides (INC / DRP / W) for this class */
        $statuses = (new StatusRepo($this->db, $this->ownerId))->forSheet($c);

        return [
            'success'     => true,
            'section'     => $section,
            'students'    => $students,
            'columns'     => array_values($columns),
            'scores'      => $scores,
            'grade_equiv' => $sheetEquiv,   // for client-side Final grade transmutation
            'use_defense' => $settings['use_defense'],
            'term_mode'   => $settings['term_mode'],
            'categories'  => $categories,
            'statuses'    => $statuses,
            'attendance_enabled' => $attEnabled,
            /* Huling araw ng Midterm; blangko = hindi hati ang attendance. */
            'attendance_cutoff'  => $attCutoff,
            /* Mga form ng section na itinago sa KLASENG ito (hindi columns).
               Ipinapasa para maipakita ng UI ang "Hidden forms" at maibalik. */
            'hidden_forms' => $hiddenForms,
        ];
    }
}
