<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\AttendanceRepo;

/* The auto attendance column overlay (enable + term/category/weight),
   scoped per class. */
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

        if (array_key_exists('term', $_POST)) {
            $aTerm = in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '';
            $repo->setTerm($scope, $aTerm);
        }
        if (array_key_exists('category_id', $_POST)) {
            $aCat = ($_POST['category_id'] === '' ? null : intval($_POST['category_id']));
            $repo->setCategory($scope, $aCat);
        }
        if (array_key_exists('weight', $_POST)) {
            $aWt = max(0, (float)$_POST['weight']);
            $repo->setWeight($scope, $aWt);
        }
        $this->ok();
    }
}
