<?php

namespace App\Core;

use App\Controllers\SectionController;
use App\Controllers\SheetController;
use App\Controllers\ActivityController;
use App\Controllers\ColumnController;
use App\Controllers\AttendanceController;
use App\Controllers\SettingsController;
use App\Controllers\TransmuteController;
use App\Controllers\CategoryController;
use App\Controllers\StatusController;
use App\Controllers\ClassController;

/* ============================================================
   Router — maps a `?api=` action to [ControllerClass, method]. Keeps
   the exact same action names the old switch($api) used, so the
   frontend (assets/js/grades.js, which only ever calls index.php)
   is untouched. Unknown actions return the same JSON as before.
   ============================================================ */
class Router
{
    /* action => [Controller::class, 'method'] */
    private const MAP = [
        // sections & pinned
        'sections'              => [SectionController::class, 'sections'],
        'my_sections'           => [SectionController::class, 'mySections'],
        'pinned_sections'       => [SectionController::class, 'pinnedSections'],
        'save_pinned_sections'  => [SectionController::class, 'savePinnedSections'],
        'subjects'              => [SectionController::class, 'subjects'],
        'school_years'          => [SectionController::class, 'schoolYears'],
        // the grading matrix
        'sheet'                 => [SheetController::class, 'sheet'],
        // activities
        'add_activity'          => [ActivityController::class, 'add'],
        'edit_activity'         => [ActivityController::class, 'edit'],
        'delete_activity'       => [ActivityController::class, 'delete'],
        'save_activity_score'   => [ActivityController::class, 'saveScore'],
        'set_linked_activity'   => [ActivityController::class, 'setLinked'],
        'sync_activity_score'   => [ActivityController::class, 'syncScore'],
        'bulk_fill_activity'    => [ActivityController::class, 'bulkFill'],
        'import_activity_scores' => [ActivityController::class, 'importScores'],
        'reorder_activities'    => [ActivityController::class, 'reorder'],
        'copy_activities'       => [ActivityController::class, 'copy'],
        // unified columns (activities + forms + attendance)
        'reorder_columns'       => [ColumnController::class, 'reorder'],
        'set_form_meta'         => [ColumnController::class, 'setFormMeta'],
        // attendance overlay
        'set_attendance_enabled' => [AttendanceController::class, 'setEnabled'],
        'set_attendance_meta'   => [AttendanceController::class, 'setMeta'],
        // per-section toggles
        'set_use_defense'       => [SettingsController::class, 'setUseDefense'],
        'set_term_mode'         => [SettingsController::class, 'setTermMode'],
        // transmutation bands
        'get_transmute'         => [TransmuteController::class, 'getBands'],
        'save_transmute'        => [TransmuteController::class, 'save'],
        // categories (term mode)
        'save_category'         => [CategoryController::class, 'save'],
        'delete_category'       => [CategoryController::class, 'delete'],
        // per-student status overrides
        'set_student_status'    => [StatusController::class, 'setOne'],
        'set_students_status'   => [StatusController::class, 'setMany'],
        // class management
        'classes'               => [ClassController::class, 'classes'],
        'create_class'          => [ClassController::class, 'create'],
        'retag_class'           => [ClassController::class, 'retag'],
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    public function dispatch(string $action): void
    {
        if (!isset(self::MAP[$action])) {
            echo json_encode(['success' => false, 'message' => 'Unknown api: ' . $action]);
            return;
        }
        [$class, $method] = self::MAP[$action];
        $controller = new $class($this->db, $this->ownerId);
        $controller->$method();
    }
}
