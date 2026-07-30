<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ClassRepo;

/* Class management. `retag` relabels the currently-viewed sheet (the request's
   class scope = the SOURCE) into a named TARGET class (to_* params). */
class ClassController extends Controller
{
    /* List the named classes for a section (for the Class dropdown). */
    public function classes(): void
    {
        $section = trim($this->get('section', ''));
        if ($section === '') {
            $this->ok(['classes' => []]);
            return;
        }
        $classes = (new ClassRepo($this->db, $this->ownerId))->listForSection($section);
        $this->ok(['classes' => $classes]);
    }

    /* Register a new (possibly empty) class so it shows in the dropdown. The
       request scope (section + school_year/semester/subject) IS the new class. */
    public function create(): void
    {
        $scope = $this->classScope();
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        if ($scope->schoolYear === '' && $scope->semester === '' && $scope->subject === '') {
            $this->fail('Enter a school year, semester, or subject.');
            return;
        }
        (new ClassRepo($this->db, $this->ownerId))->register($scope->section, $scope->schoolYear, $scope->semester, $scope->subject);
        $this->ok(['class' => ['school_year' => $scope->schoolYear, 'semester' => $scope->semester, 'subject' => $scope->subject]]);
    }

    public function retag(): void
    {
        $source = $this->classScope();                 // current view = what we're tagging
        $toSy   = trim($_POST['to_school_year'] ?? '');
        $toSem  = trim($_POST['to_semester'] ?? '');
        $toSubj = trim($_POST['to_subject'] ?? '');

        if (!$source->hasSection()) {
            $this->fail('No section.');
            return;
        }
        if ($toSy === '' && $toSem === '' && $toSubj === '') {
            $this->fail('Enter a school year, semester, or subject to tag this sheet as.');
            return;
        }
        if ($source->schoolYear === $toSy && $source->semester === $toSem && $source->subject === $toSubj) {
            $this->fail('The target class is the same as the current one.');
            return;
        }

        $repo = new ClassRepo($this->db, $this->ownerId);
        if ($repo->classHasActivities($source->section, $toSy, $toSem, $toSubj)) {
            $this->fail('That class already has activities. Pick an empty class to tag into.');
            return;
        }

        try {
            $moved = $repo->retag(
                $source->section,
                $source->schoolYear, $source->semester, $source->subject,
                $toSy, $toSem, $toSubj
            );
            $this->ok([
                'moved' => $moved,
                'to'    => ['school_year' => $toSy, 'semester' => $toSem, 'subject' => $toSubj],
            ]);
        } catch (\Throwable $e) {
            $this->fail('Could not re-tag: ' . $e->getMessage());
        }
    }
}
