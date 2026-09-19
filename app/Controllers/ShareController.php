<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\ShareRepo;

/* Shared ranking link (Class ranking ▸ Share link…). Owner- at class-scoped.
   Ang public na pagbasa ay nasa share.php, hindi rito — ang mga action na ito
   ay dumaraan pa rin sa login + access gate ng index.php. */
class ShareController extends Controller
{
    public function status(): void
    {
        $c = $this->classScope();
        if (!$c->hasSection()) {
            $this->fail('Open a section first.');
            return;
        }
        $this->ok(['link' => (new ShareRepo($this->db, $this->ownerId))->find($c)]);
    }

    public function save(): void
    {
        $c = $this->classScope();
        if (!$c->hasSection()) {
            $this->fail('Open a section first.');
            return;
        }
        $rows = json_decode((string)$this->post('rows', '[]'), true);
        if (!is_array($rows) || !$rows) {
            $this->fail('No one is ranked yet — there is nothing to share.');
            return;
        }
        try {
            $link = (new ShareRepo($this->db, $this->ownerId))->save(
                $c,
                [
                    'section'     => $c->section,
                    'class_label' => (string)$this->post('class_label', ''),
                    'term_mode'   => $this->post('term_mode', '0') === '1',
                ],
                $rows,
                [
                    'show_grades' => $this->post('show_grades', '0') === '1',
                    'short_names' => $this->post('short_names', '0') === '1',
                    'top_n'       => intval($this->post('top_n', 0)),
                    'expire_days' => intval($this->post('expire_days', 30)),
                ]
            );
            $this->ok(['link' => $link]);
        } catch (\Throwable $e) {
            error_log('eGradeBook share_ranking_save failed: ' . $e);
            $this->fail('Could not save the share link.');
        }
    }

    /* Lahat ng link ng guro + ang teacher link + ang mga klase ng kasalukuyang
       school year/semester (para sa "Update all sections"). Iisang tawag para
       sa buong "All sections" na bahagi ng modal. */
    public function overview(): void
    {
        $c = $this->classScope();
        $repo = new ShareRepo($this->db, $this->ownerId);
        $this->ok([
            'links'   => $repo->listAll(),
            'hub'     => $repo->hubToken(),
            'classes' => $repo->classesInTerm($c->schoolYear, $c->semester),
        ]);
    }

    public function createHub(): void
    {
        $this->ok(['hub' => (new ShareRepo($this->db, $this->ownerId))->createHub()]);
    }

    public function revokeHub(): void
    {
        (new ShareRepo($this->db, $this->ownerId))->revokeHub();
        $this->ok();
    }

    public function revoke(): void
    {
        $c = $this->classScope();
        if (!$c->hasSection()) {
            $this->fail('Open a section first.');
            return;
        }
        (new ShareRepo($this->db, $this->ownerId))->revoke($c);
        $this->ok();
    }
}
