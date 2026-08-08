<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\TransmuteRepo;

/* grade_transmute — the global 0–100 → 1.00–5.00 bands (per teacher). */
class TransmuteController extends Controller
{
    public function getBands(): void
    {
        $this->ok(['grade_equiv' => (new TransmuteRepo($this->db, $this->ownerId))->load()]);
    }

    /* Replace the whole set of bands. Validation mirrors the old action:
       min 0–100, point 1.00–5.00, dedup by min, highest min first. */
    public function save(): void
    {
        $raw  = $_POST['bands'] ?? '[]';
        $list = json_decode($raw, true);
        if (!is_array($list)) $list = [];

        $clean = [];
        foreach ($list as $b) {
            if (!is_array($b)) continue;
            $mn = isset($b['min'])   ? (float)$b['min']   : null;
            $pt = isset($b['point']) ? (float)$b['point'] : null;
            if ($mn === null || $pt === null) continue;
            if ($mn < 0 || $mn > 100) continue;
            if ($pt < 1 || $pt > 5)   continue;
            $mn = round($mn, 2);
            $pt = round($pt, 2);
            $clean[(string)$mn] = ['min' => $mn, 'point' => $pt];   // last write per min wins
        }
        $clean = array_values($clean);
        usort($clean, fn($a, $b) => $b['min'] <=> $a['min']);       // highest min first

        if (!$clean) {
            $this->fail('Add at least one valid band (min 0–100, point 1.00–5.00).');
            return;
        }

        try {
            (new TransmuteRepo($this->db, $this->ownerId))->replaceAll($clean);
            $this->ok(['grade_equiv' => $clean]);
        } catch (\Throwable $e) {
            error_log('eGradeBook save_transmute failed: ' . $e);
            $this->fail('Could not save the transmutation bands. Please try again.');
        }
    }
}
