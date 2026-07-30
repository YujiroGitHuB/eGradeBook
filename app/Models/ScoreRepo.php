<?php

namespace App\Models;

use App\Core\Database;

/* grade_activity_scores — manual score per student per activity. */
class ScoreRepo
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /* Upsert a single clamped score. */
    public function upsert(int $aid, string $sno, int $score): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO grade_activity_scores (activity_id, student_no, score)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score)"
        );
        $stmt->bind_param('isi', $aid, $sno, $score);
        if (!$stmt->execute()) throw new \Exception('Save failed: ' . $this->db->error());
        $stmt->close();
    }

    public function deleteForStudent(int $aid, string $sno): void
    {
        $stmt = $this->db->prepare("DELETE FROM grade_activity_scores WHERE activity_id=? AND student_no=?");
        $stmt->bind_param('is', $aid, $sno);
        $stmt->execute();
        $stmt->close();
    }

    /* Delete every score in an activity; returns affected rows. */
    public function deleteAll(int $aid): int
    {
        $stmt = $this->db->prepare("DELETE FROM grade_activity_scores WHERE activity_id=?");
        $stmt->bind_param('i', $aid);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    /* Sweep the "twins": set every cell equal to $from to $to. Returns updated count. */
    public function syncValue(int $aid, int $from, int $to): int
    {
        $stmt = $this->db->prepare(
            "UPDATE grade_activity_scores SET score=? WHERE activity_id=? AND score=?"
        );
        $stmt->bind_param('iii', $to, $aid, $from);
        if (!$stmt->execute()) throw new \Exception('Sync failed: ' . $this->db->error());
        $updated = $stmt->affected_rows;
        $stmt->close();
        return $updated;
    }

    /* student_no => true for students who already have a score in this activity. */
    public function studentsWithScore(int $aid): array
    {
        $set = [];
        $er = $this->db->query("SELECT student_no FROM grade_activity_scores WHERE activity_id=$aid");
        if ($er) while ($e = $er->fetch_assoc()) $set[(string)$e['student_no']] = true;
        return $set;
    }

    /* Apply a student_no => score map in one prepared upsert loop; returns count applied. */
    public function applyScores(int $aid, array $snoToScore): int
    {
        if (!$snoToScore) return 0;
        $ins = $this->db->prepare(
            "INSERT INTO grade_activity_scores (activity_id, student_no, score)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE score = VALUES(score)"
        );
        $applied = 0;
        foreach ($snoToScore as $sno => $sc) {
            $sno = (string)$sno;
            $sc  = (int)$sc;
            $ins->bind_param('isi', $aid, $sno, $sc);
            $ins->execute();
            $applied++;
        }
        $ins->close();
        return $applied;
    }
}
