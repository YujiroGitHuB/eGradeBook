<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   ClassRepo — cross-class operations. Currently: re-tag, which
   relabels every class-scoped row for a section from one scope
   (school_year/semester/subject) to another — e.g. naming an
   untagged (legacy) gradebook as "SY 2025-2026 · 1st · CS101".
   Nothing is copied; the rows are moved (their scope columns are
   updated) in one transaction.
   ============================================================ */
class ClassRepo
{
    /* every class-scoped table (the snapshot included) */
    private const SCOPED_TABLES = [
        'grade_activities', 'grade_categories', 'grade_settings',
        'grade_form_meta', 'grade_attendance_meta', 'grade_student_status',
        'grade_roster_snapshot',
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Register a named class so it appears in the Class dropdown even before it
       has any activities. No-op for the legacy class ('', '', ''). Idempotent. */
    public function register(string $section, string $sy, string $sem, string $subj): void
    {
        if ($sy === '' && $sem === '' && $subj === '') return; // never register the legacy class
        $admin = $this->ownerId;
        $stmt = $this->db->prepare("INSERT IGNORE INTO grade_classes (owner_id, section, school_year, semester, subject) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('issss', $admin, $section, $sy, $sem, $subj);
        $stmt->execute();
        $stmt->close();
    }

    /* The named classes for a section — the registry UNION any class already
       present in grade_activities (so pre-registry classes still show up). */
    public function listForSection(string $section): array
    {
        $admin = $this->ownerId;
        $stmt = $this->db->prepare(
            "SELECT school_year, semester, subject FROM grade_classes WHERE owner_id=? AND section=?
             UNION
             SELECT DISTINCT school_year, semester, subject FROM grade_activities
             WHERE owner_id=? AND section=? AND (school_year<>'' OR semester<>'' OR subject<>'')
             ORDER BY school_year DESC, semester ASC, subject ASC"
        );
        $stmt->bind_param('isis', $admin, $section, $admin, $section);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = ['school_year' => $r['school_year'], 'semester' => $r['semester'], 'subject' => $r['subject']];
        }
        $stmt->close();
        return $out;
    }

    /* Does the target class already have manual activities? Used to refuse a
       re-tag that would merge into / collide with an existing class. */
    public function classHasActivities(string $section, string $sy, string $sem, string $subj): bool
    {
        $admin = $this->ownerId;
        $stmt = $this->db->prepare("SELECT id FROM grade_activities
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? LIMIT 1");
        $stmt->bind_param('issss', $admin, $section, $sy, $sem, $subj);
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $has;
    }

    /* Move every class-scoped row for (section, from-scope) → (to-scope) in one
       transaction. Returns the number of activities moved. Throws on failure
       (e.g. a key collision), leaving everything untouched. */
    public function retag(string $section, string $fromSy, string $fromSem, string $fromSubj, string $toSy, string $toSem, string $toSubj): int
    {
        $conn = $this->db->conn;
        $admin = $this->ownerId;
        $conn->begin_transaction();
        try {
            $moved = 0;
            foreach (self::SCOPED_TABLES as $t) {
                // $t is from a fixed whitelist, not user input
                $stmt = $conn->prepare("UPDATE `$t` SET school_year=?, semester=?, subject=?
                    WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
                $stmt->bind_param('sssissss', $toSy, $toSem, $toSubj, $admin, $section, $fromSy, $fromSem, $fromSubj);
                $stmt->execute();
                if ($t === 'grade_activities') $moved = $stmt->affected_rows;
                $stmt->close();
            }
            $conn->commit();
            return $moved;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
