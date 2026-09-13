<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\OnboardingRepo;

/* Guided tour + What's New (components/tour.php, components/supportModal.php).
   Ang estado ay isinusulat sa pahina ng index.php, kaya ang dalawang write lang
   ang nasa API. */
class OnboardingController extends Controller
{
    public function tourDone(): void
    {
        try {
            (new OnboardingRepo($this->db, $this->ownerId))->markTourDone();
            $this->ok();
        } catch (\Throwable $e) {
            error_log('eGradeBook onboarding_tour_done failed: ' . $e);
            $this->fail('Could not save the tour progress.');
        }
    }

    public function whatsNewSeen(): void
    {
        $v = (string)$this->post('version', '');
        /* Mahigpit na Y-m-d round-trip — gaya ng midterm_end sa
           AttendanceController — dahil ikinukumpara ito bilang string. */
        $d = \DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->fail('Invalid version.');
            return;
        }
        try {
            (new OnboardingRepo($this->db, $this->ownerId))->markWhatsNewSeen($v);
            $this->ok();
        } catch (\Throwable $e) {
            error_log('eGradeBook onboarding_whatsnew_seen failed: ' . $e);
            $this->fail('Could not save.');
        }
    }
}
