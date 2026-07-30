<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_form_meta — the eGradeBook OVERLAY on a FormFlow form column
   (term/category/weight/order). Partial-update style: only the sent
   fields change. Keyed per class (owner_id, section, form_id,
   school_year, semester, subject). */
class FormMetaRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Make sure an overlay row exists (defaults) before patching fields. */
    public function ensureRow(ClassScope $c, int $formId): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $ins = $this->db->prepare("INSERT IGNORE INTO grade_form_meta (owner_id, section, form_id, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isisss', $admin_id, $sec, $formId, $sy, $sem, $sub);
        $ins->execute();
        $ins->close();
    }

    /* Changing the term clears the category (its options depend on the term). */
    public function setTerm(ClassScope $c, int $formId, string $term): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_form_meta SET term=?, category_id=NULL
            WHERE owner_id=? AND section=? AND form_id=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('sisisss', $term, $admin_id, $sec, $formId, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    public function setCategory(ClassScope $c, int $formId, ?int $cat): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_form_meta SET category_id=?
            WHERE owner_id=? AND section=? AND form_id=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('iisisss', $cat, $admin_id, $sec, $formId, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    public function setWeight(ClassScope $c, int $formId, float $weight): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $u = $this->db->prepare("UPDATE grade_form_meta SET weight=?
            WHERE owner_id=? AND section=? AND form_id=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('disisss', $weight, $admin_id, $sec, $formId, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }
}
