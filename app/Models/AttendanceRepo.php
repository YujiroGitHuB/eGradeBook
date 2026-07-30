<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_attendance_meta — the eGradeBook OVERLAY for the one auto
   "Attendance" column per class (enabled toggle + term/category/weight/
   order). The score itself is computed live from the QR scans in SheetRepo;
   this table stores only the grading overlay, scoped per class. */
class AttendanceRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    public function setEnabled(ClassScope $c, int $en): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare(
            "INSERT INTO grade_attendance_meta (owner_id, section, enabled, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)"
        );
        $stmt->bind_param('isisss', $admin_id, $sec, $en, $sy, $sem, $sub);
        $stmt->execute();
        $stmt->close();
    }

    public function ensureRow(ClassScope $c): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $ins = $this->db->prepare("INSERT IGNORE INTO grade_attendance_meta (owner_id, section, school_year, semester, subject) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $ins->execute();
        $ins->close();
    }

    /* Changing the term clears the category (options depend on the term). */
    public function setTerm(ClassScope $c, string $term): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET term=?, category_id=NULL
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('sissss', $term, $admin_id, $sec, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    public function setCategory(ClassScope $c, ?int $cat): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET category_id=?
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('iissss', $cat, $admin_id, $sec, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    public function setWeight(ClassScope $c, float $weight): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET weight=?
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('dissss', $weight, $admin_id, $sec, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    /* Overlay row for the sheet (enabled, term, category_id, weight, sort_order). */
    public function metaForSheet(ClassScope $c): ?array
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $amS = $this->db->prepare("SELECT enabled, term, category_id, weight, sort_order FROM grade_attendance_meta
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? LIMIT 1");
        $amS->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $amS->execute();
        $amRes = $amS->get_result();
        $attMeta = $amRes->fetch_assoc();
        $amS->close();
        return $attMeta ?: null;
    }
}
