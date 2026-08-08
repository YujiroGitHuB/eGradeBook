<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Models\FormRepo;
use App\Models\FormMetaRepo;
use App\Models\FormSubjectRepo;

/* Unified column ordering (activities + forms + attendance) and the
   FormFlow-form grading overlay (grade_form_meta), scoped per class. */
class ColumnController extends Controller
{
    /* REORDER COLUMNS — `order` is a JSON array of column keys ("a12","f7",
       "att") in the new left-to-right order. Activities persist to
       grade_activities (by id); forms to grade_form_meta and attendance to
       grade_attendance_meta (both keyed per class, so the upserts carry the
       full scope). One shared position counter keeps every table on a single
       ordering scale. `att` is special-cased before the numeric-id parse. */
    public function reorder(): void
    {
        $conn     = $this->db->conn;
        $admin_id = $this->ownerId;

        $scope = $this->classScope();
        $order = json_decode($_POST['order'] ?? '[]', true);
        if (!$scope->hasSection() || !is_array($order) || !$order) {
            $this->fail('No order provided.');
            return;
        }
        $section = $scope->section;
        $sy   = $scope->schoolYear;
        $sem  = $scope->semester;
        $subj = $scope->subject;

        $uAct = $conn->prepare("UPDATE grade_activities SET sort_order=? WHERE id=? AND owner_id=?");
        $uFrm = $conn->prepare(
            "INSERT INTO grade_form_meta (owner_id, section, form_id, school_year, semester, subject, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
        );
        $uAtt = $conn->prepare(
            "INSERT INTO grade_attendance_meta (owner_id, section, school_year, semester, subject, sort_order) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)"
        );
        $pos = 1;
        foreach ($order as $ck) {
            $ck = (string)$ck;
            /* attendance column ("att") — the single auto column, checked
               first because its key starts with 'a' but has no numeric id */
            if ($ck === 'att') {
                $uAtt->bind_param('issssi', $admin_id, $section, $sy, $sem, $subj, $pos);
                $uAtt->execute();
                $pos++;
                continue;
            }
            $id = intval(substr($ck, 1));
            if ($id <= 0) {
                $pos++;
                continue;
            }
            if ($ck[0] === 'a') {
                $uAct->bind_param('iii', $pos, $id, $admin_id);
                $uAct->execute();
            } elseif ($ck[0] === 'f') {
                $uFrm->bind_param('isisssi', $admin_id, $section, $id, $sy, $sem, $subj, $pos);
                $uFrm->execute();
            }
            $pos++;
        }
        $uAct->close();
        $uFrm->close();
        $uAtt->close();
        $this->ok();
    }

    /* SET FORM META — upsert the eGradeBook overlay for a FormFlow form in
       this class. Only the keys present in the request change (partial update). */
    public function setFormMeta(): void
    {
        $scope = $this->classScope();
        $fid   = intval($_POST['form_id'] ?? 0);
        if (!$scope->hasSection() || !$fid) {
            $this->fail('Missing form or section.');
            return;
        }
        if (!(new FormRepo($this->db, $this->ownerId))->owns($fid)) {
            $this->fail('Not allowed.');
            return;
        }
        $meta = new FormMetaRepo($this->db, $this->ownerId);
        $meta->ensureRow($scope, $fid);

        if (array_key_exists('term', $_POST)) {
            $fTerm = in_array($_POST['term'], ['midterm', 'final', ''], true) ? $_POST['term'] : '';
            $meta->setTerm($scope, $fid, $fTerm);
        }
        if (array_key_exists('category_id', $_POST)) {
            $fCat = ($_POST['category_id'] === '' ? null : intval($_POST['category_id']));
            $meta->setCategory($scope, $fid, $fCat);
        }
        if (array_key_exists('weight', $_POST)) {
            $fWt = max(0, (float)$_POST['weight']);
            $meta->setWeight($scope, $fid, $fWt);
        }
        /* Itago / ibalik sa klaseng ito — para hindi lumabas sa isang klase ang
           form ng ibang subject sa parehong section (per-section ang FormFlow). */
        if (array_key_exists('hidden', $_POST)) {
            $meta->setHidden($scope, $fid, (int)$_POST['hidden'] === 1);
        }
        $this->ok();
    }

    /* SET FORM SUBJECT — iangkin ang form para sa isang subject sa section na ito.
       Hindi tulad ng `hidden` (per-klase), minsanan lang ito: awtomatikong nakatago
       ang form sa lahat ng klaseng iba ang subject, pati sa mga gagawin pa lang.
       Blangko = alisin ang pag-angkin. */
    public function setFormSubject(): void
    {
        $scope = $this->classScope();
        $fid   = intval($_POST['form_id'] ?? 0);
        if (!$scope->hasSection() || !$fid) {
            $this->fail('Missing form or section.');
            return;
        }
        if (!(new FormRepo($this->db, $this->ownerId))->owns($fid)) {
            $this->fail('Not allowed.');
            return;
        }
        $subject = trim((string)($_POST['owned_subject'] ?? ''));
        (new FormSubjectRepo($this->db, $this->ownerId))->set($scope->section, $fid, $subject);
        $this->ok();
    }

    /* COPY FORM VISIBILITY — dalhin sa klaseng ito ang mga nakatagong form ng ibang
       klase sa parehong section. Ang pinagkukunan ay isang klase (school_year /
       semester / subject), hindi section — kabaligtaran ng `copy_activities`, dahil
       per-section ang pagtuklas ng forms kaya sa loob lang ng iisang section ito
       may saysay. Merge lang: walang naibabalik na dating itinago. */
    public function copyFormVisibility(): void
    {
        $scope = $this->classScope();
        if (!$scope->hasSection()) {
            $this->fail('Select a section first.');
            return;
        }
        $fromSy   = trim((string)($_POST['from_school_year'] ?? ''));
        $fromSem  = trim((string)($_POST['from_semester'] ?? ''));
        $fromSubj = trim((string)($_POST['from_subject'] ?? ''));
        if ($fromSy === $scope->schoolYear && $fromSem === $scope->semester && $fromSubj === $scope->subject) {
            $this->fail('Source and target classes are the same.');
            return;
        }
        $n = (new FormMetaRepo($this->db, $this->ownerId))->copyHiddenFrom($scope, $fromSy, $fromSem, $fromSubj);
        $this->ok(['copied' => $n]);
    }
}
