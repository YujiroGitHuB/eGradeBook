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

**Only two entry points:** `index.php` (front controller — page + all `?api=`
JSON) and `login.php`; `inc/logout.php` destroys the session. Every other PHP
file is reached through those.

**The only automated check available** is PHP's syntax linter — there is no test
suite, no linter config, no CI. Run it over the tree after editing PHP:

```bash
find app inc components index.php login.php -name '*.php' -exec php -l {} \;   # all PHP
php -l app/Models/SheetRepo.php                                                # one file
```

Everything else is verified by hand in the browser: load a section, check the
grading matrix and the computed grades, and watch the JSON in DevTools' Network
tab (each `?api=` action returns `{success: bool, ...}`).

## The cross-database bridge (most important architectural fact)

eGradeBook owns only its **grading** tables but reads live data from two sibling
apps' databases **on the same MySQL server** using cross-DB SQL (`` `db`.`table` ``):

- `egradebook_db` — this app's own tables (`grade_activities`, `grade_activity_scores`, `grade_categories`, `grade_settings`, `grade_transmute`, `grade_student_status`, `grade_pinned_sections`, `grade_form_meta`, `grade_attendance_meta`, plus the class-scoping pair `grade_classes` + `grade_roster_snapshot`). The full DDL is `app/Core/Schema.php` — read it rather than guessing at columns.
- `formflow_db` (`FORMFLOW_DB`) — **login accounts** (`admin_users`), plus `forms`, `form_questions`, `form_responses` that become auto-graded "form" columns.

`grade_form_meta` is an **overlay** on FormFlow form columns: the form's title,
points, and responses stay read-only in FormFlow, but this table lets a teacher
attach eGradeBook-side grading metadata (`term`, `category_id`, `weight`,
`sort_order`, `hidden`) so a form column can join term-mode/weighted grading and be
drag-reordered alongside manual activities. Keyed `(owner_id, section, form_id)`
— plus `school_year, semester, subject` since class scoping (below).

**Form-column scoping — the two levers for FormFlow's missing subject.** FormFlow
has no `subject` column at all (not on `forms`, not on `form_responses`), so form
columns can only be auto-discovered per **section** — and since the discovery query
has no date filter either, a reused section name (`BSIT-1A` exists every year) keeps
dragging old school years' forms into new classes. Both would otherwise show up as
columns *and count toward the grade*. There is no upstream data to filter on, so
both fixes are teacher-driven and eGradeBook-side:

1. **`grade_form_subject`** (`FormSubjectRepo`) — claims a form for one subject,
   keyed `(owner_id, section, form_id)`. **Deliberately NOT class-scoped** (like
   `grade_transmute` / `grade_pinned_sections`): one decision covers every class of
   the section, *including classes created later* — that's the whole point. Empty
   subject = unclaimed = visible everywhere.
2. **`grade_form_meta.hidden`** — a per-class override for the leftovers (old terms,
   one-offs). Must be repeated per class, which is what the `copy_form_visibility`
   action is for: it merges another class's hidden set into this one (same section
   only — forms are per-section). Merge, never mirror, so it is safe to re-run.

`SheetRepo::build()` resolves both **before** building form columns: explicit
`hidden = 1` wins, else a claim that disagrees with the class's subject hides it,
else visible. A hidden form never becomes a column and its scores never enter the
`scores` map (hence never the grade). **There is intentionally no "force show"** —
a claim pointing elsewhere is corrected by editing the claim, not by overriding it,
which is why `hidden` stayed `NOT NULL DEFAULT 0` instead of becoming tri-state.
The legacy class (empty subject) is never affected by lever 1, so untagged sheets
behave exactly as before. The build returns the dropped forms as `hidden_forms`
(`[{id,title,subject,reason}]`, `reason` = `manual` | `subject`) and puts the claim
on visible form columns as `owned_subject`, which drives the **Form columns modal**
(More ▸ Form columns…) in `grades.js`. Actions: `set_form_meta` (partial update,
carries `hidden`), plus `set_form_subject` and `copy_form_visibility`.
Column ordering is now **unified** across activities + forms + the attendance
column (all carry `sort_order`; the `reorder_columns` API and the `sheet`
action's `uasort` keep them on one scale — `reorder_columns` special-cases the
`att` key before its numeric-id parse). Grade math includes forms **and
attendance**: `termGrade` and the weighted branch of `courseworkGrade` in
`grades.js` filter on `type === 'activity' || type === 'form' || type === 'attendance'`.

