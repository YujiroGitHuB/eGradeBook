<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\CategoryRepo;

/* grade_categories — add/update/delete weighted categories per term,
   scoped per class. */
class CategoryController extends Controller
{
    public function save(): void
    {
        $cid    = intval($_POST['id'] ?? 0);
        $scope  = $this->classScope();
        $term   = in_array($_POST['term'] ?? '', ['midterm', 'final'], true) ? $_POST['term'] : '';
        $name   = trim($_POST['name'] ?? '');
        $weight = max(0, (float)($_POST['weight'] ?? 0));

        $repo = new CategoryRepo($this->db, $this->ownerId);
        if ($cid > 0) {
            $repo->update($cid, $name, $weight);
            $this->ok(['id' => $cid]);
        } else {
            if (!$scope->hasSection() || $term === '' || $name === '') {
                $this->fail('Missing category info.');
                return;
            }
            $newId = $repo->insert($scope, $term, $name, $weight);
            $this->ok(['id' => $newId]);
        }
    }

    /* Kopyahin ang mga category ng isang term papunta sa kabila, sa loob ng
       kasalukuyang klase ("Copy from Midterm" sa Grade setup). Karaniwang
       magkatulad ang dalawang term, kaya dalawang beses tinitipa ang parehong
       apat na row. Merge ang gawi — tingnan ang CategoryRepo::copyTerm.

       Ibinabalik ang buong bagong listahan ng category para may TAMANG id
       agad ang kliyente sa mga bagong likha (kailangan iyon ng dropdown ng
       bawat column header) nang walang buong reload ng sheet. */
    public function copyTerm(): void
    {
        $scope = $this->classScope();
        $from  = in_array($_POST['from_term'] ?? '', ['midterm', 'final'], true) ? $_POST['from_term'] : '';
        $to    = in_array($_POST['to_term'] ?? '', ['midterm', 'final'], true) ? $_POST['to_term'] : '';

        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        if ($from === '' || $to === '' || $from === $to) {
            $this->fail('Pick a different source term.');
            return;
        }

        $repo = new CategoryRepo($this->db, $this->ownerId);
        try {
            $res = $repo->copyTerm($scope, $from, $to);
            if ($res['added'] === 0 && $res['updated'] === 0) {
                $this->fail('That term has no categories to copy.');
                return;
            }
            $this->ok($res + ['categories' => $repo->forSheet($scope)]);
        } catch (\Throwable $e) {
            error_log('eGradeBook copy_categories failed: ' . $e);
            $this->fail('Could not copy the categories. Nothing was changed.');
        }
    }

    /* Delete a category, unassigning the activities & form columns first.
       Keyed by category id, so it is correctly class-independent. */
    public function delete(): void
    {
        $cid = intval($_POST['id'] ?? 0);
        if ($cid <= 0) {
            $this->fail('No id.');
            return;
        }
        $repo  = new CategoryRepo($this->db, $this->ownerId);
        $owner = $repo->ownerOf($cid);
        if ($owner === null || $owner !== $this->ownerId) {
            $this->fail('Not allowed.');
            return;
        }
        $repo->deleteWithUnassign($cid);
        $this->ok();
    }
}
