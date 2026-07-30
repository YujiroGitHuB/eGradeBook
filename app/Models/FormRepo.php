<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   FormRepo — BRIDGE into FormFlow's forms. The auto-graded "form"
   columns come from FormFlow (forms / form_questions / form_responses);
   those stay read-only there. This repo only guards ownership — the
   sheet read itself lives in SheetRepo where the form columns are
   interleaved with the rest of the grading matrix.
   ============================================================ */
class FormRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
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
}
