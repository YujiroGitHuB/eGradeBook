<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\SheetRepo;
use App\Models\ClassRepo;

/* The core read — assembles the whole grading matrix for one class
   (section + school_year + semester + subject). */
class SheetController extends Controller
{
    public function sheet(): void
    {
        $scope = $this->classScope();
        if (!$scope->hasSection()) {
            $this->fail('Please select a section first.');
            return;
        }
        $data = (new SheetRepo($this->db, $this->ownerId))->build($scope);
        /* keep any non-legacy class you open in the Class dropdown, even if it
           later has no activities (no-op for the legacy class) */
        (new ClassRepo($this->db, $this->ownerId))->register($scope->section, $scope->schoolYear, $scope->semester, $scope->subject);
        $this->json($data);
    }
}
