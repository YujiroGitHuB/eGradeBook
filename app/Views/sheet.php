<?php

/* ============================================================
   app/Views/sheet.php — the grading sheet page (the whole HTML UI).
   Rendered by the front controller (index.php) for non-API GETs.
   Available in scope: $_SESSION (name/role), FORMFLOW_APP_URL, APP_ROOT.
   Talks to the backend only via index.php?api=... (assets/js/grades.js),
   so the API contract is unchanged.
   ============================================================ */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <script>
        if (localStorage.getItem("ff_theme") === "light") document.documentElement.classList.add("preload-light");
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>eGradeBook</title>
    <?php include APP_ROOT . "/components/favico.php" ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/global.css?v=<?= filemtime(APP_ROOT . '/assets/css/global.css') ?>">
    <link rel="stylesheet" href="assets/css/grades.css?v=<?= filemtime(APP_ROOT . '/assets/css/grades.css') ?>">
</head>

<body class="bg-glow">

    <nav class="navbar">
        <a href="index.php" class="nav-brand">
            <img src="assets/images/logo.png" width="35px" height="35px" alt="eGradeBook">
            eGradeBook
        </a>
        <div class="navbar-right">
            <?php if (FORMFLOW_APP_URL !== ''): ?>
                <a href="<?= htmlspecialchars(FORMFLOW_APP_URL) ?>" class="btn btn-ghost btn-sm"><i class="bi bi-box-arrow-up-left"></i> <span class="btn-label">FormFlow</span></a>
            <?php endif; ?>
            <button class="theme-toggle" title="Toggle theme"><i class="bi bi-sun-fill"></i></button>
            <div class="profile" id="profileDropdown">
                <button class="profile-trigger" id="profileTrigger" aria-haspopup="true" aria-expanded="false">
                    <i class="bi bi-person-circle profile-avatar"></i>
                    <span class="profile-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></span>
                    <i class="bi bi-chevron-down profile-chev"></i>
                </button>
                <div class="profile-menu" id="profileMenu" role="menu">
                    <div class="profile-menu-head">
                        <i class="bi bi-person-circle"></i>
                        <div class="profile-menu-meta">
                            <div class="profile-menu-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                            <div class="profile-menu-sub">Signed in</div>
                        </div>
                    </div>
                    <div class="profile-menu-divider"></div>
                    <a href="inc/logout.php" onclick="confirmLogout(this.href);return false;" class="profile-menu-item danger" role="menuitem"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </div>
        <button class="nav-hamburger" id="navHamburger" aria-label="Menu"><span></span><span></span><span></span></button>
    </nav>

    <div class="nav-mobile-menu" id="navMobileMenu">
        <div class="menu-user">
            <i class="bi bi-person-circle" style="color:var(--accent);"></i>
            <?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?>
        </div>
        <div class="menu-divider"></div>
        <?php if (FORMFLOW_APP_URL !== ''): ?>
            <a href="<?= htmlspecialchars(FORMFLOW_APP_URL) ?>"><i class="bi bi-box-arrow-up-left"></i> FormFlow</a>
            <div class="menu-divider"></div>
        <?php endif; ?>
        <button class="menu-theme-toggle"><i class="bi bi-sun-fill"></i><span>Light Mode</span></button>
        <div class="menu-divider"></div>
        <a href="inc/logout.php" onclick="confirmLogout(this.href);return false;" class="menu-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
    </div>
    <div class="nav-backdrop" id="navBackdrop"></div>

    <main>
        <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem;">
            <div class="page-title">
                <h1 style="margin:0;">Grading Sheet</h1>
                <p style="margin:.3rem 0 0;color:var(--muted);">Students from attendance, scores from your forms</p>
            </div>
            <div class="gs-toolbar" style="display:flex;gap:.5rem;">
                <button class="btn btn-ghost btn-sm" id="btnAddActivity" title="Select a section first"><i class="bi bi-plus-circle"></i> <span class="btn-label">Add Activity</span></button>
                <div class="gs-more" id="gsMore">
                    <button class="btn btn-ghost btn-sm gs-more-trigger" id="btnMore" aria-haspopup="true" aria-expanded="false"><i class="bi bi-three-dots"></i> <span class="btn-label">More</span> <i class="bi bi-chevron-down gs-more-chev"></i></button>
                    <div class="gs-more-menu" id="moreMenu" role="menu">
                        <div class="gs-more-label">Setup</div>
                        <button class="profile-menu-item" id="btnCopyFrom" role="menuitem" title="Copy activity setup from another section"><i class="bi bi-copy"></i> Copy from…</button>
                        <button class="profile-menu-item" id="btnImport" role="menuitem"><i class="bi bi-upload"></i> Import CSV</button>
                        <button class="profile-menu-item" id="btnRetag" role="menuitem" title="Move the current sheet into a named class (school year / semester / subject)"><i class="bi bi-tag"></i> Tag as class…</button>
                        <button class="profile-menu-item" id="btnFormCols" role="menuitem" title="Choose which of this section's FormFlow forms belong in this class"><i class="bi bi-ui-checks-grid"></i> Form columns…</button>
                        <div class="profile-menu-divider"></div>
                        <div class="gs-more-label">Grading</div>
                        <button class="profile-menu-item" id="btnTransmute" role="menuitem"><i class="bi bi-arrow-left-right"></i> Transmutation</button>
                        <div class="profile-menu-divider"></div>
                        <div class="gs-more-label">Output</div>
                        <button class="profile-menu-item" id="btnBackup" role="menuitem"><i class="bi bi-file-earmark-excel"></i> Backup all (Excel)</button>
                        <button class="profile-menu-item" id="btnPdfSection" role="menuitem" title="PDF of the current section's grades"><i class="bi bi-file-earmark-pdf"></i> Export section (PDF)</button>
                        <button class="profile-menu-item" id="btnPdfAll" role="menuitem" title="One combined PDF of every section's grades"><i class="bi bi-file-earmark-pdf-fill"></i> Export all (PDF)</button>
                        <button class="profile-menu-item" id="btnPrint" role="menuitem"><i class="bi bi-printer"></i> Print</button>
                        <div class="profile-menu-divider"></div>
                        <div class="gs-more-label">Danger zone</div>
                        <button class="profile-menu-item danger" id="btnClearAll" role="menuitem" title="Delete your whole gradebook — every section and class"><i class="bi bi-exclamation-octagon"></i> Clear all my data…</button>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" id="btnExport"><i class="bi bi-filetype-csv"></i> <span class="btn-label">Export CSV</span></button>
            </div>
        </div>

        <!-- Stats -->
        <div class="gs-stats" id="gsStats" style="display:none;">
            <div class="gs-stat">
                <div class="v" id="stStudents">—</div>
                <div class="l">Students</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stAssess">—</div>
                <div class="l">Assessments</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stAvg">—</div>
                <div class="l">Class Average</div>
            </div>
            <div class="gs-stat">
                <div class="v" id="stPassRate">—</div>
                <div class="l">Pass Rate</div>
            </div>
        </div>

        <!-- Column picker -->
        <div class="gs-columns" id="gsColumns" style="display:none;">
            <h4><i class="bi bi-ui-checks"></i> Select assessments to include (columns)</h4>
            <div class="gs-coltags" id="colTags"></div>
        </div>

        <!-- Controls — dalawang zone: KALIWA = kung aling sheet ang tinitingnan
             mo (section + class), KANAN = paano ito ipapakita (search, passing,
             mga toggle). Hiwalay ang baseline nila, kaya hindi na nasisira ang
             pagkakahanay kapag lumaki ang kaliwa. Sariling hilera sa ibaba ang
             New-class form (tingnan ang .gs-newclass sa grades.css). -->
        <div class="gs-controls">
          <div class="gs-czone gs-czone-left">
            <div class="gs-field">
                <label for="selSection" style="display:flex;align-items:center;gap:.5rem;justify-content:space-between;">
                    <span>Section / Class</span>
                    <span class="pin-controls">
                        <button type="button" id="pinViewToggle" class="pin-chip" title="Switch between your pinned sections and all sections">My sections</button>
                        <button type="button" id="btnManageSections" class="pin-gear" title="Choose which sections to show"><i class="bi bi-gear"></i></button>
                    </span>
                </label>
                <div class="msel" id="mselSection">
                    <!-- native select kept as the source of truth (hidden), driven by the custom UI -->
                    <select id="selSection" class="msel-native" aria-hidden="true" tabindex="-1">
                        <option value="">Loading sections…</option>
                    </select>
                    <button type="button" class="msel-btn" id="mselBtn" aria-haspopup="listbox" aria-expanded="false">
                        <span class="msel-btn-label" id="mselLabel">Loading sections…</span>
                        <i class="bi bi-chevron-down msel-chev"></i>
                    </button>
                    <div class="msel-panel" id="mselPanel" role="listbox">
                        <div class="msel-search-wrap">
                            <i class="bi bi-search msel-search-ic"></i>
                            <input type="text" id="mselSearch" class="msel-search" placeholder="Search section or course…">
                        </div>
                        <div class="msel-list" id="mselList"></div>
                    </div>
                </div>
            </div>
            <div class="gs-field gs-class-field">
                <label for="selClass">Class <span class="gs-lbl-hint">— pick or create</span></label>
                <div class="gs-class-row">
                    <select id="selClass" class="gs-input gs-class-select" title="Pick a class or create a new one">
                        <option value="__legacy__">Existing (untagged) sheet</option>
                    </select>
                    <!-- Delete a named class. Only grades block it (activities and
                         status overrides) — the grading setup is cleared with the
                         class. Hidden for the untagged sheet. -->
                    <button type="button" id="btnDeleteClass" class="cls-del" title="Delete this class (its grading setup goes with it; refused if it still has activities)" style="display:none;"><i class="bi bi-trash"></i></button>
                </div>
            </div>
          </div>

          <div class="gs-czone gs-czone-right">
            <div class="gs-field">
                <label for="txtSearch">Search student</label>
                <input type="text" id="txtSearch" placeholder="Search anything…" title="Search by name, student number, status (INC/DRP/W or a custom label), or passed/failed">
            </div>
            <div class="gs-field">
                <label for="numPass">Passing %</label>
                <input type="number" id="numPass" value="75" min="0" max="100">
            </div>
            <!-- Sinasadyang HINDI .gs-field: doon ay naka-uppercase ang bawat
                 <label>, kaya sumisigaw dati ang tatlong toggle na ito. -->
            <div class="gs-opts">
                <span class="gs-optlabel">Grading options</span>
                <div class="gs-toggles">
                    <label class="gs-check" title="Count students who did not take it as 0">
                        <input type="checkbox" id="chkMissingZero" checked>
                        Count missing as 0
                    </label>
                    <label class="gs-check" title="Excel-style: Midterm + Final terms with weighted categories, averaged">
                        <input type="checkbox" id="chkTermMode">
                        Term grading
                    </label>
                    <label class="gs-check" title="Add an auto Attendance column from the QR scans (present ÷ sessions). Set its weight or category in the column header to include it in the grade.">
                        <input type="checkbox" id="chkAttendance">
                        Attendance
                    </label>
                    <button class="btn btn-ghost btn-sm" id="btnGradeSetup" style="display:none;"><i class="bi bi-sliders"></i> Grade setup</button>
                </div>
            </div>
          </div>

          <!-- New-class form (revealed when "New class…" is picked). Sariling
               buong-lapad na hilera ito para walang naiuusog kapag lumitaw —
               inline styles ang mga field dati, na siyang nagtutulak sa Search
               at Passing pababa tuwing bubuksan ito. Ang display ay binabaligtad
               ng grades.js (showNewClassForm), kaya `display:flex` ang inaasahan. -->
          <div id="newClassForm" class="gs-newclass" style="display:none;">
                <p class="gs-newclass-hint" id="newClassHint"><i class="bi bi-plus-circle"></i> New class</p>
                <div class="gs-field">
                    <label for="selSchoolYear">School Year</label>
                    <input list="syList" id="selSchoolYear" class="gs-input" placeholder="e.g. 2025-2026" autocomplete="off" maxlength="9">
                    <datalist id="syList"></datalist>
                </div>
                <div class="gs-field">
                    <label for="selSemester">Semester</label>
                    <select id="selSemester" class="gs-input">
                        <option value="">— Semester —</option>
                        <option value="1st">1st Sem</option>
                        <option value="2nd">2nd Sem</option>
                        <option value="Midyear">Midyear</option>
                    </select>
                </div>
                <div class="gs-field gs-newclass-subj">
                    <label for="selSubject">Subject</label>
                    <input list="subjectList" id="selSubject" class="gs-input" placeholder="e.g. OOP" autocomplete="off">
                    <datalist id="subjectList"></datalist>
                </div>
                <div class="gs-newclass-actions">
                    <button type="button" class="btn btn-primary btn-sm" id="btnCreateClass"><i class="bi bi-plus-lg"></i> Create</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btnCancelClass">Cancel</button>
                </div>
          </div>
        </div>

        <!-- Bulk selection bar (appears when students are selected) -->
        <div id="selBar" class="sel-bar" style="display:none;">
            <span class="sel-bar-count"><i class="bi bi-check2-square"></i> <b id="selBarCount">0</b> selected</span>
            <span class="sel-bar-sep"></span>
            <span class="sel-bar-lbl">Set status:</span>
            <button class="sel-st-btn" data-status="INC">INC</button>
            <button class="sel-st-btn" data-status="DRP">DRP</button>
            <button class="sel-st-btn" data-status="W">W</button>
            <button class="sel-st-btn sel-st-clear" data-status="">Clear status</button>
            <button class="sel-bar-x" id="selBarClear" title="Clear selection"><i class="bi bi-x-lg"></i></button>
        </div>

        <!-- Table -->
        <div id="gsArea">
            <div class="gs-empty">
                <i class="bi bi-table"></i>
                Select a section above to view the grading sheet.
            </div>
        </div>
    </main>

    <!-- Delete Activity Modal -->
    <div class="modal-backdrop" id="delActModal">
        <div class="modal">
            <h3><i class="bi bi-trash3" style="color:var(--danger);margin-right:8px;"></i>Delete Activity</h3>
            <p id="delActText">This will permanently delete the activity and all its scores. This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="btn btn-ghost" id="delActCancel">Cancel</button>
                <button class="btn btn-danger" id="delActConfirm"><i class="bi bi-trash3"></i> Delete</button>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="actModal">
        <div class="modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-plus-lg"></i></div>
                <div>
                    <h3 class="gs-mtitle">Add activity</h3>
                    <p class="gs-msub">A manual column you score by hand, e.g. Recitation or Seatwork.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:1.1rem;margin:1.25rem 0 0;">
                <div class="gs-field">
                    <label for="actTitle">Activity name</label>
                    <input type="text" id="actTitle" placeholder="e.g. Recitation 1" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:.6rem .85rem;font-size:.9rem;outline:none;">
                </div>
                <div class="gs-field">
                    <label for="actMax">Max points</label>
                    <input type="number" id="actMax" value="100" min="1" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:12px;padding:.6rem .85rem;font-size:.9rem;outline:none;width:120px;">
                </div>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="actCancel">Cancel</button>
                <button class="btn btn-primary" id="actSave"><i class="bi bi-check-lg"></i> Add column</button>
            </div>
        </div>
    </div>

    <!-- Bulk Fill Modal -->
    <div class="modal-backdrop" id="bulkFillModal">
        <div class="modal">
            <h3><i class="bi bi-arrow-bar-down" style="color:var(--accent);margin-right:8px;"></i>Fill Column</h3>
            <p style="color:var(--muted);margin-top:-.3rem;">Apply the same score to students in <b id="bulkFillCol">this activity</b>.</p>
            <div style="display:flex;flex-direction:column;gap:.8rem;margin:1rem 0;">
                <div class="gs-field">
                    <label for="bulkFillScore">Score <span id="bulkFillMax" style="color:var(--muted);font-weight:400;"></span></label>
                    <input type="number" id="bulkFillScore" min="0" placeholder="e.g. 80" style="background:var(--surface2);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;width:120px;">
                </div>
                <div class="gs-field">
                    <label>Apply to</label>
                    <label class="bulk-opt"><input type="radio" name="bulkScope" value="empty" checked> Only students without a score <span class="bulk-note">(keeps your entered scores)</span></label>
                    <label class="bulk-opt" id="bulkScopeSelWrap" style="display:none;"><input type="radio" name="bulkScope" value="selected"> Only selected students <span class="bulk-note" id="bulkSelCount"></span></label>
                    <label class="bulk-opt"><input type="radio" name="bulkScope" value="all"> All students <span class="bulk-note">(overwrites existing scores)</span></label>
                </div>
                <p id="bulkFillErr" class="bulk-err" style="display:none;"></p>
            </div>
            <div class="modal-actions">
                <button class="btn btn-ghost" id="bulkFillCancel">Cancel</button>
                <button class="btn btn-primary" id="bulkFillApply"><i class="bi bi-check-lg"></i> Apply</button>
            </div>
        </div>
    </div>

    <!-- Import CSV Modal -->
    <div class="modal-backdrop" id="importModal">
        <div class="modal imp-modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-filetype-csv"></i></div>
                <div>
                    <h3 class="gs-mtitle">Import scores from CSV</h3>
                    <p class="gs-msub">Match students by number, fill one activity.</p>
                </div>
            </div>

            <div class="imp-body">
                <div class="gs-field">
                    <label for="importActivity">Activity</label>
                    <div class="imp-select-wrap">
                        <select id="importActivity" class="imp-select"></select>
                        <i class="bi bi-chevron-down imp-select-chev"></i>
                    </div>
                </div>

                <div class="gs-field">
                    <label for="importFile">CSV file</label>
                    <input type="file" id="importFile" accept=".csv,text/csv" class="imp-file-input">
                    <label class="imp-drop" id="importDrop" for="importFile">
                        <i class="bi bi-cloud-arrow-up imp-drop-ic"></i>
                        <span class="imp-drop-main">Drop a CSV here, or <span class="imp-drop-link">browse</span></span>
                        <span class="imp-drop-hint">Two columns: student number, score</span>
                    </label>
                    <div class="imp-file-sel" id="importFileSel">
                        <i class="bi bi-check-circle-fill imp-file-check"></i>
                        <div class="imp-file-meta">
                            <div class="imp-file-name" id="importFileName">file.csv</div>
                            <p class="bulk-note imp-file-info" id="importInfo"></p>
                        </div>
                        <button type="button" class="imp-file-remove" id="importFileRemove" aria-label="Remove file"><i class="bi bi-x-lg"></i></button>
                    </div>
                    <small class="imp-drop-foot">Header row optional. <a href="#" id="importSample">Download sample</a></small>
                    <p id="importErr" class="bulk-err" style="display:none;"></p>
                    <div id="importPreview" class="imp-preview" style="display:none;"></div>
                </div>

                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Overwrite existing scores</span>
                        <span class="imp-switch-sub">Off = fill only blank cells.</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="importOverwrite" checked>
                        <span class="imp-switch-track"></span>
                    </span>
                </label>

                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Cap scores above max</span>
                        <span class="imp-switch-sub">On = clamp over-max scores down to the max instead of flagging them.</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="importCapMax">
                        <span class="imp-switch-track"></span>
                    </span>
                </label>
            </div>

            <div class="modal-actions">
                <button class="btn btn-ghost" id="importCancel">Cancel</button>
                <button class="btn btn-primary" id="importApply" disabled><i class="bi bi-upload"></i> Import scores</button>
            </div>
        </div>
    </div>

    <!-- Copy From Section Modal -->
    <div class="modal-backdrop" id="copyModal">
        <div class="modal gs-maccent">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-copy"></i></div>
                <div>
                    <h3 class="gs-mtitle">Copy setup from another section</h3>
                    <p class="gs-msub">Reuse activity columns you already built. Scores are never copied.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:1.1rem;margin:1.25rem 0 0;">
                <div class="gs-field">
                    <label for="copyFromSection">Copy from section</label>
                    <div class="imp-select-wrap">
                        <select id="copyFromSection" class="imp-select"><option value="">— Select a section —</option></select>
                        <i class="bi bi-chevron-down imp-select-chev"></i>
                    </div>
                    <p class="bulk-note" id="copyTargetNote" style="margin:.35rem 0 0;"></p>
                </div>
                <label class="imp-switch-row">
                    <span class="imp-switch-txt">
                        <span class="imp-switch-title">Also copy categories &amp; grading settings</span>
                        <span class="imp-switch-sub">Off = copy activity columns only (flat).</span>
                    </span>
                    <span class="imp-switch">
                        <input type="checkbox" id="copyIncludeSettings" checked>
                        <span class="imp-switch-track"></span>
                    </span>
                </label>
                <p class="bulk-note" style="margin:0;"><i class="bi bi-info-circle"></i> Activities with a name already in this section are skipped, so re-copying is safe.</p>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="copyCancel">Cancel</button>
                <button class="btn btn-primary" id="copyApply" disabled><i class="bi bi-copy"></i> Copy setup</button>
            </div>
        </div>
    </div>

    <!-- Tag as Class Modal (re-tag the current sheet into a named class) -->
    <div class="modal-backdrop" id="retagModal">
        <div class="modal gs-maccent" style="max-width:480px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-tag"></i></div>
                <div>
                    <h3 class="gs-mtitle">Tag this sheet as a class</h3>
                    <p class="gs-msub">Move the sheet you're viewing into a named class. Nothing is copied — it's relabeled.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:.9rem;margin:1.1rem 0 0;">
                <p class="bulk-note" id="retagFrom" style="margin:0;"></p>
                <div class="gs-field">
                    <label for="retagSy">School Year</label>
                    <input id="retagSy" maxlength="9" placeholder="e.g. 2025-2026" autocomplete="off" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;">
                </div>
                <div class="gs-field">
                    <label for="retagSem">Semester</label>
                    <select id="retagSem" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;">
                        <option value="">— Semester —</option>
                        <option value="1st">1st Sem</option>
                        <option value="2nd">2nd Sem</option>
                        <option value="Midyear">Midyear</option>
                    </select>
                </div>
                <div class="gs-field">
                    <label for="retagSubj">Subject</label>
                    <input id="retagSubj" placeholder="Subject" autocomplete="off" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;">
                </div>
                <p id="retagErr" class="bulk-err" style="display:none;"></p>
            </div>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost" id="retagCancel">Cancel</button>
                <button class="btn btn-primary" id="retagApply"><i class="bi bi-tag"></i> Tag class</button>
            </div>
        </div>
    </div>

    <!-- Clear All Modal (danger zone) — wipes THIS teacher's whole gradebook.
         Type-to-confirm: the button stays disabled until the exact phrase is
         typed, and the server checks the same phrase again (a stray
         ?api=reset_all must not go through just because no dialog blocked it).
         Only egradebook_db's own grade_* tables are touched — the FormFlow
         forms/responses and the attendance roster/scans are read-only here. -->
    <div class="modal-backdrop" id="clearAllModal">
        <div class="modal gs-maccent" style="max-width:520px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic danger"><i class="bi bi-exclamation-octagon"></i></div>
                <div>
                    <h3 class="gs-mtitle">Clear all my data</h3>
                    <p class="gs-msub">Start over with an empty gradebook. This cannot be undone.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:.9rem;margin:1.1rem 0 0;">
                <p class="bulk-note" style="margin:0;"><i class="bi bi-trash3"></i> <b>Deleted:</b> every activity and score, grading categories, settings, form column and attendance setup, student status overrides, your classes, pinned sections, and your transmutation bands (back to the default scale) — across <b>all sections</b>, not just this one.</p>
                <p class="bulk-note" style="margin:0;"><i class="bi bi-shield-check"></i> <b>Kept:</b> your FormFlow forms and their responses, the student roster, and the attendance scans. Those live in the other apps — eGradeBook only reads them. Other teachers' gradebooks are untouched.</p>
                <div class="gs-field">
                    <label for="clearAllPhrase">Type <b>CLEAR ALL</b> to confirm</label>
                    <input id="clearAllPhrase" placeholder="CLEAR ALL" autocomplete="off" spellcheck="false" style="background:var(--surface);color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font-size:.9rem;outline:none;">
                </div>
                <p id="clearAllErr" class="bulk-err" style="display:none;"></p>
            </div>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost" id="clearAllCancel">Cancel</button>
                <button class="btn btn-danger" id="clearAllApply" disabled><i class="bi bi-trash3"></i> Delete everything</button>
            </div>
        </div>
    </div>

    <!-- Form Columns Modal — which of the section's FormFlow forms belong in this class.
         FormFlow has no subject, so a section's forms are discovered into EVERY class of
         that section (and never expire, since section names repeat each school year).
         Two levers here: claim a form for one subject (once, applies to future classes
         too), or hide it in just this class. -->
    <div class="modal-backdrop" id="formColModal">
        <div class="modal gs-maccent" style="max-width:640px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-ui-checks-grid"></i></div>
                <div>
                    <h3 class="gs-mtitle">Form columns in this class</h3>
                    <p class="gs-msub">FormFlow only tags a response with its <b>section</b>, so every class of a section sees all of its forms. Pick which ones belong here.</p>
                </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:.9rem;margin:1.1rem 0 0;">
                <p class="bulk-note" id="fcTargetNote" style="margin:0;"></p>

                <div class="gs-field">
                    <label for="fcCopyFrom">Copy hidden forms from another class</label>
                    <div class="imp-select-wrap">
                        <select id="fcCopyFrom" class="imp-select"><option value="">— Select a class —</option></select>
                        <i class="bi bi-chevron-down imp-select-chev"></i>
                    </div>
                    <p class="bulk-note" style="margin:.35rem 0 0;"><i class="bi bi-info-circle"></i> Adds that class's hidden forms here. Nothing is un-hidden, so it's safe to re-run — useful when a new term inherits years of old forms.</p>
                </div>

                <div id="fcList" class="fc-list"></div>
                <p id="fcErr" class="bulk-err" style="display:none;"></p>
            </div>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost" id="fcClose">Done</button>
            </div>
        </div>
    </div>

    <!-- Manage Sections Modal (pick which sections to show) -->
    <div class="modal-backdrop" id="pinModal">
        <div class="modal gs-maccent" style="max-width:560px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-pin-angle"></i></div>
                <div>
                    <h3 class="gs-mtitle">Choose your sections</h3>
                    <p class="gs-msub">Only the sections you check will appear in the picker.</p>
                </div>
            </div>
            <div style="margin:1.1rem 0 0;">
                <div class="gs-field">
                    <input type="text" id="pinSearch" placeholder="Search section or course…">
                </div>
                <div class="pin-toolbar">
                    <button type="button" class="btn btn-ghost btn-sm" id="pinSelectAll"><i class="bi bi-check2-all"></i> Select all</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="pinClearAll"><i class="bi bi-x-lg"></i> Clear</button>
                    <span class="pin-count" id="pinCount">0 selected</span>
                </div>
                <div class="pin-list" id="pinList"></div>
            </div>
            <div class="modal-actions" style="margin-top:1.4rem;">
                <button class="btn btn-ghost" id="pinCancel">Cancel</button>
                <button class="btn btn-primary" id="pinSave"><i class="bi bi-check-lg"></i> Save</button>
            </div>
        </div>
    </div>

    <!-- Grade Setup Modal (Option B — categories per term) -->
    <div class="modal-backdrop" id="setupModal">
        <div class="modal" style="max-width:560px;">
            <h3><i class="bi bi-sliders" style="color:var(--accent);margin-right:8px;"></i>Grade Setup — Categories &amp; Weights</h3>
            <p style="color:var(--muted);margin-top:-.3rem;">Define categories per term. Each term's weights should total <b>100%</b>. Assign activities to a term &amp; category in their column header.</p>
            <div id="setupBody" style="display:flex;flex-direction:column;gap:1rem;margin:1rem 0;max-height:52vh;overflow-y:auto;overflow-x:hidden;"></div>
            <div class="modal-actions">
                <button class="btn btn-primary" id="setupClose"><i class="bi bi-check-lg"></i> Done</button>
            </div>
        </div>
    </div>

    <!-- Transmutation Modal (global bands: raw score → 1.00–5.00 equivalent) -->
    <div class="modal-backdrop" id="tmModal">
        <div class="modal gs-maccent" style="max-width:520px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-arrow-left-right"></i></div>
                <div>
                    <h3 class="gs-mtitle">Transmutation table</h3>
                    <p class="gs-msub">Raw score cutoffs mapped to the 1.00–5.00 equivalent. Used by every section, in both flat &amp; term grading.</p>
                </div>
            </div>
            <div class="tm-legend">
                <span>Min raw score</span>
                <span>Equivalent</span>
            </div>
            <div id="tmBody" class="tm-body"></div>
            <p class="bulk-note" id="tmHint" style="margin:.5rem 0 0;"><i class="bi bi-info-circle"></i> A student gets a point once their grade reaches its min. Anything below the lowest band = Failed (5.00).</p>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost btn-sm" id="tmAddBand" style="margin-right:auto;"><i class="bi bi-plus"></i> Add band</button>
                <button class="btn btn-ghost" id="tmCancel">Cancel</button>
                <button class="btn btn-primary" id="tmSave"><i class="bi bi-check-lg"></i> Save table</button>
            </div>
        </div>
    </div>

    <!-- Per-student grade breakdown -->
    <div class="modal-backdrop" id="breakdownModal">
        <div class="modal gs-maccent" style="max-width:520px;">
            <div class="gs-mhead">
                <div class="gs-mhead-ic"><i class="bi bi-calculator"></i></div>
                <div>
                    <h3 class="gs-mtitle" id="bdName">Student</h3>
                    <p class="gs-msub" id="bdSno"></p>
                </div>
            </div>
            <div id="bdBody" class="bd-body"></div>
            <div class="modal-actions" style="margin-top:1.3rem;">
                <button class="btn btn-ghost" id="bdClose"><i class="bi bi-x-lg"></i> Close</button>
                <button class="btn btn-primary" id="bdPdf"><i class="bi bi-file-earmark-pdf"></i> Save PDF</button>
            </div>
        </div>
    </div>

    <?php include APP_ROOT . "/components/footer.php"; ?>

    <script src="assets/js/global.js?v=<?= filemtime(APP_ROOT . '/assets/js/global.js') ?>"></script>
    <script src="assets/js/grades.js?v=<?= filemtime(APP_ROOT . '/assets/js/grades.js') ?>"></script>
</body>

</html>
