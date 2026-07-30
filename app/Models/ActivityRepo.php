<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_activities — the manual columns (Recitation, Project, ...). All
   ops are scoped to the logged-in teacher (owner_id), and new columns are
   stamped with the class scope (school_year, semester, section, subject). */
class ActivityRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Distinct sections where this teacher already has activities (My sections). */
    public function sectionsWithActivities(): array
    {
        $admin_id = $this->ownerId;
        $mine = [];
        $rm = $this->db->prepare("SELECT DISTINCT section FROM grade_activities WHERE owner_id = ? ORDER BY section");
        $rm->bind_param('i', $admin_id);
        $rm->execute();
        $rr = $rm->get_result();
        while ($x = $rr->fetch_assoc()) $mine[] = $x['section'];
        $rm->close();
        return $mine;
    }

    /* Distinct school years this teacher has used (for the SY picker). */
    public function schoolYears(): array
    {
        $admin_id = $this->ownerId;
        $out = [];
        $r = $this->db->query("SELECT DISTINCT school_year FROM grade_activities WHERE owner_id=$admin_id AND school_year<>'' ORDER BY school_year DESC");
        if ($r) while ($x = $r->fetch_assoc()) $out[] = $x['school_year'];
        return $out;
    }

    /* Ownership guard — the activity belongs to the logged-in teacher. */
    public function owns(int $aid): bool
    {
        $aid = intval($aid);
        $admin_id = $this->ownerId;
        $r = $this->db->query("SELECT id FROM grade_activities WHERE id=$aid AND owner_id=$admin_id LIMIT 1");
        return $r && $r->num_rows > 0;
    }

    public function maxPoints(int $aid): int
    {
        $row = $this->db->query("SELECT max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
        return (int)($row['max_points'] ?? 100);
    }

    /* section + max_points, or null if the activity is gone (bulk fill / import). */
    public function sectionAndMax(int $aid): ?array
    {
        $row = $this->db->query("SELECT section, max_points FROM grade_activities WHERE id=$aid")->fetch_assoc();
        return $row ?: null;
    }

    public function add(ClassScope $scope, string $title, int $maxPts, string $term, ?int $catId): int
    {
        $admin_id = $this->ownerId;
        $sec  = $scope->section;
        $sy   = $scope->schoolYear;
        $sem  = $scope->semester;
        $subj = $scope->subject;
        $stmt = $this->db->prepare(
            "INSERT INTO grade_activities (owner_id, section, title, max_points, term, category_id, school_year, semester, subject)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('issisisss', $admin_id, $sec, $title, $maxPts, $term, $catId, $sy, $sem, $subj);
        if (!$stmt->execute()) throw new \Exception('Insert failed: ' . $this->db->error());
        $newId = $stmt->insert_id;
        $stmt->close();
        return $newId;
    }

    public function updateBasics(int $aid, string $title, int $maxPts, float $weight): void
    {
        $stmt = $this->db->prepare("UPDATE grade_activities SET title=?, max_points=?, weight=? WHERE id=?");
        $stmt->bind_param('sidi', $title, $maxPts, $weight, $aid);
        if (!$stmt->execute()) throw new \Exception('Update failed: ' . $this->db->error());
        $stmt->close();
    }

    public function updateTerm(int $aid, string $term): void
    {
        $st = $this->db->prepare("UPDATE grade_activities SET term=? WHERE id=?");
        $st->bind_param('si', $term, $aid);
        $st->execute();
        $st->close();
    }

    public function updateCategory(int $aid, ?int $cat): void
    {
        $st = $this->db->prepare("UPDATE grade_activities SET category_id=? WHERE id=?");
        $st->bind_param('ii', $cat, $aid);
        $st->execute();
        $st->close();
    }

    /* When max is lowered, clamp scores that exceeded it. Returns the
       changed [{student_no, score}] so the frontend can sync totals/%. */
    public function clampScoresOverMax(int $aid, int $maxPts): array
    {
        $clamped = [];
        $res = $this->db->query("SELECT student_no FROM grade_activity_scores WHERE activity_id=$aid AND score > $maxPts");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $clamped[] = ['student_no' => $row['student_no'], 'score' => $maxPts];
            }
            $res->free();
        }
        if ($clamped) {
            $this->db->query("UPDATE grade_activity_scores SET score=$maxPts WHERE activity_id=$aid AND score > $maxPts");
        }
        return $clamped;
    }

    public function delete(int $aid): void
    {
        $this->db->query("DELETE FROM grade_activities WHERE id=$aid"); // cascades scores
    }

    public function setLinked(int $aid, int $linked): void
    {
        $admin_id = $this->ownerId;
        $stmt = $this->db->prepare("UPDATE grade_activities SET linked=? WHERE id=? AND owner_id=?");
        $stmt->bind_param('iii', $linked, $aid, $admin_id);
        if (!$stmt->execute()) throw new \Exception('Save failed: ' . $this->db->error());
        $stmt->close();
    }

    /* Persist a new left-to-right order of activity ids (drag reorder). */
    public function reorder(array $order): void
    {
        $admin_id = $this->ownerId;
        $stmt = $this->db->prepare("UPDATE grade_activities SET sort_order=? WHERE id=? AND owner_id=?");
        $pos = 1;
        foreach ($order as $aid) {
            $aid = intval($aid);
            $stmt->bind_param('iii', $pos, $aid, $admin_id);
            $stmt->execute();
            $pos++;
        }
        $stmt->close();
    }
}
