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

    /* Burahin ang klaseng WALANG laman (ang scope ng request ang klase).
       Sadyang hindi ito makakabura ng grado: kung may anumang datos ang klase,
       tumatanggi ito at pinapangalanan kung ano — ang guro mismo ang maglilipat
       o magbubura niyon. Ang tanging naaalis ay ang pangalan sa dropdown at ang
       kusang-nabuong roster snapshot. */
    public function delete(): void
    {
        $scope = $this->classScope();
        if (!$scope->hasSection()) {
            $this->fail('No section.');
            return;
        }
        /* Ang legacy (untagged) sheet ay hindi isang pangalang klase — wala ito sa
           registry, at ang "pagbura" nito ay mangangahulugan ng pagbura ng buong
           dating gradebook. Hindi ito ang trabaho ng aksyong ito. */
        if ($scope->schoolYear === '' && $scope->semester === '' && $scope->subject === '') {
            $this->fail('The untagged sheet is not a named class, so there is nothing to delete.');
            return;
        }

        $repo = new ClassRepo($this->db, $this->ownerId);
        $conflicts = $repo->targetConflicts($scope->section, $scope->schoolYear, $scope->semester, $scope->subject);
        if ($conflicts) {
            $this->fail('This class still has ' . implode(', ', $conflicts) . '. Move or delete them first.');
            return;
        }

        $repo->deleteEmpty($scope->section, $scope->schoolYear, $scope->semester, $scope->subject);
        $this->ok();
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

        /* Tingnan LAHAT ng table na inililipat, hindi activities lang — kung hindi,
           ang klaseng may settings/category/form setup pero walang activity ay
           nakalulusot dito at bumabagsak sa hilaw na "Duplicate entry" ng MySQL.
           (Ang roster snapshot ay sadyang wala rito: kusa itong nabubuo sa
           pagbukas lang ng klase, at minementeha ito ng ClassRepo::retag.) */
        $repo = new ClassRepo($this->db, $this->ownerId);
        $conflicts = $repo->targetConflicts($source->section, $toSy, $toSem, $toSubj);
        if ($conflicts) {
            $this->fail('That class already has ' . implode(', ', $conflicts) . '. Pick an empty class to tag into.');
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
            /* Ang tunay na banggaan ay nahuhuli na ng targetConflicts() sa itaas;
               kung may nakalusot pa rin, hindi hilaw na SQL ang dapat makita. */
            error_log('eGradeBook retag failed: ' . $e);
            $this->fail('Could not re-tag this class. Please try again.');
        }
    }
}
