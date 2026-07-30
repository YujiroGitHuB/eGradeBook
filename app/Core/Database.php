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
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS);

        if ($this->conn->connect_error) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'DB connection failed: ' . $this->conn->connect_error,
            ]);
            exit;
        }

        // Auto-create eGradeBook's own database (idempotent)
        $this->conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        if (!$this->conn->select_db(DB_NAME)) {
            echo json_encode(['success' => false, 'message' => 'Cannot select DB: ' . $this->conn->error]);
            exit;
        }

        $this->conn->set_charset('utf8mb4');

        // ── TIMEZONE — Philippine Standard Time (UTC+8) ──
        date_default_timezone_set('Asia/Manila');
        $this->conn->query("SET time_zone = '+08:00'");
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
       (e.g. hasCol('form_responses', 'penalty_score', FORMFLOW_DB)). */
    public function hasCol(string $table, string $col, ?string $db = null): bool
    {
        $t = $this->conn->real_escape_string($table);
        $c = $this->conn->real_escape_string($col);
        if ($db !== null) {
            $d = $this->conn->real_escape_string($db);
            $r = $this->conn->query("SHOW COLUMNS FROM `$d`.`$t` LIKE '$c'");
        } else {
            $r = $this->conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
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