`grade_attendance_meta` is another **overlay**, this time on an auto column
computed from the QR attendance scans: one optional "Attendance" column per
section (key `att`, type `attendance`, `id 0`). When
`grade_attendance_meta.enabled = 1`, the `sheet` action reads
`bcc_qr_attendance_db.attendance_tbl` — present = the student has a scan on a
session `date`, and the column `max` is the count of distinct session dates for
the section (section-scoped like the roster; a section running multiple subjects
pools their dates). The score is read-only; the table stores only the eGradeBook
overlay (`enabled`, `term`, `category_id`, `weight`, `sort_order`), so the column
joins weighted/term grading exactly like a form column. Keyed `(owner_id,
section)` — plus `school_year, semester, subject` since class scoping (below).
Toggled by the `Attendance` checkbox → `set_attendance_enabled`; overlay edited
via `set_attendance_meta`.
- `bcc_qr_attendance_db` (`ATTENDANCE_DB`) — the **student roster** (`students_tbl`: sections, names, courses) and the **attendance scans** (`attendance_tbl`, read only when the attendance column is enabled).

All three constants live in `inc/db.php`. This design breaks if the databases
move to separate physical servers — the joins would need a REST/replication
bridge instead. There is **no `admin_users` table here**; login (`AuthController`
via `UserRepo`, called from `login.php`) queries FormFlow's table directly via
`password_verify`, so credentials stay in sync with FormFlow automatically.

The **profile photo** rides along with those credentials: `admin_users.avatar`
is read at login into `$_SESSION['admin_avatar']`. It is the one bridged value
the SQL bridge cannot fully resolve — the column holds a path *relative to
FormFlow's own folder* (`uploads/avatars/<random>.<ext>`) and the file lives on
FormFlow's disk, so a browser-reachable base is needed: **`FORMFLOW_WEB_BASE`**
in `inc/db.php`, defaulting to `../FormFlow/` (side-by-side deploy); `''`
disables photos. `Auth::avatarUrl()` builds the URL and **sanitises it** — the
value comes from another app's database and lands in a `src`, so anything with
a scheme, a leading `//`, a backslash, or `..` is rejected (same posture as
`AuthController::safeNext()`). `UserRepo` guards the column with
`hasCol(..., FORMFLOW_DB)`: naming a column FormFlow hasn't migrated yet would
fail the whole login query, locking everyone out over a picture. The view falls
back to the `bi-person-circle` icon both when there is no photo and, via
`onerror`, when the URL 404s. Sessions created before this feature are
backfilled once in `index.php`; changing the photo in FormFlow reaches
eGradeBook on the next login.

## Auth & access model

- **`App\Core\Auth` is the only session gate.** `Auth::start()` sets the cookie
  flags (`SameSite=Strict`, `HttpOnly`, `Secure` under HTTPS) and
  `use_strict_mode` *before* `session_start()`, so it must stay the single place
  a session is opened — never call `session_start()` elsewhere. SameSite is what
  stands in for CSRF tokens: there are none, and every `?api=` write trusts the
  session cookie alone. (Split FormFlow and eGradeBook across *different domains*
  and this must drop to `Lax`, or navigation between them breaks; on one host the
  two are same-site and Strict costs nothing.) `inc/auth.php` is a legacy shim
  that now just delegates here — nothing includes it.
- Post-login redirect goes through `AuthController::safeNext()`. The old
  `strpos($next,'http')===0` check missed `//evil.com` and `/\evil.com`, which
  browsers treat as another site — an open redirect through `login.php?next=`.
