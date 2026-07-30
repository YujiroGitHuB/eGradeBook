<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\RosterRepo;
use App\Models\ActivityRepo;
use App\Models\PinnedRepo;
use App\Models\SubjectRepo;

/* Sections list (from the attendance roster) + the teacher's pinned subset,
   plus the class-scope pickers' option lists (subjects, school years). */
class SectionController extends Controller
{
    /* Distinct subjects for a section (from the attendance scans) — feeds the
       Subject picker. Free-text subjects are also allowed on the frontend. */
    public function subjects(): void
    {
        $section = trim($this->get('section', ''));
        if ($section === '') {
            $this->ok(['subjects' => []]);
            return;
        }
        $subjects = (new SubjectRepo($this->db))->forSection($section);
        $this->ok(['subjects' => $subjects]);
    }

    /* School years this teacher has already used — feeds the SY picker. */
    public function schoolYears(): void
    {
        $years = (new ActivityRepo($this->db, $this->ownerId))->schoolYears();
        $this->ok(['school_years' => $years]);
    }

    /* All sections with course + headcount. */
    public function sections(): void
    {
        $sections = (new RosterRepo($this->db))->sections();
        $this->ok(['sections' => $sections]);
    }

    /* Only sections where this teacher already has activities. */
    public function mySections(): void
    {
        $mine = (new ActivityRepo($this->db, $this->ownerId))->sectionsWithActivities();
        $this->ok(['sections' => $mine]);
    }

    public function pinnedSections(): void
    {
        $pinned = (new PinnedRepo($this->db, $this->ownerId))->list();
        $this->ok(['pinned' => $pinned]);
    }

    /* Replace the whole pinned set. */
    public function savePinnedSections(): void
    {
        $raw  = $this->post('sections', '[]');
        $list = json_decode($raw, true);
        if (!is_array($list)) $list = [];

        try {
            (new PinnedRepo($this->db, $this->ownerId))->replaceAll($list);
            $this->ok(['count' => count($list)]);
        } catch (\Throwable $e) {
            $this->fail('Could not save: ' . $e->getMessage());
        }
    }
}
