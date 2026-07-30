<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\SettingsRepo;
use App\Models\CategoryRepo;

/* Per-class toggles: use_defense (flat mode) and term_mode (Option B). */
class SettingsController extends Controller
{
    public function setUseDefense(): void
    {
        $scope  = $this->classScope();
        $useDef = (($_POST['value'] ?? '1') === '1' || ($_POST['value'] ?? '') === 'true') ? 1 : 0;
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        (new SettingsRepo($this->db, $this->ownerId))->setUseDefense($scope, $useDef);
        $this->ok(['use_defense' => (bool)$useDef]);
    }

    public function setTermMode(): void
    {
        $scope = $this->classScope();
        $tm = (($_POST['value'] ?? '0') === '1' || ($_POST['value'] ?? '') === 'true') ? 1 : 0;
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        (new SettingsRepo($this->db, $this->ownerId))->setTermMode($scope, $tm);

        /* first enable → seed the default categories (from Excel) for this class */
        if ($tm === 1) {
            (new CategoryRepo($this->db, $this->ownerId))->seedDefaultsIfEmpty($scope);
        }
        $this->ok(['term_mode' => (bool)$tm]);
    }
}
