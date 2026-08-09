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
    /* LAMAN — ang tinipang gradebook mismo. Ito lang ang hindi kayang buuing
       muli sa isang klik, kaya ito lang ang humahadlang sa pagbura ng klase.
       (Ang mga score ay nakasabit sa activity_id, kaya kasama na sila rito.) */
    private const CONTENT_TABLES = [
        'grade_activities'      => 'activities',
        'grade_student_status'  => 'student status overrides',
    ];

    /* SETUP — mga kahon at toggle: kung paano bibilangin ang laman, hindi ang
       laman mismo. Nabubuo ang row na ito sa isang tsek lang (Attendance,
       Term grading) o sa pagtatago ng isang form column — at ang ilan ay
       WALANG paraan sa UI para tanggalin (nananatili ang grade_form_meta kahit
       ipakita mong muli ang column, at ang grade_attendance_meta kahit alisin
       mo ang tsek). Kaya kapag ibinura ang klase, kasama nang nililinis ang mga
       ito sa halip na maging hadlang — dating naiiwang bitag ito: klaseng
       walang kahit isang activity pero hindi na mabura kailanman. */
    private const SETUP_TABLES = [
        'grade_categories'      => 'grading categories',
        'grade_settings'        => 'grading settings',
        'grade_form_meta'       => 'form column setup',
        'grade_attendance_meta' => 'attendance setup',
    ];

    /* Lahat ng class-scoped na table ng guro — ito ang inililipat nang buo ng
       retag(), at ito ang ipinagbabawal na mabanggaan doon (may mawawala kung
       papatungan). Ang paghahati sa laman/setup sa itaas ay para LANG sa
       pagbura ng klase. */
    private static function dataTables(): array
    {
        return self::CONTENT_TABLES + self::SETUP_TABLES;
    }

    /* grade_roster_snapshot ay SADYANG hiwalay. Hindi ito tinipa ng guro —
       kusang napupuno sa TUWING binubuksan ang isang tagged class (tingnan ang
       RosterRepo::topUpSnapshot). Kaya kung isasama ito sa hadlang, hindi na
       maire-retag ang isang klaseng nabuksan lang minsan; at kung basta itong
       ili-lipat, babangga sa PK. Minementeha natin ito: INSERT IGNORE ang mga
       row ng pinagmulan papunta sa target (nananaig ang naunang nakuha, kaya
       hindi nawawala ang estudyanteng wala na sa live roster), tapos burahin
       ang sa pinagmulan. Mabubuo muli naman ito sa susunod na pagbasa. */
    private const SNAPSHOT_TABLE = 'grade_roster_snapshot';

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

    /* Anong tunay na datos ang meron na ang target class? Nagbabalik ng mga
       nababasang label (walang laman = ligtas i-retag).

       Dating activities lang ang tinitingnan — pero anim na table ang inililipat,
       kaya ang isang klaseng may settings/category/form setup pero walang activity
       ay nakalulusot sa hadlang at bumabagsak sa hilaw na "Duplicate entry" mula
       sa MySQL. Dito na nahuhuli, nang may maayos na mensahe. */
    public function targetConflicts(string $section, string $sy, string $sem, string $subj): array
    {
        return $this->conflictsIn(self::dataTables(), $section, $sy, $sem, $subj);
    }

    /* Para sa PAGBURA ng klase: laman lang ang tinitingnan. Hindi hadlang ang
       setup — nililinis iyon ng deleteEmpty(). Tingnan ang SETUP_TABLES. */
    public function contentConflicts(string $section, string $sy, string $sem, string $subj): array
    {
        return $this->conflictsIn(self::CONTENT_TABLES, $section, $sy, $sem, $subj);
    }

    private function conflictsIn(array $tables, string $section, string $sy, string $sem, string $subj): array
    {
        $admin = $this->ownerId;
        $found = [];
        foreach ($tables as $t => $label) {
            // $t ay galing sa fixed whitelist, hindi user input
            $stmt = $this->db->prepare("SELECT 1 FROM `$t`
                WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? LIMIT 1");
            $stmt->bind_param('issss', $admin, $section, $sy, $sem, $subj);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) $found[] = $label;
            $stmt->close();
        }
        return $found;
    }

    /* Alisin ang klaseng WALANG LAMAN. Ang tumatawag ang dapat tumiyak muna sa
       contentConflicts() na wala nga itong activity o status override — hindi
       kailanman bumubura ng grado ang paraang ito.

       Ang naaalis: ang pangalan sa registry, ang kusang-nabuong roster snapshot
       (hindi tinipa ng guro; muling mabubuo kung mabubuksan ulit ang klase), at
       ang SETUP_TABLES — ang mga kahon at toggle na walang taglay na marka.
       Kung hindi lilinisin ang mga iyon, maiiwan silang ulila sa isang klaseng
       wala na, at ang klase mismo ay hindi kailanman mabubura.

       Nagbabalik ng mga label ng setup na aktuwal na nalinis (para may
       maisumbong sa guro). Iisang transaction — lahat o wala. */
    public function deleteEmpty(string $section, string $sy, string $sem, string $subj): array
    {
        $conn = $this->db->conn;
        $admin = $this->ownerId;

        $conn->begin_transaction();
        try {
            $cleared = [];
            foreach (self::SETUP_TABLES as $t => $label) {
                // $t ay galing sa fixed whitelist, hindi user input
                $stmt = $conn->prepare("DELETE FROM `$t`
                    WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
                $stmt->bind_param('issss', $admin, $section, $sy, $sem, $subj);
                $stmt->execute();
                if ($stmt->affected_rows > 0) $cleared[] = $label;
                $stmt->close();
            }

            $t = self::SNAPSHOT_TABLE;
            $snap = $conn->prepare("DELETE FROM `$t`
                WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
            $snap->bind_param('issss', $admin, $section, $sy, $sem, $subj);
            $snap->execute();
            $snap->close();

            $del = $conn->prepare("DELETE FROM grade_classes
                WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
            $del->bind_param('issss', $admin, $section, $sy, $sem, $subj);
            $del->execute();
            $del->close();

            $conn->commit();
            return $cleared;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
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
            foreach (array_keys(self::dataTables()) as $t) {
                // $t is from a fixed whitelist, not user input
                $stmt = $conn->prepare("UPDATE `$t` SET school_year=?, semester=?, subject=?
                    WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
                $stmt->bind_param('sssissss', $toSy, $toSem, $toSubj, $admin, $section, $fromSy, $fromSem, $fromSubj);
                $stmt->execute();
                if ($t === 'grade_activities') $moved = $stmt->affected_rows;
                $stmt->close();
            }

            /* Roster snapshot — merge, hindi basta lipat (tingnan ang SNAPSHOT_TABLE).
               Ang IGNORE ay nagpaparaya sa estudyanteng nasa magkabila: nananatili
               ang naunang nakuhang pangalan sa target, at hindi bumabagsak ang
               buong retag dahil lang nabuksan na minsan ang target. */
            $t = self::SNAPSHOT_TABLE;
            $ins = $conn->prepare(
                "INSERT IGNORE INTO `$t` (owner_id, school_year, semester, section, subject, student_no, fullname, course)
                 SELECT owner_id, ?, ?, ?, ?, student_no, fullname, course FROM `$t`
                  WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?"
            );
            $ins->bind_param('ssssissss', $toSy, $toSem, $section, $toSubj, $admin, $section, $fromSy, $fromSem, $fromSubj);
            $ins->execute();
            $ins->close();

            $del = $conn->prepare("DELETE FROM `$t`
                WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
            $del->bind_param('issss', $admin, $section, $fromSy, $fromSem, $fromSubj);
            $del->execute();
            $del->close();

            $conn->commit();
            return $moved;
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
