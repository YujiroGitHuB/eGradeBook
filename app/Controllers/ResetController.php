<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\ResetRepo;

/* "Clear all" — ibinabalik sa malinis ang gradebook. Tatlong target:

     me    — sarili mo lang (kahit sino, ito ang dating gawi)
     owner — isang partikular na guro   } SUPERADMIN LANG
     all   — bawat guro sa buong app    }

   Tingnan ang ResetRepo para sa listahan ng table at kung bakit hindi
   kailanman nagagalaw ang formflow_db / bcc_qr_attendance_db. */
class ResetController extends Controller
{
    /* Ang salitang kailangang i-type, ayon sa target. Sinusuri ito sa SERVER,
       hindi lang sa modal: ang isang `?api=reset_all` na galing sa bookmark, sa
       history, o sa naiwang tab ay hindi dapat makabura ng lahat dahil lang
       walang nakaharang na dialog.

       Iba-iba ang parirala nang sinasadya. Ang "CLEAR ALL" ay nakasanayan na at
       madaling maulit nang hindi iniisip; ang pagbura ng datos ng IBANG tao ay
       hindi dapat kasingdali niyon, kaya ang pangalan mismo ng guro ang
       tinitipa, at may sariling parirala ang "lahat". */
    private const PHRASE_SELF = 'CLEAR ALL';
    private const PHRASE_ALL  = 'CLEAR EVERYTHING';

    /* Ang mapipiling guro + gaano kalaki ang datos nila. Superadmin lang. */
    public function targets(): void
    {
        if (!Auth::isSuperadmin()) {
            $this->fail('Only a superadmin can do that.');
            return;
        }
        $this->ok([
            'owners' => (new ResetRepo($this->db, $this->ownerId))->ownersWithData(),
            'me'     => $this->ownerId,
        ]);
    }

    public function clearAll(): void
    {
        $target  = (string)$this->post('target', 'me');
        $ownerId = intval($this->post('owner_id', 0));
        $typed   = trim((string)$this->post('confirm', ''));

        if (!in_array($target, ['me', 'owner', 'all'], true)) {
            $this->fail('Unknown target.');
            return;
        }

        /* Ang paglabas sa sariling datos ay superadmin-only. Sinusuri rito, sa
           server — hindi sapat na nakatago ang mga kontrol sa modal. */
        if ($target !== 'me' && !Auth::isSuperadmin()) {
            $this->fail('Only a superadmin can clear another teacher\'s data.');
            return;
        }

        $repo = new ResetRepo($this->db, $this->ownerId);

        /* Sinong buburahin, at anong pariralang kailangan */
        if ($target === 'all') {
            $who    = null;
            $phrase = self::PHRASE_ALL;
            $label  = 'every teacher';
        } elseif ($target === 'owner') {
            /* Ang sariling id na dumaan bilang "owner" ay ituring na "me" —
               parehong resulta, at ang mas mahinang parirala ang tama roon. */
            if ($ownerId === $this->ownerId) {
                $who    = $this->ownerId;
                $phrase = self::PHRASE_SELF;
                $label  = 'your gradebook';
            } else {
                $owners = $repo->ownersWithData();
                $match  = null;
                foreach ($owners as $o) if ($o['id'] === $ownerId) $match = $o;
                if (!$match) {
                    $this->fail('That teacher has no gradebook data to clear.');
                    return;
                }
                $who    = $ownerId;
                /* Ang username mismo ang parirala: hindi mo ito matitipa nang
                   hindi mo tinitingnan kung sino ang tinatamaan. */
                $phrase = 'CLEAR ' . $match['username'];
                $label  = $match['full_name'] . '\'s gradebook';
            }
        } else {
            $who    = $this->ownerId;
            $phrase = self::PHRASE_SELF;
            $label  = 'your gradebook';
        }

        if ($typed !== $phrase) {
            $this->fail('Type ' . $phrase . ' to confirm.');
            return;
        }

        try {
            $deleted = $repo->clearAll($who);
            $this->ok(['deleted' => $deleted, 'label' => $label]);
        } catch (\Throwable $e) {
            /* Rolled back na ng repo — buo pa rin ang gradebook. Detalye sa
               log, pangungusap lang sa guro (tingnan ang CLAUDE.md). */
            error_log('eGradeBook clear-all failed [' . $target . ']: ' . $e);
            $this->fail('Could not clear the gradebook. Nothing was deleted.');
        }
    }
}
