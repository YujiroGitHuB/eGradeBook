<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_student_status — per-student final-status override (INC/DRP/W or a
   custom label), scoped per class. Overlay only; never touches scores. */
class StatusRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Set one student's status; '' clears (deletes) the override. */
    public function setOne(ClassScope $c, string $sno, string $status): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        if ($status === '') {
            $st = $this->db->prepare("DELETE FROM grade_student_status
                WHERE owner_id=? AND section=? AND student_no=? AND school_year=? AND semester=? AND subject=?");
            $st->bind_param('isssss', $admin_id, $sec, $sno, $sy, $sem, $sub);
        } else {
            $st = $this->db->prepare("INSERT INTO grade_student_status (owner_id, section, student_no, status, school_year, semester, subject)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE status = VALUES(status)");
            $st->bind_param('issssss', $admin_id, $sec, $sno, $status, $sy, $sem, $sub);
        }
        $st->execute();
        $st->close();
    }

    /* Bulk set/clear for many students in one transaction; throws on failure. */
    public function setMany(ClassScope $c, string $status, array $snos): void
    {
        $conn = $this->db->conn;
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $conn->begin_transaction();
        try {
            if ($status === '') {
                $del = $conn->prepare("DELETE FROM grade_student_status
                    WHERE owner_id=? AND section=? AND student_no=? AND school_year=? AND semester=? AND subject=?");
                foreach ($snos as $sno) {
                    $sno = trim((string)$sno);
                    if ($sno === '') continue;
                    $del->bind_param('isssss', $admin_id, $sec, $sno, $sy, $sem, $sub);
                    $del->execute();
                }
                $del->close();
            } else {
                $ins = $conn->prepare("INSERT INTO grade_student_status (owner_id, section, student_no, status, school_year, semester, subject)
                                       VALUES (?, ?, ?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE status = VALUES(status)");
                foreach ($snos as $sno) {
                    $sno = trim((string)$sno);
                    if ($sno === '') continue;
                    $ins->bind_param('issssss', $admin_id, $sec, $sno, $status, $sy, $sem, $sub);
                    $ins->execute();
                }
                $ins->close();
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /* student_no => status map for the sheet. */
    public function forSheet(ClassScope $c): array
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $statuses = [];
        $stmt = $this->db->prepare("SELECT student_no, status FROM grade_student_status
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $stmt->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $stmt->execute();
        $rs = $stmt->get_result();
        while ($x = $rs->fetch_assoc()) $statuses[$x['student_no']] = $x['status'];
        $stmt->close();
        return $statuses;
    }
}
