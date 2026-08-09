<?php

namespace App\Models;

use App\Core\Database;

/* ============================================================
   ResetRepo — ang "Clear all" ng isang guro: binubura ang LAHAT ng
   sariling grading data sa bawat section at bawat klase, para malinis
   na makapagsimula ulit.

   DALAWANG hangganan ang mahigpit dito:

   1. OWNER-SCOPED. Bawat DELETE ay may `owner_id = ?`. Kahit superadmin
      lang ang nakakapasok sa eGradeBook, maaaring higit sa isa sila —
      ang isa ay hindi bumubura ng gradebook ng iba. Walang TRUNCATE dito
      kahit kailan; wala ring paraan itong pilitin mula sa API.

   2. HINDI GINAGALAW ANG BRIDGE. Puro `grade_*` (egradebook_db) lang ang
      nasa listahan. Ang formflow_db (forms, sagot, admin_users) at ang
      bcc_qr_attendance_db (roster, QR scans) ay BINABASA lang ng app na
      ito — hindi kanya ang datos na iyon at hindi ito ang lugar para
      burahin iyon. Kaya buo pa rin ang roster at ang mga form pagkatapos
      ng reset; ang naaalis ay ang grading na ipinatong natin sa kanila.

   Hindi rin kasama ang `grade_schema_version` — pag-aari iyon ng
   Schema::migrate() at walang owner_id (tingnan ang CLAUDE.md).
   ============================================================ */
class ResetRepo
{
    /* table => label na nakikita ng guro. Ang pagkakasunod-sunod ay
       sinasadya: ang mga anak muna bago ang magulang. */
    private const OWNED_TABLES = [
        'grade_activities'      => 'activities',
        'grade_categories'      => 'grading categories',
        'grade_settings'        => 'grading settings',
        'grade_form_meta'       => 'form column setup',
        'grade_form_subject'    => 'form subject claims',
        'grade_attendance_meta' => 'attendance setup',
        'grade_student_status'  => 'student status overrides',
        'grade_roster_snapshot' => 'roster snapshots',
        'grade_classes'         => 'classes',
        'grade_pinned_sections' => 'pinned sections',
        'grade_transmute'       => 'transmutation bands',
    ];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Burahin ang lahat. Nagbabalik ng [label => bilang ng row] para sa mga
       may aktuwal na nabura. Iisang transaction — lahat o wala, kaya walang
       kalahating-linis na gradebook kung may pumalya sa gitna. */
    public function clearAll(): array
    {
        $conn  = $this->db->conn;
        $admin = $this->ownerId;

        $conn->begin_transaction();
        try {
            $deleted = [];

            /* Ang mga score ay walang owner_id — nakasabit sila sa activity.
               May ON DELETE CASCADE naman ang FK, pero tahasan natin silang
               burahin muna: hindi ipinatutupad ang FK kung MyISAM pala ang
               table, at ang tahimik na maiiwang score ay magiging ulila na
               kakabit sa id ng bagong activity balang-araw. Ligtas ito kahit
               gumana ang cascade — wala na lang matatanggal nito. */
            $sc = $conn->prepare(
                "DELETE s FROM grade_activity_scores s
                   JOIN grade_activities a ON a.id = s.activity_id
                  WHERE a.owner_id = ?"
            );
            $sc->bind_param('i', $admin);
            $sc->execute();
            if ($sc->affected_rows > 0) $deleted['scores'] = $sc->affected_rows;
            $sc->close();

            foreach (self::OWNED_TABLES as $t => $label) {
                // $t ay galing sa fixed whitelist sa itaas, hindi user input
                $stmt = $conn->prepare("DELETE FROM `$t` WHERE owner_id = ?");
                $stmt->bind_param('i', $admin);
                $stmt->execute();
                if ($stmt->affected_rows > 0) $deleted[$label] = $stmt->affected_rows;
                $stmt->close();
            }

            $conn->commit();
            return $deleted;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
