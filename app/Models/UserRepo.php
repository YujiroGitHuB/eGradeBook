<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   UserRepo — BRIDGE into FormFlow's admin_users (login accounts).
   eGradeBook has NO admin_users of its own; it reads FormFlow's
   table directly on the same MySQL server, so a password changed in
   FormFlow reflects here immediately (single source of truth).
   ============================================================ */
class UserRepo
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, username, password, full_name, role FROM " . FORMFLOW_DB . ".admin_users WHERE username = ?"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $user ?: null;
    }
}
