<div align="center">

<img src="assets/images/logo.png" width="80" height="80" alt="eGradeBook logo">

# eGradeBook

**Standalone grading sheets, bridged to your student &amp; form data.**

Computes college grading sheets from live class rosters, auto-graded FormFlow
quizzes, and QR attendance scans — no spreadsheet juggling, no re-typing scores.

<sub>PHP 8 · MySQL · vanilla JS · no framework, no build step, no Composer/npm</sub>

</div>

---

## What it is

eGradeBook is a self-contained PHP app that a teacher opens to see one class as a
grading matrix: students down the side, graded columns across the top, and a
computed final grade (and PH 1.00–5.00 point equivalent) per student.

What makes it different from a spreadsheet is that most of the sheet fills itself
in. It owns only its **grading** tables and reads everything else live from two
sibling apps on the same MySQL server:

| Source | Provides |
| --- | --- |
| **FormFlow** (`formflow_db`) | Login accounts, plus every online form/quiz a student answered → an auto-scored column |
| **QR Attendance** (`bcc_qr_attendance_db`) | The student roster, and attendance scans → an optional auto Attendance column |
| **eGradeBook** (`egradebook_db`) | Manual activity columns, scores, categories, weights, settings, transmutation bands |

So a quiz scored in FormFlow and a class scanned in the attendance app both show
up here as graded columns automatically. You only hand-enter the things that have
no other source — recitation, projects, defense.

> **Requires all three databases on one MySQL server.** The bridge is plain
> cross-database SQL (`` `db`.`table` ``) over a single connection. Splitting them
> onto separate servers breaks the joins — see [CLAUDE.md](CLAUDE.md) for what
> that would take instead.

## Features

**Columns**
- **Auto form columns** — every FormFlow form answered by the section, scored from
  its responses (penalties applied), read-only.
- **Auto attendance column** — present ÷ total sessions, from the QR scans. Opt-in
  per class via the `Attendance` checkbox.
- **Manual activities** — add your own columns with a max-points value.
- **Linked columns** — one score shared by every student (for a common activity).
- **Drag to reorder** any column; forms, activities and attendance share one order.

**Entering scores**
- Inline editing with keyboard navigation between cells.
- **Fill column** — apply one score to all students, only the blanks, or a selection.
- **Import CSV** — match scores by student number (a template can be downloaded).
- **Copy from…** — clone another section's whole column setup into this one.

**Grading**
- Two modes per class: **flat** (coursework 50% + defense 50%) or **term mode**
  (Midterm/Final with weighted categories, Excel-style).
- Per-column **weights** — set any weight and coursework becomes a weighted
  average; leave them all at 0 for the simple points-based total.
- **Transmutation** — raw 0–100 → 1.00–5.00 via editable bands, seeded from the PH
  college default scale (96→1.00 … 75→3.00, below → 5.00).
- Adjustable passing % (default 75), "count missing as 0" toggle, and pass/fail
  highlighting.
- **Status overrides** — INC / DRP / W or your own custom label, per student, as an
  overlay that never touches the scores.

**Organising**
- **Classes** — each gradebook is scoped to `school year · semester · section ·
  subject`, so reusing a section name next year starts a clean sheet instead of
  piling onto the old one. Existing untagged sheets keep working and can be named
  later with **Tag as class…**.
- **Roster snapshot** — a named class keeps its students even if the upstream
  roster later changes.
- **Pinned sections** — pick the subset of sections you actually teach.
- Search students by name, number, status or pass/fail; sort by name or grade.

**Getting data out**
- **Export CSV** (current sheet) · **Backup all (Excel)** — every section, one
  sheet per tab · **Export section / all (PDF)** · **Print** with a formatted header.

**Other**
- Dark/light theme, shared with FormFlow.
- Warns when opened inside an in-app browser (Messenger, Facebook, …).

## Requirements

- PHP **7.4+** with `mysqli` — developed and run on PHP **8.2**, which is what to
  use if you have the choice
- MySQL / MariaDB
- Apache or any PHP-capable web server — XAMPP is the intended setup
- An existing **FormFlow** install (for accounts) and **QR Attendance** install
  (for the roster) on the same MySQL server
