<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   FormRepo — BRIDGE into FormFlow's forms. The auto-graded "form"
   columns come from FormFlow and stay read-only there.

   Scores are read through FormFlow's CONTRACT VIEWS, not its tables:
     gradebook_forms   form_id, owner_id, title, created_at, total_points
     gradebook_scores  form_id, section, student_no, score (after the
                       penalty), raw_score, penalty, max_score, submitted_at
   Both are defined in FormFlow's Schema::createGradebookViews(), beside the
   rest of FormFlow's scoring code. So the arithmetic (the penalty, which
   questions count toward the total) has ONE home, in FormFlow, and this repo
   reads the answer instead of working it out again in a second repository.

   `max_score` ≠ `total_points`, sadya: ang max_score ay ang sariling total ng
   estudyante (ang sinagot lang niya — desisyon ng FormFlow), habang ang grado
   ay laban sa points ng BUONG papel. Kaya `total` ang column max dito; kung
   hindi, ang na-auto-submit sa kalagitnaan ay mas mataas pa sa nakatapos.

   Kapag wala ang mga view (luma pa ang FormFlow sa server, tinanggihan ng host
   ang CREATE VIEW, o na-restore ang FormFlow DB mula sa backup nito), bumabalik
   ito sa pagbasa ng mga table na may PAREHONG kuwenta — walang nagbabagong
   grado — at nila-log ito. Ang fallback ay kopya ng view: kapag binago ang
   kuwenta sa FormFlow, sabayan dito hangga't may fallback pa.
   ============================================================ */
class FormRepo
{
    private Database $db;
    private int $ownerId;
    /* false once the views have failed in this request — one log line, not two */
    private bool $viaViews = true;

    /* Every query here reads FormFlow, so this holds the FormFlow connection
       (the app's own one when FORMFLOW_DB_USER is blank; Database::formflow()). */
    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db->formflow();
        $this->ownerId = $ownerId;
    }

    /* The form lives in FormFlow; we only guard who may attach
       grade_form_meta (the eGradeBook overlay) to it. */
    public function owns(int $formId): bool
    {
        $formId = intval($formId);
        $admin_id = $this->ownerId;
        $r = $this->db->query("SELECT id FROM " . FORMFLOW_DB . ".forms WHERE id=$formId AND owner_id=$admin_id LIMIT 1");
        return $r && $r->num_rows > 0;
    }

    /* This teacher's forms that have at least one response from $section,
       oldest first, with what each paper is out of.
       → [['id' => int, 'title' => string, 'total' => int], ...] */
    public function formsForSection(string $section): array
    {
        if ($this->viaViews) {
            try {
                return $this->formRows(
                    "SELECT form_id, title, total_points
                     FROM " . FORMFLOW_DB . ".gradebook_forms
                     WHERE owner_id = ?
                       AND form_id IN (SELECT form_id FROM " . FORMFLOW_DB . ".gradebook_scores WHERE section = ?)
                     ORDER BY created_at ASC, form_id ASC",
                    $section
                );
            } catch (\Throwable $e) {
                $this->viewsFailed($e);
            }
        }

        /* FALLBACK — ang kuwenta ng gradebook_forms, mula sa mga table. */
        return $this->formRows(
            "SELECT f.id AS form_id, f.title,
                    (SELECT COALESCE(SUM(CASE WHEN q.points > 0 THEN q.points ELSE 0 END), 0)
                     FROM " . FORMFLOW_DB . ".form_questions q WHERE q.form_id = f.id) AS total_points
             FROM " . FORMFLOW_DB . ".forms f
             WHERE f.owner_id = ?
               AND f.id IN (SELECT form_id FROM " . FORMFLOW_DB . ".form_responses WHERE section = ?)
             ORDER BY f.created_at ASC, f.id ASC",
            $section
        );
    }

    /* One row per response for these forms × these students, oldest first
       (so when an old database still holds duplicates, the latest wins).
       → [['form_id' => int, 'student_no' => string, 'score' => int, 'raw' => int,
           'penalty' => int, 'max' => int, 'at' => string], ...] */
    public function scores(array $formIds, array $studentNos): array
    {
        if (!$formIds || !$studentNos) return [];

        $marks  = fn(array $list) => implode(',', array_fill(0, count($list), '?'));
        $where  = "form_id IN (" . $marks($formIds) . ") AND student_no IN (" . $marks($studentNos) . ")";
        $types  = str_repeat('i', count($formIds)) . str_repeat('s', count($studentNos));
        $params = array_merge(array_map('intval', $formIds), array_map('strval', $studentNos));

        if ($this->viaViews) {
            try {
                return $this->scoreRows(
                    "SELECT form_id, student_no, score, raw_score, penalty, max_score, submitted_at
                     FROM " . FORMFLOW_DB . ".gradebook_scores
                     WHERE $where
                     ORDER BY submitted_at ASC",
                    $types,
                    $params
                );
            } catch (\Throwable $e) {
                $this->viewsFailed($e);
            }
        }

        /* FALLBACK — ang kuwenta ng gradebook_scores. Wala pang penalty_score
           ang napakalumang FormFlow, kaya may hasCol pa rin dito. */
        $pen = $this->db->hasCol('form_responses', 'penalty_score', FORMFLOW_DB)
            ? 'GREATEST(COALESCE(penalty_score, 0), 0)'
            : '0';
        return $this->scoreRows(
            "SELECT form_id, student_no,
                    GREATEST(COALESCE(score, 0) - $pen, 0) AS score,
                    COALESCE(score, 0) AS raw_score,
                    $pen AS penalty,
                    COALESCE(max_score, 0) AS max_score,
                    submitted_at
             FROM " . FORMFLOW_DB . ".form_responses
             WHERE $where
             ORDER BY submitted_at ASC",
            $types,
            $params
        );
    }

    private function formRows(string $sql, string $section): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('is', $this->ownerId, $section);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'id'    => (int)$r['form_id'],
                'title' => $r['title'],
                'total' => (int)$r['total_points'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    private function scoreRows(string $sql, string $types, array $params): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                'form_id'    => (int)$r['form_id'],
                'student_no' => (string)$r['student_no'],
                'score'      => (int)$r['score'],
                'raw'        => (int)$r['raw_score'],
                'penalty'    => (int)$r['penalty'],
                'max'        => (int)$r['max_score'],
                'at'         => $r['submitted_at'],
            ];
        }
        $stmt->close();
        return $rows;
    }

    /* Log the detail, keep the sheet: the fallback gives the same numbers. */
    private function viewsFailed(\Throwable $e): void
    {
        $this->viaViews = false;
        error_log('eGradeBook: FormFlow gradebook views unavailable, reading its tables instead: ' . $e->getMessage());
    }
}
