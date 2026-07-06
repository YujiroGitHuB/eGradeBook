# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

eGradeBook is a **standalone PHP + MySQL app** (no framework, no build step, no
Composer/npm) that generates college grading sheets. It is deployed by dropping
the folder into a PHP web root (XAMPP-style) and browsing to it. There are no
tests, no linter, and no package manifest.

**Run it:** serve the folder under Apache/PHP (e.g. XAMPP `htdocs`) and open
`index.php`. The database (`egradebook_db`) is created automatically on first
request; connection defaults are `localhost` / `root` / no password (see
`inc/db.php`). To reset a single teacher's data, delete their rows (keyed by
`owner_id`) or drop the `grade_*` tables — they are recreated on next load.

Static assets are cache-busted at runtime via `filemtime()` query strings, so
edits to CSS/JS take effect on reload with no build.

## The cross-database bridge (most important architectural fact)

eGradeBook owns only its **grading** tables but reads live data from two sibling
apps' databases **on the same MySQL server** using cross-DB SQL (`` `db`.`table` ``):

- `egradebook_db` — this app's own tables (`grade_activities`, `grade_activity_scores`, `grade_categories`, `grade_settings`, `grade_transmute`, `grade_student_status`, `grade_pinned_sections`, `grade_form_meta`).
- `formflow_db` (`FORMFLOW_DB`) — **login accounts** (`admin_users`), plus `forms`, `form_questions`, `form_responses` that become auto-graded "form" columns.

`grade_form_meta` is an **overlay** on FormFlow form columns: the form's title,
points, and responses stay read-only in FormFlow, but this table lets a teacher
attach eGradeBook-side grading metadata (`term`, `category_id`, `weight`,
`sort_order`) so a form column can join term-mode/weighted grading and be
drag-reordered alongside manual activities. Keyed `(owner_id, section, form_id)`.
Column ordering is now **unified** across activities + forms (both carry
`sort_order`; the `reorder_columns` API and the `sheet` action's `uasort` keep
them on one scale). Grade math includes forms: `termGrade` and the weighted
branch of `courseworkGrade` in `grades.js` filter on
`type === 'activity' || type === 'form'`.
- `bcc_qr_attendance_db` (`ATTENDANCE_DB`, table `students_tbl`) — the **student roster** (sections, names, courses).

All three constants live in `inc/db.php`. This design breaks if the databases
move to separate physical servers — the joins would need a REST/replication
bridge instead. There is **no `admin_users` table here**; login in `login.php`
queries FormFlow's table directly via `password_verify`, so credentials stay in
sync with FormFlow automatically.

## Auth & access model

- `inc/auth.php` gates every page (redirects to `login.php` if no session).
- `index.php` additionally requires **`role === 'superadmin'`** — non-superadmins
  get a 403 for both page loads and API calls. Treat eGradeBook as superadmin-only.
- All grading data is scoped per teacher by `owner_id = $_SESSION['admin_id']`.
  Any new query touching `grade_*` tables must filter/insert with `owner_id`, and
  ownership-check helpers like `$ownsActivity()` guard mutations.

## index.php — single-file API + page

`index.php` (~1650 lines) is the whole app. Two responsibilities in one file:

1. **Schema bootstrap (top of file):** `CREATE TABLE IF NOT EXISTS` plus a series
   of idempotent inline migrations (checked via `information_schema.COLUMNS` or
   the `$colExists`/`addColIfMissing`/`hasCol` helpers in `inc/db.php`). New
   columns/tables are added here, not in a separate migration system.
2. **API layer:** any request with `?api=` (GET or POST) returns JSON and exits
   before the HTML renders. It's one big `switch ($api)`. Key actions: `sections`,
   `my_sections`, `sheet` (builds the whole grading matrix), `add_activity` /
   `edit_activity` / `delete_activity`, `save_activity_score`, `bulk_fill_activity`,
   `import_activity_scores`, `set_linked_activity`, `reorder_activities`,
   `save_category` / `delete_category`, `get_transmute` / `save_transmute`,
   `set_student_status` / `set_students_status`, `copy_activities`, and the
   `set_use_defense` / `set_term_mode` toggles.

The `sheet` action is the core read: it assembles `students` (roster), unified
`columns` (form columns from FormFlow + manual activity columns), and a
`scores[student_no][key]` map, which the frontend renders.

After the API `switch`, the rest of the file is the HTML page. Shared UI pieces
are in `components/` (`favico`, `footer`, `logoutModal`, `supportModal`).

## Frontend (`assets/js/`)

Vanilla JS, no framework. `grades.js` (~2260 lines) is the client for the whole
grading sheet: it calls `index.php?api=...`, holds state in a global `SHEET`
object, and computes grades client-side. Grading logic to preserve when editing:

- **Coursework grade:** if activities carry weights → weighted average
  `Σ(score/max × weight) ÷ Σweight × 100`; otherwise legacy points-based
  `Σscore ÷ Σmax × 100` (includes form columns).
- **Transmutation:** raw 0–100 → 1.00–5.00 point via editable bands
  (`TRANSMUTE`, seeded from the PH college default scale). This is the single
  source of truth for both flat and term grades; keep the PHP `$DEFAULT_EQUIV`
  in `index.php` and JS `DEFAULT_EQUIV` in `grades.js` consistent.
- **Two grading modes** per section: flat (coursework × 0.50 + defense × 0.50,
  see `CW_WEIGHT`/`DEF_WEIGHT`) vs. term mode (Midterm/Final with weighted
  categories), toggled by `term_mode` in `grade_settings`.
- **Status overrides** (INC/DRP/W) are an overlay in `grade_student_status`; they
  never modify scores.

`global.js` provides shared UI helpers (`showToast`, `escHtml`, theme toggle —
theme persisted in `localStorage` under `ff_theme`, shared with FormFlow).
`detection.js` warns users who open the app inside in-app browsers
(Messenger/Facebook/etc.) and depends on SweetAlert2.

## Conventions

- Comments are bilingual (English + Filipino) and heavily explain the bridge
  assumptions — keep new schema/bridge changes documented the same way.
- External deps are CDN `<link>`/`<script>` only (Bootstrap Icons, Google Fonts,
  SweetAlert2). No local vendored libraries.
- New DB columns: add an idempotent migration at the top of `index.php` rather
  than assuming a fresh schema — production tables already exist.
