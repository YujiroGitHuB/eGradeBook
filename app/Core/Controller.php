<?php

namespace App\Core;

/* ============================================================
   Controller — base for every API controller. Holds the shared
   Database + the logged-in teacher's owner_id (all grading data is
   scoped per teacher). Provides small request/response helpers so
   the concrete controllers stay thin.
   ============================================================ */
abstract class Controller
{
    /* The class scope (school_year, semester, section, subject) for this
       request. Empty fields = the legacy class, so old callers that only
       send `section` keep working unchanged. */
    protected function classScope(): ClassScope
    {
        return ClassScope::fromRequest();
    }

    protected Database $db;
    protected int $ownerId;

    public function __construct(Database $db, int $ownerId)
    {
        $this->db = $db;
        $this->ownerId = $ownerId;
    }

    /* ── response ── */
    protected function json(array $data): void
    {
        echo json_encode($data);
    }
    protected function ok(array $extra = []): void
    {
        $this->json(['success' => true] + $extra);
    }
    protected function fail(string $message): void
    {
        $this->json(['success' => false, 'message' => $message]);
    }

    /* ── request input ── */
    protected function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }
    protected function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }
}
