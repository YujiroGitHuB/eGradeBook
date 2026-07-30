<?php

namespace App\Models;

use App\Core\Database;

/* grade_transmute — GLOBAL per teacher (one set of bands used by every
   section, both flat and term grading). Raw 0–100 → 1.00–5.00 point.
   Keep DEFAULT_EQUIV in sync with the JS DEFAULT_EQUIV in grades.js. */
class TransmuteRepo
{
    /* Default PH college scale (min raw score -> equivalent point). */
    public const DEFAULT_EQUIV = [
        [96, 1.00], [94, 1.25], [91, 1.50], [88, 1.75], [85, 2.00],
        [82, 2.25], [79, 2.50], [76, 2.75], [75, 3.00],
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* This teacher's bands, highest min first. Seeds the default PH scale
       on first use (empty table), per teacher. */
    public function load(): array
    {
        $conn = $this->db->conn;
        $admin_id = $this->ownerId;
        $rows = [];
        $r = $conn->query("SELECT min_score, point FROM grade_transmute WHERE owner_id=$admin_id ORDER BY min_score DESC");
        if ($r) while ($x = $r->fetch_assoc()) $rows[] = ['min' => (float)$x['min_score'], 'point' => (float)$x['point']];
        if (!$rows) {
            $ins = $conn->prepare("INSERT INTO grade_transmute (owner_id, min_score, point) VALUES (?, ?, ?)");
            foreach (self::DEFAULT_EQUIV as $b) {
                $mn = $b[0];
                $pt = $b[1];
                $ins->bind_param('idd', $admin_id, $mn, $pt);
                $ins->execute();
            }
            $ins->close();
            foreach (self::DEFAULT_EQUIV as $b) $rows[] = ['min' => (float)$b[0], 'point' => (float)$b[1]];
        }
        return $rows;
    }

    /* Replace the whole set of bands (validated & sorted by the caller) in
       one transaction; throws on failure. */
    public function replaceAll(array $clean): void
    {
        $conn = $this->db->conn;
        $admin_id = $this->ownerId;
        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM grade_transmute WHERE owner_id = ?");
            $del->bind_param('i', $admin_id);
            $del->execute();
            $ins = $conn->prepare("INSERT INTO grade_transmute (owner_id, min_score, point) VALUES (?, ?, ?)");
            foreach ($clean as $b) {
                $mn = $b['min'];
                $pt = $b['point'];
                $ins->bind_param('idd', $admin_id, $mn, $pt);
                $ins->execute();
            }
            $ins->close();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