- Internet access on the client — CSS/JS libraries load from CDN

There is nothing to compile or install: no Composer, no npm, no build step.

## Installation

1. **Drop the folder into your web root.**

   ```bash
   git clone <repo-url> eGradeBook       # into e.g. C:\xampp\htdocs\
   ```

2. **Check the database settings** in [inc/db.php](inc/db.php) — this is the only
   file you normally edit:

   ```php
   define('DB_HOST', 'localhost');
   define('DB_USER', 'root');
   define('DB_PASS', '');

   define('DB_NAME',        'egradebook_db');          // created automatically
   define('FORMFLOW_DB',    'formflow_db');            // accounts + forms
   define('ATTENDANCE_DB',  'bcc_qr_attendance_db');   // roster + scans
   define('FORMFLOW_APP_URL', '');                     // optional link back to FormFlow
   ```

3. **Browse to it** — e.g. <http://localhost/eGradeBook/>.

   `egradebook_db` and all its tables are created on the first request, and new
   columns are migrated in automatically on later requests. There is no
   migration command to run.

4. **Sign in with your FormFlow account.** eGradeBook has no user table of its
   own — it authenticates against FormFlow's `admin_users`, so a password changed
   there works here immediately.

> **Access is superadmin-only.** Your FormFlow account must have
> `role = 'superadmin'`; anyone else gets a 403. All grading data is private to
> the account that entered it.

## Using it

1. Pick a **section** (use the gear to choose which sections appear in your list).
2. Pick or create a **class** — school year, semester, subject. Leave it as
   *Existing (untagged) sheet* to keep working the old way.
3. Form columns and the roster appear on their own. Add manual columns with
   **Add Activity**, and tick **Attendance** if you want the scans graded.
4. Type scores directly into the grid — they save as you go.
5. Set weights (or switch on **Term mode** and define categories under **Grade
   setup**), then read the final grade and point equivalent off the right-hand
   columns.
6. Export or print from the **More** menu.

### Resetting

To clear one teacher's data, delete their rows (everything is keyed by
`owner_id`); to start completely over, drop the `grade_*` tables — they are
recreated on the next page load. FormFlow and attendance data is never written
to, only read.

## Project layout

```
index.php            Front controller — the page, and every ?api= JSON endpoint
login.php            Sign-in (bridged to FormFlow accounts)
inc/db.php           Bridge configuration — the file you edit
app/
  Core/              Database, Schema (all DDL + migrations), Auth, Router, ClassScope
  Models/            One repository per table; the cross-database queries live here
  Controllers/       Thin request handlers, one per API domain
  Views/sheet.php    The grading-sheet page
assets/              CSS, vanilla JS (grades.js is the whole client), logo
components/          Shared footer / modals
docs/                Design notes (class-scoping plan)
```

Developing on it? [CLAUDE.md](CLAUDE.md) is the architecture guide — the bridge
assumptions, the grading math, and where new columns/actions go.

## Notes &amp; limitations

- **No test suite.** The only automated check is PHP's syntax linter:
  `find app inc index.php login.php -name '*.php' -exec php -l {} \;`
- Grades are computed **client-side** in `grades.js`; the server stores raw scores.
- Form responses and attendance scans are **read-only** here — fix them in the app
  that owns them.
- FormFlow has no notion of a subject and stamps a response only with its
  **section**, so every class of a section sees all of its forms — and because
  section names repeat each year, old forms keep coming back. Sort it out under
  **More ▸ Form columns…**: assign a form to a subject (one time, applies to
  future classes too) or hide it in just this class, optionally copying another
  class's hidden list. Nothing is ever changed in FormFlow.
- Bootstrap Icons, Google Fonts and SweetAlert2 load from CDN; the Excel (SheetJS)
  and PDF (jsPDF) libraries are fetched on demand only when you export. Exports
  need a connection.
- Times are pinned to **Asia/Manila (UTC+8)**.

## Credits

Developed by **[Charles Nixon Cayading](https://cncc.vercel.app)**.

© eGradeBook — All Rights Reserved.
