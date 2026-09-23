<?php

namespace App\Core;

use mysqli;

/* ============================================================
   Database — single mysqli connection to the MySQL server that
   hosts all three databases (egradebook_db + the FormFlow &
   attendance bridges). The cross-DB `db`.`table` queries in the
   repositories depend on this being ONE server; see inc/db.php
   for the bridge assumption.
   ============================================================ */
class Database
{
    public mysqli $conn;

    public function __construct()
    {
        /* Nag-THROW, hindi nag-e-echo. Dati ay JSON ang isinusulat nito at
           agad na exit — kaya kahit PAGE load ay hubad na JSON blob ang lumalabas
           (kasama pa ang connect_error, na may host/user). Ang tumatawag ang
           bahalang magpasya kung JSON ba o HTML ang nababagay; tingnan ang
           index.php at login.php. */
        $this->conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);

        if ($this->conn->connect_error) {
            throw new \RuntimeException('DB connection failed: ' . $this->conn->connect_error);
        }

        /* Auto-create eGradeBook's own database (idempotent).
           Naka-off ito sa shared hosting (DB_AUTO_CREATE=false sa .env):
           walang CREATE DATABASE privilege doon ang MySQL user — ang
           control panel ang gumagawa ng database — kaya isa itong query
           na tiyak na babagsak sa BAWAT request. Ang select_db sa ibaba
           ang tunay na tseke; iyon ang magsasabi kung wala talaga. */
        if (!defined('DB_AUTO_CREATE') || DB_AUTO_CREATE) {
            $this->conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }

        if (!$this->conn->select_db(DB_NAME)) {
            throw new \RuntimeException('Cannot select DB: ' . $this->conn->error);
        }

        $this->conn->set_charset('utf8mb4');

        /* ── TIMEZONE — Philippine Standard Time (UTC+8) bilang default ──
           Dito lang ito itinatakda, para sabay ang PHP at ang MySQL session
           (tingnan ang Conventions sa CLAUDE.md). Ang offset ay hinahango sa
           mismong timezone, hindi hardcoded na '+08:00', kaya ang pagpapalit
           ng APP_TIMEZONE sa .env ay hindi nag-iiwan ng MySQL na nasa ibang
           oras kaysa sa PHP. */
        $tz = defined('APP_TIMEZONE') && APP_TIMEZONE !== '' ? APP_TIMEZONE : 'Asia/Manila';
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable $e) {
            // Maling pangalan sa .env — huwag ipabagsak ang buong app dahil doon.
            error_log('eGradeBook: unknown APP_TIMEZONE "' . $tz . '", falling back to Asia/Manila');
            $zone = new \DateTimeZone('Asia/Manila');
        }
        date_default_timezone_set($zone->getName());
        $offset = (new \DateTime('now', $zone))->format('P');
        $stmt = $this->conn->prepare("SET time_zone = ?");
        $stmt->bind_param('s', $offset);
        $stmt->execute();
        $stmt->close();
    }

    /* ── thin mysqli passthroughs ── */
    public function query(string $sql)
    {
        return $this->conn->query($sql);
    }
    public function prepare(string $sql)
    {
        return $this->conn->prepare($sql);
    }
    public function escape(string $s): string
    {
        return $this->conn->real_escape_string($s);
    }
    public function error(): string
    {
        return $this->conn->error;
    }
    public function insertId(): int
    {
        return (int)$this->conn->insert_id;
    }
    public function begin(): void
    {
        $this->conn->begin_transaction();
    }
    public function commit(): void
    {
        $this->conn->commit();
    }
    public function rollback(): void
    {
        $this->conn->rollback();
    }
    public function close(): void
    {
        $this->conn->close();
    }

    /* Quick check: is a bridged database accessible? (flagged to the user,
       not fatal — same intent as the old gradingSystem_canAccess helper.) */
    public function canAccess(string $dbName): bool
    {
        $r = $this->conn->query("SHOW DATABASES LIKE '" . $this->conn->real_escape_string($dbName) . "'");
        return $r && $r->num_rows > 0;
    }

    /* Does $col exist in $table? Optional $db for cross-db tables
       (e.g. hasCol('form_responses', 'penalty_score', FORMFLOW_DB)).

       Isang TANONG ito, hindi isang utos: "puwede ko bang banggitin ang
       hanay na ito?" Kaya ang bawat pagkabigo ay `false`, hindi pagsabog.
       Ang `$r &&` sa dulo ay sapat noong nagbabalik ng false ang mysqli
       kapag pumalya — pero mula PHP 8.1 ay EXCEPTION na ang default
       (MYSQLI_REPORT_ERROR|STRICT), kaya ang parehong pagkabigo ay
       umaakyat na ngayon bilang fatal.

       At hindi ito teoretikal: sa shared hosting, ang SHOW COLUMNS sa
       database ng FormFlow ay tinatanggihan kapag walang SELECT doon ang
       MySQL user — kaya ang tsek na ginawa para HINDI mabasag ang login
       ay siya mismong bumasag nito, na may puting 500. Ang totoong query
       sa susunod ay babagsak pa rin, pero doon na ito hahawakan, kung
       saan may mababasang mensaheng maibibigay. */
    public function hasCol(string $table, string $col, ?string $db = null): bool
    {
        $t = $this->conn->real_escape_string($table);
        $c = $this->conn->real_escape_string($col);
        try {
            if ($db !== null) {
                $d = $this->conn->real_escape_string($db);
                $r = $this->conn->query("SHOW COLUMNS FROM `$d`.`$t` LIKE '$c'");
            } else {
                $r = $this->conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
            }
        } catch (\Throwable $e) {
            error_log('eGradeBook hasCol(' . ($db !== null ? $db . '.' : '') . $table . '.' . $col . ') failed: ' . $e->getMessage());
            return false;
        }
        return $r && $r->num_rows > 0;
    }

    /* Does $col exist in $table (current DB), via information_schema?
       Used by the idempotent migrations in App\Core\Schema. */
    public function colExists(string $table, string $col): bool
    {
        $r = $this->conn->query("SELECT COUNT(*) c FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'");
        return $r && ($x = $r->fetch_assoc()) && (int)$x['c'] > 0;
    }
}
