<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AttendanceRepo;

/* The auto attendance column overlay (enable + term/category/weight + ang
   `midterm_end` na naghahati sa Midterm at Final), scoped per class. */
class AttendanceController extends Controller
{
    /* Toggle grade_attendance_meta.enabled for a class. */
    public function setEnabled(): void
    {
        $scope = $this->classScope();
        $en = (($_POST['value'] ?? '0') === '1' || ($_POST['value'] ?? '') === 'true') ? 1 : 0;
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        (new AttendanceRepo($this->db, $this->ownerId))->setEnabled($scope, $en);
        $this->ok(['enabled' => (bool)$en]);
    }

    /* Partial-update the overlay — only the sent fields change. */
    public function setMeta(): void
    {
        $scope = $this->classScope();
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        $repo = new AttendanceRepo($this->db, $this->ownerId);
        $repo->ensureRow($scope);

        /* Aling kalahati ang inaayos. Kapag hati ang attendance ay dalawa ang
           column ('att' = Midterm, 'attf' = Final) pero IISA lang ang hilera —
           ang final_* na hanay ang para sa Final. Anumang hindi 'final' ay
           itinuturing na Midterm (kasama ang hindi hating column). */
        $half = (($_POST['half'] ?? '') === 'final') ? 'final' : 'midterm';

        /* Huling araw ng Midterm. Blangko = alisin ang hati (balik sa iisang
           column na bumibilang ng lahat ng session). Mahigpit ang tsek sa
           YYYY-MM-DD dahil dumadaan ito sa SQL bilang petsa. */
        if (array_key_exists('midterm_end', $_POST)) {
            $raw = trim((string)$_POST['midterm_end']);
            $date = null;
            if ($raw !== '') {
                $d = \DateTime::createFromFormat('Y-m-d', $raw);
                if (!$d || $d->format('Y-m-d') !== $raw) {
                    $this->fail('Invalid date. Use the date picker (YYYY-MM-DD).');
                    return;
                }
                $date = $raw;
            }
            $repo->setCutoff($scope, $date);
        }
        /* Naka-lock ang term ng hating column (ang petsa ang nagtatakda), kaya
           ang Midterm na hanay lang ang may term na maiitakda pa. */
        if (array_key_exists('term', $_POST) && $half === 'midterm') {
            $aTerm = in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '';
            $repo->setTerm($scope, $aTerm);
        }
        if (array_key_exists('category_id', $_POST)) {
            $aCat = ($_POST['category_id'] === '' ? null : intval($_POST['category_id']));
            $repo->setCategory($scope, $aCat, $half);
        }
        if (array_key_exists('weight', $_POST)) {
            $aWt = max(0, (float)$_POST['weight']);
            $repo->setWeight($scope, $aWt, $half);
        }
        $this->ok();
    }
}
