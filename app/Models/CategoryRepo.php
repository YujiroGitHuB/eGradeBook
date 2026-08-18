<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_categories — weighted categories per term (Midterm/Final), used by
   Option B term-based grading. Scoped per class. */
class CategoryRepo
{
    /* Ang default na hanay ng category na inihahasik sa UNANG pagbukas ng term
       mode. Iisang pinagmulan ito ngayon: ginagamit ng seedDefaultsIfEmpty()
       para maghasik AT ng clearUntouchedDefaults() para makilala ang mga
       hindi pa nagagalaw na inihasik. Kung dalawang lugar ito, tahimik na
       hindi na makikilala ang default matapos baguhin ang isa sa kanila. */
    public const DEFAULTS = [['Quiz', 20], ['Activity', 30], ['Attendance', 10], ['Exam', 40]];

    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    public function update(int $cid, string $name, float $weight): void
    {
        $admin_id = $this->ownerId;
        $stmt = $this->db->prepare("UPDATE grade_categories SET name=?, weight=? WHERE id=? AND owner_id=?");
        $stmt->bind_param('sdii', $name, $weight, $cid, $admin_id);
        $stmt->execute();
        $stmt->close();
    }

    public function insert(ClassScope $c, string $term, string $name, float $weight): int
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('isssdsss', $admin_id, $sec, $term, $name, $weight, $sy, $sem, $sub);
        $stmt->execute();
        $newId = $stmt->insert_id;
        $stmt->close();
        return $newId;
    }

    /* owner_id of a category, or null if it doesn't exist (ownership check). */
    public function ownerOf(int $cid): ?int
    {
        $chk = $this->db->query("SELECT owner_id FROM grade_categories WHERE id=$cid");
        if (!$chk || !($cr = $chk->fetch_assoc())) return null;
        return (int)$cr['owner_id'];
    }

    /* Delete a category, first unassigning the activities & form columns using it.
       Keyed by category id (unique), so this is correctly class-independent. */
    public function deleteWithUnassign(int $cid): void
    {
        $admin_id = $this->ownerId;
        $this->db->query("UPDATE grade_activities SET category_id=NULL WHERE category_id=$cid AND owner_id=$admin_id");
        $this->db->query("UPDATE grade_form_meta SET category_id=NULL WHERE category_id=$cid AND owner_id=$admin_id");
        /* Ang attendance column ay may sariling category_id din. Kung hindi ito
           lilinisin, mananatili itong tumuturo sa burado nang category — at dahil
           ang termGrade() ay tumutugma lang sa mga umiiral na category, TAHIMIK
           itong mawawala sa term grade (walang error, iba na ang final grade,
           "cat?" lang ang makikita sa header). */
        $this->db->query("UPDATE grade_attendance_meta SET category_id=NULL WHERE category_id=$cid AND owner_id=$admin_id");
        $this->db->query("DELETE FROM grade_categories WHERE id=$cid AND owner_id=$admin_id");
    }

    public function countForClass(ClassScope $c): int
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare("SELECT COUNT(*) c FROM grade_categories
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?");
        $stmt->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($r['c'] ?? 0);
    }

    /* First enable of term mode for a class → seed the default categories. */
    public function seedDefaultsIfEmpty(ClassScope $c): void
    {
        if ($this->countForClass($c) !== 0) return;
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $defaults = self::DEFAULTS;
        $ins = $this->db->prepare("INSERT INTO grade_categories (owner_id, section, term, name, weight, sort_order, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach (['midterm', 'final'] as $tname) {
            $so = 0;
            foreach ($defaults as $d) {
                $ins->bind_param('isssdisss', $admin_id, $sec, $tname, $d[0], $d[1], $so, $sy, $sem, $sub);
                $ins->execute();
                $so++;
            }
        }
        $ins->close();
    }

    /* Alisin ang mga default na category na inihasik ng seedDefaultsIfEmpty() at
       HINDI PA nagagalaw — pareho pa rin ang pangalan AT timbang, at walang kahit
       isang activity, form column o attendance na nakakabit sa kanila.

       Kailangan ito ng "Copy from…". Kapag binuksan ang term mode BAGO mag-copy,
       naunang naihasik ang 8 default; ang pagkopya naman ay MERGE lang —
       nagdaragdag nang hindi bumubura. Kaya nagsasama ang inihasik at ang nakopya,
       at dahil buo pa rin ang timbang ng WALANG LAMANG category sa termGrade()
       (catPct = 0, pero dagdag pa rin sa totalW), tahimik na nababawasan ang term
       grade ng lahat — walang error, mukhang normal ang sheet.

       Ang term LANG na may dalang kapalit ang pinagmulan ang nililinis, kaya hindi
       tayo maiiwang walang kahit isang category kapag wala namang ikokopya. Ang
       nagalaw na (binagong pangalan o timbang, o may nakakabit nang column) ay
       hindi ginagalaw — trabaho na iyon ng guro, hindi na inihasik lang. */
    public function clearUntouchedDefaults(ClassScope $c, array $terms): int
    {
        if (!$terms) return 0;
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;

        /* kandidato: tugma ang pangalan AT timbang sa inihasik na default */
        $cand = [];
        $sel = $this->db->prepare("SELECT id, name, weight FROM grade_categories
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? AND term=?");
        foreach ($terms as $term) {
            $term = (string)$term;
            $sel->bind_param('isssss', $admin_id, $sec, $sy, $sem, $sub, $term);
            $sel->execute();
            $rs = $sel->get_result();
            while ($row = $rs->fetch_assoc()) {
                $nm = mb_strtolower(trim((string)$row['name']));
                $wt = (float)$row['weight'];
                foreach (self::DEFAULTS as $d) {
                    if ($nm === mb_strtolower($d[0]) && abs($wt - (float)$d[1]) < 0.005) {
                        $cand[] = (int)$row['id'];
                        break;
                    }
                }
            }
        }
        $sel->close();
        if (!$cand) return 0;

        /* ...pero huwag ang may nakakabit nang column. Interpolated IN (...) dahil
           hindi kayang i-bind ng mysqli ang listahan — int-cast ang bawat id, gaya
           ng ginagawa ng SheetRepo. TATLO ang table na may category_id, hindi dalawa
           (tingnan din ang deleteWithUnassign). */
        $idList = implode(',', array_map('intval', $cand));
        $used = [];
        foreach (['grade_activities', 'grade_form_meta', 'grade_attendance_meta'] as $t) {
            $q = $this->db->query("SELECT DISTINCT category_id FROM `$t`
                WHERE owner_id=$admin_id AND category_id IN ($idList)");
            if ($q) while ($u = $q->fetch_assoc()) $used[(int)$u['category_id']] = true;
        }
        $drop = array_values(array_filter($cand, fn($id) => !isset($used[$id])));
        if (!$drop) return 0;

        /* Walang nakaturo sa mga ito (kakasuri lang natin), kaya hindi na kailangan
           ang deleteWithUnassign() — walang uunassign. */
        $dropList = implode(',', array_map('intval', $drop));
        $this->db->query("DELETE FROM grade_categories WHERE owner_id=$admin_id AND id IN ($dropList)");
        return count($drop);
    }

    /* Kopyahin ang buong hanay ng category ng isang term papunta sa kabila
       (Midterm → Final o kabaligtaran), sa loob ng IISANG klase.

       MERGE, hindi mirror — at ito ang mahalagang pagpili:
         - Ang katulad ng PANGALAN (case-insensitive) sa target ay UPDATE lang
           ang weight at sort_order. Kaya ligtas itong ulitin, at hindi
           nadodoble ang "Quiz" tuwing pipindutin.
         - Ang wala pa sa target ay ini-INSERT.
         - Ang nasa target LANG (hal. "Project" na sa Final lang) ay HINDI
           binubura. Ang pagbura ng category ay nag-aalis ng kabit nito sa mga
           activity (tingnan ang deleteWithUnassign) — hindi iyon dapat
           mangyari nang hindi hinihingi. Kung lalampas sa 100% ang term dahil
           doon, sasabihin naman iyon ng total sa ulo ng modal.

       Nagbabalik ng ['added' => n, 'updated' => n]. */
    public function copyTerm(ClassScope $c, string $from, string $to): array
    {
        $conn     = $this->db->conn;
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;

        /* pinagmulan */
        $src = $conn->prepare(
            "SELECT name, weight, sort_order FROM grade_categories
              WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? AND term=?
              ORDER BY sort_order ASC, id ASC"
        );
        $src->bind_param('isssss', $admin_id, $sec, $sy, $sem, $sub, $from);
        $src->execute();
        $rows = [];
        $r = $src->get_result();
        while ($x = $r->fetch_assoc()) $rows[] = $x;
        $src->close();
        if (!$rows) return ['added' => 0, 'updated' => 0];

        /* ang meron na sa target, naka-index sa maliit na titik na pangalan */
        $existing = [];
        $tgt = $conn->prepare(
            "SELECT id, name FROM grade_categories
              WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? AND term=?"
        );
        $tgt->bind_param('isssss', $admin_id, $sec, $sy, $sem, $sub, $to);
        $tgt->execute();
        $tr = $tgt->get_result();
        while ($x = $tr->fetch_assoc()) $existing[mb_strtolower($x['name'])] = (int)$x['id'];
        $tgt->close();

        $conn->begin_transaction();
        try {
            $added = 0;
            $updated = 0;
            $upd = $conn->prepare("UPDATE grade_categories SET weight=?, sort_order=? WHERE id=? AND owner_id=?");
            $ins = $conn->prepare(
                "INSERT INTO grade_categories (owner_id, section, term, name, weight, sort_order, school_year, semester, subject)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            foreach ($rows as $row) {
                $name = $row['name'];
                $wt   = (float)$row['weight'];
                $so   = (int)$row['sort_order'];
                $key  = mb_strtolower($name);
                if (isset($existing[$key])) {
                    $cid = $existing[$key];
                    $upd->bind_param('diii', $wt, $so, $cid, $admin_id);
                    $upd->execute();
                    $updated++;
                } else {
                    $ins->bind_param('isssdisss', $admin_id, $sec, $to, $name, $wt, $so, $sy, $sem, $sub);
                    $ins->execute();
                    $added++;
                }
            }
            $upd->close();
            $ins->close();
            $conn->commit();
            return ['added' => $added, 'updated' => $updated];
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    /* Categories for the sheet payload (id, term, name, weight), scoped per class. */
    public function forSheet(ClassScope $c): array
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $categories = [];
        $stmtC = $this->db->prepare(
            "SELECT id, term, name, weight, sort_order FROM grade_categories
             WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=?
             ORDER BY term ASC, sort_order ASC, id ASC"
        );
        $stmtC->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $stmtC->execute();
        $cres = $stmtC->get_result();
        while ($c2 = $cres->fetch_assoc()) {
            $categories[] = [
                'id'     => (int)$c2['id'],
                'term'   => $c2['term'],
                'name'   => $c2['name'],
                'weight' => (float)$c2['weight'],
            ];
        }
        $stmtC->close();
        return $categories;
    }
}
