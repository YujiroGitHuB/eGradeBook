<?php

namespace App\Models;

use App\Core\Database;

/* grade_pinned_sections — the subset of sections a teacher pins to the picker. */
class PinnedRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    public function list(): array
    {
        $admin_id = $this->ownerId;
        $pinned = [];
        $rp = $this->db->prepare("SELECT section FROM grade_pinned_sections WHERE owner_id = ?");
        $rp->bind_param('i', $admin_id);
        $rp->execute();
        $rr = $rp->get_result();
        while ($row = $rr->fetch_assoc()) $pinned[] = $row['section'];
        $rp->close();
        return $pinned;
    }

    /* Replace the whole pinned set in one transaction; throws on failure. */
    public function replaceAll(array $list): void
    {
        $conn = $this->db->conn;
        $admin_id = $this->ownerId;
        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM grade_pinned_sections WHERE owner_id = ?");
            $del->bind_param('i', $admin_id);
            $del->execute();

            if ($list) {
                $ins = $conn->prepare("INSERT IGNORE INTO grade_pinned_sections (owner_id, section) VALUES (?, ?)");
                foreach ($list as $sec) {
                    $sec = trim((string)$sec);
                    if ($sec === '') continue;
                    $ins->bind_param('is', $admin_id, $sec);
                    $ins->execute();
                }
            }
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
