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

    /* Itago / ibalik ang form column sa KLASENG ito. Dahil per-section ang
       auto-discovery ng forms (walang subject ang FormFlow), ito ang paraan
       para hindi lumabas sa isang klase ang form ng ibang subject sa parehong
       section. Overlay lang ito — buo pa rin ang form at ang mga sagot sa
       FormFlow, at ang ibang klase ay hindi apektado. */
    public function setHidden(ClassScope $c, int $formId, bool $hidden): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $h   = $hidden ? 1 : 0;
        $u = $this->db->prepare("UPDATE grade_form_meta SET hidden=?
            WHERE owner_id=? AND section=? AND form_id=? AND school_year=? AND semester=? AND subject=?");
        $u->bind_param('iisisss', $h, $admin_id, $sec, $formId, $sy, $sem, $sub);
        $u->execute();
        $u->close();
    }

    /* Dalhin sa klaseng ito ang mga nakatagong form ng ibang klase sa PAREHONG
       section. Ito ang lunas sa pag-ipon: walang date filter ang pagtuklas ng
       forms (per-section lang), kaya ang mga form ng nakaraang taon ay lumalabas
       pa rin sa bagong klase ng kaparehong pangalan ng section. Sa halip na
       itago ulit isa-isa, kinokopya ang naunang klase.

       MERGE ang gawi, hindi mirror: ang hidden=1 lang ang dinadala, kaya walang
       biglang lumalabas na form na tahasan mong itinago rito. Ligtas ulitin. */
    public function copyHiddenFrom(ClassScope $to, string $fromSy, string $fromSem, string $fromSubj): int
    {
        $admin_id = $this->ownerId;
        $sec = $to->section;
        $sy  = $to->schoolYear;
        $sem = $to->semester;
        $sub = $to->subject;
        $stmt = $this->db->prepare(
            "INSERT INTO grade_form_meta (owner_id, section, form_id, school_year, semester, subject, hidden)
             SELECT owner_id, section, form_id, ?, ?, ?, 1
               FROM grade_form_meta
              WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? AND hidden=1
             ON DUPLICATE KEY UPDATE hidden = 1"
        );
        $stmt->bind_param('sssissss', $sy, $sem, $sub, $admin_id, $sec, $fromSy, $fromSem, $fromSubj);
        $stmt->execute();
        $stmt->close();

        /* Hiwalay na bilangin ang pinagkunan: ang affected_rows ng INSERT..ON
           DUPLICATE ay 1 kada bagong row pero 2 kada na-update, kaya hindi ito
           mapagkakatiwalaang bilang ng form. */
        $cnt = $this->db->prepare("SELECT COUNT(*) n FROM grade_form_meta
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? AND hidden=1");
        $cnt->bind_param('issss', $admin_id, $sec, $fromSy, $fromSem, $fromSubj);
        $cnt->execute();
        $n = (int)($cnt->get_result()->fetch_assoc()['n'] ?? 0);
        $cnt->close();
        return $n;
    }
}
