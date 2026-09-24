<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   AccessRepo — grade_app_access: kung sinong FormFlow account ang
   makakapasok sa eGradeBook.

   Hindi ito owner-scoped. Talaan ito ng buong app, hindi datos ng
   isang guro — kaya walang `owner_id` sa kahit aling query rito.
   Tanging superadmin ang nakakabasa/nakakasulat nito, at ang gate
   na iyon ay nasa AccessController (hindi rito).

   Ang listahan ng account mismo ay galing pa rin sa FormFlow
   (`admin_users`) — walang sariling accounts ang eGradeBook.
   Tingnan ang Schema.php para sa dahilan kung bakit kailangan ang
   talaang ito (magkahati sa session ang dalawang app).
   ============================================================ */
class AccessRepo
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /* Nasa allowlist ba ang account na ito? (Hiwalay ang panuntunang
       "laging pasado ang superadmin" — nasa Auth::requireAccess iyon.) */
    public function has(int $adminId): bool
    {
        if ($adminId <= 0) return false;
        $stmt = $this->db->prepare("SELECT 1 FROM grade_app_access WHERE admin_id = ? LIMIT 1");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }

    /* Bawat FormFlow account + kung may access na ba rito. Superadmin man o
       hindi, isinasama lahat: kailangang makita ng namamahala ang buong
       listahan, at ang `role` ang nagpapaliwanag kung bakit may ilang
       laging naka-on.

       Dalawang basa, hindi JOIN: nasa ibang database ang admin_users, at sa
       Hostinger ay ibang login ang nakakabasa roon (Database::formflow()) —
       walang iisang user na nakakakita ng dalawang table nang sabay. */
    public function listAccounts(): array
    {
        $granted = [];
        $g = $this->db->query("SELECT admin_id FROM grade_app_access");
        if ($g) while ($r = $g->fetch_assoc()) $granted[(int)$r['admin_id']] = true;

        $res = $this->db->formflow()->query(
            "SELECT id, username, full_name, role
               FROM " . FORMFLOW_DB . ".admin_users
              ORDER BY full_name ASC, username ASC"
        );
        $out = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $out[] = [
                    'id'        => (int)$r['id'],
                    'username'  => (string)$r['username'],
                    'full_name' => (string)($r['full_name'] ?: $r['username']),
                    'role'      => (string)($r['role'] ?? 'admin'),
                    'granted'   => isset($granted[(int)$r['id']]),
                ];
            }
        }
        return $out;
    }

    /* Tiyaking totoong FormFlow account ito bago bigyan ng access — kung
       hindi, maiiwan tayong may talaan ng mga id na walang katumbas. */
    public function accountExists(int $adminId): bool
    {
        if ($adminId <= 0) return false;
        $stmt = $this->db->formflow()->prepare("SELECT 1 FROM " . FORMFLOW_DB . ".admin_users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }

    public function grant(int $adminId, int $grantedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO grade_app_access (admin_id, granted_by) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE granted_by = VALUES(granted_by)"
        );
        $stmt->bind_param('ii', $adminId, $grantedBy);
        $stmt->execute();
        $stmt->close();
    }

    /* Inaalis lang ang pagpasok — HINDI ang gradebook. Nananatili ang lahat
       ng grade_* row na may owner_id na iyon, kaya buo pa rin ang lahat kung
       ibabalik ang access (o kung may kailangang i-export). Ang pagbura ay
       hiwalay na pasya, at ang "Clear all my data" ang para roon. */
    public function revoke(int $adminId): void
    {
        $stmt = $this->db->prepare("DELETE FROM grade_app_access WHERE admin_id = ?");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $stmt->close();
    }
}
