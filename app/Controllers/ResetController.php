<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ResetRepo;

/* "Clear all" — ibinabalik sa malinis ang buong gradebook ng nakalog-in na
   guro (lahat ng section, lahat ng klase). Tingnan ang ResetRepo para sa
   eksaktong listahan ng table at kung bakit hindi kailanman nagagalaw ang
   formflow_db / bcc_qr_attendance_db. */
class ResetController extends Controller
{
    /* Ang salitang kailangang i-type ng guro. Sinusuri ito sa SERVER, hindi
       lang sa modal: ang isang `?api=reset_all` na galing sa bookmark, sa
       history, o sa naiwang tab ay hindi dapat makabura ng lahat dahil lang
       walang nakaharang na dialog. */
    private const CONFIRM_PHRASE = 'CLEAR ALL';

    public function clearAll(): void
    {
        if (trim((string)$this->post('confirm', '')) !== self::CONFIRM_PHRASE) {
            $this->fail('Type ' . self::CONFIRM_PHRASE . ' to confirm.');
            return;
        }

        try {
            $deleted = (new ResetRepo($this->db, $this->ownerId))->clearAll();
            $this->ok(['deleted' => $deleted]);
        } catch (\Throwable $e) {
            /* Rolled back na ng repo — buo pa rin ang gradebook. Detalye sa
               log, pangungusap lang sa guro (tingnan ang CLAUDE.md). */
            error_log('eGradeBook clear-all failed: ' . $e);
            $this->fail('Could not clear your gradebook. Nothing was deleted.');
        }
    }
}
