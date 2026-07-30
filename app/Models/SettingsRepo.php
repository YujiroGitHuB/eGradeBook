<?php

namespace App\Models;

use App\Core\Database;
use App\Core\ClassScope;

/* grade_settings — per-class toggles (use_defense, term_mode), scoped by
   (owner_id, section, school_year, semester, subject). */
class SettingsRepo
{
    private Database $db;
    private int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* Effective settings for the sheet: defaults use_defense=true, term_mode=false. */
    public function forSheet(ClassScope $c): array
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $useDefense = true;
        $termMode   = false;
        $stmt = $this->db->prepare("SELECT use_defense, term_mode FROM grade_settings
            WHERE owner_id=? AND section=? AND school_year=? AND semester=? AND subject=? LIMIT 1");
        $stmt->bind_param('issss', $admin_id, $sec, $sy, $sem, $sub);
        $stmt->execute();
        $sres = $stmt->get_result();
        if ($srow = $sres->fetch_assoc()) {
            $useDefense = (int)$srow['use_defense'] === 1;
            $termMode   = (int)$srow['term_mode'] === 1;
        }
        $stmt->close();
        return ['use_defense' => $useDefense, 'term_mode' => $termMode];
    }

    public function setUseDefense(ClassScope $c, int $val): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare(
            "INSERT INTO grade_settings (owner_id, section, use_defense, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE use_defense = VALUES(use_defense)"
        );
        $stmt->bind_param('isisss', $admin_id, $sec, $val, $sy, $sem, $sub);
        $stmt->execute();
        $stmt->close();
    }

    public function setTermMode(ClassScope $c, int $val): void
    {
        $admin_id = $this->ownerId;
        $sec = $c->section;
        $sy  = $c->schoolYear;
        $sem = $c->semester;
        $sub = $c->subject;
        $stmt = $this->db->prepare(
            "INSERT INTO grade_settings (owner_id, section, term_mode, school_year, semester, subject) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE term_mode = VALUES(term_mode)"
        );
        $stmt->bind_param('isisss', $admin_id, $sec, $val, $sy, $sem, $sub);
        $stmt->execute();
        $stmt->close();
    }
}
