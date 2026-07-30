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
