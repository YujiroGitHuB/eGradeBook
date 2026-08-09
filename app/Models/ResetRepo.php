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

    /* Burahin ang gradebook ng ISANG guro, o ng LAHAT kapag null ang
       $targetOwnerId (superadmin lang ang nakakaabot doon — tingnan ang
       ResetController). Nagbabalik ng [label => bilang ng row] para sa mga may
       aktuwal na nabura. Iisang transaction — lahat o wala, kaya walang
       kalahating-linis na gradebook kung may pumalya sa gitna.

       Kahit sa "lahat" ay DELETE pa rin, hindi TRUNCATE: hindi nairo-rollback
       ang TRUNCATE (DDL ito, at implicit commit), kaya ang isang pagkabigo sa
       gitna ay mag-iiwan ng kalahating-burang datos ng LAHAT ng guro nang
       walang balikan. */
    public function clearAll(?int $targetOwnerId): array
    {
        $conn = $this->db->conn;
        $all  = ($targetOwnerId === null);

        $conn->begin_transaction();
        try {
            $deleted = [];

            /* Ang mga score ay walang owner_id — nakasabit sila sa activity.
               May ON DELETE CASCADE naman ang FK, pero tahasan natin silang
               burahin muna: hindi ipinatutupad ang FK kung MyISAM pala ang
               table, at ang tahimik na maiiwang score ay magiging ulila na
               kakabit sa id ng bagong activity balang-araw. Ligtas ito kahit
               gumana ang cascade — wala na lang matatanggal nito. */
            if ($all) {
                $conn->query("DELETE FROM grade_activity_scores");
                if ($conn->affected_rows > 0) $deleted['scores'] = $conn->affected_rows;
            } else {
                $sc = $conn->prepare(
                    "DELETE s FROM grade_activity_scores s
                       JOIN grade_activities a ON a.id = s.activity_id
                      WHERE a.owner_id = ?"
                );
                $sc->bind_param('i', $targetOwnerId);
                $sc->execute();
                if ($sc->affected_rows > 0) $deleted['scores'] = $sc->affected_rows;
                $sc->close();
            }

            foreach (self::OWNED_TABLES as $t => $label) {
                // $t ay galing sa fixed whitelist sa itaas, hindi user input
                if ($all) {
                    $conn->query("DELETE FROM `$t`");
                    if ($conn->affected_rows > 0) $deleted[$label] = $conn->affected_rows;
                    continue;
                }
                $stmt = $conn->prepare("DELETE FROM `$t` WHERE owner_id = ?");
                $stmt->bind_param('i', $targetOwnerId);
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

    /* Sinong guro ang may laman na gradebook — para may mapipili ang superadmin,
       at para makita muna niya kung gaano kalaki ang buburahin bago pumindot.
       Ang pangalan ay galing sa FormFlow (walang sariling accounts ang app na
       ito); kung wala na roon ang account, ang id na lang ang ipapakita —
       naiwang datos iyon ng buradong account, at iyon nga ang dapat malinis. */
    public function ownersWithData(): array
    {
        /* Union sa LAHAT ng owned table, hindi sa grade_activities lang: ang
           gurong may naiwang settings o form setup pero walang kahit isang
           activity ay dapat pa ring mapili. Kung hindi, may datos na hindi
           kailanman maaabot ng paglilinis mula sa UI. */
        $parts = [];
        foreach (array_keys(self::OWNED_TABLES) as $t) $parts[] = "SELECT DISTINCT owner_id FROM `$t`";
        $counts = [];
        $r = $this->db->query(implode(' UNION ', $parts));
        if ($r) while ($x = $r->fetch_assoc()) $counts[(int)$x['owner_id']] = 0;
        if (!$counts) return [];

        /* Bilang ng activity kada guro — sukatan lang, para makita ng
           superadmin kung gaano kalaki ang buburahin bago pumindot. */
        $ar = $this->db->query("SELECT owner_id, COUNT(*) n FROM grade_activities GROUP BY owner_id");
        if ($ar) while ($x = $ar->fetch_assoc()) $counts[(int)$x['owner_id']] = (int)$x['n'];

        $names = [];
        $ids = implode(',', array_map('intval', array_keys($counts)));
        $nr = $this->db->query(
            "SELECT id, username, full_name FROM " . FORMFLOW_DB . ".admin_users WHERE id IN ($ids)"
        );
        if ($nr) {
            while ($x = $nr->fetch_assoc()) {
                $names[(int)$x['id']] = [
                    'username'  => (string)$x['username'],
                    'full_name' => (string)($x['full_name'] ?: $x['username']),
                ];
            }
        }

        $out = [];
        foreach ($counts as $id => $n) {
            $out[] = [
                'id'         => $id,
                'username'   => $names[$id]['username']  ?? ('#' . $id),
                'full_name'  => $names[$id]['full_name'] ?? ('Deleted account #' . $id),
                'activities' => $n,
                'orphan'     => !isset($names[$id]),
            ];
        }
        usort($out, fn($a, $b) => strcasecmp($a['full_name'], $b['full_name']));
        return $out;
    }
}
