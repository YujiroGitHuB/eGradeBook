# Class-scoped Gradebooks — Implementation Plan

Status: **Phases 0–3 done** (schema + backend + UI pickers + roster snapshot +
re-tag helper). This document is the agreed design for adding academic-year /
semester / subject separation to eGradeBook.

Implemented: `App\Core\ClassScope` (the scope DTO, built via
`Controller::classScope()`), `App\Models\SubjectRepo` (subjects from
`attendance_tbl`), the `subjects` + `school_years` API actions, class-scoping in
every owner+section repo/query and `SheetRepo::build`. The UI is a **Class
dropdown** + inline **New-class** form in `sheet.php`, backed by a `grade_classes`
registry (`ClassRepo`; `classes` / `create_class` actions, auto-registered on
add-activity and on view) so classes persist in the dropdown even when empty.
`grades.js` injects the scope into every `apiGet`/`apiPost` call and persists it
in `localStorage` under `eg_class`. Verified: legacy-class reads stay
byte-identical to the pre-feature HEAD; new classes are isolated from the legacy
`('', '', '')` class.

## Problem

Everything is currently keyed on `(owner_id, section)`. There is no academic
year, semester, or subject dimension anywhere in the grade tables (and none in
the roster either — `students_tbl` has only `section` + `course`). So:

- Reusing a section name across years/semesters → the same activity columns,
  categories, weights and settings carry over and pile up.
- One teacher teaching the same section for two subjects → all activities pool
  together (the app can't tell them apart except by name).

The real cycle to support: `1st Sem → 2nd Sem → (new SY) 1st Sem again`, with
different subjects.

> Note: the existing **`term`** (Midterm/Final, in `grade_activities.term` /
> `grade_settings.term_mode`) is a sub-period *within* a semester — it is **not**
> the semester. Semester is a new, separate dimension.

## Model

Make the gradebook unit a **class**, not a section:

```
Class = (owner_id, school_year, semester, section, subject)
```

Each class is an independent gradebook. When "1st Sem" comes around next year,
the teacher selects/creates a new school year → a fresh class, with the old one
preserved and viewable.

## Adopted defaults

1. **Semester** options: `1st`, `2nd`, `Midyear`.
2. **School year** format: `2024-2025`.
3. **Forms**: form *raw scores* stay section-level in the MVP (their responses
   live in FormFlow keyed by section only); only the form *overlay*
   (`grade_form_meta`: weight/category/term/order) becomes class-scoped.
4. **`grade_transmute`** (global per teacher) and **`grade_pinned_sections`**
   (a section-picker convenience) stay as-is — NOT class-scoped.

## Sources for the picker values

- **Subject** — `SELECT DISTINCT subject FROM bcc_qr_attendance_db.attendance_tbl
  WHERE section = ?` (real data already exists), plus free-text fallback.
- **School year** — distinct values already used (from `grade_activities`) ∪ a
  current-SY suggestion from today's date ∪ free-text. No new "periods" table for
  the MVP.
- **Semester** — fixed list `{1st, 2nd, Midyear}`.

## Schema changes (`app/Core/Schema.php`)

Add `school_year VARCHAR(9)`, `semester VARCHAR(8)`, `subject VARCHAR(120)`
(all `NOT NULL DEFAULT ''`) to the class-scoped tables. Existing rows get `''`
for all three via the column default — i.e. they fall into a single **legacy
class** `('', '', '')`, so current gradebooks keep loading unchanged (no separate
back-fill UPDATE needed).

| Table | Columns | Key change |
|---|---|---|
| `grade_activities` | +3 | (index only; scores inherit via `activity_id`) |
| `grade_categories` | +3 | (index only) |
| `grade_settings` | +3 | PK → `(owner_id, section, school_year, semester, subject)` |
| `grade_form_meta` | +3 | PK → `(owner_id, section, form_id, school_year, semester, subject)` |
| `grade_attendance_meta` | +3 | PK → `(owner_id, section, school_year, semester, subject)` |
| `grade_student_status` | +3 | UNIQUE `uniq_owner_sec_student` → `(owner_id, section, student_no, school_year, semester, subject)` |
| `grade_transmute` | — | none (global) |
| `grade_pinned_sections` | — | none (section-level) |

All migrations idempotent and guarded (columns via `colExists`; key changes
guarded by checking whether the key already contains `subject` via
`information_schema`). `grade_student_status` keeps its `id AUTO_INCREMENT`
PRIMARY KEY; only its UNIQUE index changes.

## Application changes (later phases)

- **`app/Core/ClassScope.php`** (new) — immutable DTO `(school_year, semester,
  section, subject)`, passed wherever `section` is passed today.
- **`app/Models/*Repo`** — every `(owner_id, section)` filter/insert gains
  `AND school_year=? AND semester=? AND subject=?`. Affected: `ActivityRepo`,
  `CategoryRepo`, `SettingsRepo`, `StatusRepo`, `FormMetaRepo`, `AttendanceRepo`,
  and `SheetRepo::build(ClassScope)`. `RosterRepo` stays section-only.
- **`app/Models/SubjectRepo`** (new, bridge) — `subjectsForSection($section)`.
- **`app/Controllers/*`** — read the 3 extra params, build a `ClassScope`, pass
  to repos. New action(s): `subjects` (+ maybe `school_years`), registered in
  `Core/Router.php`.
- **`app/Views/sheet.php` + `assets/js/grades.js`** — School Year / Semester /
  Subject pickers next to the section picker; thread the 3 params through the
  central `getApi`/`postApi` helpers; persist the selection in `localStorage`.
  (This is where `grades.js` changes — expected for a new feature.)

## Known limitations

1. **Form raw scores** are section-level, not class-level (their responses are
   owned by FormFlow, keyed by section). Only the overlay is class-scoped. Could
   be extended later with per-class date windows.
2. **Roster history** — the roster is live from `students_tbl` by section. Old
   classes show correct *scores* (stored here by `student_no`); for the *student
   list*, **Phase 3 added `grade_roster_snapshot`** — for non-legacy classes,
   `RosterRepo::rosterForClass()` tops up the snapshot from the live roster
   (`INSERT IGNORE`) and reads from it, so a class keeps its students/names even
   if `students_tbl` later changes. The legacy class always uses the live roster.

## Phasing

| Phase | Scope | Status |
|---|---|---|
| **0** | Schema migration (columns + legacy default + key changes). | ✅ done |
| **1 — Subject** | `SubjectRepo` + subject picker + thread `subject`. | ✅ done (folded into the backend pass) |
| **2 — SY + Semester** | Pickers + thread + SY option source. | ✅ done |
| **3** | Roster snapshot (`grade_roster_snapshot` + `RosterRepo::rosterForClass`) + re-tag helper (`retag_class` / `ClassRepo` / "Tag as class…" in the More menu). | ✅ done |

## Rollback

A pre-change `mysqldump` of `egradebook_db` is kept before Phase 0 is applied.
All code changes are in git; the added columns/keys are additive and can be
dropped if needed.
