<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   SubjectRepo — BRIDGE into the attendance DB for the subject list.
   `bcc_qr_attendance_db.attendance_tbl` already records `subject`
   per scan, so the subject options for a section come straight from
   real data (a teacher may also type a custom one on the frontend).
   ============================================================ */
class SubjectRepo
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /* Distinct non-empty subjects seen for a section, alphabetical. */
    public function forSection(string $section): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT subject FROM " . ATTENDANCE_DB . ".attendance_tbl
             WHERE section = ? AND subject IS NOT NULL AND subject <> ''
             ORDER BY subject"
        );
        $stmt->bind_param('s', $section);
        $stmt->execute();
        $res = $stmt->get_result();
        $subjects = [];
        while ($r = $res->fetch_assoc()) $subjects[] = $r['subject'];
        $stmt->close();
        return $subjects;
    }
}
