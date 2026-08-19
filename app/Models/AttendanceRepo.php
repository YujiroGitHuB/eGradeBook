<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_attendance_meta — the eGradeBook OVERLAY for the auto "Attendance"
   column(s) of a class (enabled toggle + term/category/weight/order). The
   score itself is computed live from the QR scans in SheetRepo; this table
   stores only the grading overlay, scoped per class.

   ISANG hilera pa rin kada klase kahit dalawa ang column kapag hati sa
   Midterm/Final (tingnan ang `midterm_end` sa Schema.php): ang lumang
   category_id/weight/sort_order ang para sa kalahating MIDTERM, at ang
   final_* para sa FINAL. Kaya `half` ('midterm' | 'final') ang ipinapasa sa
   mga setter — whitelisted sa columnFor(), hindi galing sa request nang
   diretso. */
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

    /* Huling araw ng Midterm. Blangko/`null` = walang hati: iisang
       attendance column na bumibilang ng LAHAT ng session ng klase — ang
       eksaktong dating gawi. */
    public function setCutoff(ClassScope $c, ?string $date): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET midterm_end=?
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('sissss', $date, $admin_id, $sec, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    /* Column name para sa kalahating hinihiling. WHITELIST ito: ang `half` ay
       galing sa request, at dumadaan sa SQL string (hindi ma-bind ang pangalan
       ng column), kaya walang ibang halaga ang pinapayagan. */
    private static function columnFor(string $field, string $half): string
    {
        $map = [
            'category_id' => ['midterm' => 'category_id', 'final' => 'final_category_id'],
            'weight'      => ['midterm' => 'weight',      'final' => 'final_weight'],
            'sort_order'  => ['midterm' => 'sort_order',  'final' => 'final_sort_order'],
        ];
        return $map[$field][$half] ?? $map[$field]['midterm'];
    }

    public function setCategory(ClassScope $c, ?int $cat, string $half = 'midterm'): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $col = self::columnFor('category_id', $half);
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET `$col`=?
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('iissss', $cat, $admin_id, $sec, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    public function setWeight(ClassScope $c, float $weight, string $half = 'midterm'): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $col = self::columnFor('weight', $half);
        $u = $this->db->prepare("UPDATE grade_attendance_meta SET `$col`=?
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
        $amS = $this->db->prepare("SELECT enabled, term, category_id, weight, sort_order,
                   midterm_end, final_category_id, final_weight, final_sort_order
            FROM grade_attendance_meta
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? LIMIT 1");
        $amS->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $amS->execute();
        $amRes = $amS->get_result();
        $attMeta = $amRes->fetch_assoc();
        $amS->close();
        return $attMeta ?: null;
    }
}
