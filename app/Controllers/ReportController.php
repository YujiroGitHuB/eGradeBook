<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ReportRepo;

/* Ulo ng PDF report (More ▸ Setup ▸ Report header…). Owner-scoped;
   walang class scope — tingnan ang ReportRepo kung bakit. */
class ReportController extends Controller
{
    public function getHeader(): void
    {
        $repo = new ReportRepo($this->db, $this->ownerId);
        $this->ok([
            'header' => $repo->get(),
            /* Ang pangalan ng naka-login ay ipinapadala bilang MUNGKAHI lang
               para sa Faculty — hindi ito naka-imbak. Ang guro ang magpapasya
               kung ang pangalan sa account niya nga ang gusto niyang lumabas
               sa report (madalas may titulo pa: "Ma'am", "PhD", at iba pa). */
            'suggest_faculty' => (string)($_SESSION['admin_name'] ?? ''),
        ]);
    }

    public function saveHeader(): void
    {
        $vals = [];
        foreach (ReportRepo::FIELDS as $f) $vals[$f] = $this->post($f, '');

        try {
            $saved = (new ReportRepo($this->db, $this->ownerId))->save($vals);
            /* Ibinabalik ang nilinis na halaga, hindi ang ipinadala: kung may
               pinutol o inalis na bagong linya, dapat makita agad ng guro ang
               aktuwal na lalabas sa PDF. */
            $this->ok(['header' => $saved]);
        } catch (\Throwable $e) {
            error_log('eGradeBook save_report_header failed: ' . $e);
            $this->fail('Could not save the report header.');
        }
    }
}