- **`Auth::requireAccess($db, $isApi)` is the access gate** (403 for both page
  loads and API calls). A **superadmin always passes**; anyone else must be in
  the `grade_app_access` allowlist (`App\Models\AccessRepo`). It runs *after*
  the DB boot in `index.php`, unlike the login gate, because it reads a table.
  **The reason it cannot simply be "do you have a FormFlow account":** the two
  apps share **one PHP session** — same host, default `PHPSESSID` on path `/`,
  and FormFlow's `login.php` writes the very same `admin_id` / `admin_role` /
  `admin_avatar` keys. So a FormFlow login already satisfies
  `Auth::requireLogin()` here without ever touching eGradeBook's `login.php`.
  If account existence granted entry, every FormFlow account — including ones
  created later for unrelated purposes — would get a gradebook automatically.
  Superadmins bypass the table so the last administrator can never lock
  themselves (or everyone) out. Managed via **More ▸ Admin ▸ Manage access…**
  (`access_list` / `set_access`), which `AccessController` re-checks for
  superadmin: *having* access is not permission to *grant* it. Revoking only
  removes entry — the teacher's `grade_*` rows are left intact.
- All grading data is scoped per teacher by `owner_id = $_SESSION['admin_id']`
  (exposed as `Auth::ownerId()`, passed into every repo/controller). Any new query
  touching `grade_*` tables must filter/insert with `owner_id`, and ownership-check
  helpers like `ActivityRepo::owns()` / `FormRepo::owns()` guard mutations.
- **`reset_all` ("Clear all", More ▸ Danger zone)** wipes a gradebook — every
  section, every class. Three targets: `me` (anyone), `owner` (one teacher) and
  `all` (every teacher); the last two are **superadmin-only, re-checked in
  `ResetController`** — the dropdown is simply absent for everyone else, and a
  hidden control is not a permission check. `reset_targets` feeds the picker
  (also superadmin-only) from `ResetRepo::ownersWithData()`, which unions
  `owner_id` across *all* owned tables, not just `grade_activities`, so a
  teacher holding only leftover settings can still be cleared.
  `App\Models\ResetRepo` holds the table whitelist and one hard limit: only
  `egradebook_db`'s own `grade_*` tables are listed (`formflow_db` and
  `bcc_qr_attendance_db` are read-only bridges, so the forms, responses, roster
  and scans all survive; `grade_app_access` is app config, not gradebook data,
  and is left alone too). It is **always `DELETE`, never `TRUNCATE`**, even for
  `all`: `TRUNCATE` is DDL and cannot roll back, so one mid-way failure would
  leave every teacher half-wiped with no way back. `grade_activity_scores` has
  no `owner_id`, so a targeted clear deletes it first via a join on
  `grade_activities` rather than trusting the FK cascade, which a MyISAM table
  would silently ignore. **`grade_transmute` is deliberately spared**: it is a
  scale the teacher owns, not gradebook content — identical across every
  section and year, and rarely touched once set. Clearing it would silently
  drop them back to the default band table, so the same raw scores would
  produce different final grades next term with nothing to show why; it is
  edited in the Transmutation modal instead. `grade_schema_version` is never
  touched (see the `Schema::migrate()` note under Conventions). Guarded by
  **type-to-confirm**, with a *different* phrase per target: `CLEAR ALL` for
  yourself, `CLEAR <username>` for another teacher, `CLEAR EVERYTHING` for all.
  That is deliberate — `CLEAR ALL` becomes muscle memory, and destroying
  someone else's work should not be reachable by the same reflex. The server
  recomputes the required phrase from the target and compares it itself, so a
  disabled button is never the only defence against a stray `?api=reset_all`.

## Class scoping (school year / semester / subject)

The gradebook unit is a **class** = `(owner_id, school_year, semester, section,
subject)`, not just `(owner_id, section)`. Existing rows carry `''` for the three
scope columns — the **legacy class** — so old sheets keep working unchanged.

- `App\Core\ClassScope` is the scope DTO, built from the request via
  `Controller::classScope()` and passed to repos / `SheetRepo::build()` wherever a
  bare `section` used to be. Empty fields = the legacy class.
- Class-scoped tables (`grade_activities`, `grade_categories`, `grade_settings`,
  `grade_form_meta`, `grade_attendance_meta`, `grade_student_status`) each carry
  `school_year`/`semester`/`subject` and include them in their PK/UNIQUE key (see
  `Schema.php`). **`grade_transmute` (global per teacher) and
  `grade_pinned_sections` (a section-picker convenience) are intentionally NOT
  class-scoped.** Scores hang off `activity_id`, so they inherit scope. The
  attendance auto column also filters scans by `subject` when the class has one.
