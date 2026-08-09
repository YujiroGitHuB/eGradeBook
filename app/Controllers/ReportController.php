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

    /* Hiwalay sa get_report_header dahil daan-daang KB ang data URI at
       hinihingi ang teksto sa bawat page load. Ito ay hinihingi lang kapag
       may aktuwal na iguguhit (PDF export o print). */
    public function getBanner(): void
    {
        $this->ok(['banner' => (new ReportRepo($this->db, $this->ownerId))->getBanner()]);
    }

    public function saveHeader(): void
    {
        $vals = [];
        foreach (ReportRepo::FIELDS as $f) $vals[$f] = $this->post($f, '');

        try {
            $repo = new ReportRepo($this->db, $this->ownerId);
            /* Ibinabalik ang nilinis na halaga, hindi ang ipinadala: kung may
               pinutol o inalis na bagong linya, dapat makita agad ng guro ang
               aktuwal na lalabas sa PDF. */
            $repo->save($vals);

            /* Tatlong estado ang banner, kaya array_key_exists — hindi empty():
                 walang susi     → walang binago (teksto lang ang sine-save)
                 susing blangko  → inaalis ang banner
                 may data URI    → pinapalitan
               Kung empty() ang gagamitin, ang pag-alis ay hindi mangyayari
               kailanman — magmumukhang hindi tumutugon ang Remove. */
            if (array_key_exists('banner', $_POST)) {
                $err = $repo->saveBanner(
                    (string)$_POST['banner'],
                    intval($this->post('banner_w', 0)),
                    intval($this->post('banner_h', 0))
                );
                if ($err !== '') {
                    $this->fail($err);
                    return;
                }
            }

            $this->ok(['header' => $repo->get()]);
        } catch (\Throwable $e) {
            error_log('eGradeBook save_report_header failed: ' . $e);
            $this->fail('Could not save the report header.');
        }
    }
}
