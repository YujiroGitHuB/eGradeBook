<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\StatusRepo;

/* Per-student final-status overrides (INC / DRP / W, or a custom label),
   scoped per class. */
class StatusController extends Controller
{
    public function setOne(): void
    {
        $scope = $this->classScope();
        $sno   = trim($_POST['student_no'] ?? '');
        $raw   = trim($_POST['status'] ?? '');
        if (!$scope->hasSection() || $sno === '') {
            $this->fail('Missing section or student.');
            return;
        }
        /* INC/DRP/W stay normalised to uppercase; anything else is a
           custom label kept as-typed (capped to fit the column). */
        $builtin = ['INC', 'DRP', 'W'];
        $status  = in_array(strtoupper($raw), $builtin, true)
            ? strtoupper($raw)
            : mb_substr($raw, 0, 24);

        (new StatusRepo($this->db, $this->ownerId))->setOne($scope, $sno, $status);
        $this->ok(['status' => $status]);
    }

    public function setMany(): void
    {
        $scope  = $this->classScope();
        $status = strtoupper(trim($_POST['status'] ?? ''));
        $snos   = json_decode($_POST['students'] ?? '[]', true);
        if (!is_array($snos)) $snos = [];
        $allowed = ['INC', 'DRP', 'W'];
        if (!$scope->hasSection() || !$snos) {
            $this->fail('Missing section or students.');
            return;
        }
        if ($status !== '' && !in_array($status, $allowed, true)) {
            $this->fail('Invalid status.');
            return;
        }
        try {
            (new StatusRepo($this->db, $this->ownerId))->setMany($scope, $status, $snos);
            $this->ok(['status' => $status, 'count' => count($snos)]);
        } catch (\Throwable $e) {
            error_log('eGradeBook set_students_status failed: ' . $e);
            $this->fail('Could not save the status. Please try again.');
        }
    }
}
