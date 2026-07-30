<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_categories — weighted categories per term (Midterm/Final), used by
   Option B term-based grading. Scoped per class. */
class CategoryRepo
{
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
        $defaults = [['Quiz', 20], ['Activity', 30], ['Attendance', 10], ['Exam', 40]];
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
