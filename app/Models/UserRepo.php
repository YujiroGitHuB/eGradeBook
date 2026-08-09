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
        /* Ang `avatar` ay idinagdag ng FormFlow sa sarili nitong migration, at
           hindi natin mapapatakbo iyon mula rito. Kung hindi pa ito umiiral sa
           install na ito, ang pagbanggit dito ay magpapabagsak sa BUONG QUERY —
           ibig sabihin, hindi na makakapasok kahit sino dahil lang sa larawan.
           Kaya tinitingnan muna; kapag wala, tuloy pa rin ang login nang walang
           larawan (tingnan ang Auth::avatarUrl). */
        $hasAvatar = $this->db->hasCol('admin_users', 'avatar', FORMFLOW_DB);
        $cols = 'id, username, password, full_name, role' . ($hasAvatar ? ', avatar' : '');
        $stmt = $this->db->prepare(
            "SELECT $cols FROM " . FORMFLOW_DB . ".admin_users WHERE username = ?"
        );
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $user ?: null;
    }

    /* Ang naka-imbak na avatar path ng isang account ('' kung wala).
       Para sa mga session na naunang nabuo bago pa naging bahagi ng login
       ang larawan — nang hindi kailangang mag-log out muna ang guro. */
    public function avatarOf(int $adminId): string
    {
        if ($adminId <= 0 || !$this->db->hasCol('admin_users', 'avatar', FORMFLOW_DB)) return '';
        $stmt = $this->db->prepare("SELECT avatar FROM " . FORMFLOW_DB . ".admin_users WHERE id = ?");
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string)($row['avatar'] ?? '');
    }
}
