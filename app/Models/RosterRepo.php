<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* ============================================================
   RosterRepo — BRIDGE into the attendance DB (ATTENDANCE_DB.students_tbl).
   This is the student roster (sections, names, courses). Read-only from
   eGradeBook's side; owned by the QR attendance app.

   For NON-legacy classes it also maintains a per-class snapshot
   (grade_roster_snapshot) so a class keeps its students/names even if the
   upstream roster later changes — see rosterForClass().
   ============================================================ */
class RosterRepo
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /* The roster to render for a class. Legacy class ('', '', '') → the live
       roster, exactly as before. Non-legacy → top up the snapshot from the live
       roster (INSERT IGNORE, never overwriting), then read the snapshot, so
       previously-seen students persist even if students_tbl drops them. */
    public function rosterForClass(ClassScope $c, int $ownerId): array
    {
        if ($c->schoolYear === '' && $c->semester === '' && $c->subject === '') {
            return $this->roster($c->section);
        }
        $this->topUpSnapshot($c, $ownerId);
        return $this->snapshotRoster($c, $ownerId);
    }

    /* INSERT IGNORE the current live roster into this class's snapshot (cross-DB
       INSERT ... SELECT on the same server). Idempotent; adds new enrollees,
       never overwrites a captured name. */
    private function topUpSnapshot(ClassScope $c, int $ownerId): void
    {
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO grade_roster_snapshot (owner_id, school_year, semester, section, subject, student_no, fullname, course)
             SELECT ?, ?, ?, ?, ?, student_no, fullname, course
             FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . " WHERE section=?"
        );
        $stmt->bind_param('isssss', $ownerId, $sy, $sem, $sec, $sub, $sec);
        $stmt->execute();
        $stmt->close();
    }

    /* Read this class's frozen roster (same shape as the live roster). */
    private function snapshotRoster(ClassScope $c, int $ownerId): array
    {
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare(
            "SELECT student_no, fullname, course, section FROM grade_roster_snapshot
             WHERE owner_id=? AND school_year=? AND semester=? AND section=? AND subject=?
             ORDER BY fullname"
        );
        $stmt->bind_param('issss', $ownerId, $sy, $sem, $sec, $sub);
        $stmt->execute();
        $rs = $stmt->get_result();
        $students = [];
        while ($r = $rs->fetch_assoc()) $students[] = $r;
        $stmt->close();
        return $students;
    }

    /* All sections with their course + headcount (the section picker). */
    public function sections(): array
    {
        $sql = "SELECT section, course, COUNT(*) AS cnt
                FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . "
                WHERE section IS NOT NULL AND section <> ''
                GROUP BY section, course
                ORDER BY course, section";
        $res = $this->db->query($sql);
        if (!$res) throw new \Exception('Cannot access attendance DB: ' . $this->db->error());
        $sections = [];
        while ($row = $res->fetch_assoc()) {
            $sections[] = [
                'section' => $row['section'],
                'course'  => $row['course'],
                'count'   => (int)$row['cnt'],
            ];
        }
        return $sections;
    }

    /* Full roster rows for a section (student_no, fullname, course, section). */
    public function roster(string $section): array
    {
        $stmt = $this->db->prepare(
            "SELECT student_no, fullname, course, section
             FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . "
             WHERE section = ?
             ORDER BY fullname"
        );
        if (!$stmt) throw new \Exception('Attendance query error: ' . $this->db->error());
        $stmt->bind_param('s', $section);
        $stmt->execute();
        $rs = $stmt->get_result();
        $students = [];
        while ($r = $rs->fetch_assoc()) $students[] = $r;
        $stmt->close();
        return $students;
    }

    /* Just the student numbers in a section (bulk fill / CSV import). */
    public function studentNos(string $section): array
    {
        $stmt = $this->db->prepare(
            "SELECT student_no FROM " . ATTENDANCE_DB . "." . ATTENDANCE_TABLE . " WHERE section=?"
        );
        $stmt->bind_param('s', $section);
        $stmt->execute();
        $rrs = $stmt->get_result();
        $snos = [];
        while ($rr = $rrs->fetch_assoc()) $snos[] = $rr['student_no'];
        $stmt->close();
        return $snos;
    }
}