- **One roster source for both reading and writing.** A non-legacy class renders
  from `grade_roster_snapshot`, so anything that *writes* scores across a roster
  must read the same list — use `RosterRepo::studentNosForClass()`, never the
  section-wide `studentNos()`. Bulk fill and CSV import used the latter and so
  silently skipped students who had left `students_tbl` but were still on the
  sheet (exactly the case the snapshot exists for). They now resolve the class
  from the **activity's own row** (`ActivityRepo::sectionAndMax()` returns the
  scope, `scopeOf()` wraps it) rather than from the request, so the two can't
  disagree.
- The section's classes are picked from a **Class dropdown** (`selClass`) fed by
  the `classes` action; "➕ New class…" reveals an inline create form
  (`create_class`). Classes live in a `grade_classes` registry (`ClassRepo`),
  auto-registered when an activity is added (`ActivityController::add`) and when a
  class is opened (`SheetController::sheet`), so a class stays in the dropdown even
  with no activities. The `classes` list also unions in any class already present
  in `grade_activities`. Subject suggestions in the create form come from
  `attendance_tbl.subject` via `App\Models\SubjectRepo` (`subjects` action); prior
  school years from `school_years`. `grades.js` injects the scope into **every**
  `apiGet`/`apiPost` call and persists it in `localStorage` under `eg_class`.
- **Roster snapshot** (`grade_roster_snapshot`): for non-legacy classes,
  `RosterRepo::rosterForClass()` tops up a per-class snapshot from the live roster
  (`INSERT IGNORE`) and reads from it, so a class keeps its students/names even if
  `students_tbl` later changes; the legacy class always uses the live roster.
  **Re-tag**: the `retag_class` action (`App\Models\ClassRepo` /
  `App\Controllers\ClassController`, "Tag as class…" in the More menu) relabels a
  whole class's rows from one scope to another (e.g. naming an untagged sheet).
  It splits the scoped tables in two: `ClassRepo::dataTables()` (the six the
  teacher actually fills) are **moved**, and the target is refused if any of them
  already has rows — `targetConflicts()` checks all six and names them in the
  error, where the old guard looked only at `grade_activities` and let the rest
  fall through to a raw MySQL "Duplicate entry". `grade_roster_snapshot` is
  deliberately **excluded from that guard and merged instead** (`INSERT IGNORE`
  the source rows, then delete them): it fills itself on *every* read of a tagged
  class, so counting it as a conflict would block re-tagging into any class that
  had merely been opened, and moving it outright would collide. IGNORE keeps the
  target's already-captured name and preserves source-only students — the very
  students the snapshot exists to hold on to.
  **Delete** (`delete_class`, the trash button beside the Class dropdown) splits
  those same six differently: `CONTENT_TABLES` (`grade_activities`,
  `grade_student_status` — what the teacher typed; scores hang off `activity_id`)
  **block** the delete, while `SETUP_TABLES` (`grade_categories`,
  `grade_settings`, `grade_form_meta`, `grade_attendance_meta`) are **cleared
  along with it** and reported back as `cleared`. Setup used to count as content,
  which made a class with zero activities permanently undeletable once the
  teacher ticked Attendance or hid one form column — worse, those two rows have
  no UI that removes them (un-hiding a form leaves `hidden = 0`, un-ticking
  Attendance leaves `enabled = 0`), so there was no way out at all. Only the
  legacy (untagged) sheet is still refused outright.
- NB: the app's existing **`term`** (Midterm/Final) is a sub-period *within* a
  semester — **not** the semester. Full design in `docs/class-scoping-plan.md`.

## Architecture — MVC/OOP (front controller + `app/`)

`index.php` is now a **thin front controller** (~45 lines): boot → auth gate →
superadmin gate → schema bootstrap → then either route a `?api=` request to a
controller (JSON) or render the grading-sheet view. The old ~2000-line monolith
was refactored into `app/`, but the **`?api=` contract is unchanged**, so
`assets/js/grades.js` was not touched *by the refactor* (verified with
byte-for-byte old-vs-new JSON parity on the read + write actions). The later
class-scoping feature is the one change that added scope params to its
`apiGet`/`apiPost` — see "Class scoping" above.

There is still **no Composer**. `app/bootstrap.php` registers a hand-rolled PSR-4
autoloader (`App\` → `app/`) so the "drop the folder into htdocs" deploy model
stays intact (no `composer install`). It also defines `APP_ROOT` (project root)
and requires `inc/db.php` — which is now **bridge config constants only**; the
mysqli connection + query helpers moved into `App\Core\Database`.

Layers under `app/`:

- **`Core/`** — `Database` (the single mysqli connection, `escape`/`hasCol`/
  `colExists` + transaction wrappers; it **throws** `RuntimeException` when MySQL
  is unreachable rather than echoing JSON, so `index.php` / `login.php` can answer
  with JSON or an HTML page as appropriate), `Schema::migrate()` (all `CREATE TABLE IF
  NOT EXISTS` + idempotent inline migrations — **add new columns/tables here**),
  `Auth` (session gate + the superadmin gate + `ownerId()`), `Controller` (base:
  holds `$db`/`$ownerId`, gives `json`/`ok`/`fail`/`post`/`get`/`classScope()`
  helpers), `ClassScope` (the class-scope DTO — see "Class scoping"), and
  `Router` (maps every `?api=` action name → `[Controller::class, 'method']` —
  **register new actions here**; `Core/Router.php`'s `MAP` is the complete list
  — read it rather than trusting a count quoted here, which goes stale).
- **`Models/`** — one owner-scoped repository per table/domain. Grade tables:
  `ActivityRepo`, `ScoreRepo`, `CategoryRepo`, `SettingsRepo`, `TransmuteRepo`
  (holds `DEFAULT_EQUIV`), `StatusRepo`, `FormMetaRepo`, `AttendanceRepo`,
  `PinnedRepo`, `ClassRepo` (cross-class re-tag). Cross-DB **bridge** repos,
  isolated here: `RosterRepo`
  (ATTENDANCE_DB roster), `SubjectRepo` (ATTENDANCE_DB subjects), `FormRepo`
  (FORMFLOW_DB ownership guard), `UserRepo` (FORMFLOW_DB login). `SheetRepo::build()` is the composite read behind the
  `sheet` action — the one query that spans all three databases, so its cross-DB
  SQL is kept intact there rather than fragmented.
- **`Controllers/`** — thin: parse `$_POST/$_GET`, validate, call repos, echo
  JSON. One per API domain: `SectionController`, `SheetController`,
  `ActivityController` (the biggest — CRUD, bulk fill, CSV import, linked mode,
  reorder, and the verbatim `copy_activities` orchestration), `ColumnController`
  (`reorder_columns` + `set_form_meta`), `AttendanceController`,
  `SettingsController`, `TransmuteController` (its get-bands method is
  **`getBands()`**, not `get()`, to avoid clashing with the base
  `Controller::get()` input helper), `CategoryController` (incl.
  `copy_categories` — Midterm ⇄ Final within one class; it **merges**:
  same-named categories take the source's weight, missing ones are inserted,
  and target-only ones are *never* deleted, because deleting a category
  unassigns every activity/form/attendance column pointing at it via
  `deleteWithUnassign()`. Safe to re-run; returns the class's full new category
  list so the client gets real ids for the column-header dropdowns without a
  sheet reload), `StatusController`,
  `ClassController` (`retag_class`), `ResetController` (`reset_all` — the
  danger-zone "Clear all my data", see below), `AccessController` (the
  superadmin-only app allowlist — see "Auth & access model"), plus
  `AuthController` (login, used by `login.php`).
- **`Views/`** — `sheet.php` (the grading-sheet page). `login.php` keeps its view
  inline. Shared UI pieces are still in `components/` (`favico`, `footer`,
  `logoutModal`, `supportModal`); the view includes them via `APP_ROOT`.

**Where things go now:** a new DB column → `Core/Schema.php`; a new API action →
a controller method + a `Router` map entry, with the SQL in a `Models/` repo (not
the controller). The `sheet` read assembles `students` (roster), unified `columns`
(FormFlow forms + manual activities + the optional auto attendance column), and a
`scores[student_no][key]` map, which the frontend renders.

## Frontend (`assets/js/`)

Vanilla JS, no framework. `grades.js` (~3k lines) is the client for the whole
grading sheet: it calls `index.php?api=...`, holds state in a global `SHEET`
object, and computes grades client-side. Grading logic to preserve when editing:

- **Coursework grade:** if activities carry weights → weighted average
  `Σ(score/max × weight) ÷ Σweight × 100`; otherwise legacy points-based
  `Σscore ÷ Σmax × 100` (includes form columns and the attendance column when
  enabled — attendance's `score` is present-count, `max` is total sessions).
- **Transmutation:** raw 0–100 → 1.00–5.00 point via editable bands
  (`TRANSMUTE`, seeded from the PH college default scale). This is the single
  source of truth for both flat and term grades. The default scale is
  **duplicated** in two places that must be edited together:
  `App\Models\TransmuteRepo::DEFAULT_EQUIV` (PHP, seeds a teacher's bands) and
  `DEFAULT_EQUIV` in `grades.js` (JS fallback). That duplication now has a third
  consumer: **"Reset to default"** in the Transmutation modal (`tmResetDefaults`)
  loads the *JS* copy into the draft, so the two drifting apart would mean the
  reset button hands back a different scale than a fresh teacher is seeded with.
  It only fills the draft — the teacher still has to press **Save table**, and
  Cancel abandons it. This button is the *only* way back to the defaults, on
  purpose: `reset_all` deliberately spares `grade_transmute` (see above).
- **Two grading modes** per section: flat (coursework × 0.50 + defense × 0.50,
  see `CW_WEIGHT`/`DEF_WEIGHT`) vs. term mode (Midterm/Final with weighted
  categories), toggled by `term_mode` in `grade_settings`.
- **Status overrides** (INC/DRP/W) are an overlay in `grade_student_status`; they
  never modify scores.
- **Three tables carry a `category_id`**, not two: `grade_activities`,
  `grade_form_meta` *and* `grade_attendance_meta`. `CategoryRepo::deleteWithUnassign()`
  must clear all three — it used to miss the attendance one, leaving it pointed at
  a deleted category, and since `termGrade()` only matches categories that still
  exist, the attendance column dropped out of the term grade silently.
- **Both grading modes obey the same two controls** — the column picker
  (`selectedCols`) and the "Missing = 0" checkbox. `termGrade()` used to read
  every column and always count an unscored one as 0, so in term mode both
  controls were visible but inert: unchecking a column removed it from the table
  yet left it in the grade. The two breakdown renderers (on-screen modal and PDF)
  filter identically, so what is shown always adds up to what was computed —
  the on-screen one also silently omitted `attendance` while counting it.
  A category whose columns are all unchecked contributes 0% at full weight,
  matching how a category with no activities has always behaved.

- **PDF/print header** — a letterhead **banner image** plus school, department,
  title, faculty and a footer line live in `grade_report_header`, **one row per
  teacher, no class scope**
  (`ReportRepo`, `get_report_header` / `save_report_header`, More ▸ Output ▸
  Report header…). One school and one signature serve every section and term,
  so scoping it per class would just mean retyping. **A blank field is omitted
  from the output entirely**, never printed empty — so a teacher who never
  opens the editor gets exactly the old layout. `grades.js` caches it in
  `REPORT_HDR` because "Export all" walks many sections and must not refetch
  per page. All three outputs share it: the section PDF, the student grade slip
  (which keeps its own "Grade Slip" title — it is a different document), and
  the browser Print header. Note the faculty name came from
  `document.querySelector('.user-pill span')`, which is *FormFlow's* class and
  does not exist in this app, so "Faculty:" was silently blank on every PDF and
  printout; `reportFaculty()` now reads the saved name, falling back to
  `.profile-name`.
- **The banner is a data URI in the database, not a file.** There is no upload
  handling anywhere in eGradeBook and no writable folder to rely on in the
  "drop it in htdocs" deploy, so `rhReadImage()` downscales to 1600px on a
  canvas, flattens onto white (transparent PNGs go black in some PDF viewers)
  and encodes JPEG q0.85 — ~240KB for a full-width letterhead. It is stored in
  `banner MEDIUMTEXT` with `banner_w/h`, which jsPDF needs for the aspect ratio.
  Two rules protect the layout and the page: the data URI **must** match
  `data:image/(png|jpeg);base64,…` server-side — **SVG is deliberately refused**
  because it is markup that can carry script and jsPDF cannot draw it — and
  `drawBanner()` caps the drawn height (80pt landscape, 70pt portrait), shrinking
  the *width* to match so a square logo cannot eat half the page. `get_report_header`
  returns only a `has_banner` flag and the dimensions; the image itself comes
  from the separate `get_report_banner`, because the header call runs on **every
  page load** for the print header and must stay small. When a banner is set,
  School and Department are skipped in all three outputs — the letterhead
  already shows them.
- **Never call the browser's `confirm()`** — use `await uiConfirm({title,
  message, ok, icon, danger})` in `grades.js`, which drives the shared
  `#uiConfirmModal` and resolves to a boolean. The native dialog is stamped
  "<host> says", ignores the theme, and can't emphasise *what* is about to be
  destroyed. `message` is inserted as HTML so a name can be bolded — run
  anything from data through `escHtml()` first. Every exit (OK, Cancel,
  backdrop, Escape) must resolve the promise, or the caller's `await` hangs and
  the app looks frozen; opening a second confirm resolves the first as `false`
  for the same reason. Destructive prompts pass `danger: true`, which focuses
  Cancel so a stray Enter cannot confirm them.

`global.js` provides shared UI helpers (`showToast`, `escHtml`, theme toggle —
theme persisted in `localStorage` under `ff_theme`, shared with FormFlow).
`detection.js` warns users who open the app inside in-app browsers
(Messenger/Facebook/etc.) and depends on SweetAlert2.

## Conventions

- **SQL style:** repos use **prepared statements** (`prepare` + `bind_param`) —
  there is not one `Database::escape()` call anywhere in `Models/`. The
  deliberate exception is `SheetRepo`, which builds `IN (...)` lists of roster
  student numbers / form ids by interpolation (mysqli can't bind a list),
  escaping each element with `real_escape_string` and casting ids to `int`.
  Follow the prepared-statement path for anything new; if you must interpolate,
  escape or int-cast at the point of interpolation like `SheetRepo` does.
- `Database`'s constructor also pins the app to **`Asia/Manila` (UTC+8)** for
  both PHP and the MySQL session — don't set timezones elsewhere. `canAccess()`
  checks a bridged DB is reachable (advisory, non-fatal).
- Comments are bilingual (English + Filipino) and heavily explain the bridge
  assumptions — keep new schema/bridge changes documented the same way.
- External deps are CDN `<link>`/`<script>` only (Bootstrap Icons, Google Fonts,
  SweetAlert2). No local vendored libraries. The heavy export libraries —
  **SheetJS/xlsx** (Excel backup) and **jsPDF + autotable** (PDF export) — are
  *lazy-loaded* from CDN inside `grades.js` (`loadScriptOnce` /
  `loadExternalScript`) only when the user exports, so they stay off the initial
  page load. Follow that pattern for anything else that bulky.
- New DB columns: add an idempotent migration in `app/Core/Schema.php` — inside
  **`runAll()`**, not `migrate()` — checked via `$db->colExists(...)` rather than
  assuming a fresh schema, since production tables already exist.
- **`Schema::migrate()` is version-gated, and the version is this file's own
  `filemtime()`.** The migrations cost ~285 ms per request (≈25 `colExists()`
  calls, ~10.8 ms each because `information_schema` is slow on MariaDB 10.4) and
  ran on *every* `?api=` hit, so every score save paid it. Now a marker row in
  `grade_schema_version` short-circuits the whole thing in ~0.3 ms. Because the
  marker is the file's mtime, **editing `Schema.php` re-runs the migrations by
  itself** — there is no version constant to remember to bump. Nothing else may
  write that table.
- **Errors: log the detail, show a sentence.** `index.php` and the controllers
  never put `$e->getMessage()` in a response — a teacher was seeing raw MySQL text
  like `Duplicate entry '230-…' for key 'PRIMARY'`. Use `error_log()` plus a
  human message. Likewise an unauthenticated `?api=` call gets **401 JSON with
  `auth: false`** instead of a 302 to `login.php` (`fetch` follows the redirect and
  the client would otherwise render login-page HTML as the error text);
  `grades.js` watches for that flag and sends the user back to log in.
