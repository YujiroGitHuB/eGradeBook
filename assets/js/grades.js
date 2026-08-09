/* ============================================================
      Grading Sheet — client logic (self-contained)
      ============================================================ */
const API = 'index.php'; // same-folder relative — grades.js is only ever loaded by index.php (eGradeBook standalone app)
let SHEET = null; // { students, columns, scores, section }
let selectedCols = new Set(); // set of column keys ('f12','a3',...)
let SESSION_ENDED = false;    // 401 na nakita — isang babala lang, hindi kada request
let selectedStudents = new Set(); // set of student_no selected for bulk edit
let sortKey = null;               // null | 'name' | 'grade' — row sort column
let sortDir = 1;                  // 1 = ascending, -1 = descending

/* Per-student final status overrides. INC/DRP/W are built in; anything else is
   a teacher's custom label (shown as-is, styled with the neutral st-custom). */
const STATUS_FULL = { INC: 'Incomplete', DRP: 'Dropped', W: 'Withdrawn' };
const BUILTIN_STATUS = ['INC', 'DRP', 'W'];
const isCustomStatus = st => !!st && !BUILTIN_STATUS.includes(st);
const stBadge = st => {
    const cls = isCustomStatus(st) ? 'st-custom' : `st-${String(st).toLowerCase()}`;
    return `<span class="st-badge ${cls}" title="${escAttr(st)}">${escHtml(st)}</span>`;
};
let ALL_SECTIONS = [];        // cached section list (for the Copy-from picker)
let PINNED = new Set();       // this teacher's chosen sections (subset shown in the picker)
let SECTION_VIEW = 'pinned';  // 'pinned' = show only PINNED, 'all' = show everything

/* ── Class scope (school_year + semester + subject) ─────────
   The gradebook unit is a "class" = section + school_year + semester +
   subject. Empty fields = the LEGACY class (existing sheets). CLASS rides
   along with every API call (see apiGet/apiPost) and is persisted locally. */
let CLASS = { school_year: '', semester: '', subject: '' };
const CLASS_KEY = 'eg_class';
function loadClassPref() {
    try {
        const j = JSON.parse(localStorage.getItem(CLASS_KEY) || '{}');
        CLASS.school_year = j.school_year || '';
        CLASS.semester    = j.semester || '';
        CLASS.subject     = j.subject || '';
    } catch (e) {}
}
function saveClassPref() { try { localStorage.setItem(CLASS_KEY, JSON.stringify(CLASS)); } catch (e) {} }
function classParams() { return { school_year: CLASS.school_year, semester: CLASS.semester, subject: CLASS.subject }; }

const $ = id => document.getElementById(id);

/* ── Final grade config ─────────────────────────────────────
   Final = Midterm(coursework) × CW_WEIGHT + Final(defense) × DEF_WEIGHT
   (change only if the weights change) */
const CW_WEIGHT  = 0.50;   // Midterm — coursework (activities/forms)
const DEF_WEIGHT = 0.50;   // Final term — defense

/* fallback PH transmutation if none has come from the server yet */
const DEFAULT_EQUIV = [
    { min: 96, point: 1.00 }, { min: 94, point: 1.25 }, { min: 91, point: 1.50 },
    { min: 88, point: 1.75 }, { min: 85, point: 2.00 }, { min: 82, point: 2.25 },
    { min: 79, point: 2.50 }, { min: 76, point: 2.75 }, { min: 75, point: 3.00 },
];

/* The teacher's live bands — GLOBAL and editable in the Transmutation modal.
   Set from the sheet payload / get_transmute; falls back to DEFAULT_EQUIV.
   Single source of truth for BOTH flat Final grade and term Equivalent. */
let TRANSMUTE = null;
function equivBands() {
    if (Array.isArray(TRANSMUTE) && TRANSMUTE.length) return TRANSMUTE;
    if (SHEET && Array.isArray(SHEET.grade_equiv) && SHEET.grade_equiv.length) return SHEET.grade_equiv;
    return DEFAULT_EQUIV;
}

/* 0–100 → 1.00–5.00 (top-down; below the lowest min = 5.00). */
function transmutePoint(score) {
    for (const row of equivBands()) {
        if (score >= Number(row.min)) return Number(row.point).toFixed(2);
    }
    return '5.00';
}

const DEFENSE_PASS = 75;   // defense passing raw (≈ 3.00)

/* defense raw grade (0–100) of the student, or null if none yet.
   Single source of the defense value for render and export. */
function defenseRaw(sno) {
    const r = getRec(sno, 'dfn_raw');
    return (r && r.score !== '' && r.score !== null) ? parseFloat(r.score) : null;
}

/* Coursework grade of the student.
   • Has weights (total > 0): weighted average → Σ(score/max × weight) ÷ Σweight × 100
   • No weights: legacy points-based → Σscore ÷ Σmax × 100 (includes forms)
   Returns got/max (raw, for display), pct, gotAny, and a weighted flag. */
function courseworkGrade(s, missingZero) {
    const sel   = SHEET.columns.filter(c => selectedCols.has(c.key));
    const acts  = sel.filter(c => c.type === 'activity');
    const forms = sel.filter(c => c.type === 'form');
    const atts  = sel.filter(c => c.type === 'attendance');
    /* activities, form columns AND the auto attendance column can all carry a weight */
    const weighables = [...acts, ...forms, ...atts];
    const totalW = weighables.reduce((t, c) => t + (Number(c.weight) || 0), 0);

    let got = 0, max = 0, gotAny = false;
    weighables.forEach(c => {
        const rec = getRec(s.student_no, c.key);
        const cmax = c.max || (rec ? rec.max : 0) || 0;
        if (rec) { got += Number(rec.score) || 0; max += cmax; gotAny = true; }
        else if (missingZero) max += cmax;
    });

    if (totalW > 0) {
        let wGot = 0, wGraded = false;
        weighables.forEach(c => {
            const w = Number(c.weight) || 0;
            if (w <= 0) return;
            const rec = getRec(s.student_no, c.key);
            const cmax = c.max || (rec ? rec.max : 0) || 0;
            if (rec && cmax > 0) { wGot += (Number(rec.score) / cmax * 100) * w; wGraded = true; }
            /* no score → 0 contribution (missing = 0), but the weight is still included */
        });
        return { got, max, gotAny: wGraded, pct: wGot / totalW, weighted: true, totalW };
    }
    return { got, max, gotAny, pct: max > 0 ? (got / max * 100) : 0, weighted: false, totalW: 0 };
}

/* Final grade.
   • Subject has a defense → coursework × 30% + defense × 70%
        (null if the student has no defense yet — awaiting)
   • No defense → coursework only (100%)  */
function finalGrade(cwPct, hasCw, defRaw, sectionHasDefense) {
    if (sectionHasDefense) {
        if (defRaw === null) return null;                 // awaiting the defense
        const val = cwPct * CW_WEIGHT + defRaw * DEF_WEIGHT;
        return { val, pt: transmutePoint(val), hasCw, defRaw, mode: 'combined' };
    }
    if (!hasCw) return null;                               // no coursework yet
    return { val: cwPct, pt: transmutePoint(cwPct), hasCw, defRaw: null, mode: 'coursework' };
}

/* ── Option B: Term-based grading (Midterm/Final × weighted categories) ── */
/* Term "Equivalent" now uses the SAME global bands as the flat Final grade
   (one source of truth). Only difference from transmutePoint: below the lowest
   band returns null, so the Remark column can show "Failed" instead of 5.00. */
function transmuteExcel(score) {
    const s = Math.round(score * 10) / 10;   // ROUND(x,1) like Excel
    for (const row of equivBands()) if (s >= Number(row.min)) return Number(row.point).toFixed(2);
    return null;   // below the lowest band → Failed
}

/* Grade of a term (0–100) = Σ (categoryPct × weight) ÷ Σweight × 100
   Sinusunod nito ang PAREHONG dalawang kontrol na sinusunod ng courseworkGrade:
   ang column picker (selectedCols) at ang "Missing = 0". Dati ay binabasa nito
   ang lahat ng column at laging binibilang na 0 ang walang score — kaya sa term
   mode ay nakikita't naka-click ang dalawang kontrol pero walang epekto sa grado
   (nawawala lang sa talahanayan ang column, bilang pa rin ito). */
function termGrade(s, term) {
    const cats = (SHEET.categories || []).filter(c => c.term === term);
    if (!cats.length) return null;
    const missingZero = $('chkMissingZero') ? $('chkMissingZero').checked : false;
    let totalW = 0, acc = 0, anyScore = false;
    cats.forEach(cat => {
        /* activities, form columns AND the attendance column assigned to this term + category */
        const acts = SHEET.columns.filter(c => (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && selectedCols.has(c.key) && c.term === term && c.category_id === cat.id);
        let raw = 0, mx = 0;
        acts.forEach(a => {
            const rec = getRec(s.student_no, a.key);
            if (rec) { raw += Number(rec.score) || 0; mx += a.max || 0; anyScore = true; }
            else if (missingZero) mx += a.max || 0;
        });
        const catPct = mx > 0 ? (raw / mx) : 0;     // 0..1
        acc += catPct * (Number(cat.weight) || 0);
        totalW += Number(cat.weight) || 0;
    });
    if (totalW <= 0) return null;
    return { grade: acc / totalW * 100, anyScore, totalW };
}

/* General Average = (Midterm + Final) ÷ 2 (or if only one term, that one) */
function generalAverage(s) {
    const mid = termGrade(s, 'midterm');
    const fin = termGrade(s, 'final');
    if (mid && fin) return { ave: (mid.grade + fin.grade) / 2, mid, fin, anyScore: mid.anyScore || fin.anyScore };
    if (mid) return { ave: mid.grade, mid, fin: null, anyScore: mid.anyScore };
    if (fin) return { ave: fin.grade, mid: null, fin, anyScore: fin.anyScore };
    return null;
}

/* ── Row sorting (by name or final grade) ───────────────── */
function studentSortVal(s, key) {
    if (key === 'name') return (s.fullname || '').toLowerCase();
    if (key === 'grade') {
        if (SHEET.term_mode === true) {
            const ga = generalAverage(s);
            return (ga && ga.anyScore) ? ga.ave : null;   // ungraded → null (sorted last)
        }
        const cg = courseworkGrade(s, $('chkMissingZero').checked);
        return cg.gotAny ? cg.pct : null;
    }
    return null;
}

function sortStudents(list) {
    if (!sortKey) return list;
    return list.slice().sort((a, b) => {
        const va = studentSortVal(a, sortKey);
        const vb = studentSortVal(b, sortKey);
        if (va === null && vb === null) return 0;          // keep ungraded together, always last
        if (va === null) return 1;
        if (vb === null) return -1;
        if (typeof va === 'string') return sortDir * va.localeCompare(vb);
        return sortDir * (va - vb);
    });
}

const sortArrow = k => sortKey !== k ? ''
    : (sortDir === 1 ? ' <i class="bi bi-caret-up-fill sort-ar"></i>' : ' <i class="bi bi-caret-down-fill sort-ar"></i>');

/* Free-hosting (InfinityFree / free.nf) anti-bot check: instead of our JSON the
   host sometimes returns a tiny JavaScript challenge (aes.js / toNumbers() /
   __test cookie) that only a full-page navigation can solve. Detect it so we
   don't dump raw markup as an "error", and recover by reloading once. */
function isHostChallenge(txt) {
    if (!txt) return false;
    const t = txt.slice(0, 800).toLowerCase();
    return t.includes('aes.js')
        || t.includes('tonumbers(')
        || t.includes('slowaes')
        || t.includes('__test');
}

let __hostReloadScheduled = false;
let __hostChallengeNotified = false;
function handleHostChallenge() {
    /* only one action per page life — many parallel API calls can all trip this */
    if (__hostReloadScheduled || __hostChallengeNotified) return;
    const KEY = 'ff_hostcheck_ts';
    const last = parseInt(sessionStorage.getItem(KEY) || '0', 10);
    const recentlyReloaded = Date.now() - last < 20000;   // reloaded within last 20s?
    if (!recentlyReloaded) {
        /* First hit (or an old one): a full reload lets the browser run the
           host's script and set the __test cookie, after which fetch() works. */
        __hostReloadScheduled = true;
        sessionStorage.setItem(KEY, String(Date.now()));
        showToastSafe('Verifying your browser with the host… reloading.', 'info');
        setTimeout(() => location.reload(), 1200);
    } else {
        /* Already reloaded and STILL challenged → stop looping; explain it. */
        __hostChallengeNotified = true;
        showToastSafe('The host’s security check is blocking data requests. Wait a moment and try again, or use a different browser/network.', 'error');
    }
}

/* Normalise an API text response: host-challenge → {challenge}, bad JSON →
   friendly message, otherwise the parsed JSON. */
function parseApiResponse(txt) {
    if (isHostChallenge(txt)) {
        handleHostChallenge();
        return { success: false, challenge: true, message: 'Host security check — please wait…' };
    }
    let d;
    try {
        d = JSON.parse(txt);
    } catch (e) {
        console.error('non-JSON:', txt);
        return { success: false, message: (txt || '').trim().slice(0, 200) || 'Unexpected server response.' };
    }
    /* Nag-expire ang session (401 mula sa Auth::requireLogin). Walang
       mase-save mula rito, kaya sabihin ito nang malinaw at ibalik sa login
       sa halip na hayaang mabigo nang tahimik ang bawat susunod na pindot. */
    if (d && d.auth === false && !SESSION_ENDED) {
        SESSION_ENDED = true;
        showToastSafe(d.message || 'Your session expired. Please log in again.', 'error');
        setTimeout(() => { window.location.href = 'login.php'; }, 1800);
    }
    return d;
}

async function apiGet(params) {
    const qs = new URLSearchParams({ ...classParams(), ...params });
    try {
        const res = await fetch(`${API}?${qs}`);
        return parseApiResponse(await res.text());
    } catch (e) {
        return { success: false, message: 'Network error — check your connection.' };
    }
}
async function apiPost(params) {
    const fd = new FormData();
    Object.entries({ ...classParams(), ...params }).forEach(([k, v]) => fd.append(k, v));
    try {
        const res = await fetch(API, { method: 'POST', body: fd });
        return parseApiResponse(await res.text());
    } catch (e) {
        return { success: false, message: 'Network error — check your connection.' };
    }
}

/* ── Load sections on start ─────────────────────────────── */
async function loadSections() {
    const sel = $('selSection');
    /* fetch the full section list + this teacher's pinned subset in parallel */
    const [d, p] = await Promise.all([
        apiGet({ api: 'sections' }),
        apiGet({ api: 'pinned_sections' })
    ]);
    if (!d.success) {
        sel.innerHTML = `<option value="">⚠ ${escAttr(d.message || 'Error')}</option>`;
        return;
    }
    if (!d.sections.length) {
        sel.innerHTML = `<option value="">No sections found</option>`;
        return;
    }
    ALL_SECTIONS = d.sections;
    PINNED = new Set((p && p.success && Array.isArray(p.pinned)) ? p.pinned : []);
    /* if nothing pinned yet, default the picker to "All" so it isn't empty */
    if (PINNED.size === 0) SECTION_VIEW = 'all';
    renderSectionOptions();
}

/* ── Class pickers: subjects (per section) + school-year suggestions ── */
async function loadSubjectsFor(section) {
    const dl = $('subjectList');
    if (!dl) return;
    if (!section) { dl.innerHTML = ''; return; }
    const d = await apiGet({ api: 'subjects', section });
    const subs = (d && d.success && Array.isArray(d.subjects)) ? d.subjects : [];
    /* suggestions only — Subject is a free-text input so a teacher can grade a
       subject even before any QR attendance exists for it */
    dl.innerHTML = subs.map(s => `<option value="${escAttr(s)}"></option>`).join('');
}
async function loadSchoolYears() {
    const dl = $('syList');
    if (!dl) return;
    const d = await apiGet({ api: 'school_years' });
    const years = (d && d.success && Array.isArray(d.school_years)) ? d.school_years : [];
    dl.innerHTML = years.map(y => `<option value="${escAttr(y)}"></option>`).join('');
}
/* ── Class dropdown: list, select, create ── */
let CLASSES = [];   // {school_year, semester, subject}[] for the current section
const classKey = c => `${c.school_year}${c.semester}${c.subject}`;
const classLabel = c => [c.school_year, c.semester, c.subject].filter(Boolean).join(' · ') || 'Existing (untagged) sheet';
const classIsLegacy = () => !CLASS.school_year && !CLASS.semester && !CLASS.subject;

/* Fill the Class <select> for a section: legacy + this teacher's classes +
   "New class…". The current class is kept selected (carried across sections
   even if it isn't in the new section's list yet). */
async function loadClasses(section) {
    const sel = $('selClass');
    if (!sel) return;
    hideNewClassForm();
    if (!section) { sel.innerHTML = '<option value="__legacy__">Existing (untagged) sheet</option>'; return; }
    const d = await apiGet({ api: 'classes', section });
    CLASSES = (d && d.success && Array.isArray(d.classes)) ? d.classes : [];
    const curKey = classKey(CLASS);
    let html = '<option value="__legacy__">Existing (untagged) sheet</option>'
        + CLASSES.map(c => `<option value="${escAttr(classKey(c))}">${escHtml(classLabel(c))}</option>`).join('');
    if (!classIsLegacy() && !CLASSES.some(c => classKey(c) === curKey)) {
        /* carry the current class across sections even if not registered there yet */
        html += `<option value="${escAttr(curKey)}">${escHtml(classLabel(CLASS))}</option>`;
        CLASSES.push({ school_year: CLASS.school_year, semester: CLASS.semester, subject: CLASS.subject });
    }
    html += '<option value="__new__">➕ New class…</option>';
    sel.innerHTML = html;
    sel.value = classIsLegacy() ? '__legacy__' : curKey;
    updateDeleteClassBtn();
}

/* Ipakita ang trash button para lang sa PANGALANANG klase — walang buburahin
   sa untagged sheet, at tinatanggihan iyon ng server. */
function updateDeleteClassBtn() {
    const b = $('btnDeleteClass');
    if (!b) return;
    b.style.display = classIsLegacy() ? 'none' : 'inline-flex';
}

/* Burahin ang kasalukuyang klase. Ang server ang nagpapasya: tumatanggi ito
   kapag may activity o status override pa ang klase, kaya hindi ito makakabura
   ng grado. Ang setup lang (settings, categories, form/attendance column setup)
   ang kasamang nililinis — sinasabi ito ng kumpirmasyon nang tahasan. */
async function deleteCurrentClass() {
    if (!SHEET || classIsLegacy()) return;
    const label = classLabel(CLASS);
    if (!await uiConfirm({
        title: 'Delete this class?',
        message: `<b>${escHtml(label)}</b> — its grading setup (settings, categories, form and attendance column setup) is removed with it. No scores are touched; if the class still has activities, this will be refused.`,
        ok: 'Delete class',
        icon: 'bi-trash',
        danger: true,
    })) return;

    const d = await apiPost({ api: 'delete_class', section: SHEET.section });
    if (!d.success) { showToastSafe(d.message || 'Could not delete the class.', 'error'); return; }

    CLASS = { school_year: '', semester: '', subject: '' };   // bumalik sa untagged sheet
    saveClassPref();
    await loadClasses(SHEET.section);
    updateDeleteClassBtn();
    loadSheet(SHEET.section);
    const cleared = Array.isArray(d.cleared) && d.cleared.length ? ` Cleared its ${d.cleared.join(', ')}.` : '';
    showToastSafe(`Deleted the class "${label}".${cleared}`, 'success');
}

function setClassFromSelect() {
    const v = $('selClass').value;
    if (v === '__legacy__') CLASS = { school_year: '', semester: '', subject: '' };
    else {
        const c = CLASSES.find(x => classKey(x) === v);
        if (c) CLASS = { school_year: c.school_year, semester: c.semester, subject: c.subject };
    }
    saveClassPref();
}

async function onSelClassChange() {
    if ($('selClass').value === '__new__') { showNewClassForm(); return; }
    hideNewClassForm();
    setClassFromSelect();
    updateDeleteClassBtn();
    const section = $('selSection').value;
    if (section) loadSheet(section);
}

function showNewClassForm() {
    const f = $('newClassForm');
    if (!f) return;
    $('selSchoolYear').value = '';
    $('selSemester').value = '';
    $('selSubject').value = '';
    loadSchoolYears();
    loadSubjectsFor($('selSection').value);
    /* Sabihin kung SAAN gagawin ang klase. Nakapatong ang bagong klase sa
       section na bukas, pero sa oras na bumukas ang form ay "New class…" na
       ang nakasulat sa dropdown — wala nang natitirang nagsasabi niyon. */
    const hint = $('newClassHint');
    const sec = $('selSection').value;
    if (hint) {
        hint.innerHTML = sec
            ? `<i class="bi bi-plus-circle"></i> New class for <b>${escHtml(sec)}</b>`
            : '<i class="bi bi-plus-circle"></i> New class';
    }
    f.style.display = 'flex';
    $('selSchoolYear').focus();
}
function hideNewClassForm() {
    const f = $('newClassForm');
    if (f) f.style.display = 'none';
}
function revertClassSelect() {
    hideNewClassForm();
    const sel = $('selClass');
    if (sel) sel.value = classIsLegacy() ? '__legacy__' : classKey(CLASS);
}

async function onCreateClass() {
    const sy = $('selSchoolYear').value.trim();
    const sem = $('selSemester').value;
    const subj = $('selSubject').value.trim();
    if (!sy && !sem && !subj) { showToastSafe('Enter a school year, semester, or subject.', 'error'); return; }
    const section = $('selSection').value;
    if (!section) { showToastSafe('Pick a section first.', 'error'); return; }
    const d = await apiPost({ api: 'create_class', section, school_year: sy, semester: sem, subject: subj });
    if (!d.success) { showToastSafe(d.message || 'Could not create class.', 'error'); return; }
    CLASS = { school_year: sy, semester: sem, subject: subj };
    saveClassPref();
    await loadClasses(section);
    $('selClass').value = classKey(CLASS);
    hideNewClassForm();
    loadSheet(section);
}

/* ── Render the section <select>, filtered by the current view ── */
function renderSectionOptions() {
    const sel = $('selSection');
    const current = sel.value;   // keep the current pick if still visible

    const usePinned = (SECTION_VIEW === 'pinned' && PINNED.size > 0);
    const list = usePinned ? ALL_SECTIONS.filter(s => PINNED.has(s.section)) : ALL_SECTIONS;

    /* update the My/All toggle chip */
    const chip = $('pinViewToggle');
    if (chip) {
        chip.textContent = usePinned ? 'My sections' : 'All sections';
        chip.classList.toggle('is-all', !usePinned);
        chip.title = usePinned
            ? 'Showing your pinned sections — tap to show all'
            : 'Showing all sections — tap to show only your pinned ones';
    }

    if (!list.length) {
        sel.innerHTML = `<option value="">No pinned sections — tap ⚙ to add</option>`;
        MSEL_LIST = [];
        renderMselList();
        syncMselLabel();
        return;
    }
    sel.innerHTML = `<option value="">— Select section —</option>` +
        list.map(s => {
            const label = (s.course ? s.course + ' · ' : '') + s.section + ` (${s.count})`;
            return `<option value="${escAttr(s.section)}">${escAttr(label)}</option>`;
        }).join('');

    /* restore the previous selection if it's still in the filtered list */
    if (current && list.some(s => s.section === current)) sel.value = current;

    /* mirror into the custom (modern) dropdown */
    MSEL_LIST = list;
    renderMselList();
    syncMselLabel();
}

/* ── Modern section dropdown (custom skin over the native <select>) ── */
let MSEL_LIST = [];   // sections currently shown in the custom panel
function renderMselList() {
    const box = $('mselList');
    if (!box) return;
    const cur = $('selSection').value;
    const q = ($('mselSearch') ? $('mselSearch').value : '').trim().toLowerCase();
    if (!MSEL_LIST.length) {
        box.innerHTML = `<div class="msel-empty">No pinned sections — tap ⚙ to add</div>`;
        return;
    }
    const filtered = MSEL_LIST.filter(s => {
        const label = ((s.course ? s.course + ' · ' : '') + s.section).toLowerCase();
        return !q || label.includes(q);
    });
    if (!filtered.length) {
        box.innerHTML = `<div class="msel-empty">No section matches “${escHtml(q)}”.</div>`;
        return;
    }
    box.innerHTML = filtered.map(s => {
        const label = (s.course ? s.course + ' · ' : '') + s.section;
        const on = s.section === cur;
        return `<button type="button" class="msel-opt ${on ? 'on' : ''}" role="option" data-sec="${escAttr(s.section)}">
            <span class="msel-opt-name">${escHtml(label)}</span>
            <span class="msel-opt-count">${s.count}</span>
            ${on ? '<i class="bi bi-check2 msel-opt-check"></i>' : ''}
        </button>`;
    }).join('');
    box.querySelectorAll('.msel-opt').forEach(b => {
        b.addEventListener('click', () => {
            const sel = $('selSection');
            sel.value = b.dataset.sec;
            sel.dispatchEvent(new Event('change'));   // reuse existing change → loadSheet
            syncMselLabel();
            closeMsel();
        });
    });
}
function syncMselLabel() {
    const sel = $('selSection');
    const lbl = $('mselLabel');
    if (!sel || !lbl) return;
    const opt = sel.options[sel.selectedIndex];
    lbl.textContent = (sel.value && opt) ? opt.textContent : '— Select section —';
    lbl.classList.toggle('is-placeholder', !sel.value);
    const box = $('mselList');
    if (box) box.querySelectorAll('.msel-opt').forEach(b => b.classList.toggle('on', b.dataset.sec === sel.value));
}
function openMsel() {
    const p = $('mselPanel'); if (!p) return;
    p.classList.add('show');
    $('mselBtn').setAttribute('aria-expanded', 'true');
    const s = $('mselSearch');
    if (s) { s.value = ''; renderMselList(); setTimeout(() => s.focus(), 30); }
}
function closeMsel() {
    const p = $('mselPanel'); if (!p) return;
    p.classList.remove('show');
    $('mselBtn').setAttribute('aria-expanded', 'false');
}
function toggleMsel() {
    $('mselPanel') && $('mselPanel').classList.contains('show') ? closeMsel() : openMsel();
}

/* ── Toggle between "My sections" and "All sections" ─────── */
function toggleSectionView() {
    SECTION_VIEW = (SECTION_VIEW === 'pinned') ? 'all' : 'pinned';
    renderSectionOptions();
}

/* ── Manage-sections modal ──────────────────────────────── */
let pinDraft = new Set();   // working copy while the modal is open

function openPinModal() {
    pinDraft = new Set(PINNED);
    $('pinSearch').value = '';
    buildPinList('');
    $('pinModal').classList.add('show');
}
function closePinModal() { $('pinModal').classList.remove('show'); }

function buildPinList(filter) {
    const wrap = $('pinList');
    const q = (filter || '').trim().toLowerCase();
    const rows = ALL_SECTIONS.filter(s => {
        if (!q) return true;
        return (s.section + ' ' + (s.course || '')).toLowerCase().includes(q);
    });
    if (!rows.length) {
        wrap.innerHTML = `<div class="pin-empty">No sections match "${escHtml(filter)}".</div>`;
    } else {
        wrap.innerHTML = rows.map(s => {
            const checked = pinDraft.has(s.section) ? 'checked' : '';
            const course = s.course ? `<span class="pin-meta">${escHtml(s.course)} · ${s.count} students</span>` : `<span class="pin-meta">${s.count} students</span>`;
            return `<label class="pin-row">
                        <input type="checkbox" data-section="${escAttr(s.section)}" ${checked}>
                        <span class="pin-name">${escHtml(s.section)}</span>
                        ${course}
                    </label>`;
        }).join('');
    }
    updatePinCount();
}

function updatePinCount() {
    $('pinCount').textContent = `${pinDraft.size} selected`;
}

async function savePinnedSections() {
    const btn = $('pinSave');
    btn.disabled = true;
    const arr = [...pinDraft];
    const d = await apiPost({ api: 'save_pinned_sections', sections: JSON.stringify(arr) });
    btn.disabled = false;
    if (!d.success) { showToastSafe(d.message || 'Could not save.', 'error'); return; }

    PINNED = new Set(arr);
    /* if they pinned something, snap back to "My sections" view */
    SECTION_VIEW = (PINNED.size > 0) ? 'pinned' : 'all';
    renderSectionOptions();
    closePinModal();
    showToastSafe(`Saved — ${PINNED.size} section${PINNED.size === 1 ? '' : 's'} in your list.`, 'success');
}

/* ── Load a section's sheet ─────────────────────────────── */
async function loadSheet(section) {
    if (!section) {
        SHEET = null;
        $('btnAddActivity').title = 'Select a section first';
        $('gsStats').style.display = 'none';
        $('gsColumns').style.display = 'none';
        $('gsArea').innerHTML = `<div class="gs-empty"><i class="bi bi-table"></i>Select a section above to view the grading sheet.</div>`;
        return;
    }
    $('gsArea').innerHTML = `<div class="gs-empty"><div class="spinner-accent" style="margin:0 auto 1rem;"></div>Loading grading sheet…</div>`;
    const d = await apiGet({
        api: 'sheet',
        section
    });
    if (!d.success) {
        if (d.challenge) {
            /* host anti-bot check — a reload is already scheduled (handleHostChallenge) */
            $('gsArea').innerHTML = `<div class="gs-empty"><div class="spinner-accent" style="margin:0 auto 1rem;"></div>
                Verifying your browser with the host…
                <div style="color:var(--muted);font-size:.85rem;margin-top:.4rem;">This page will reload automatically. If it keeps looping, try another browser or network.</div></div>`;
        } else {
            $('gsArea').innerHTML = `<div class="gs-empty"><i class="bi bi-exclamation-triangle"></i>${escHtml(d.message || 'Error loading sheet')}</div>`;
        }
        return;
    }
    const prevSection = SHEET ? SHEET.section : null;
    SHEET = d;
    if (Array.isArray(d.grade_equiv) && d.grade_equiv.length) TRANSMUTE = d.grade_equiv;   // keep global bands in sync
    if (prevSection !== d.section) selectedStudents.clear();   // reset selection on the new section
    $('btnAddActivity').title = '';
    /* default selected: columns that have content; activities always checked */
    selectedCols = new Set(d.columns.filter(c => c.type === 'activity' || c.responded > 0).map(c => c.key));
    if (selectedCols.size === 0) d.columns.forEach(c => selectedCols.add(c.key));
    renderColumnPicker();

    /* term grading (Option B) */
    $('chkTermMode').checked = d.term_mode === true;
    $('btnGradeSetup').style.display = d.term_mode === true ? '' : 'none';

    /* auto attendance column toggle */
    if ($('chkAttendance')) $('chkAttendance').checked = d.attendance_enabled === true;

    render();
}

/* ── Column picker ──────────────────────────────────────── */
function renderColumnPicker() {
    const box = $('colTags');
    const hiddenForms = SHEET.hidden_forms || [];
    /* Keep the picker visible when every column is hidden — otherwise there
       would be no way back to the "restore" chips below. */
    if (!SHEET.columns.length && !hiddenForms.length) {
        $('gsColumns').style.display = 'none';
        return;
    }
    $('gsColumns').style.display = 'block';
    box.innerHTML = SHEET.columns.map(c => {
        const on = selectedCols.has(c.key);
        const icon = c.type === 'activity'   ? '<i class="bi bi-pencil-square" style="color:var(--accent2)"></i> '
                   : c.type === 'defense'    ? '<i class="bi bi-shield-check" style="color:var(--accent)"></i> '
                   : c.type === 'attendance' ? '<i class="bi bi-calendar-check" style="color:var(--accent)"></i> '
                   : '';
        const meta = c.type === 'defense'
                   ? `${c.responded}/${SHEET.students.length} · live`
                   : c.type === 'attendance'
                   ? `${c.responded}/${SHEET.students.length} present · ${c.max || 0} sessions`
                   : `${c.responded}/${SHEET.students.length} · ${c.max || '?'} pts`;
        return `<label class="gs-tag ${on ? 'on' : ''}" data-key="${c.key}">
                        <input type="checkbox" ${on ? 'checked' : ''}>
                        ${icon}${escHtml(c.title)}
                        <span class="ct">${meta}</span>
                    </label>`;
    }).join('');

    /* Forms of this section left out of THIS class — they stay live in FormFlow
       and in the section's other classes. One door into the modal, which scales
       when years of old forms pile up on a reused section name. */
    if (hiddenForms.length) {
        box.innerHTML += `<span class="gs-hidden-wrap" data-tip="Forms from this section that are not part of this class. FormFlow has no subject, so every class of a section sees all of its forms.">
                    <span class="gs-hidden-lbl"><i class="bi bi-eye-slash"></i> ${hiddenForms.length} form${hiddenForms.length > 1 ? 's' : ''} not in this class</span>
                    <button class="gs-tag gs-tag-hidden" id="colTagsManage" data-tip="Choose which forms belong in this class">Manage <i class="bi bi-sliders"></i></button>
                </span>`;
    }

    box.querySelectorAll('.gs-tag').forEach(tag => {
        const cb = tag.querySelector('input');
        if (!cb) return;                       // the Manage button, wired below
        cb.addEventListener('change', e => {
            const key = tag.dataset.key;
            if (e.target.checked) {
                selectedCols.add(key);
                tag.classList.add('on');
            } else {
                selectedCols.delete(key);
                tag.classList.remove('on');
            }
            render();
        });
    });
    const mng = $('colTagsManage');
    if (mng) mng.addEventListener('click', openFormColModal);
}

/* get a student's record for a column */
function getRec(studentNo, key) {
    return (SHEET.scores[studentNo] || {})[key];
}

/* Computed pass/fail of a student — 'passed' | 'failed' | '' (empty when not
   graded yet, or when a status override INC/DRP/W applies). Used by search so
   a teacher can type "passed" / "failed" to filter the roster. */
function studentPassFail(s) {
    const status = (SHEET.statuses || {})[s.student_no] || '';
    if (status) return '';                       // INC/DRP/W → not a pass/fail
    if (SHEET.term_mode === true) {
        const ga = generalAverage(s);
        if (!ga || !ga.anyScore) return '';
        return transmuteExcel(ga.ave) !== null ? 'passed' : 'failed';
    }
    const cg = courseworkGrade(s, $('chkMissingZero') ? $('chkMissingZero').checked : false);
    if (!cg.gotAny) return '';
    const pass = clampPct(parseFloat($('numPass').value) || 0);
    return cg.pct >= pass ? 'passed' : 'failed';
}

/* ── Render the sheet ───────────────────────────────────── */
function render() {
    if (!SHEET) return;
    const pass = clampPct(parseFloat($('numPass').value) || 0);
    const missingZero = $('chkMissingZero').checked;
    const search = $('txtSearch').value.trim().toLowerCase();

    let cols = SHEET.columns.filter(c => selectedCols.has(c.key));
    const termMode = SHEET.term_mode === true;
    if (termMode) {
        /* Group by term ONLY (Midterm → Final). Within each term, the
           manual drag order (sort_order in SHEET.columns) is what's followed.
           Array.sort is stable so the drag order is preserved when the
           term is the same (return 0). This doesn't affect computation — the
           termGrade() filters by term + category_id, not by position. */
        const termRank = t => (t === 'midterm' ? 0 : t === 'final' ? 1 : 2);
        cols = cols.slice().sort((a, b) => {
            if (a.type !== 'activity' || b.type !== 'activity') return 0;
            return termRank(a.term) - termRank(b.term);
        });
    }
    /* A search that is exactly a status code ("inc"/"drp"/"w") is treated as a
       status-only filter — otherwise a bare "w" would also match every name
       containing "w". Likewise "passed"/"failed" (or "pass"/"fail") filter by
       the computed remark. Anything else is a normal contains-match on name,
       student no., status label, and pass/fail word. */
    const codeSearch = search && STATUS_FULL[search.toUpperCase()] ? search.toUpperCase() : null;
    const pfSearch = (search === 'passed' || search === 'pass') ? 'passed'
                   : (search === 'failed' || search === 'fail') ? 'failed' : null;
    let students = SHEET.students.filter(s => {
        if (!search) return true;
        const st = (SHEET.statuses || {})[s.student_no] || '';
        if (codeSearch) return st === codeSearch;
        if (pfSearch)   return studentPassFail(s) === pfSearch;
        const stText = st ? (st + ' ' + (STATUS_FULL[st] || '')).toLowerCase() : studentPassFail(s);
        return (s.fullname || '').toLowerCase().includes(search)
            || (s.student_no || '').toLowerCase().includes(search)
            || stText.includes(search);
    });

    if (!students.length) {
        const msg = search ? 'No matching student.' : 'No students found in this section.';
        $('gsArea').innerHTML = `<div class="gs-empty"><i class="bi bi-person-x"></i>${msg}</div>`;
        $('gsStats').style.display = 'none';
        return;
    }

    students = sortStudents(students);

    const hasCols = cols.length > 0;
    const hasDefense = false;   // no live defense — activity/import is the only channel
    const hasCourse  = SHEET.columns.some(c => c.type === 'activity' || c.type === 'form' || c.type === 'attendance');
    const hasFinal   = hasCourse || hasDefense;   // final gumagana may defense man o wala

    let head = `<tr><th class="col-sel"><input type="checkbox" id="selAllRows" title="Select all"></th><th class="col-no">#</th><th class="col-name sortable" data-sort="name" title="Sort by name" style="text-align:left;">Student${sortArrow('name')}</th>`;
    cols.forEach(c => {
        if (c.type === 'activity') {
            let sub;
            if (termMode) {
                const cats = (SHEET.categories || []).filter(k => k.term === c.term);
                const catOpts = `<option value="">cat?</option>` + cats.map(k =>
                    `<option value="${k.id}" ${k.id === c.category_id ? 'selected' : ''}>${escHtml(k.name)}</option>`).join('');
                sub = `<span class="sub sub-term">
                        <span class="tc-row">
                            <select class="act-term-edit" data-aid="${c.id}" data-key="${c.key}" data-tip="Term (Midterm / Final)">
                                <option value="" ${!c.term ? 'selected' : ''}>term?</option>
                                <option value="midterm" ${c.term === 'midterm' ? 'selected' : ''}>Midterm</option>
                                <option value="final" ${c.term === 'final' ? 'selected' : ''}>Final</option>
                            </select>
                            <select class="act-cat-edit" data-aid="${c.id}" data-key="${c.key}" data-tip="Category">${catOpts}</select>
                        </span>
                        <span class="mx">max <input type="number" class="act-max-edit" min="1" value="${c.max || 100}" data-aid="${c.id}" data-key="${c.key}" data-tip="Maximum points"></span>
                    </span>`;
            } else {
                sub = `<span class="sub">/
                                <input type="number" class="act-max-edit" min="1" value="${c.max || 100}"
                                    data-aid="${c.id}" data-key="${c.key}" data-tip="Maximum points"> ·
                                <input type="number" class="act-wt-edit" min="0" step="1" value="${(+c.weight || 0)}"
                                    data-aid="${c.id}" data-key="${c.key}" data-tip="Weight % (Excel-style; 0 = no weight)">% wt</span>`;
            }
            let _catName = '';
            if (termMode) {
                const _kc = (SHEET.categories || []).find(k => k.id === c.category_id && k.term === c.term);
                _catName = _kc ? _kc.name : '';
            }
            const _printBits = termMode
                ? [c.term ? (c.term === 'midterm' ? 'Midterm' : 'Final') : '', _catName, `max ${c.max || 100}`].filter(Boolean)
                : [`max ${c.max || 100}`, (+c.weight > 0 ? `${+c.weight}% wt` : '')].filter(Boolean);
            const _printSub = `<span class="act-print-sub">${_printBits.map(escHtml).join(' · ')}</span>`;
            head += `<th class="act-col ${c.linked ? 'is-linked' : ''}" data-aid="${c.id}" data-colkey="${c.key}"><span class="act-head">
                            <span class="act-drag" draggable="true" data-colkey="${c.key}" data-tip="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
                            <input type="text" class="act-title-edit" value="${escAttr(c.title)}"
                                data-aid="${c.id}" data-key="${c.key}" data-tip="Click to rename">
                            <button class="act-link ${c.linked ? 'on' : ''}" data-aid="${c.id}" data-key="${c.key}" data-tip="${c.linked ? 'Sync ON — editing a score updates every cell with the same value. Click to turn off.' : 'Sync same scores — when ON, editing a cell also updates all cells that share the same value.'}"><i class="bi bi-link-45deg"></i></button>
                            <button class="act-fill" data-aid="${c.id}" data-tip="Fill the same score for all students"><i class="bi bi-arrow-bar-down"></i></button>
                            <button class="act-del" data-aid="${c.id}" data-tip="Delete this activity">&times;</button></span>
                            ${sub}<span class="act-print">${escHtml(c.title)}${_printSub}</span></th>`;
        } else if (c.type === 'defense') {
            const subTxt = c.scale === '5' ? 'defense · 1.00–5.00' : 'defense · live avg';
            head += `<th class="dfn-col" title="From defense panel (live, read-only)">${escHtml(c.title)}<span class="sub">${subTxt}</span></th>`;
        } else if (c.type === 'form') {
            /* Form column (from FormFlow). Title / max / scores are READ-ONLY
               here — only the eGradeBook overlay (term, category, weight, order)
               is editable, so it can join term-mode / weighted grading. */
            let fsub;
            if (termMode) {
                const cats = (SHEET.categories || []).filter(k => k.term === c.term);
                const catOpts = `<option value="">cat?</option>` + cats.map(k =>
                    `<option value="${k.id}" ${k.id === c.category_id ? 'selected' : ''}>${escHtml(k.name)}</option>`).join('');
                fsub = `<span class="sub sub-term">
                        <span class="tc-row">
                            <select class="frm-term-edit" data-fid="${c.id}" data-key="${c.key}" data-tip="Term (Midterm / Final)">
                                <option value="" ${!c.term ? 'selected' : ''}>term?</option>
                                <option value="midterm" ${c.term === 'midterm' ? 'selected' : ''}>Midterm</option>
                                <option value="final" ${c.term === 'final' ? 'selected' : ''}>Final</option>
                            </select>
                            <select class="frm-cat-edit" data-fid="${c.id}" data-key="${c.key}" data-tip="Category">${catOpts}</select>
                        </span>
                        <span class="mx">max <b>${c.max || '?'}</b> <span class="frm-ro" data-tip="Set in FormFlow">FormFlow</span></span>
                    </span>`;
            } else {
                fsub = `<span class="sub">/ <b>${c.max || '?'}</b> ·
                            <input type="number" class="frm-wt-edit" min="0" step="1" value="${(+c.weight || 0)}"
                                data-fid="${c.id}" data-key="${c.key}" data-tip="Weight % (Excel-style; 0 = no weight)">% wt</span>`;
            }
            let _fcatName = '';
            if (termMode) {
                const _fkc = (SHEET.categories || []).find(k => k.id === c.category_id && k.term === c.term);
                _fcatName = _fkc ? _fkc.name : '';
            }
            const _fprintBits = termMode
                ? [c.term ? (c.term === 'midterm' ? 'Midterm' : 'Final') : '', _fcatName, `max ${c.max || '?'}`].filter(Boolean)
                : [`max ${c.max || '?'}`, (+c.weight > 0 ? `${+c.weight}% wt` : '')].filter(Boolean);
            const _fprintSub = `<span class="act-print-sub">${_fprintBits.map(escHtml).join(' · ')}</span>`;
            head += `<th class="act-col frm-col" data-colkey="${c.key}"><span class="act-head">
                            <span class="act-drag" draggable="true" data-colkey="${c.key}" data-tip="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
                            <span class="frm-title" data-tip="From FormFlow — rename it there"><i class="bi bi-ui-checks-grid frm-ic"></i>${escHtml(c.title)}</span>
                            <button class="frm-hide" data-fid="${c.id}" data-tip="Hide in this class — FormFlow shows a section's forms in every class of that section. Hiding removes it here only (and from the grade); it stays in FormFlow and in your other classes."><i class="bi bi-eye-slash"></i></button></span>
                            ${fsub}<span class="act-print">${escHtml(c.title)}${_fprintSub}</span></th>`;
        } else if (c.type === 'attendance') {
            /* Attendance column — AUTO from the QR scans. Score (present ÷
               sessions) is READ-ONLY; only the eGradeBook overlay (term,
               category, weight, order) is editable so it can join term /
               weighted grading, like a form column. */
            let asub;
            if (termMode) {
                const cats = (SHEET.categories || []).filter(k => k.term === c.term);
                const catOpts = `<option value="">cat?</option>` + cats.map(k =>
                    `<option value="${k.id}" ${k.id === c.category_id ? 'selected' : ''}>${escHtml(k.name)}</option>`).join('');
                asub = `<span class="sub sub-term">
                        <span class="tc-row">
                            <select class="att-term-edit" data-key="${c.key}" data-tip="Term (Midterm / Final)">
                                <option value="" ${!c.term ? 'selected' : ''}>term?</option>
                                <option value="midterm" ${c.term === 'midterm' ? 'selected' : ''}>Midterm</option>
                                <option value="final" ${c.term === 'final' ? 'selected' : ''}>Final</option>
                            </select>
                            <select class="att-cat-edit" data-key="${c.key}" data-tip="Category">${catOpts}</select>
                        </span>
                        <span class="mx">max <b>${c.max || 0}</b> <span class="frm-ro" data-tip="Auto from QR attendance scans">sessions</span></span>
                    </span>`;
            } else {
                asub = `<span class="sub">/ <b>${c.max || 0}</b> ·
                            <input type="number" class="att-wt-edit" min="0" step="1" value="${(+c.weight || 0)}"
                                data-key="${c.key}" data-tip="Weight % (Excel-style; 0 = no weight)">% wt</span>`;
            }
            let _acatName = '';
            if (termMode) {
                const _akc = (SHEET.categories || []).find(k => k.id === c.category_id && k.term === c.term);
                _acatName = _akc ? _akc.name : '';
            }
            const _aprintBits = termMode
                ? [c.term ? (c.term === 'midterm' ? 'Midterm' : 'Final') : '', _acatName, `${c.max || 0} sessions`].filter(Boolean)
                : [`${c.max || 0} sessions`, (+c.weight > 0 ? `${+c.weight}% wt` : '')].filter(Boolean);
            const _aprintSub = `<span class="act-print-sub">${_aprintBits.map(escHtml).join(' · ')}</span>`;
            head += `<th class="act-col frm-col att-col" data-colkey="${c.key}"><span class="act-head">
                            <span class="act-drag" draggable="true" data-colkey="${c.key}" data-tip="Drag to reorder"><i class="bi bi-grip-vertical"></i></span>
                            <span class="frm-title" data-tip="Auto from QR attendance — present ÷ sessions"><i class="bi bi-calendar-check frm-ic"></i>${escHtml(c.title)}</span></span>
                            ${asub}<span class="act-print">${escHtml(c.title)}${_aprintSub}</span></th>`;
        } else {
            head += `<th>${escHtml(c.title)}<span class="sub">/ ${c.max || '?'}</span></th>`;
        }
    });
    if (termMode) {
        head += `<th class="fin-col" title="Midterm Grade (100%)">Midterm</th>`
              + `<th class="fin-col" title="Final Grade (100%)">Final</th>`
              + `<th class="fin-col sortable" data-sort="grade" title="Sort by general average">General Ave${sortArrow('grade')}</th>`
              + `<th class="fin-col" title="Transmuted (bands mo)">Equivalent</th>`
              + `<th>Remark</th>`;
    } else {
        if (hasCols) head += `<th class="col-total">Total</th><th class="col-pct sortable" data-sort="grade" title="Sort by grade">%${sortArrow('grade')}</th><th>Remark</th>`;
        if (hasDefense) head += `<th class="dfn-col" title="Verdict base sa defense grade (pass ≥ 75)">Defense</th>`;
        if (hasFinal) {
            if (hasDefense) {
                head += `<th class="fin-col" title="(Midterm + Final defense) ÷ 2">Final Average</th>`
                      + `<th class="fin-col" title="Final na na-transmute sa 1.00–5.00">Final (1.00–5.00)</th>`;
            } else {
                head += `<th class="fin-col" title="Coursework only (no defense in this subject)">Final</th>`
                      + `<th class="fin-col" title="Final na na-transmute sa 1.00–5.00">Final (1.00–5.00)</th>`;
            }
        }
    }
    head += `</tr>`;

    let body = '';
    let pctSum = 0,
        pctN = 0,
        passCount = 0;

    students.forEach((s, i) => {
        let cells = '';
        cols.forEach(c => {
            const rec = getRec(s.student_no, c.key);
            const cmax = c.max || (rec ? rec.max : 0) || 0;
            if (c.type === 'activity') {
                const val = rec ? rec.score : '';
                cells += `<td class="act-cell"><input type="number" class="act-score ${c.linked ? 'sync-on' : ''}" min="0" max="${c.max}"
                                value="${val}" data-aid="${c.id}" data-sno="${escAttr(s.student_no)}" data-key="${c.key}"></td>`;
            } else if (c.type === 'defense') {
                /* LIVE read-only — display-only, EXCLUDED from Total / % / Remark
                   so the 1.00–5.00 point doesn't distort the points-based total */
                if (rec && rec.score !== '' && rec.score !== null) {
                    let disp, ok;
                    if (c.scale === '5') {            // transmuted point — lower is better
                        const pt = parseFloat(rec.score);
                        disp = pt.toFixed(2);
                        ok = pt <= 3.00;
                    } else {                           // raw 0–100 average
                        const v = parseFloat(rec.score);
                        disp = v.toFixed(2);
                        ok = v >= pass;
                    }
                    const isGroup = rec.src === 'group';
                    const srcLbl = isGroup ? 'group' : 'individual';
                    const badge = isGroup ? ' <span class="dfn-src">G</span>' : '';
                    cells += `<td class="dfn-cell ${ok ? 'cell-pass' : 'cell-fail'}" title="From defense panel (live · ${srcLbl} grade)">${disp}${badge}</td>`;
                } else {
                    cells += `<td class="dfn-cell cell-miss">—</td>`;
                }
            } else if (c.type === 'attendance') {
                /* read-only present/total, computed from the QR scans */
                if (rec && rec.max > 0) {
                    const p = Number(rec.score) || 0;
                    const pctA = rec.max > 0 ? (p / rec.max * 100) : 0;
                    const okA = pctA >= pass;
                    cells += `<td class="${okA ? 'cell-pass' : 'cell-fail'}" title="Present ${p} of ${rec.max} session${rec.max === 1 ? '' : 's'} (${pctA.toFixed(0)}%)">${p}<span style="color:var(--muted);font-weight:400;">/${rec.max}</span></td>`;
                } else {
                    cells += `<td class="cell-miss" title="No attendance sessions recorded yet">—</td>`;
                }
            } else if (rec) {
                const cellPass = cmax > 0 ? (rec.score / cmax * 100) >= pass : true;
                const penNote = rec.penalty > 0 ? ` title="raw ${rec.raw} − penalty ${rec.penalty}"` : '';
                cells += `<td class="${cellPass ? 'cell-pass' : 'cell-fail'}"${penNote}>${rec.score}</td>`;
            } else {
                cells += `<td class="cell-miss">—</td>`;
            }
        });
        const cg = courseworkGrade(s, missingZero);
        const got = cg.got, max = cg.max;
        const graded = cg.gotAny;              // has at least one actual coursework score
        const pct = cg.pct;
        const isPass = pct >= pass;
        /* only include students with an actual score in class stats */
        if (hasCols && graded) {
            pctSum += pct;
            pctN++;
            if (isPass) passCount++;
        }

        let tail = '';
        const stStatus = (SHEET.statuses || {})[s.student_no] || '';
        if (termMode) {
            /* ── Option B: Midterm / Final / General Ave / Equivalent / Remark ── */
            const ga = generalAverage(s);
            const fmt = t => (t ? t.grade.toFixed(1) : '—');
            const graded2 = !!(ga && ga.anyScore);
            const equiv = graded2 ? transmuteExcel(ga.ave) : null;   // null if < 75 (Failed)
            const passed = equiv !== null;
            tail += `<td class="fin-cell" title="Midterm term grade">${graded2 ? fmt(ga.mid) : '—'}</td>`
                  + `<td class="fin-cell" title="Final term grade">${graded2 ? fmt(ga.fin) : '—'}</td>`
                  + `<td class="fin-cell" title="(Midterm + Final) ÷ 2">${graded2 ? `<b>${ga.ave.toFixed(2)}</b>` : '—'}</td>`;
            if (stStatus) {
                tail += `<td class="fin-cell st-cell">${stBadge(stStatus)}</td>`
                      + `<td class="status-remark">${STATUS_FULL[stStatus] || stStatus}</td>`;
            } else if (graded2) {
                tail += `<td class="fin-cell ${passed ? 'cell-pass' : 'cell-fail'}">${equiv !== null ? equiv : '5.00'}</td>`
                      + `<td class="${passed ? 'remark-pass' : 'remark-fail'}">${passed ? 'Passed' : 'Failed'}</td>`;
                if (passed) passCount++;
                pctN++;
                pctSum += ga.ave;
            } else {
                tail += `<td class="fin-cell cell-miss">—</td><td class="cell-miss">Not graded</td>`;
            }
        } else {
        if (hasCols) {
            const pctTitle = cg.weighted ? ' title="weighted average"' : '';
            const totCell = cg.weighted
                ? `<td class="col-total" title="raw points (di kasama sa weighted %)">${got}<span style="color:var(--muted);font-weight:400;"> / ${max}</span></td>`
                : `<td class="col-total">${got}<span style="color:var(--muted);font-weight:400;"> / ${max}</span></td>`;
            const remarkCell = stStatus
                ? `<td class="status-remark">${STATUS_FULL[stStatus] || stStatus}</td>`
                : graded ? `<td class="${isPass ? 'remark-pass' : 'remark-fail'}">${isPass ? 'Passed' : 'Failed'}</td>`
                         : `<td class="cell-miss">Not graded</td>`;
            tail = graded
                ? `${totCell}
                   <td class="col-pct ${isPass ? 'pct-pass' : 'pct-fail'}"${pctTitle}>${pct.toFixed(1)}%${cg.weighted ? ' <span class="wt-mark">w</span>' : ''}</td>
                   ${remarkCell}`
                : `<td class="col-total cell-miss">—</td>
                   <td class="col-pct cell-miss">—</td>
                   ${remarkCell}`;
        }
        /* Defense verdict — derived directly from the defense grade (pass ≥ 75 / ≤ 3.00),
           separate from the coursework Total to make the official defense pass clear */
        if (hasDefense) {
            const dv = defenseRaw(s.student_no);
            if (dv !== null) {
                const dpass = dv >= DEFENSE_PASS;
                tail += `<td class="${dpass ? 'remark-pass' : 'remark-fail'}" title="Defense grade ${dv.toFixed(2)} (pass ≥ ${DEFENSE_PASS})">${dpass ? 'Passed' : 'Failed'}</td>`;
            } else {
                tail += `<td class="cell-miss">—</td>`;
            }
        }
        if (hasFinal) {
            const dv = defenseRaw(s.student_no);
            const fg = finalGrade(pct, graded, dv, hasDefense);
            if (fg) {
                const fpass  = fg.val >= pass;
                const ptPass = parseFloat(fg.pt) <= 3.00;
                const cwNote = fg.hasCw ? `${pct.toFixed(1)}%` : '0% (no coursework, 0)';
                const tip = fg.mode === 'combined'
                    ? `Midterm ${cwNote} · Final defense ${dv.toFixed(2)}`
                    : `Coursework only ${cwNote} (no defense)`;
                tail += `<td class="fin-cell ${fpass ? 'cell-pass' : 'cell-fail'}" title="${tip}">${fg.val.toFixed(2)}</td>`
                      + (stStatus ? `<td class="fin-cell st-cell">${stBadge(stStatus)}</td>` : `<td class="fin-cell ${ptPass ? 'cell-pass' : 'cell-fail'}">${fg.pt}</td>`);
            } else {
                const why = hasDefense ? 'waiting for defense grade' : 'not graded yet';
                tail += `<td class="fin-cell cell-miss" title="${why}">—</td><td class="fin-cell cell-miss">—</td>`;
            }
        }
        }

        const isSel = selectedStudents.has(s.student_no);
        body += `<tr class="${isSel ? 'row-selected' : ''}">
                <td class="col-sel"><input type="checkbox" class="row-sel" data-sno="${escAttr(s.student_no)}" ${isSel ? 'checked' : ''}></td>
                <td class="col-no">${i + 1}</td>
                <td class="col-name bd-open" data-sno="${escAttr(s.student_no)}" title="View grade breakdown">${escHtml(s.fullname)}<span class="sn">${escHtml(s.student_no)}</span></td>
                ${cells}
                ${tail}
            </tr>`;
    });

    const hint = hasCols ? '' :
        `<div class="gs-hint"><i class="bi bi-info-circle"></i> Showing class roster only. Click <b>Add Activity</b> or check an assessment above to start grading.</div>`;

    /* coursework weight-total indicator (when there are weights) —
       activities AND form columns can both carry weight */
    const weightCols = SHEET.columns.filter(c => c.type === 'activity' || c.type === 'form' || c.type === 'attendance');
    const totalWeight = weightCols.reduce((t, c) => t + (+c.weight || 0), 0);
    const formWeight  = weightCols.filter(c => c.type === 'form').reduce((t, c) => t + (+c.weight || 0), 0);
    let wtNote = '';
    if (totalWeight > 0) {
        const ok = Math.abs(totalWeight - 100) < 0.01;
        /* remind the teacher that FormFlow columns count toward this total too */
        const inclForms = formWeight > 0
            ? ` <span class="wt-incl" data-tip="This total includes FormFlow form columns (${(+formWeight.toFixed(2))}%), not just manual activities.">incl. forms</span>` : '';
        wtNote = `<div class="wt-note-wrap"><span class="wt-chip ${ok ? 'wt-ok' : 'wt-warn'}"
            title="Sum of every weighted column — manual activities + FormFlow form columns. Aim for 100%.">
            <i class="bi bi-${ok ? 'check-circle' : 'exclamation-triangle'}"></i>
            Weights ${(+totalWeight.toFixed(2))}%${ok ? '' : ' — should be 100%'}${inclForms}</span></div>`;
    }

    /* print-only header — Section / Faculty / Passing / Date + compact summary.
       Ang '.user-pill span' na hinahanap dito dati ay klase ng FormFlow, wala
       rito — kaya "—" ang Faculty sa bawat print mula pa noon. Ang REPORT_HDR
       ay puwedeng wala pa (hindi pa naibubukas ang PDF o ang editor) — hindi
       ito hinihintay: sinsero pa ring lumalabas ang print, at kompleto na sa
       susunod na render. */
    const teacher  = reportFaculty(REPORT_HDR);
    const passVal  = $('numPass').value || '75';
    const today    = new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
    const clsAvg   = pctN ? (pctSum / pctN).toFixed(1) + '%' : '—';
    const passRate = pctN ? Math.round(passCount / pctN * 100) + '%' : '—';
    const _rh = REPORT_HDR || {};
    const printHead = `
        <div class="gs-print-head">
            ${_rh.school ? `<div class="gph-school">${escHtml(_rh.school)}</div>` : ''}
            ${_rh.department ? `<div class="gph-dept">${escHtml(_rh.department)}</div>` : ''}
            <div class="gph-title">${escHtml(_rh.title || 'Grading Sheet')}</div>
            <div class="gph-meta">
                <span><b>Section:</b> ${escHtml(SHEET.section || '—')}</span>
                <span><b>Faculty:</b> ${escHtml(teacher || '—')}</span>
                <span><b>Passing:</b> ${escHtml(String(passVal))}%</span>
                <span><b>Date:</b> ${escHtml(today)}</span>
            </div>
            <div class="gph-meta gph-sub">
                <span>${students.length} students</span>
                <span>${cols.length} assessments</span>
                <span>Class avg: ${clsAvg}</span>
                <span>Pass rate: ${passRate}</span>
            </div>
        </div>`;

    /* preserve scroll + focus so it doesn't 'jump' on re-render */
    const _prevWrap = $('gsArea').querySelector('.gs-wrap');
    const _scroll = _prevWrap ? [_prevWrap.scrollLeft, _prevWrap.scrollTop] : null;
    const _ae = document.activeElement;
    let _refocus = null;
    if (_ae && _ae.dataset && $('gsArea').contains(_ae)) {
        _refocus = {
            cls: (_ae.className || '').split(' ')[0],
            sno: _ae.dataset.sno || '',
            key: _ae.dataset.key || '',
            aid: _ae.dataset.aid || '',
        };
    }

    $('gsArea').innerHTML = hint + wtNote + printHead + `<div class="gs-topscroll"><div class="gs-topscroll-inner"></div></div><div class="gs-wrap"><table class="gs"><thead>${head}</thead><tbody>${body}</tbody></table></div>`;

    /* restore scroll + focus */
    const _newWrap = $('gsArea').querySelector('.gs-wrap');
    if (_newWrap && _scroll) { _newWrap.scrollLeft = _scroll[0]; _newWrap.scrollTop = _scroll[1]; }
    if (_refocus && _refocus.cls) {
        const esc = s => (window.CSS && CSS.escape) ? CSS.escape(s) : String(s).replace(/["\\\]]/g, '\\$&');
        let sel = '';
        if (_refocus.sno && _refocus.key) sel = `[data-sno="${esc(_refocus.sno)}"][data-key="${esc(_refocus.key)}"]`;
        else if (_refocus.aid && _refocus.key) sel = `[data-aid="${esc(_refocus.aid)}"][data-key="${esc(_refocus.key)}"]`;
        else if (_refocus.aid) sel = `[data-aid="${esc(_refocus.aid)}"]`;
        const cand = sel ? $('gsArea').querySelector(`.${_refocus.cls}${sel}`) : null;
        if (cand) { try { cand.focus(); } catch (e) {} }
    }

    /* sync the top scrollbar with the table's horizontal scroll */
    (() => {
        const wrap = $('gsArea').querySelector('.gs-wrap');
        const top = $('gsArea').querySelector('.gs-topscroll');
        const inner = $('gsArea').querySelector('.gs-topscroll-inner');
        const table = wrap && wrap.querySelector('table.gs');
        if (!wrap || !top || !inner || !table) return;
        const setW = () => { inner.style.width = table.scrollWidth + 'px'; };
        setW();
        setTimeout(setW, 60);   // after render, correct width
        top.scrollLeft = wrap.scrollLeft;
        let syncing = false;
        top.addEventListener('scroll', () => { if (syncing) return; syncing = true; wrap.scrollLeft = top.scrollLeft; syncing = false; });
        wrap.addEventListener('scroll', () => { if (syncing) return; syncing = true; top.scrollLeft = wrap.scrollLeft; syncing = false; });
    })();

    /* wire editable activity inputs */
    $('gsArea').querySelectorAll('input.act-score').forEach(inp => {
        inp.addEventListener('change', onScoreEdit);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                /* Enter → next student row, Shift+Enter → previous (spreadsheet-style) */
                if (!focusScoreCell(inp, e.shiftKey ? -1 : 1)) inp.blur();
            }
        });
    });
    /* wire student name → grade breakdown */
    $('gsArea').querySelectorAll('td.bd-open').forEach(td => {
        td.addEventListener('click', () => openBreakdown(td.dataset.sno));
    });
    /* wire sortable column headers (Student name, final grade / %) */
    $('gsArea').querySelectorAll('th.sortable').forEach(th => {
        th.addEventListener('click', () => {
            const k = th.dataset.sort;
            if (sortKey === k) sortDir = -sortDir;         // same column → flip direction
            else { sortKey = k; sortDir = (k === 'name') ? 1 : -1; }  // name: A→Z, grade: high→low
            render();
        });
    });
    /* wire delete-activity buttons */
    $('gsArea').querySelectorAll('.act-del').forEach(btn => {
        btn.addEventListener('click', () => deleteActivity(parseInt(btn.dataset.aid)));
    });
    /* wire fill-all (bulk score) buttons */
    $('gsArea').querySelectorAll('.act-fill').forEach(btn => {
        btn.addEventListener('click', () => bulkFillActivity(parseInt(btn.dataset.aid)));
    });
    /* wire sync (same-score) toggle buttons */
    $('gsArea').querySelectorAll('.act-link').forEach(btn => {
        btn.addEventListener('click', () => toggleLinkedActivity(parseInt(btn.dataset.aid)));
    });
    /* wire drag-to-reorder of columns (activities AND form columns, unified —
       keyed by column key like "a3" / "f7") */
    $('gsArea').querySelectorAll('.act-drag').forEach(h => {
        h.addEventListener('dragstart', e => {
            draggedKey = h.dataset.colkey || null;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(draggedKey));
        });
        h.addEventListener('dragend', () => {
            draggedKey = null;
            $('gsArea').querySelectorAll('.act-col.drag-over').forEach(t => t.classList.remove('drag-over'));
        });
    });
    $('gsArea').querySelectorAll('th[data-colkey]').forEach(th => {
        th.addEventListener('dragover', e => {
            if (!draggedKey) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            th.classList.add('drag-over');
        });
        th.addEventListener('dragleave', () => th.classList.remove('drag-over'));
        th.addEventListener('drop', e => {
            e.preventDefault();
            th.classList.remove('drag-over');
            const targetKey = th.dataset.colkey;
            if (draggedKey && targetKey) reorderColumns(draggedKey, targetKey);
        });
    });
    /* wire row-selection checkboxes */
    $('gsArea').querySelectorAll('input.row-sel').forEach(cb => {
        cb.addEventListener('change', () => {
            const sno = cb.dataset.sno;
            if (cb.checked) selectedStudents.add(sno);
            else selectedStudents.delete(sno);
            cb.closest('tr').classList.toggle('row-selected', cb.checked);
            syncSelAll();
        });
    });
    const selAll = $('selAllRows');
    if (selAll) {
        selAll.addEventListener('change', () => {
            $('gsArea').querySelectorAll('input.row-sel').forEach(cb => {
                cb.checked = selAll.checked;
                const sno = cb.dataset.sno;
                if (selAll.checked) selectedStudents.add(sno);
                else selectedStudents.delete(sno);
                cb.closest('tr').classList.toggle('row-selected', selAll.checked);
            });
            updateSelBar();
        });
        syncSelAll();
    }
    /* wire editable activity header (rename + max points) */
    $('gsArea').querySelectorAll('input.act-title-edit, input.act-max-edit, input.act-wt-edit, select.act-term-edit, select.act-cat-edit').forEach(inp => {
        inp.addEventListener('change', onActivityEdit);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') inp.blur();
        });
    });
    /* wire editable form-column overlay (term / category / weight) */
    $('gsArea').querySelectorAll('input.frm-wt-edit, select.frm-term-edit, select.frm-cat-edit').forEach(inp => {
        inp.addEventListener('change', onFormEdit);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') inp.blur();
        });
    });
    /* wire editable attendance overlay (term / category / weight) */
    $('gsArea').querySelectorAll('input.att-wt-edit, select.att-term-edit, select.att-cat-edit').forEach(inp => {
        inp.addEventListener('change', onAttendanceEdit);
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') inp.blur();
        });
    });
    /* wire "hide this form in this class" */
    $('gsArea').querySelectorAll('button.frm-hide').forEach(btn => {
        btn.addEventListener('click', () => setFormHidden(parseInt(btn.dataset.fid), true));
    });

    $('gsStats').style.display = 'flex';
    $('stStudents').textContent = SHEET.students.length;
    $('stAssess').textContent = cols.length;
    $('stAvg').textContent = pctN ? (pctSum / pctN).toFixed(1) + '%' : '—';
    $('stPassRate').textContent = pctN ? Math.round(passCount / pctN * 100) + '%' : '—';
}

/* move focus to the same activity column in the next/prev student row */
function focusScoreCell(fromInp, dir) {
    const aid = fromInp.dataset.aid;
    if (!aid) return false;
    const escA = (window.CSS && CSS.escape) ? CSS.escape(aid) : aid;
    let tr = fromInp.closest('tr');
    while (tr) {
        tr = dir > 0 ? tr.nextElementSibling : tr.previousElementSibling;
        if (!tr) break;
        const cell = tr.querySelector(`input.act-score[data-aid="${escA}"]`);
        if (cell) { cell.focus(); cell.select(); return true; }
    }
    return false;
}

/* ── Save a manual score (inline edit) ──────────────────── */
async function onScoreEdit(e) {
    const inp = e.target;
    const aid = parseInt(inp.dataset.aid);
    const sno = inp.dataset.sno;
    const key = inp.dataset.key;
    let val = inp.value.trim();

    const col = SHEET.columns.find(c => c.key === key);
    /* did the teacher type a value above this activity's max? (server clamps it) */
    const maxN = col ? Number(col.max) : null;
    const typedNum = val === '' ? null : Number(val);
    const wasOverMax = typedNum !== null && Number.isFinite(typedNum) && maxN !== null && typedNum > maxN;
    /* capture the value BEFORE this edit — needed for same-score sync */
    const prevRec = (SHEET.scores[sno] || {})[key];
    const oldVal = (prevRec && prevRec.score !== '' && prevRec.score !== null && prevRec.score !== undefined)
        ? Number(prevRec.score) : null;

    const d = await apiPost({
        api: 'save_activity_score',
        activity_id: aid,
        student_no: sno,
        score: val
    });
    if (!d.success) {
        showToastSafe(d.message || 'Save failed', 'error');
        return;
    }

    /* update local model so totals recompute correctly */
    if (!SHEET.scores[sno]) SHEET.scores[sno] = {};
    if (d.cleared) {
        delete SHEET.scores[sno][key];
    } else {
        SHEET.scores[sno][key] = {
            score: d.score,
            raw: d.score,
            penalty: 0,
            max: col ? col.max : 0,
            at: null
        };
        inp.value = d.score; // reflect clamped value
    }

    /* alert the teacher when their entry was above the activity's max */
    if (wasOverMax && !d.cleared) {
        showToastSafe(`You entered ${typedNum}, but the max is ${maxN} — saved as ${d.score}.`, 'warning');
    }

    /* ── SYNC same scores ──────────────────────────────────────
       If this activity has sync ON and we changed a real number to a
       different number, update every OTHER cell that had the same old
       value so they move together. Blank cells are never swept in. */
    if (col && col.linked && !d.cleared && oldVal !== null) {
        const newVal = Number(d.score);
        if (newVal !== oldVal) {
            const sd = await apiPost({
                api: 'sync_activity_score',
                activity_id: aid,
                from_score: oldVal,
                to_score: newVal
            });
            if (sd && sd.success) {
                /* mirror the change in the local model for matching students */
                let touched = 0;
                SHEET.students.forEach(st => {
                    const r = (SHEET.scores[st.student_no] || {})[key];
                    if (r && Number(r.score) === oldVal) {
                        r.score = newVal;
                        r.raw = newVal;
                        touched++;
                    }
                });
                if (touched > 0) {
                    showToastSafe(`Synced ${touched} cell${touched === 1 ? '' : 's'} with the same score.`, 'success');
                }
            }
        }
    }

    /* recompute responded count for that column */
    if (col) col.responded = SHEET.students.filter(st => SHEET.scores[st.student_no] && SHEET.scores[st.student_no][key]).length;

    inp.classList.add('saved');
    setTimeout(() => inp.classList.remove('saved'), 700);
    render();
}

/* ── Unified column reorder (activities + form columns, keyed by column key) ── */
function reorderColumns(dragKey, targetKey) {
    if (dragKey === targetKey) return;
    /* Only reorderable column types take part; other types (if any) keep
       their slots. We reorder the movable subset and stitch it back. */
    const isMovable = c => c.type === 'activity' || c.type === 'form' || c.type === 'attendance';
    const movable = SHEET.columns.filter(isMovable);
    const from = movable.findIndex(c => c.key === dragKey);
    const to   = movable.findIndex(c => c.key === targetKey);
    if (from < 0 || to < 0 || from === to) return;

    const [moved] = movable.splice(from, 1);
    movable.splice(to, 0, moved);

    /* map back into SHEET.columns, preserving positions of non-movable columns */
    let mi = 0;
    SHEET.columns = SHEET.columns.map(c => isMovable(c) ? movable[mi++] : c);
    render();

    apiPost({ api: 'reorder_columns', section: SHEET.section, order: JSON.stringify(movable.map(c => c.key)) })
        .then(d => {
            if (d && d.success) showToastSafe('Column order saved.', 'success');
            else showToastSafe((d && d.message) || 'Failed to save order', 'error');
        });
}

/* ── Save the form-column overlay (term / category / weight) ── */
async function onFormEdit(e) {
    const inp = e.target;
    const fid = parseInt(inp.dataset.fid);
    const key = inp.dataset.key;
    const col = SHEET.columns.find(c => c.key === key);
    if (!col) return;

    const isTerm   = inp.classList.contains('frm-term-edit');
    const isCat    = inp.classList.contains('frm-cat-edit');
    const isWeight = inp.classList.contains('frm-wt-edit');

    const payload = { api: 'set_form_meta', section: SHEET.section, form_id: fid };
    if (isTerm) {
        payload.term = inp.value;
        payload.category_id = '';           // clear category when term changes
        col.term = inp.value;
        col.category_id = null;
    } else if (isCat) {
        payload.category_id = inp.value === '' ? '' : parseInt(inp.value);
        col.category_id = inp.value === '' ? null : parseInt(inp.value);
    } else if (isWeight) {
        const w = Math.max(0, parseFloat(inp.value) || 0);
        inp.value = w;
        if (w === (+col.weight || 0)) return;
        payload.weight = w;
        col.weight = w;
    } else {
        return;
    }

    const d = await apiPost(payload);
    if (!d.success) { showToastSafe(d.message || 'Update failed', 'error'); return; }
    render();
}

/* ── Hide / restore a form column IN THIS CLASS ──
   FormFlow has no notion of a subject — it only knows a form's `section` — so a
   section's forms are auto-discovered into EVERY class of that section. When one
   section runs two subjects, the other subject's form would otherwise show up
   here and count toward the grade. Hiding is an eGradeBook-side overlay
   (grade_form_meta.hidden, keyed per class): the form and its responses stay
   untouched in FormFlow, and your other classes are unaffected.
   Reloads the sheet because the set of columns changes. */
async function setFormHidden(fid, hidden, fromModal) {
    if (!SHEET || !fid) return;
    const d = await apiPost({ api: 'set_form_meta', section: SHEET.section, form_id: fid, hidden: hidden ? 1 : 0 });
    if (!d.success) { showToastSafe(d.message || 'Update failed', 'error'); return; }
    await loadSheet(SHEET.section);
    if (fromModal) renderFormColList();
    showToastSafe(hidden ? 'Form hidden in this class.' : 'Form restored.', 'success');
}

/* ── Form columns modal ──────────────────────────────────
   Two levers on the same problem — FormFlow tags a response only with its
   SECTION, so a section's forms land in every class of that section, and
   because section names repeat each school year they never age out:
     • Subject   — claim a form for one subject. One decision, and it applies
                   to every class of the section, including ones made later.
     • Hide here — a per-class override for the leftovers (old terms, one-offs).
   A claim only takes effect in classes that HAVE a subject; the legacy
   (untagged) sheet keeps seeing everything, as it always did. */
let FC_SUBJECTS = [];

async function openFormColModal() {
    if (!SHEET) { showToastSafe('Select a section first.', 'error'); return; }
    $('fcErr').style.display = 'none';
    $('fcTargetNote').innerHTML = `<i class="bi bi-info-circle"></i> This class: <b>${escHtml(classLabel(CLASS))}</b> · section <b>${escHtml(SHEET.section)}</b>`;

    /* copy source = another class of the SAME section (forms are per-section,
       so classes of other sections share no forms) */
    const others = CLASSES.filter(c => classKey(c) !== classKey(CLASS));
    const sel = $('fcCopyFrom');
    sel.innerHTML = others.length
        ? `<option value="">— Select a class —</option>` + others.map(c => `<option value="${escAttr(classKey(c))}">${escHtml(classLabel(c))}</option>`).join('')
        : `<option value="">No other class in this section yet</option>`;
    sel.disabled = !others.length;

    /* subject suggestions: the section's QR subjects + subjects already used by
       this section's classes + whatever the forms are already claimed by */
    const d = await apiGet({ api: 'subjects', section: SHEET.section });
    const set = new Set((d && d.success && Array.isArray(d.subjects)) ? d.subjects : []);
    CLASSES.forEach(c => { if (c.subject) set.add(c.subject); });
    fcAllForms().forEach(f => { if (f.owned_subject) set.add(f.owned_subject); });
    if (CLASS.subject) set.add(CLASS.subject);
    FC_SUBJECTS = [...set].sort((a, b) => a.localeCompare(b));

    renderFormColList();
    $('formColModal').classList.add('show');
}

function closeFormColModal() { $('formColModal').classList.remove('show'); }

/* Every form of this section: the ones showing as columns + the ones left out. */
function fcAllForms() {
    if (!SHEET) return [];
    const shown = SHEET.columns.filter(c => c.type === 'form')
        .map(c => ({ id: c.id, title: c.title, owned_subject: c.owned_subject || '', reason: '' }));
    const hidden = (SHEET.hidden_forms || [])
        .map(f => ({ id: f.id, title: f.title, owned_subject: f.subject || '', reason: f.reason || 'manual' }));
    return [...shown, ...hidden].sort((a, b) => a.title.localeCompare(b.title));
}

function renderFormColList() {
    const forms = fcAllForms();
    const box = $('fcList');
    if (!forms.length) {
        box.innerHTML = `<div class="bulk-note" style="padding:.4rem 0;">This section has no FormFlow forms yet.</div>`;
        return;
    }
    box.innerHTML = forms.map(f => {
        const opts = `<option value="">— any subject —</option>` + FC_SUBJECTS.map(s =>
            `<option value="${escAttr(s)}" ${s === f.owned_subject ? 'selected' : ''}>${escHtml(s)}</option>`).join('');
        /* A subject-claimed form can't be force-shown in a class it doesn't
           belong to — changing the claim is the coherent way out, so we say so
           instead of offering a button that would fight the claim. */
        const state = f.reason === 'subject'
            ? `<span class="fc-state fc-off" title="Hidden because it belongs to another subject">belongs to ${escHtml(f.owned_subject)}</span>`
            : f.reason === 'manual'
                ? `<button class="fc-btn" data-show="${f.id}" data-tip="Bring this form back into this class"><i class="bi bi-eye"></i> Show</button>`
                : `<button class="fc-btn" data-hide="${f.id}" data-tip="Hide this form in this class only"><i class="bi bi-eye-slash"></i> Hide</button>`;
        return `<div class="fc-row ${f.reason ? 'is-off' : ''}">
                    <span class="fc-title"><i class="bi bi-ui-checks-grid frm-ic"></i>${escHtml(f.title)}</span>
                    <select class="fc-subj" data-fid="${f.id}" data-tip="Which subject owns this form? Applies to every class of this section, now and later.">${opts}</select>
                    ${state}
                </div>`;
    }).join('');

    box.querySelectorAll('.fc-subj').forEach(s =>
        s.addEventListener('change', () => setFormSubject(parseInt(s.dataset.fid), s.value)));
    box.querySelectorAll('[data-hide]').forEach(b =>
        b.addEventListener('click', () => setFormHidden(parseInt(b.dataset.hide), true, true)));
    box.querySelectorAll('[data-show]').forEach(b =>
        b.addEventListener('click', () => setFormHidden(parseInt(b.dataset.show), false, true)));
}

/* Claim a form for a subject (or clear the claim with an empty value). */
async function setFormSubject(fid, subject) {
    if (!SHEET || !fid) return;
    const d = await apiPost({ api: 'set_form_subject', section: SHEET.section, form_id: fid, owned_subject: subject });
    if (!d.success) { showToastSafe(d.message || 'Update failed', 'error'); return; }
    await loadSheet(SHEET.section);
    renderFormColList();
    showToastSafe(subject ? `Form assigned to ${subject}.` : 'Form no longer tied to a subject.', 'success');
}

/* Pull another class's hidden forms into this one (merge — nothing is un-hidden). */
async function applyCopyFormVisibility() {
    const v = $('fcCopyFrom').value;
    if (!v || !SHEET) return;
    const src = CLASSES.find(c => classKey(c) === v);
    if (!src) return;
    const d = await apiPost({
        api: 'copy_form_visibility',
        section: SHEET.section,
        from_school_year: src.school_year,
        from_semester: src.semester,
        from_subject: src.subject,
    });
    if (!d.success) {
        $('fcErr').textContent = d.message || 'Copy failed';
        $('fcErr').style.display = '';
        return;
    }
    $('fcErr').style.display = 'none';
    $('fcCopyFrom').value = '';
    await loadSheet(SHEET.section);
    renderFormColList();
    showToastSafe(`Copied ${d.copied || 0} hidden form${(d.copied || 0) === 1 ? '' : 's'} from ${classLabel(src)}.`, 'success');
}

/* ── Save the attendance-column overlay (term / category / weight) ──
   Mirrors onFormEdit, but keyed per section (one attendance column). */
async function onAttendanceEdit(e) {
    const inp = e.target;
    const col = SHEET.columns.find(c => c.key === 'att');
    if (!col) return;

    const isTerm   = inp.classList.contains('att-term-edit');
    const isCat    = inp.classList.contains('att-cat-edit');
    const isWeight = inp.classList.contains('att-wt-edit');

    const payload = { api: 'set_attendance_meta', section: SHEET.section };
    if (isTerm) {
        payload.term = inp.value;
        payload.category_id = '';           // clear category when term changes
        col.term = inp.value;
        col.category_id = null;
    } else if (isCat) {
        payload.category_id = inp.value === '' ? '' : parseInt(inp.value);
        col.category_id = inp.value === '' ? null : parseInt(inp.value);
    } else if (isWeight) {
        const w = Math.max(0, parseFloat(inp.value) || 0);
        inp.value = w;
        if (w === (+col.weight || 0)) return;
        payload.weight = w;
        col.weight = w;
    } else {
        return;
    }

    const d = await apiPost(payload);
    if (!d.success) { showToastSafe(d.message || 'Update failed', 'error'); return; }
    render();
}

function syncSelAll() {
    const selAll = $('selAllRows');
    if (!selAll) return;
    const boxes = $('gsArea').querySelectorAll('input.row-sel');
    const checked = $('gsArea').querySelectorAll('input.row-sel:checked').length;
    selAll.checked = boxes.length > 0 && checked === boxes.length;
    selAll.indeterminate = checked > 0 && checked < boxes.length;
    updateSelBar();
}

/* show/hide the bulk selection bar + live count */
function updateSelBar() {
    const bar = $('selBar');
    if (!bar) return;
    const n = selectedStudents.size;
    bar.style.display = n ? 'flex' : 'none';
    const c = $('selBarCount');
    if (c) c.textContent = n;
}

/* apply a final status (INC / DRP / W, or '' to clear) to all selected students */
async function bulkSetStatus(status) {
    if (!SHEET || !selectedStudents.size) return;
    const snos = [...selectedStudents];
    const d = await apiPost({
        api: 'set_students_status',
        section: SHEET.section,
        students: JSON.stringify(snos),
        status
    });
    if (!d.success) { showToastSafe(d.message || 'Could not update statuses.', 'error'); return; }
    if (!SHEET.statuses) SHEET.statuses = {};
    snos.forEach(sno => { if (status) SHEET.statuses[sno] = status; else delete SHEET.statuses[sno]; });
    selectedStudents.clear();
    render();
    showToastSafe(
        status ? `${snos.length} student${snos.length === 1 ? '' : 's'} marked as ${status}.`
               : `Status cleared for ${snos.length} student${snos.length === 1 ? '' : 's'}.`,
        'success'
    );
}

/* ── Sync same-score mode ───────────────────────────────────
   When ON for an activity, editing any cell also updates every other
   cell in that activity that had the same value (see onScoreEdit). */
async function toggleLinkedActivity(aid) {
    const col = SHEET.columns.find(c => c.key === 'a' + aid);
    if (!col) return;
    const turningOn = !col.linked;
    const d = await apiPost({
        api: 'set_linked_activity',
        activity_id: aid,
        linked: turningOn ? '1' : '0'
    });
    if (!d.success) { showToastSafe(d.message || 'Could not update.', 'error'); return; }
    col.linked = d.linked;
    render();
    showToastSafe(
        turningOn
            ? 'Sync ON — editing a score now updates all cells with the same value.'
            : 'Sync OFF — scores are independent again.',
        'info'
    );
}

function bulkFillActivity(aid) {
    const col = SHEET.columns.find(c => c.key === 'a' + aid);
    if (!col) return;
    pendingFillAid = aid;
    $('bulkFillCol').textContent = col.title;
    $('bulkFillMax').textContent = `(0–${col.max})`;
    const inp = $('bulkFillScore');
    inp.max = col.max;
    inp.value = '';
    /* show the 'selected' option if there's a selection; make it the default */
    const nSel = selectedStudents.size;
    const selWrap = $('bulkScopeSelWrap');
    if (selWrap) {
        if (nSel > 0) {
            selWrap.style.display = '';
            $('bulkSelCount').textContent = `(${nSel} selected)`;
            const rSel = document.querySelector('input[name="bulkScope"][value="selected"]');
            if (rSel) rSel.checked = true;
        } else {
            selWrap.style.display = 'none';
            const rEmpty = document.querySelector('input[name="bulkScope"][value="empty"]');
            if (rEmpty) rEmpty.checked = true;
        }
    }
    $('bulkFillErr').style.display = 'none';
    $('bulkFillModal').classList.add('show');
    setTimeout(() => inp.focus(), 50);
}

function closeBulkFillModal() {
    $('bulkFillModal').classList.remove('show');
    pendingFillAid = null;
}

async function applyBulkFill() {
    const aid = pendingFillAid;
    if (!aid) return;
    const col = SHEET.columns.find(c => c.key === 'a' + aid);
    const err = $('bulkFillErr');
    const raw = $('bulkFillScore').value.trim();

    const num = Number(raw);
    if (raw === '' || !Number.isFinite(num) || num < 0 || (col && num > col.max)) {
        err.textContent = col ? `Please enter a valid score between 0 and ${col.max}.` : 'Please enter a valid score.';
        err.style.display = 'block';
        return;
    }
    const mode = (document.querySelector('input[name="bulkScope"]:checked') || {}).value || 'empty';

    const payload = { api: 'bulk_fill_activity', activity_id: aid, score: raw, mode };
    if (mode === 'selected') {
        if (selectedStudents.size === 0) {
            err.textContent = 'No students selected. Tick the checkboxes first.';
            err.style.display = 'block';
            return;
        }
        payload.students = JSON.stringify([...selectedStudents]);
    }

    closeBulkFillModal();
    const d = await apiPost(payload);
    if (!d.success) {
        showToastSafe(d.message || 'Bulk fill failed', 'error');
        return;
    }
    showToastSafe(`Applied ${d.score} to ${d.applied} student${d.applied === 1 ? '' : 's'}.`, 'success');
    selectedStudents.clear();          // unselect after updating
    await loadSheet(SHEET.section);
}

/* ── Edit activity header (rename / change max, inline) ─── */
async function onActivityEdit(e) {
    const inp = e.target;
    const aid = parseInt(inp.dataset.aid);
    const key = inp.dataset.key;
    const col = SHEET.columns.find(c => c.key === key);
    if (!col) return;

    const isTitle  = inp.classList.contains('act-title-edit');
    const isWeight = inp.classList.contains('act-wt-edit');
    const isTerm   = inp.classList.contains('act-term-edit');
    const isCat    = inp.classList.contains('act-cat-edit');

    /* term / category (Option B) — separate short path */
    if (isTerm || isCat) {
        const payload = { api: 'edit_activity', activity_id: aid, title: col.title, max_points: col.max, weight: (+col.weight || 0) };
        if (isTerm) {
            payload.term = inp.value;
            payload.category_id = '';        // clear the category when the term changes
            col.term = inp.value;
            col.category_id = null;
        } else {
            payload.category_id = inp.value === '' ? '' : parseInt(inp.value);
            col.category_id = inp.value === '' ? null : parseInt(inp.value);
        }
        const dd = await apiPost(payload);
        if (!dd.success) { showToastSafe(dd.message || 'Update failed', 'error'); return; }
        render();
        return;
    }

    let newTitle  = col.title;
    let newMax    = col.max;
    let newWeight = (+col.weight || 0);

    if (isTitle) {
        newTitle = inp.value.trim();
        if (!newTitle) { inp.value = col.title; return; }
        if (newTitle === col.title) return;
    } else if (isWeight) {
        newWeight = Math.max(0, parseFloat(inp.value) || 0);
        inp.value = newWeight;
        if (newWeight === (+col.weight || 0)) return;
    } else {
        newMax = Math.max(1, parseInt(inp.value) || col.max);
        inp.value = newMax;
        if (newMax === col.max) return;
    }

    const d = await apiPost({
        api: 'edit_activity',
        activity_id: aid,
        title: newTitle,
        max_points: newMax,
        weight: newWeight
    });
    if (!d.success) {
        showToastSafe(d.message || 'Update failed', 'error');
        inp.value = isTitle ? col.title : (isWeight ? (+col.weight || 0) : col.max);
        return;
    }

    col.title  = d.activity.title;
    col.max    = d.activity.max;
    col.weight = d.activity.weight;

    /* if scores were clamped because max dropped, sync the local model */
    if (d.clamped && d.clamped.length) {
        d.clamped.forEach(c2 => {
            const rec = SHEET.scores[c2.student_no] && SHEET.scores[c2.student_no][key];
            if (rec) {
                rec.score = c2.score;
                rec.raw = c2.score;
            }
        });
        showToastSafe(`Activity updated · ${d.clamped.length} score(s) clamped to ${col.max}`, 'success');
    } else {
        showToastSafe('Activity updated', 'success');
    }

    renderColumnPicker();
    render();
}

/* ── Add activity ───────────────────────────────────────── */

/* Ang huling term/category na ginamit sa PAGDARAGDAG ng activity. Sunod-sunod
   ang paggawa ng column ("Quiz 1", "Quiz 2", …) at halos laging iisa ang term
   at category ng mga iyon, kaya sayang ang bawat muling pagpili. Sa memorya
   lang ito — kada section/klase ay ibang set ng category id, at sinusuri
   naman sa ibaba kung buhay pa ang naaalalang id bago ito i-preselect. */
let lastActTerm = '';
let lastActCat  = '';
let lastActCatName = '';   // pang-match kapag ibang klase / ibang term ang id

function openActModal() {
    if (!SHEET) {
        showToastSafe('Select a section first before adding an activity.', 'error');
        return;
    }
    $('actTitle').value = '';
    $('actMax').value = 100;

    /* Sa flat mode ay max points at weight ang gamit, hindi term/category —
       itago ang dalawa para hindi magmukhang may hinihinging wala namang
       silbi. Tugma ito sa header, na ganito rin ang pagpili (grades.js:757). */
    const termMode = SHEET.term_mode === true;
    $('actTermWrap').style.display = termMode ? 'flex' : 'none';
    $('actCatWrap').style.display  = termMode ? 'flex' : 'none';
    if (termMode) {
        $('actTerm').value = lastActTerm;
        fillActCatOptions(lastActCat, lastActCatName);
    }

    $('actModal').classList.add('show');
    setTimeout(() => $('actTitle').focus(), 50);
}

/* Punan ang Category dropdown ayon sa napiling Term. Ang mga category ay
   per-term, kaya kailangang muling buuin sa tuwing magpapalit ng term —
   pareho ito ng ginagawa ng column header (grades.js:808).

   Dalawang antas ang pagpili: id muna, tapos PANGALAN. Karaniwang magkatulad
   ang hanay ng category ng Midterm at Final (Quiz / Activity / Attendance /
   Exam), pero magkaibang row sila kaya magkaibang id — kung id lang ang
   susundan, ang paglipat ng term ay laging nagre-reset sa "Not set". */
function fillActCatOptions(preferId, preferName) {
    const term = $('actTerm').value;
    const cats = (SHEET && SHEET.categories || []).filter(k => k.term === term);
    const sel = $('actCat');
    sel.innerHTML = '<option value="">— Not set —</option>'
        + cats.map(k => `<option value="${k.id}">${escHtml(k.name)}</option>`).join('');

    const byId = cats.find(k => String(k.id) === String(preferId));
    const byName = preferName
        ? cats.find(k => k.name.toLowerCase() === String(preferName).toLowerCase())
        : null;
    sel.value = byId ? String(byId.id) : (byName ? String(byName.id) : '');

    const hint = $('actCatHint');
    if (!term) {
        hint.innerHTML = '<i class="bi bi-info-circle"></i> Pick a term first to see its categories.';
        hint.style.display = 'block';
    } else if (!cats.length) {
        hint.innerHTML = '<i class="bi bi-info-circle"></i> No categories for this term yet — add them in <b>Grade setup</b>.';
        hint.style.display = 'block';
    } else {
        hint.style.display = 'none';
    }
}

function closeActModal() {
    $('actModal').classList.remove('show');
}

async function saveActivity() {
    const title = $('actTitle').value.trim();
    const max = Math.max(1, parseInt($('actMax').value) || 100);
    if (!title) {
        $('actTitle').focus();
        return;
    }
    /* Term mode lang may term/category — sa flat mode ay nakatago ang dalawa,
       at ipinapadala natin silang blangko para hindi makadikit ang lumang pili
       sa isang column na hindi naman ito kailangan. */
    const termMode = SHEET.term_mode === true;
    const term = termMode ? $('actTerm').value : '';
    const cat  = termMode ? $('actCat').value  : '';

    const d = await apiPost({
        api: 'add_activity',
        section: SHEET.section,
        title,
        max_points: max,
        term,
        category_id: cat
    });
    if (!d.success) {
        showToastSafe(d.message || 'Could not add activity', 'error');
        return;
    }
    if (termMode) {                                            // handa na sa susunod
        lastActTerm = term;
        lastActCat  = cat;
        const k = (SHEET.categories || []).find(x => String(x.id) === String(cat));
        lastActCatName = k ? k.name : '';
    }
    SHEET.columns.push(d.activity);
    selectedCols.add(d.activity.key);
    closeActModal();
    renderColumnPicker();
    render();
    showToastSafe('Activity added', 'success');
}

let pendingDeleteAid = null;
let pendingFillAid = null;
let importRows = null;   // validated { student_no: score } ready to send
let importRaw  = null;   // [{ sno, raw }] parsed rows, pre-validation (re-checked per activity)
let draggedKey = null;   // column key ('a3'/'f7') currently being dragged

function deleteActivity(aid) {
    pendingDeleteAid = aid;
    const col = SHEET.columns.find(c => c.key === 'a' + aid);
    $('delActText').innerHTML = col ?
        `This will permanently delete <b>${escHtml(col.title)}</b> and all its scores. This action cannot be undone.` :
        'This will permanently delete the activity and all its scores. This action cannot be undone.';
    $('delActModal').classList.add('show');
}

function closeDelModal() {
    $('delActModal').classList.remove('show');
    pendingDeleteAid = null;
}

async function confirmDeleteActivity() {
    const aid = pendingDeleteAid;
    if (!aid) return;
    $('delActModal').classList.remove('show');
    const d = await apiPost({
        api: 'delete_activity',
        activity_id: aid
    });
    if (!d.success) {
        showToastSafe(d.message || 'Delete failed', 'error');
        pendingDeleteAid = null;
        return;
    }
    const key = 'a' + aid;
    SHEET.columns = SHEET.columns.filter(c => c.key !== key);
    selectedCols.delete(key);
    SHEET.students.forEach(s => {
        if (SHEET.scores[s.student_no]) delete SHEET.scores[s.student_no][key];
    });
    renderColumnPicker();
    render();
    showToastSafe('Activity deleted', 'success');
    pendingDeleteAid = null;
}

/* ── CSV import — scores matched by student number (empty-only) ── */
function parseCsvText(text) {
    return String(text).split(/\r?\n/)
        .map(l => l.trim())
        .filter(l => l.length)
        .map(line => line.split(',').map(c => c.trim().replace(/^"(.*)"$/, '$1')));
}

function openImportModal() {
    if (!SHEET) {
        showToastSafe('Select a section first.', 'error');
        return;
    }
    const acts = SHEET.columns.filter(c => c.type === 'activity');
    if (!acts.length) {
        showToastSafe('Add an activity first to import into.', 'error');
        return;
    }
    $('importActivity').innerHTML = acts
        .map(a => `<option value="${a.id}">${escHtml(a.title)} (max ${a.max})</option>`)
        .join('');
    $('importFile').value = '';
    $('importInfo').style.display = 'none';
    $('importErr').style.display = 'none';
    $('importApply').disabled = true;
    const ow = $('importOverwrite');
    if (ow) ow.checked = true;   // default: overwrite existing scores
    const cap = $('importCapMax');
    if (cap) cap.checked = false; // default: flag over-max rather than clamp
    clearImportFileUI();         // show drop zone, hide selected-file row
    importRows = null;
    importRaw = null;
    clearImportPreview();
    $('importModal').classList.add('show');
}

function closeImportModal() {
    $('importModal').classList.remove('show');
    importRows = null;
    importRaw = null;
    clearImportPreview();
}

function clearImportFileUI() {
    const drop = $('importDrop'), sel = $('importFileSel'), err = $('importErr'), info = $('importInfo');
    if (drop) drop.style.display = '';
    if (sel) sel.classList.remove('show');
    if (err) err.style.display = 'none';
    if (info) { info.textContent = ''; info.style.display = 'none'; }
    const fi = $('importFile');
    if (fi) fi.value = '';
    importRows = null;
    importRaw = null;
    clearImportPreview();
    $('importApply').disabled = true;
}

function showImportFileUI(name) {
    const drop = $('importDrop'), sel = $('importFileSel'), nm = $('importFileName');
    if (nm) nm.textContent = name || 'file.csv';
    if (drop) drop.style.display = 'none';
    if (sel) sel.classList.add('show');
}

function handleImportFile(file) {
    const err = $('importErr'), info = $('importInfo');
    err.style.display = 'none';
    info.style.display = 'none';
    $('importApply').disabled = true;
    importRows = null;
    importRaw = null;
    clearImportPreview();
    if (!file) { clearImportFileUI(); return; }

    showImportFileUI(file.name);

    const reader = new FileReader();
    reader.onload = () => {
        let rows = parseCsvText(reader.result);
        if (!rows.length) { showImportError('The file is empty.'); return; }
        /* skip header row if the 2nd column isn't a number */
        if (!Number.isFinite(Number(rows[0][1]))) rows = rows.slice(1);

        importRaw = rows
            .map(r => ({ sno: (r[0] || '').trim(), raw: (r[1] == null ? '' : String(r[1])).trim() }))
            .filter(r => r.sno !== '' || r.raw !== '');

        if (!importRaw.length) {
            showImportError('No rows found (expected: student number, score).');
            return;
        }
        validateImport();
    };
    reader.readAsText(file);
}

/* Validate the parsed rows against the SELECTED activity's max + the roster.
   Re-runnable — the activity dropdown re-triggers it since max can change. */
function validateImport() {
    const err = $('importErr'), info = $('importInfo');
    err.style.display = 'none';
    if (!importRaw || !SHEET) return;

    const aid = parseInt($('importActivity').value);
    const act = SHEET.columns.find(c => c.type === 'activity' && c.id === aid);
    const max = act ? Number(act.max) : null;
    const capMax = $('importCapMax') ? $('importCapMax').checked : false;

    const roster = new Set(SHEET.students.map(s => String(s.student_no)));
    const seen = new Set();
    const good = {};                 // sno -> int score (importable)
    const problems = [];             // rows that WON'T import (real errors)
    let importable = 0, over = 0, neg = 0, nan = 0, dupe = 0, notin = 0, rounded = 0, capped = 0, blank = 0;

    importRaw.forEach(r => {
        const sno = r.sno;
        if (!sno) return;
        if (seen.has(sno)) { dupe++; problems.push({ sno, val: r.raw || '(blank)', reason: 'duplicate' }); return; }
        seen.add(sno);

        if (r.raw === '') { blank++; return; }                       // blank = silently skipped (templates ship blank)
        const n = Number(r.raw);
        if (!Number.isFinite(n)) { nan++; problems.push({ sno, val: r.raw, reason: 'not a number' }); return; }
        if (n < 0) { neg++; problems.push({ sno, val: r.raw, reason: 'negative' }); return; }
        if (!roster.has(sno)) { notin++; problems.push({ sno, val: r.raw, reason: 'not in section' }); return; }

        let v = n;
        if (max !== null && v > max) {
            if (capMax) { v = max; capped++; }                       // clamp down and import
            else { over++; problems.push({ sno, val: r.raw, reason: `over max (${max})` }); return; }
        }
        if (!Number.isInteger(v)) { v = Math.round(v); rounded++; }
        good[sno] = v;
        importable++;
    });

    importRows = importable ? good : null;

    const parts = [`${importRaw.length} row${importRaw.length === 1 ? '' : 's'}`, `${importable} will import`];
    if (capped)  parts.push(`${capped} capped to ${max}`);
    if (rounded) parts.push(`${rounded} rounded`);
    if (blank)   parts.push(`${blank} blank skipped`);
    info.textContent = parts.join(' · ') + '.';
    info.style.display = 'block';

    const noMatch = importable === 0 && notin > 0 && !over && !neg && !nan && !dupe;
    const fileSample = problems.filter(p => p.reason === 'not in section').slice(0, 3).map(p => p.sno);
    const rosterSample = SHEET.students.slice(0, 3).map(s => String(s.student_no));
    renderImportPreview(problems, { over, neg, nan, dupe, notin }, { noMatch, notin, fileSample, rosterSample });

    $('importApply').disabled = importable === 0;
    if (importable === 0 && !noMatch) {
        showImportError(
            (over || neg || nan) ? 'No valid scores to import — fix the flagged rows.' :
            notin ? 'None of these student numbers match this section.' :
            blank ? 'All rows are blank — nothing to import yet.' :
            'Nothing to import.'
        );
    }
}

function renderImportPreview(problems, c, ctx) {
    const box = $('importPreview');
    if (!box) return;
    if (!problems.length) { box.style.display = 'none'; box.innerHTML = ''; return; }

    const shown = problems.slice(0, 8);
    const rowsHtml = shown.map(p => `
        <div class="imp-prow">
            <span class="imp-psno">${escHtml(p.sno)}</span>
            <span class="imp-pval">${escHtml(String(p.val))}</span>
            <span class="imp-preason">${escHtml(p.reason)}</span>
        </div>`).join('');
    const more = problems.length > shown.length
        ? `<div class="imp-pmore">+${problems.length - shown.length} more</div>` : '';

    /* nothing matched the roster → one clear diagnostic instead of a wall of rows */
    if (ctx && ctx.noMatch) {
        const fileEx = (ctx.fileSample || []).map(escHtml).join(' · ') || '—';
        const rostEx = (ctx.rosterSample || []).map(escHtml).join(' · ') || '—';
        box.innerHTML =
            `<div class="imp-nomatch">
                <div class="imp-nm-head"><i class="bi bi-exclamation-triangle"></i> No student numbers match this section</div>
                <div class="imp-nm-sub">All ${ctx.notin} scored row${ctx.notin === 1 ? '' : 's'} belong to a different roster.</div>
                <div class="imp-nm-cmp">
                    <span class="imp-nm-k">Your file</span><span class="imp-nm-v">${fileEx}</span>
                    <span class="imp-nm-k">This section</span><span class="imp-nm-v">${rostEx}</span>
                </div>
                <div class="imp-nm-hint">Likely the wrong section is selected above, or the file was exported from another section.</div>
                <div class="imp-nm-actions">
                    <button type="button" class="btn btn-ghost btn-sm" id="impNmTemplate"><i class="bi bi-download"></i> Download this section's template</button>
                    <button type="button" class="imp-nm-showall" id="impNmShowAll">Show all ${ctx.notin}</button>
                </div>
                <div class="imp-ptable" id="impNmList" style="display:none;margin-top:.6rem;">${rowsHtml}${more}</div>
            </div>`;
        box.style.display = 'block';
        const tpl = $('impNmTemplate');
        if (tpl) tpl.addEventListener('click', downloadSampleCsv);
        const sa = $('impNmShowAll'), list = $('impNmList');
        if (sa && list) sa.addEventListener('click', () => {
            const open = list.style.display === 'none';
            list.style.display = open ? 'block' : 'none';
            sa.textContent = open ? 'Hide list' : `Show all ${ctx.notin}`;
        });
        return;
    }

    const bits = [];
    if (c.over)  bits.push(`${c.over} over max`);
    if (c.neg)   bits.push(`${c.neg} negative`);
    if (c.nan)   bits.push(`${c.nan} not a number`);
    if (c.dupe)  bits.push(`${c.dupe} duplicate`);
    if (c.notin) bits.push(`${c.notin} not in section`);

    box.innerHTML =
        `<div class="imp-phead"><i class="bi bi-exclamation-triangle"></i> ${bits.join(' · ')} — won't be imported:</div>`
        + `<div class="imp-ptable">${rowsHtml}${more}</div>`;
    box.style.display = 'block';
}

function showImportError(msg) {
    const err = $('importErr');
    err.textContent = msg;
    err.style.display = 'block';
}

function clearImportPreview() {
    const box = $('importPreview');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

function downloadSampleCsv() {
    let rows = [['student_no', 'score']];
    if (SHEET && SHEET.students && SHEET.students.length) {
        SHEET.students.forEach(s => rows.push([s.student_no, '']));   // real roster, blank score
    } else {
        rows.push(['024-0001', '80'], ['024-0002', '75']);           // generic if there's no section
    }
    const csv = rows.map(r => r.map(c => {
        const v = String(c ?? '');
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }).join(',')).join('\n');

    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const safe = String((SHEET && SHEET.section) || 'section').replace(/[^\w.-]+/g, '_');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `import_template_${safe}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}

async function applyImport() {
    const aid = parseInt($('importActivity').value);
    if (!aid || !importRows) return;
    $('importApply').disabled = true;
    const overwrite = $('importOverwrite') ? $('importOverwrite').checked : true;
    const d = await apiPost({
        api: 'import_activity_scores',
        activity_id: aid,
        scores: JSON.stringify(importRows),
        overwrite: overwrite ? '1' : '0'
    });
    if (!d.success) {
        showToastSafe(d.message || 'Import failed', 'error');
        $('importApply').disabled = false;
        return;
    }
    closeImportModal();
    let msg = `Imported ${d.applied} score${d.applied === 1 ? '' : 's'}.`;
    if (d.skipped)   msg += ` ${d.skipped} kept (blanks-only mode).`;
    if (d.invalid)   msg += ` ${d.invalid} skipped (out of range).`;
    if (d.unmatched) msg += ` ${d.unmatched} not in section.`;
    showToastSafe(msg, 'success');
    await loadSheet(SHEET.section);
}

/* ── Grade Setup (Option B — categories per term) ── */
function openSetupModal() {
    if (!SHEET) { showToastSafe('Select a section first.', 'error'); return; }
    renderSetup();
    $('setupModal').classList.add('show');
}
function closeSetupModal() { $('setupModal').classList.remove('show'); }

function renderSetup() {
    const cats = SHEET.categories || [];
    const terms = [['midterm', 'Midterm'], ['final', 'Final']];
    $('setupBody').innerHTML = terms.map(([tk, tlabel]) => {
        const list = cats.filter(c => c.term === tk);
        const total = list.reduce((t, c) => t + (+c.weight || 0), 0);
        const ok = Math.abs(total - 100) < 0.01;
        const rows = list.map(c => `
            <div class="setup-row" data-id="${c.id}">
                <input class="setup-name" value="${escAttr(c.name)}" data-id="${c.id}">
                <input type="number" class="setup-wt" value="${(+c.weight || 0)}" min="0" step="1" data-id="${c.id}"> %
                <button class="setup-del" data-id="${c.id}" title="Delete category">&times;</button>
            </div>`).join('');
        /* "Copy from <kabilang term>" — halos laging magkatulad ang hanay ng
           dalawang term, kaya dalawang beses tinitipa ang parehong apat na row.
           Ipinapakita lang kung may makokopya nga sa kabila. */
        const [otherKey, olabel] = tk === 'midterm' ? ['final', 'Final'] : ['midterm', 'Midterm'];
        const otherHas = cats.some(c => c.term === otherKey);
        const copyBtn = otherHas
            ? `<button class="btn btn-ghost btn-sm setup-copy" data-from="${otherKey}" data-to="${tk}"
                 title="Copy ${olabel}'s categories and weights here. Same-named ones are updated; nothing is deleted."><i class="bi bi-copy"></i> Copy from ${olabel}</button>`
            : '';
        return `<div class="setup-term">
            <div class="setup-term-head"><b>${tlabel}</b> <span class="${ok ? 'wt-ok-txt' : 'wt-warn-txt'}">total ${(+total.toFixed(2))}%${ok ? ' ✓' : ' ⚠'}</span></div>
            ${rows || '<div class="bulk-note" style="padding:.2rem 0;">No categories yet.</div>'}
            <div class="setup-actions">
                <button class="btn btn-ghost btn-sm setup-add" data-term="${tk}"><i class="bi bi-plus"></i> Add category</button>
                ${copyBtn}
            </div>
        </div>`;
    }).join('');
    $('setupBody').querySelectorAll('.setup-name, .setup-wt').forEach(inp => inp.addEventListener('change', saveCategoryEdit));
    $('setupBody').querySelectorAll('.setup-del').forEach(b => b.addEventListener('click', () => deleteCategory(parseInt(b.dataset.id))));
    $('setupBody').querySelectorAll('.setup-add').forEach(b => b.addEventListener('click', () => addCategory(b.dataset.term)));
    $('setupBody').querySelectorAll('.setup-copy').forEach(b =>
        b.addEventListener('click', () => copyCategories(b.dataset.from, b.dataset.to)));
}

/* Kopyahin ang hanay ng category ng kabilang term. Walang nabubura kailanman
   — ang katulad ng pangalan ay na-a-update ang weight, ang wala pa ay
   idinaragdag, at ang nasa term na ito LANG ay iniiwan. Kaya ligtas ulitin. */
async function copyCategories(from, to) {
    const labelOf = t => (t === 'midterm' ? 'Midterm' : 'Final');
    const clash = (SHEET.categories || []).some(c => c.term === to);
    if (clash && !await uiConfirm({
        title: `Copy into ${labelOf(to)}?`,
        message: `Categories with the same name take <b>${escHtml(labelOf(from))}</b>'s weight, and new ones are added. `
            + `Nothing in ${escHtml(labelOf(to))} is deleted.`,
        ok: 'Copy categories',
        icon: 'bi-copy',
    })) return;

    const d = await apiPost({ api: 'copy_categories', section: SHEET.section, from_term: from, to_term: to });
    if (!d.success) { showToastSafe(d.message || 'Could not copy the categories.', 'error'); return; }

    /* Ibinabalik ng server ang buong bagong listahan — kailangan ang TUNAY na
       id ng bagong likha, dahil doon nakasalalay ang category dropdown ng
       bawat column header. */
    SHEET.categories = d.categories || SHEET.categories;
    renderSetup();
    render();
    const bits = [];
    if (d.added)   bits.push(`${d.added} added`);
    if (d.updated) bits.push(`${d.updated} updated`);
    showToastSafe(`Copied from ${labelOf(from)} — ${bits.join(', ')}.`, 'success');
}

async function saveCategoryEdit(e) {
    const id = parseInt(e.target.dataset.id);
    const row = $('setupBody').querySelector(`.setup-row[data-id="${id}"]`);
    const name = row.querySelector('.setup-name').value.trim() || 'Category';
    const weight = Math.max(0, parseFloat(row.querySelector('.setup-wt').value) || 0);
    const d = await apiPost({ api: 'save_category', id, name, weight });
    if (!d.success) { showToastSafe(d.message || 'Save failed', 'error'); return; }
    const cat = SHEET.categories.find(c => c.id === id);
    if (cat) { cat.name = name; cat.weight = weight; }
    renderSetup();
    render();
}

async function addCategory(term) {
    const d = await apiPost({ api: 'save_category', id: 0, section: SHEET.section, term, name: 'New category', weight: 0 });
    if (!d.success) { showToastSafe(d.message || 'Add failed', 'error'); return; }
    SHEET.categories.push({ id: d.id, term, name: 'New category', weight: 0 });
    renderSetup();
    render();
}

async function deleteCategory(id) {
    const d = await apiPost({ api: 'delete_category', id });
    if (!d.success) { showToastSafe(d.message || 'Delete failed', 'error'); return; }
    SHEET.categories = SHEET.categories.filter(c => c.id !== id);
    SHEET.columns.forEach(c => { if (c.category_id === id) c.category_id = null; });
    renderSetup();
    render();
}

/* ── Transmutation table (global bands: raw score → 1.00–5.00) ── */
let tmDraft = [];

async function openTmModal() {
    /* prefer live bands; else the sheet's; else pull the teacher's saved set
       (get_transmute works even with no section loaded — it's global) */
    let bands = (Array.isArray(TRANSMUTE) && TRANSMUTE.length) ? TRANSMUTE
              : (SHEET && Array.isArray(SHEET.grade_equiv) && SHEET.grade_equiv.length) ? SHEET.grade_equiv
              : null;
    if (!bands) {
        const d = await apiGet({ api: 'get_transmute' });
        bands = (d && d.success && Array.isArray(d.grade_equiv) && d.grade_equiv.length) ? d.grade_equiv : DEFAULT_EQUIV;
        TRANSMUTE = bands;
    }
    tmDraft = bands.map(b => ({ min: Number(b.min), point: Number(b.point) }));
    renderTm();
    $('tmModal').classList.add('show');
}
function closeTmModal() { $('tmModal').classList.remove('show'); }

function renderTm() {
    if (!tmDraft.length) {
        $('tmBody').innerHTML = '<div class="bulk-note" style="padding:.3rem 0;">No bands yet — add one below.</div>';
        return;
    }
    $('tmBody').innerHTML = tmDraft.map((b, i) => `
        <div class="tm-row" data-idx="${i}">
            <input type="number" class="tm-min" data-idx="${i}" value="${b.min === '' ? '' : b.min}" min="0" max="100" step="1" placeholder="min">
            <i class="bi bi-arrow-right tm-arrow"></i>
            <input type="number" class="tm-pt" data-idx="${i}" value="${b.point === '' ? '' : b.point}" min="1" max="5" step="0.25" placeholder="pt">
            <button class="tm-del setup-del" data-idx="${i}" title="Remove band">&times;</button>
        </div>`).join('');
    $('tmBody').querySelectorAll('.tm-min, .tm-pt').forEach(inp => inp.addEventListener('input', tmEdit));
    $('tmBody').querySelectorAll('.tm-del').forEach(btn =>
        btn.addEventListener('click', () => { tmDraft.splice(+btn.dataset.idx, 1); renderTm(); }));
}

function tmEdit(e) {
    const i = +e.target.dataset.idx;
    const v = e.target.value === '' ? '' : Number(e.target.value);
    if (e.target.classList.contains('tm-min')) tmDraft[i].min = v;
    else tmDraft[i].point = v;
}

function tmAddBand() {
    tmDraft.push({ min: '', point: '' });
    renderTm();
    const mins = $('tmBody').querySelectorAll('.tm-min');
    if (mins.length) mins[mins.length - 1].focus();
}

/* Ibalik ang karaniwang PH college scale. Ang DRAFT lang ang pinapalitan —
   hindi ito nagse-save, kaya nakikita mo muna ang siyam na banda at nababawi
   ng Cancel. Ito ang tanging paraan para maibalik ang default: sadyang hindi
   hinahawakan ng "Clear all" ang transmutation (tingnan ang ResetRepo), dahil
   ang tahimik na pagpapalit ng iskala ay nagbabago ng grado nang walang
   anumang nagpapakita kung bakit. */
async function tmResetDefaults() {
    const same = tmDraft.length === DEFAULT_EQUIV.length
        && DEFAULT_EQUIV.every((d, i) => Number(tmDraft[i].min) === d.min && Number(tmDraft[i].point) === d.point);
    if (same) { showToastSafe('Already the default scale.', 'success'); return; }
    if (!await uiConfirm({
        title: 'Reset to the default scale?',
        message: 'The bands in this table are replaced with the standard PH college scale. '
            + '<b>Nothing is saved</b> until you press Save table — Cancel still puts your bands back.',
        ok: 'Load default scale',
        icon: 'bi-arrow-counterclockwise',
    })) return;
    tmDraft = DEFAULT_EQUIV.map(b => ({ min: b.min, point: b.point }));
    renderTm();
    showToastSafe('Default scale loaded — press Save table to keep it.', 'success');
}

async function saveTm() {
    /* mirror server-side validation: min 0–100, point 1.00–5.00, dedup by min */
    const clean = [];
    const seen = new Set();
    for (const b of tmDraft) {
        const mn = Number(b.min), pt = Number(b.point);
        if (b.min === '' || b.point === '' || Number.isNaN(mn) || Number.isNaN(pt)) continue;
        if (mn < 0 || mn > 100 || pt < 1 || pt > 5) continue;
        const key = mn.toFixed(2);
        if (seen.has(key)) continue;
        seen.add(key);
        clean.push({ min: mn, point: pt });
    }
    if (!clean.length) { showToastSafe('Add at least one valid band (min 0–100, point 1.00–5.00).', 'error'); return; }
    clean.sort((a, b) => b.min - a.min);   // highest min first

    const d = await apiPost({ api: 'save_transmute', bands: JSON.stringify(clean) });
    if (!d.success) { showToastSafe(d.message || 'Save failed', 'error'); return; }
    TRANSMUTE = d.grade_equiv;
    if (SHEET) SHEET.grade_equiv = d.grade_equiv;
    closeTmModal();
    if (SHEET) render();
    const n = d.grade_equiv.length;
    showToastSafe(`Transmutation saved — ${n} band${n === 1 ? '' : 's'}.`, 'success');
}

/* ── Full backup: every section → one .xlsx (one sheet per section) ── */
function loadScriptOnce(src) {
    return new Promise((resolve, reject) => {
        if (window.XLSX) return resolve();
        const existing = document.querySelector('script[data-lib="xlsx"]');
        if (existing) { existing.addEventListener('load', () => resolve()); existing.addEventListener('error', reject); return; }
        const sc = document.createElement('script');
        sc.src = src; sc.dataset.lib = 'xlsx';
        sc.onload = () => resolve();
        sc.onerror = () => reject(new Error('load failed'));
        document.head.appendChild(sc);
    });
}

async function exportBackup() {
    showToastSafe('Preparing backup…', 'info');
    try {
        await loadScriptOnce('https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js');
    } catch (e) {
        showToastSafe('Could not load the Excel library — check your connection.', 'error');
        return;
    }
    if (!window.XLSX) { showToastSafe('Excel library unavailable.', 'error'); return; }

    const ms = await apiGet({ api: 'my_sections' });
    const sections = (ms && ms.success && Array.isArray(ms.sections)) ? ms.sections : [];
    if (!sections.length) { showToastSafe('No sections with activities to back up yet.', 'info'); return; }

    const wb = XLSX.utils.book_new();
    const usedNames = new Set();
    const sheetName = raw => {                       // Excel tab names: ≤31 chars, no \ / ? * [ ] :
        let base = String(raw || 'Section').replace(/[\\/?*\[\]:]/g, ' ').slice(0, 28).trim() || 'Section';
        let n = base, i = 2;
        while (usedNames.has(n.toLowerCase())) n = `${base.slice(0, 25)} ${i++}`;
        usedNames.add(n.toLowerCase());
        return n;
    };

    const summary = [['eGradeBook backup'], ['Exported', new Date().toLocaleString()], [],
                     ['Section', 'Students', 'Activities', 'Scores', 'Status']];
    let totalScores = 0, okCount = 0, emptyCount = 0, failCount = 0;

    for (const sec of sections) {
        let d = null;
        try { d = await apiGet({ api: 'sheet', section: sec }); } catch (e) { d = null; }

        if (!d || !d.success) {                       // never skip silently — record it
            failCount++;
            const why = (d && d.message) ? d.message : 'fetch failed';
            summary.push([sec, 0, 0, 0, why]);
            XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet([['Could not load this section', why]]), sheetName(sec));
            continue;
        }

        const acts = (d.columns || []).filter(c => c.type === 'activity' || c.type === 'form' || c.type === 'attendance');
        const students = d.students || [];
        const scores = d.scores || {};
        const st = d.statuses || {};

        const rows = [['Student No', 'Name', 'Status', ...acts.map(a => `${a.title} (max ${a.max || 0})`)]];
        let secScores = 0;
        students.forEach(s => {
            const row = [s.student_no, s.fullname || '', st[s.student_no] || ''];
            acts.forEach(a => {
                const rec = (scores[s.student_no] || {})[a.key];
                if (rec && rec.score !== '' && rec.score !== null && rec.score !== undefined) { row.push(Number(rec.score)); secScores++; }
                else row.push('');
            });
            rows.push(row);
        });
        totalScores += secScores;

        XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), sheetName(sec));
        if (!students.length) { emptyCount++; summary.push([sec, 0, acts.length, 0, 'no students in roster']); }
        else { okCount++; summary.push([sec, students.length, acts.length, secScores, 'ok']); }

        await new Promise(r => setTimeout(r, 120));   // ease off InfinityFree between requests
    }

    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(summary), 'Summary');
    wb.SheetNames.unshift(wb.SheetNames.pop());       // move Summary to the front

    const stamp = new Date().toISOString().slice(0, 10);
    XLSX.writeFile(wb, `eGradeBook_backup_${stamp}.xlsx`);

    const bits = [`${okCount} section${okCount === 1 ? '' : 's'}`, `${totalScores} score${totalScores === 1 ? '' : 's'}`];
    if (emptyCount) bits.push(`${emptyCount} empty`);
    if (failCount)  bits.push(`${failCount} failed`);
    const kind = okCount === 0 ? 'error' : (emptyCount || failCount) ? 'warning' : 'success';
    showToastSafe('Backup: ' + bits.join(' · ') + '. Check the Summary sheet for details.', kind);
}

/* ── Export ALL sections → one combined PDF (page per section) ──
   Presentation/printout of the final grades (Midterm/Final/General Ave/
   Equivalent/Remark in term mode, or Final %/Equivalent/Remark in flat mode).
   Uses jsPDF + autotable, loaded on demand from CDN (same pattern as the xlsx
   backup). This is NOT a data backup — use "Backup all (Excel)" for re-import. */
function loadExternalScript(src) {
    return new Promise((resolve, reject) => {
        const sel = `script[data-src="${src}"]`;
        const ex = document.querySelector(sel);
        if (ex) {
            if (ex.dataset.loaded === '1') return resolve();
            ex.addEventListener('load', () => resolve());
            ex.addEventListener('error', () => reject(new Error('load failed')));
            return;
        }
        const sc = document.createElement('script');
        sc.src = src; sc.dataset.src = src;
        sc.onload = () => { sc.dataset.loaded = '1'; resolve(); };
        sc.onerror = () => reject(new Error('load failed'));
        document.head.appendChild(sc);
    });
}

/* ── Report header (ulo ng mga PDF) ──────────────────────────
   Isa kada guro, hindi kada klase. Naka-cache dito matapos ang unang hingi,
   dahil ang "Export all" ay dumadaan sa maraming section at hindi dapat
   umuulit ang tawag kada pahina. */
let REPORT_HDR = null;
let rhSuggestName = '';

async function loadReportHeader() {
    if (REPORT_HDR) return REPORT_HDR;
    const d = await apiGet({ api: 'get_report_header' });
    /* Kapag pumalya (walang network, lumang server), blangko — mas mabuting
       mawalan ng ulo ang PDF kaysa hindi tuluyang ma-export. */
    REPORT_HDR = (d && d.success && d.header) ? d.header
        : { school: '', department: '', title: '', faculty: '', note: '' };
    if (d && d.suggest_faculty) rhSuggestName = d.suggest_faculty;
    return REPORT_HDR;
}

/* Ang pangalan ng gurong ilalagay sa report: ang tahasang itinakda muna, tapos
   ang pangalan sa navbar. (Ang lumang code dito ay humahanap ng '.user-pill
   span' — klase iyon ng FormFlow, wala rito, kaya BLANGKO ang Faculty sa bawat
   PDF mula pa noon.) */
function reportFaculty(hdr) {
    if (hdr && hdr.faculty) return hdr.faculty;
    const el = document.querySelector('.profile-name');
    return el ? el.textContent.trim() : '';
}

/* Iguhit ang ulo sa itaas ng isang pahina; ibinabalik ang y kung saan puwede
   nang magsimula ang laman. Ang blangkong field ay LINALAKTAWAN — walang
   naiiwang bakanteng linya, kaya ang hindi humahawak ng setting na ito ay
   nakakakuha ng eksaktong dating anyo. */
function drawReportHead(doc, hdr, opts) {
    const o = opts || {};
    const left = o.left || 40;
    const fallbackTitle = o.fallbackTitle || 'eGradeBook — Grade Sheet';
    let y = o.top || 42;

    if (hdr.school) {
        doc.setFont('helvetica', 'bold'); doc.setFontSize(13); doc.setTextColor(20);
        doc.text(hdr.school, left, y); y += 15;
    }
    if (hdr.department) {
        doc.setFont('helvetica', 'normal'); doc.setFontSize(9.5); doc.setTextColor(110);
        doc.text(hdr.department, left, y); y += 14;
    }
    doc.setFont('helvetica', 'bold'); doc.setFontSize(hdr.school ? 12 : 15); doc.setTextColor(20);
    doc.text(hdr.title || fallbackTitle, left, y); y += 18;
    doc.setFont('helvetica', 'normal'); doc.setTextColor(0);
    return y;
}

/* ── Report header editor ── */
async function openReportHdr() {
    $('rhErr').style.display = 'none';
    $('rhModal').classList.add('show');
    const h = await loadReportHeader();
    $('rhSchool').value  = h.school || '';
    $('rhDept').value    = h.department || '';
    $('rhTitle').value   = h.title || '';
    $('rhFaculty').value = h.faculty || '';
    $('rhNote').value    = h.note || '';
    setTimeout(() => $('rhSchool').focus(), 60);
}
function closeReportHdr() { $('rhModal').classList.remove('show'); }

async function saveReportHdr() {
    const btn = $('rhSave');
    btn.disabled = true;
    const d = await apiPost({
        api: 'save_report_header',
        school: $('rhSchool').value,
        department: $('rhDept').value,
        title: $('rhTitle').value,
        faculty: $('rhFaculty').value,
        note: $('rhNote').value,
    });
    btn.disabled = false;
    if (!d.success) {
        $('rhErr').textContent = d.message || 'Could not save the report header.';
        $('rhErr').style.display = 'block';
        return;
    }
    /* Ang NILINIS na halaga ng server ang itinatago at ipinapakita — kung may
       pinutol o inalis na bagong linya, dapat iyon ang makita ng guro, hindi
       ang tinipa niya. */
    REPORT_HDR = d.header;
    closeReportHdr();
    showToastSafe('Report header saved — it applies to every PDF.', 'success');
}

/* Export the current section only → PDF (same layout as Export all). */
async function exportSectionPDF() {
    if (!SHEET || !SHEET.section) { showToastSafe('Select a section first before exporting.', 'error'); return; }
    const safe = String(SHEET.section).replace(/[^\w.-]+/g, '_') || 'section';
    return buildSectionsPDF([SHEET.section], `eGradeBook_${safe}`);
}

/* Export every section → one combined PDF. */
async function exportAllPDF() {
    const ms = await apiGet({ api: 'my_sections' });
    const sections = (ms && ms.success && Array.isArray(ms.sections)) ? ms.sections : [];
    if (!sections.length) { showToastSafe('No sections to export yet.', 'info'); return; }
    return buildSectionsPDF(sections, 'eGradeBook_grades');
}

/* Core: render the given sections (page per section) into one PDF and save it. */
async function buildSectionsPDF(sections, fileBase) {
    showToastSafe('Preparing PDF…', 'info');
    try {
        await loadExternalScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
        await loadExternalScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js');
    } catch (e) {
        showToastSafe('Could not load the PDF library — check your connection.', 'error');
        return;
    }
    const jsPDFctor = window.jspdf && window.jspdf.jsPDF;
    if (!jsPDFctor) { showToastSafe('PDF library unavailable.', 'error'); return; }

    const pass = clampPct(parseFloat($('numPass').value) || 75);
    const missingZero = $('chkMissingZero') ? $('chkMissingZero').checked : false;

    const doc = new jsPDFctor({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const hdr = await loadReportHeader();
    const teacher = reportFaculty(hdr);

    /* the grade helpers read the globals SHEET / selectedCols — swap them per
       section, then restore so the live view is untouched afterwards */
    const savedSheet = SHEET, savedSel = selectedCols;
    let pageAdded = false, okCount = 0, emptyCount = 0, failCount = 0;

    try {
        for (const sec of sections) {
            let d = null;
            try { d = await apiGet({ api: 'sheet', section: sec }); } catch (e) { d = null; }
            if (!d || !d.success) { failCount++; continue; }

            SHEET = d;
            selectedCols = new Set(d.columns.filter(c => c.type === 'activity' || c.responded > 0).map(c => c.key));
            if (selectedCols.size === 0) d.columns.forEach(c => selectedCols.add(c.key));

            const termMode = d.term_mode === true;
            const students = d.students || [];
            const st = d.statuses || {};

            /* the individual assessment columns (activities + forms) shown on the
               sheet — so the PDF has the full breakdown, not just final grades */
            const assessCols = (d.columns || []).filter(c => (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && selectedCols.has(c.key));
            const assessHeads = assessCols.map(c => `${c.title}\n/${c.max || 0}`);

            const tailHeads = termMode
                ? ['Midterm', 'Final', 'General Ave', 'Equivalent', 'Remark']
                : ['Final %', 'Equivalent', 'Remark'];
            const head = [['#', 'Student No', 'Name', ...assessHeads, ...tailHeads]];
            const body = [];

            students.forEach((s, i) => {
                const status = st[s.student_no] || '';
                const remarkOverride = status ? (STATUS_FULL[status] || status) : null;
                /* per-assessment score cells */
                const scoreCells = assessCols.map(c => {
                    const rec = getRec(s.student_no, c.key);
                    return (rec && rec.score !== '' && rec.score !== null && rec.score !== undefined) ? Number(rec.score) : '—';
                });
                if (termMode) {
                    const mid = termGrade(s, 'midterm'), fin = termGrade(s, 'final'), ga = generalAverage(s);
                    const equiv = (ga && ga.anyScore) ? transmuteExcel(ga.ave) : null;
                    const remark = remarkOverride || ((ga && ga.anyScore) ? (equiv !== null ? 'Passed' : 'Failed') : '—');
                    body.push([
                        i + 1, s.student_no, s.fullname || '', ...scoreCells,
                        mid ? mid.grade.toFixed(1) : '—',
                        fin ? fin.grade.toFixed(1) : '—',
                        (ga && ga.anyScore) ? ga.ave.toFixed(2) : '—',
                        status ? '—' : ((ga && ga.anyScore) ? (equiv !== null ? equiv : '5.00') : '—'),
                        remark,
                    ]);
                } else {
                    const cg = courseworkGrade(s, missingZero);
                    const pt = cg.gotAny ? transmutePoint(cg.pct) : '—';
                    const remark = remarkOverride || (cg.gotAny ? (cg.pct >= pass ? 'Passed' : 'Failed') : '—');
                    body.push([
                        i + 1, s.student_no, s.fullname || '', ...scoreCells,
                        cg.gotAny ? cg.pct.toFixed(1) + '%' : '—',
                        status ? '—' : pt,
                        remark,
                    ]);
                }
            });

            if (!students.length) emptyCount++; else okCount++;

            if (pageAdded) doc.addPage();
            pageAdded = true;

            let hy = drawReportHead(doc, hdr, { left: 40, top: 42 });
            doc.setFontSize(9.5); doc.setTextColor(110);
            const meta = `Section: ${sec}    ·    Mode: ${termMode ? 'Term (Midterm / Final)' : 'Coursework'}    ·    Passing: ${pass}%`;
            doc.text(meta, 40, hy); hy += 14;
            doc.text(`${teacher ? 'Faculty: ' + teacher + '    ·    ' : ''}Exported: ${new Date().toLocaleString()}`, 40, hy); hy += 14;
            doc.setTextColor(0);

            doc.autoTable({
                head, body, startY: hy,
                styles: { fontSize: 7, cellPadding: 2, overflow: 'linebreak', halign: 'center', valign: 'middle' },
                headStyles: { fillColor: [37, 99, 235], textColor: 255, halign: 'center', fontSize: 6.8 },
                columnStyles: {
                    0: { halign: 'center', cellWidth: 20 },
                    1: { halign: 'center' },
                    2: { halign: 'left', cellWidth: 92 },
                },
                didParseCell: (data) => {
                    if (data.section === 'body' && data.column.index === head[0].length - 1) {
                        const v = String(data.cell.raw || '');
                        if (v === 'Passed') data.cell.styles.textColor = [22, 128, 61];
                        else if (v === 'Failed') data.cell.styles.textColor = [190, 40, 40];
                        else if (v !== '—') data.cell.styles.textColor = [180, 100, 10];   // INC/DRP/W
                    }
                },
                didDrawPage: () => {
                    doc.setFontSize(8); doc.setTextColor(150);
                    const w = doc.internal.pageSize.getWidth(), h = doc.internal.pageSize.getHeight();
                    if (hdr.note) doc.text(hdr.note, 40, h - 20);
                    doc.text(`Page ${doc.internal.getNumberOfPages()}`, w - 60, h - 20);
                    doc.setTextColor(0);
                },
                margin: { left: 40, right: 40 },
            });

            await new Promise(r => setTimeout(r, 120));   // ease off InfinityFree between requests
        }
    } finally {
        SHEET = savedSheet; selectedCols = savedSel;   // restore the live view's state
    }

    if (!pageAdded) { showToastSafe('Nothing to export.', 'info'); return; }
    const stamp = new Date().toISOString().slice(0, 10);
    doc.save(`${fileBase}_${stamp}.pdf`);

    const bits = [`${okCount} section${okCount === 1 ? '' : 's'}`];
    if (emptyCount) bits.push(`${emptyCount} empty`);
    if (failCount)  bits.push(`${failCount} failed`);
    showToastSafe('PDF: ' + bits.join(' · ') + '.', failCount ? 'warning' : 'success');
}

/* ── CSV export ─────────────────────────────────────────── */
function exportCSV() {
    if (!SHEET) {
        showToastSafe('Select a section first before exporting.', 'error');
        return;
    }
    const pass = clampPct(parseFloat($('numPass').value) || 0);
    const missingZero = $('chkMissingZero').checked;
    const selected   = SHEET.columns.filter(c => selectedCols.has(c.key));
    const courseCols = selected.filter(c => c.type !== 'defense');   // only points-based ones enter the Total
    const hasDefense = false;   // no live defense

    if (!courseCols.length && !hasDefense) {
        alert('Please select at least one column.');
        return;
    }

    const head = ['#', 'Student No', 'Name',
        ...courseCols.map(c => `${c.title} (/${c.max || '?'})`),
        'Total', 'Max', 'Percentage', 'Remark'];
    if (hasDefense) head.push('Defense (avg /100)', 'Defense (1.00-5.00)', 'Defense Source', 'Defense Remark');
    const hasCourse = SHEET.columns.some(c => c.type === 'activity' || c.type === 'form' || c.type === 'attendance');
    const hasFinal  = hasCourse || hasDefense;
    if (hasFinal) {
        const finLbl = hasDefense ? 'Final Average' : 'Final (coursework)';
        head.push(finLbl, 'Final (1.00-5.00)', 'Final Remark');
    }
    const termMode = SHEET.term_mode === true;
    if (termMode) head.push('Midterm', 'Final', 'General Ave', 'Equivalent', 'Remark');

    const rows = [head];
    SHEET.students.forEach((s, i) => {
        const cells = courseCols.map(c => {
            const rec = getRec(s.student_no, c.key);
            return rec ? rec.score : '';
        });

        const cg = courseworkGrade(s, missingZero);
        const total  = cg.gotAny ? cg.got : '';
        const maxOut = cg.gotAny ? cg.max : '';
        const pctOut = cg.gotAny ? cg.pct.toFixed(1) + '%' : '';
        const remark = cg.gotAny ? (cg.pct >= pass ? 'Passed' : 'Failed') : 'Not graded';

        const row = [i + 1, s.student_no, s.fullname, ...cells, total, maxOut, pctOut, remark];

        if (hasDefense) {
            const draw = getRec(s.student_no, 'dfn_raw');
            const dtx  = getRec(s.student_no, 'dfn_tx');
            const dv   = defenseRaw(s.student_no);
            if (dv !== null) {
                row.push(
                    dv.toFixed(2),
                    dtx ? String(dtx.score) : '',
                    draw.src === 'group' ? 'Group' : 'Individual',
                    dv >= DEFENSE_PASS ? 'Passed' : 'Failed'
                );
            } else {
                row.push('', '', '', '');
            }
        }
        if (hasFinal) {
            const fg = finalGrade(cg.pct, cg.gotAny, defenseRaw(s.student_no), hasDefense);
            if (fg) {
                row.push(fg.val.toFixed(2), fg.pt, fg.val >= pass ? 'Passed' : 'Failed');
            } else {
                row.push('', '', '');
            }
        }
        if (termMode) {
            const ga = generalAverage(s);
            if (ga && ga.anyScore) {
                const eq = transmuteExcel(ga.ave);
                row.push(
                    ga.mid ? ga.mid.grade.toFixed(2) : '',
                    ga.fin ? ga.fin.grade.toFixed(2) : '',
                    ga.ave.toFixed(2),
                    eq !== null ? eq : '5.00',
                    eq !== null ? 'Passed' : 'Failed'
                );
            } else {
                row.push('', '', '', '', '');
            }
        }
        rows.push(row);
    });

    const csv = rows.map(r => r.map(c => {
        const v = String(c ?? '');
        return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
    }).join(',')).join('\n');

    const blob = new Blob(["\uFEFF" + csv], {
        type: 'text/csv;charset=utf-8;'
    });
    const safeSection = String(SHEET.section || 'section').replace(/[^\w.-]+/g, '_');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `grading_${safeSection}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(a.href);
}

/* ── Copy setup from another section ─────────────────────── */
function sectionLabel(s) {
    return (s.course ? s.course + ' · ' : '') + s.section + ` (${s.count})`;
}

function openCopyModal() {
    if (!SHEET) { showToastSafe('Select the target section first.', 'error'); return; }
    const cur = SHEET.section;
    const sel = $('copyFromSection');
    const others = ALL_SECTIONS.filter(s => s.section !== cur);
    if (!others.length) {
        sel.innerHTML = `<option value="">No other sections available</option>`;
    } else {
        sel.innerHTML = `<option value="">— Select a section —</option>` +
            others.map(s => `<option value="${escAttr(s.section)}">${escAttr(sectionLabel(s))}</option>`).join('');
    }
    const curObj = ALL_SECTIONS.find(s => s.section === cur);
    $('copyTargetNote').textContent = 'Copying into: ' + (curObj ? sectionLabel(curObj) : cur);
    $('copyIncludeSettings').checked = true;
    $('copyApply').disabled = true;
    $('copyModal').classList.add('show');
}

function closeCopyModal() {
    $('copyModal').classList.remove('show');
}

async function applyCopy() {
    if (!SHEET) return;
    const from = $('copyFromSection').value;
    if (!from) { showToastSafe('Pick a section to copy from.', 'error'); return; }
    const incl = $('copyIncludeSettings').checked;
    const btn = $('copyApply');
    btn.disabled = true;
    const d = await apiPost({
        api: 'copy_activities',
        to_section: SHEET.section,
        from_section: from,
        include_settings: incl ? '1' : '0'
    });
    if (!d.success) {
        showToastSafe(d.message || 'Copy failed', 'error');
        btn.disabled = false;
        return;
    }
    closeCopyModal();
    /* Refresh whenever anything landed on the sheet: new activity columns,
       copied categories, or the grade setup (term mode) being applied. */
    const changed = (d.copied > 0) || (d.cats > 0) || (incl && d.settings);
    if (changed) {
        await loadSheet(SHEET.section);   // reload to show new columns / categories / grade setup
        const kind = (d.copied > 0 || d.cats > 0) ? 'success' : 'info';
        showToastSafe(d.message, kind);
    } else {
        showToastSafe(d.message || 'Nothing to copy.', 'info');
    }
}

/* ── Per-student grade breakdown ────────────────────────── */
let bdStudentNo = null;   // student_no whose breakdown is currently open (for Save PDF)
function openBreakdown(sno) {
    if (!SHEET) return;
    const s = SHEET.students.find(st => String(st.student_no) === String(sno));
    if (!s) return;
    bdStudentNo = s.student_no;
    $('bdName').textContent = s.fullname || 'Student';
    $('bdSno').textContent = s.student_no || '';
    $('bdBody').innerHTML = (SHEET.term_mode === true ? buildBreakdownTerm(s) : buildBreakdownFlat(s)) + buildStatusPicker(s);
    $('bdBody').querySelectorAll('.bd-st-btn').forEach(btn => {
        btn.addEventListener('click', () => setStudentStatus(s.student_no, btn.dataset.status));
    });
    /* custom final-status label → applies on Set / Enter */
    const cin = $('bdCustomStatus'), cset = $('bdCustomSet');
    if (cin && cset) {
        const applyCustom = () => { const v = cin.value.trim(); if (v) setStudentStatus(s.student_no, v); };
        cset.addEventListener('click', applyCustom);
        cin.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); applyCustom(); } });
    }
    $('breakdownModal').classList.add('show');
}
function closeBreakdown() { $('breakdownModal').classList.remove('show'); }

/* Save the OPEN student's breakdown as a one-page PDF grade slip — the same
   "% × weight = points" view shown in the modal, printable/shareable so each
   student can see exactly how their grade was built. */
async function exportStudentPDF() {
    if (!SHEET || !bdStudentNo) { showToastSafe('Open a student first.', 'error'); return; }
    const s = SHEET.students.find(st => String(st.student_no) === String(bdStudentNo));
    if (!s) { showToastSafe('Student not found.', 'error'); return; }

    showToastSafe('Preparing grade slip…', 'info');
    try {
        await loadExternalScript('https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
    } catch (e) { showToastSafe('Could not load the PDF library — check your connection.', 'error'); return; }
    const jsPDFctor = window.jspdf && window.jspdf.jsPDF;
    if (!jsPDFctor) { showToastSafe('PDF library unavailable.', 'error'); return; }

    const doc = new jsPDFctor({ orientation: 'portrait', unit: 'pt', format: 'a4' });
    const pageW = doc.internal.pageSize.getWidth();
    const pageH = doc.internal.pageSize.getHeight();
    const left = 48, right = pageW - 48;
    let y = 56;
    const nl = (h = 16) => { y += h; if (y > pageH - 50) { doc.addPage(); y = 56; } };
    const put = (text, x, o = {}) => {
        doc.setFont('helvetica', o.bold ? 'bold' : 'normal');
        doc.setFontSize(o.size || 10);
        doc.setTextColor(...(o.color || [20, 20, 20]));
        doc.text(String(text), x, y, o.align ? { align: o.align } : undefined);
    };
    const rule = () => { doc.setDrawColor(210); doc.line(left, y, right, y); };

    /* Ang ulo ay pareho ng grade sheet — paaralan/departamento galing sa
       setting ng guro — pero LAGING "Grade Slip" ang pamagat: ibang dokumento
       ito, at ang pamagat ng buong sheet ("Grade Sheet") ay mali rito. */
    const hdr = await loadReportHeader();
    if (hdr.school)     { put(hdr.school, left, { bold: true, size: 13 }); nl(16); }
    if (hdr.department) { put(hdr.department, left, { size: 9.5, color: [110, 110, 110] }); nl(14); }
    put('Grade Slip', left, { bold: true, size: hdr.school ? 13 : 16 }); nl(22);

    put(s.fullname || 'Student', left, { bold: true, size: 13 });
    put(`No. ${s.student_no || ''}`, right, { size: 10, color: [110, 110, 110], align: 'right' }); nl(16);
    const teacher = reportFaculty(hdr);
    put(`Section: ${SHEET.section}${teacher ? '   ·   Faculty: ' + teacher : ''}`, left, { size: 9, color: [110, 110, 110] }); nl(12);
    put(`Generated: ${new Date().toLocaleString()}`, left, { size: 9, color: [110, 110, 110] }); nl(10);
    rule(); nl(22);

    const termMode = SHEET.term_mode === true;
    const status = (SHEET.statuses || {})[s.student_no] || '';

    if (termMode) {
        [['midterm', 'Midterm'], ['final', 'Final']].forEach(([tKey, tLabel]) => {
            const cats = (SHEET.categories || []).filter(c => c.term === tKey);
            if (!cats.length) return;
            const tg = termGrade(s, tKey);
            const totalW = cats.reduce((t, c) => t + (Number(c.weight) || 0), 0);
            put(tLabel, left, { bold: true, size: 12 });
            put(tg ? tg.grade.toFixed(1) : '—', right, { bold: true, size: 12, color: [37, 99, 235], align: 'right' }); nl(18);
            cats.forEach(cat => {
                /* kaparehong salaan ng termGrade — dapat ipakita ng breakdown ang
                   mismong mga column na binilang sa grado sa itaas nito */
                const acts = SHEET.columns.filter(c => (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && selectedCols.has(c.key) && c.term === tKey && c.category_id === cat.id);
                let raw = 0, mx = 0;
                acts.forEach(a => { const rec = getRec(s.student_no, a.key); mx += a.max || 0; if (rec) raw += Number(rec.score) || 0; });
                const catPct = mx > 0 ? raw / mx * 100 : 0;
                const wt = Number(cat.weight) || 0;
                const contrib = totalW > 0 ? (catPct / 100 * wt / totalW * 100) : 0;
                const fracTxt = mx > 0 ? `${raw}/${mx}` : '—';
                put(`${cat.name} (${wt}%)`, left + 14, { bold: true, size: 10 });
                put(`${fracTxt} × ${wt}% = ${contrib.toFixed(1)} pts`, right, { size: 9.5, color: [37, 99, 235], align: 'right' }); nl(15);
                acts.forEach(a => {
                    const rec = getRec(s.student_no, a.key);
                    put(a.title, left + 28, { size: 9, color: [90, 90, 90] });
                    put(rec ? `${Number(rec.score)} / ${a.max || 0}` : `— / ${a.max || 0}`, right, { size: 9, color: [60, 60, 60], align: 'right' }); nl(13);
                });
                if (!acts.length) { put('No activities', left + 28, { size: 9, color: [150, 150, 150] }); nl(13); }
            });
            put(`${tLabel} = sum of the points above = ${tg ? tg.grade.toFixed(1) : '—'}`, left + 14, { size: 9, color: [110, 110, 110] }); nl(20);
        });

        const ga = generalAverage(s);
        const equiv = (ga && ga.anyScore) ? transmuteExcel(ga.ave) : null;
        rule(); nl(20);
        put('General Average', left, { bold: true, size: 11 });
        put(ga && ga.anyScore ? ga.ave.toFixed(2) : '—', right, { bold: true, size: 11, align: 'right' }); nl(16);
        put('Equivalent', left, { size: 10 });
        put(status ? '—' : (equiv !== null ? equiv : (ga && ga.anyScore ? '5.00' : '—')), right, { size: 10, align: 'right' }); nl(16);
        const remark = status ? (STATUS_FULL[status] || status) : ((ga && ga.anyScore) ? (equiv !== null ? 'Passed' : 'Failed') : '—');
        put('Remark', left, { bold: true, size: 10 });
        put(remark, right, { bold: true, size: 10, align: 'right', color: status ? [180, 100, 10] : (equiv !== null ? [22, 128, 61] : [190, 40, 40]) }); nl(16);
    } else {
        const missingZero = $('chkMissingZero') ? $('chkMissingZero').checked : false;
        const pass = clampPct(parseFloat($('numPass').value) || 0);
        const cg = courseworkGrade(s, missingZero);
        const cols = SHEET.columns.filter(c => (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && selectedCols.has(c.key));
        put('Coursework', left, { bold: true, size: 12 });
        put(cg.gotAny ? cg.pct.toFixed(1) + '%' : '—', right, { bold: true, size: 12, color: [37, 99, 235], align: 'right' }); nl(18);
        cols.forEach(c => {
            const rec = getRec(s.student_no, c.key);
            const wtxt = (cg.weighted && Number(c.weight) > 0) ? ` (${Number(c.weight)}%)` : '';
            put(c.title + wtxt, left + 14, { size: 9, color: [90, 90, 90] });
            put(rec ? `${Number(rec.score)} / ${c.max || 0}` : `— / ${c.max || 0}`, right, { size: 9, color: [60, 60, 60], align: 'right' }); nl(13);
        });
        nl(6); rule(); nl(18);
        put('Final grade', left, { bold: true, size: 11 });
        put(cg.gotAny ? cg.pct.toFixed(1) + '%' : '—', right, { bold: true, size: 11, align: 'right' }); nl(16);
        put('Equivalent', left, { size: 10 });
        put(status ? '—' : (cg.gotAny ? transmutePoint(cg.pct) : '—'), right, { size: 10, align: 'right' }); nl(16);
        const isPass = cg.pct >= pass;
        const remark = status ? (STATUS_FULL[status] || status) : (cg.gotAny ? (isPass ? 'Passed' : 'Failed') : '—');
        put('Remark', left, { bold: true, size: 10 });
        put(remark, right, { bold: true, size: 10, align: 'right', color: status ? [180, 100, 10] : (isPass ? [22, 128, 61] : [190, 40, 40]) }); nl(16);
    }

    /* Footer note ng guro (hal. "Prepared by: ___ Noted by: ___") — sa ibaba
       ng pahina, hindi kasunod ng huling linya, para pare-pareho ang puwesto
       nito sa bawat slip anuman ang haba ng breakdown. */
    if (hdr.note) {
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(8.5);
        doc.setTextColor(130);
        doc.text(hdr.note, left, pageH - 40);
        doc.setTextColor(0);
    }

    const safe = String(s.student_no || 'student').replace(/[^\w.-]+/g, '_');
    doc.save(`gradeslip_${safe}.pdf`);
    showToastSafe('Grade slip saved.', 'success');
}

function buildStatusPicker(s) {
    const cur = (SHEET.statuses || {})[s.student_no] || '';
    const opts = [['', 'Auto'], ['INC', 'INC'], ['DRP', 'DRP'], ['W', 'W']];
    const custom = isCustomStatus(cur);
    const btns = opts.map(([v, lbl]) =>
        `<button type="button" class="bd-st-btn ${cur === v ? 'active' : ''}" data-status="${v}">${lbl}</button>`).join('');
    return `<div class="bd-status">
        <div class="bd-status-lbl">Final status <span class="bd-note">overrides the computed remark</span></div>
        <div class="bd-status-btns">${btns}</div>
        <div class="bd-status-custom">
            <input type="text" id="bdCustomStatus" class="bd-custom-input ${custom ? 'active' : ''}" maxlength="24"
                placeholder="Custom label (e.g. OJT, Transferred)" value="${custom ? escAttr(cur) : ''}">
            <button type="button" class="bd-custom-set" id="bdCustomSet">Set</button>
        </div>
    </div>`;
}

async function setStudentStatus(sno, status) {
    if (!SHEET) return;
    const d = await apiPost({ api: 'set_student_status', section: SHEET.section, student_no: sno, status });
    if (!d.success) { showToastSafe(d.message || 'Could not save status.', 'error'); return; }
    /* trust the server's normalised value (e.g. "inc" → "INC", custom capped) */
    const saved = (typeof d.status === 'string') ? d.status : status;
    if (!SHEET.statuses) SHEET.statuses = {};
    if (saved) SHEET.statuses[sno] = saved; else delete SHEET.statuses[sno];
    render();
    openBreakdown(sno);   // refresh active state
    const full = STATUS_FULL[saved];
    showToastSafe(saved ? `Marked as ${saved}${full ? ' (' + full + ')' : ''}.` : 'Status cleared — back to computed grade.', 'success');
}

/* Remark row for the breakdown modal. A final-status override (INC/DRP/W)
   wins over the computed Passed/Failed — same rule as the badge on the sheet
   row (see render / SHEET.statuses). Returns { cls, row } so the caller can
   also neutralise the pass/fail colour of the surrounding .bd-final box. */
function bdRemark(s, isPass) {
    const st = (SHEET.statuses || {})[s.student_no] || '';
    if (st) {
        /* built-in shows "INC — Incomplete"; custom shows the label as-is */
        const label = STATUS_FULL[st] ? `${escHtml(st)} — ${escHtml(STATUS_FULL[st])}` : escHtml(st);
        return {
            cls: 'bd-override',
            row: `<div class="bd-final-row"><span>Remark</span><span class="bd-remark bd-r-status">${label}</span></div>`,
        };
    }
    return {
        cls: isPass ? 'bd-pass' : 'bd-fail',
        row: `<div class="bd-final-row"><span>Remark</span><span class="bd-remark ${isPass ? 'bd-r-pass' : 'bd-r-fail'}">${isPass ? 'Passed' : 'Failed'}</span></div>`,
    };
}

function bdActRow(name, rec, mx) {
    const score = rec ? `${Number(rec.score)} <span class="bd-max">/ ${mx}</span>`
                      : `<span class="bd-blank">— / ${mx}</span>`;
    return `<div class="bd-act"><span class="bd-act-name">${escHtml(name)}</span><span class="bd-act-score">${score}</span></div>`;
}

/* Term mode: Midterm/Final → categories (weight) → activities, then General Average */
function buildBreakdownTerm(s) {
    const ga = generalAverage(s);
    if (!ga || !ga.anyScore) return `<div class="bd-empty">No grades yet for this student.</div>`;

    let html = '';
    [['midterm', 'Midterm'], ['final', 'Final']].forEach(([tKey, tLabel]) => {
        const cats = (SHEET.categories || []).filter(c => c.term === tKey);
        if (!cats.length) return;
        const tg = termGrade(s, tKey);
        const totalW = cats.reduce((t, c) => t + (Number(c.weight) || 0), 0);
        html += `<div class="bd-term">
            <div class="bd-term-head"><span>${tLabel}</span><span class="bd-term-grade">${tg ? tg.grade.toFixed(1) : '—'}</span></div>`;
        cats.forEach(cat => {
            /* kaparehong salaan ng termGrade. Dating kulang dito ang 'attendance',
               kaya bumibilang ito sa term grade pero hindi lumalabas sa breakdown —
               mukhang mali ang matematika kahit tama naman. */
            const acts = SHEET.columns.filter(c => (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && selectedCols.has(c.key) && c.term === tKey && c.category_id === cat.id);
            let raw = 0, mx = 0, rows = '';
            acts.forEach(a => {
                const rec = getRec(s.student_no, a.key);
                const amax = a.max || 0; mx += amax;
                if (rec) raw += Number(rec.score) || 0;
                rows += bdActRow(a.title, rec, amax);
            });
            const catPct = mx > 0 ? (raw / mx * 100) : 0;
            const wt = Number(cat.weight) || 0;
            /* how many points this category contributes to the term grade —
               (score ÷ max) × weight (÷ total weight so it always sums to the term). */
            const contrib = totalW > 0 ? (catPct / 100 * wt / totalW * 100) : 0;
            const fracTxt = mx > 0 ? `${raw}/${mx}` : '—';
            html += `<div class="bd-cat">
                <div class="bd-cat-head">
                    <span>${escHtml(cat.name)} <span class="bd-wt">${wt}%</span></span>
                    <span class="bd-cat-pct">${fracTxt} × ${wt}% = <b class="bd-contrib">${contrib.toFixed(1)} pts</b></span>
                </div>
                ${rows || '<div class="bd-act bd-act-empty">No activities</div>'}
            </div>`;
        });
        html += `<div class="bd-term-sum">${tLabel} = sum of the points above = <b>${tg ? tg.grade.toFixed(1) : '—'}</b></div>`;
        html += `</div>`;
    });

    const equiv = transmuteExcel(ga.ave);
    const passed = equiv !== null;
    const note = (ga.mid && ga.fin) ? '= (Midterm + Final) ÷ 2' : (ga.mid ? '= Midterm only' : '= Final only');
    const rem = bdRemark(s, passed);
    html += `<div class="bd-final ${rem.cls}">
        <div class="bd-final-row"><span>General average <span class="bd-note">${note}</span></span><span class="bd-final-num">${ga.ave.toFixed(2)}</span></div>
        <div class="bd-final-row"><span>Equivalent</span><span class="bd-final-num">${passed ? equiv : '5.00'}</span></div>
        ${rem.row}
    </div>`;
    return html;
}

/* Flat mode: selected activities/forms → coursework % → equivalent */
function buildBreakdownFlat(s) {
    const missingZero = $('chkMissingZero').checked;
    const pass = clampPct(parseFloat($('numPass').value) || 0);
    const cg = courseworkGrade(s, missingZero);
    if (!cg.gotAny) return `<div class="bd-empty">No grades yet for this student.</div>`;

    const sel = SHEET.columns.filter(c => selectedCols.has(c.key));
    const list = [...sel.filter(c => c.type === 'activity'), ...sel.filter(c => c.type === 'form'), ...sel.filter(c => c.type === 'attendance')];
    let rows = '';
    list.forEach(c => {
        const rec = getRec(s.student_no, c.key);
        const cmax = c.max || (rec ? rec.max : 0) || 0;
        const wt = (cg.weighted && (c.type === 'activity' || c.type === 'form' || c.type === 'attendance') && Number(c.weight) > 0)
            ? ` <span class="bd-wt">${Number(c.weight)}%</span>` : '';
        rows += `<div class="bd-act"><span class="bd-act-name">${escHtml(c.title)}${wt}</span><span class="bd-act-score">${rec ? `${Number(rec.score)} <span class="bd-max">/ ${cmax}</span>` : `<span class="bd-blank">— / ${cmax}</span>`}</span></div>`;
    });

    const isPass = cg.pct >= pass;
    const pt = transmutePoint(cg.pct);
    const method = cg.weighted
        ? 'Weighted average — Σ(score ÷ max × weight) ÷ total weight'
        : 'Points-based — Σ score ÷ Σ max';

    const rem = bdRemark(s, isPass);
    return `<div class="bd-method">${method}</div>
        <div class="bd-cat">
            <div class="bd-cat-head"><span>Activities</span><span class="bd-cat-pct">${cg.pct.toFixed(1)}%</span></div>
            ${rows}
        </div>
        <div class="bd-final ${rem.cls}">
            <div class="bd-final-row"><span>Final grade</span><span class="bd-final-num">${cg.pct.toFixed(1)}%</span></div>
            <div class="bd-final-row"><span>Equivalent</span><span class="bd-final-num">${pt}</span></div>
            ${rem.row}
        </div>`;
}

/* ── helpers ────────────────────────────────────────────── */
function escAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function clampPct(n) {
    return Math.max(0, Math.min(100, n));
}

function showToastSafe(msg, type) {
    if (typeof showToast === 'function') showToast(msg, type);
    else console.log(type + ':', msg);
}
if (typeof escHtml !== 'function') {
    window.escHtml = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ── In-app confirmation (kapalit ng native confirm) ─────────
   Ang confirm() ng browser ay nagpapakita ng "lexon.free.nf says", walang
   pormat, at hindi kayang bigyang-diin ang pangalan ng tinatamaan — mukha
   itong babala ng browser, hindi bahagi ng sistema. Isang modal na sinusundan
   ang parehong pattern ng iba rito, na nagbabalik ng Promise<boolean>:

       if (!await uiConfirm({ title, message })) return;

   Ang `message` ay HTML (para sa <b> sa pangalan) — kaya escHtml() ang
   anumang galing sa datos, gaya ng ginagawa sa lahat ng tumatawag dito. */
let uiConfirmResolve = null;

function uiConfirm({ title, message, ok = 'Confirm', icon = 'bi-question-circle', danger = false }) {
    /* Nasa sheet.php ang HTML ng modal. Kapag na-upload ang grades.js pero
       hindi ang view (madaling mangyari sa manu-manong deploy), wala ang
       #uiConfirmModal — at ang isang Promise na walang sasagot ay hahantong sa
       await na nakabitin, na para bang na-freeze ang app. Mas mabuting bumalik
       sa dating dialog ng browser: pangit, pero gumagana. */
    if (!$('uiConfirmModal')) {
        const plain = String(message).replace(/<[^>]*>/g, '');
        return Promise.resolve(window.confirm(title + '\n\n' + plain));
    }
    return new Promise(resolve => {
        /* Isang tanong lang ang nakabukas. Kung may naiwang hindi nasagot,
           ituring itong "hindi" para hindi kailanman ma-iwang nakabitin ang
           await ng naunang tumawag. */
        if (uiConfirmResolve) { const old = uiConfirmResolve; uiConfirmResolve = null; old(false); }
        uiConfirmResolve = resolve;

        $('uiConfirmTitle').textContent = title;
        $('uiConfirmMsg').innerHTML = message;
        $('uiConfirmIc').className = 'bi ' + icon;
        $('uiConfirmIcWrap').classList.toggle('danger', !!danger);
        const yes = $('uiConfirmYes');
        yes.innerHTML = escHtml(ok);
        yes.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');

        $('uiConfirmModal').classList.add('show');
        /* Sa mapanirang tanong, ang Cancel ang naka-focus — hindi dapat
           masagot ng isang Enter ang isang bagay na hindi na mababawi. */
        setTimeout(() => (danger ? $('uiConfirmNo') : yes).focus(), 60);
    });
}

function uiConfirmClose(answer) {
    const r = uiConfirmResolve;
    uiConfirmResolve = null;
    $('uiConfirmModal').classList.remove('show');
    if (r) r(answer);
}

/* ── Tag as class (re-tag the current sheet into a named class) ── */
function openRetag() {
    if (!SHEET) { showToastSafe('Open a section first.', 'error'); return; }
    const label = (CLASS.school_year || CLASS.semester || CLASS.subject)
        ? [CLASS.school_year, CLASS.semester, CLASS.subject].filter(Boolean).join(' · ')
        : 'the untagged (existing) sheet';
    $('retagFrom').innerHTML = `<i class="bi bi-info-circle"></i> Tagging section <b>${escHtml(SHEET.section)}</b> — ${escHtml(label)} — into:`;
    $('retagSy').value = CLASS.school_year;
    $('retagSem').value = CLASS.semester;
    $('retagSubj').value = CLASS.subject;
    $('retagErr').style.display = 'none';
    $('retagModal').classList.add('show');
}
function closeRetag() { $('retagModal').classList.remove('show'); }
async function applyRetag() {
    if (!SHEET) return;
    const toSy = $('retagSy').value.trim();
    const toSem = $('retagSem').value;
    const toSubj = $('retagSubj').value.trim();
    const err = $('retagErr');
    const show = m => { err.textContent = m; err.style.display = 'block'; };
    if (!toSy && !toSem && !toSubj) return show('Enter a school year, semester, or subject.');
    if (toSy === CLASS.school_year && toSem === CLASS.semester && toSubj === CLASS.subject)
        return show('That is the same as the current class.');
    const btn = $('retagApply');
    btn.disabled = true;
    /* Ang SOURCE ay ang kasalukuyang klase. Ang school_year/semester/subject
       nito ay isinasama ng classParams() ng apiPost — pero HINDI ang `section`,
       kaya kailangang tahasang ipadala. Kung wala ito, "No section." ang laging
       isinasagot ng server at hindi kailanman natutuloy ang pag-tag. */
    const d = await apiPost({
        api: 'retag_class',
        section: SHEET.section,
        to_school_year: toSy, to_semester: toSem, to_subject: toSubj,
    });
    btn.disabled = false;
    if (!d.success) return show(d.message || 'Could not tag.');
    closeRetag();
    /* switch the view to the newly-tagged class */
    CLASS.school_year = toSy; CLASS.semester = toSem; CLASS.subject = toSubj;
    saveClassPref();
    if ($('selSchoolYear')) $('selSchoolYear').value = toSy;
    if ($('selSemester')) $('selSemester').value = toSem;
    if ($('selSubject')) $('selSubject').value = toSubj;
    showToastSafe(`Tagged as ${[toSy, toSem, toSubj].filter(Boolean).join(' · ')} — ${d.moved} activit${d.moved === 1 ? 'y' : 'ies'} moved.`, 'success');
    /* Muling buuin ang Class dropdown bago mag-load: bago pa lang ang klaseng ito,
       kaya wala pa ito sa listahan. Kung hindi, mananatiling "Existing (untagged)
       sheet" ang nakasulat gayong ang tinitingnan mo na ay ang bagong klase. */
    await loadClasses(SHEET.section);
    loadSheet(SHEET.section);
}

/* ── Manage access (superadmin only) ─────────────────────────
   Sinong FormFlow account ang makakagamit ng eGradeBook. Nasa server ang
   tunay na gate (Auth::requireAccess) at ang superadmin check (
   AccessController) — pampadali lang ito, hindi seguridad: wala rito ang
   modal kung hindi ka superadmin, pero ang server pa rin ang nagpapasya. */
async function openAccessModal() {
    const box = $('accessModal');
    if (!box) return;
    $('accessList').innerHTML = '<div class="bulk-note">Loading…</div>';
    box.classList.add('show');
    const d = await apiGet({ api: 'access_list' });
    if (!d.success) {
        $('accessList').innerHTML = `<div class="bulk-err">${escHtml(d.message || 'Could not load the accounts.')}</div>`;
        return;
    }
    renderAccessList(d.accounts || [], d.me);
}
function closeAccessModal() { $('accessModal').classList.remove('show'); }

function renderAccessList(accounts, meId) {
    if (!accounts.length) { $('accessList').innerHTML = '<div class="bulk-note">No accounts found.</div>'; return; }
    $('accessList').innerHTML = accounts.map(a => {
        const isSuper = a.role === 'superadmin';
        /* Laging naka-on at hindi mapapatay ang superadmin — kapareho ng
           panuntunan sa server, kaya walang switch na mukhang gumagana pero
           tatanggihan naman. Ikaw mismo ay may dagdag na "(you)". */
        const on = isSuper || a.granted;
        const tag = isSuper ? '<span class="acc-tag">superadmin</span>' : '';
        const me  = a.id === meId ? '<span class="acc-tag">you</span>' : '';
        return `<label class="acc-row${isSuper ? ' is-locked' : ''}">
            <input type="checkbox" data-id="${a.id}" ${on ? 'checked' : ''} ${isSuper ? 'disabled' : ''}>
            <span class="acc-meta">
                <span class="acc-name">${escHtml(a.full_name)} ${tag}${me}</span>
                <span class="acc-user">@${escHtml(a.username)}</span>
            </span>
        </label>`;
    }).join('');
    $('accessList').querySelectorAll('input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', () => setAccess(cb, parseInt(cb.dataset.id), cb.checked));
    });
}

async function setAccess(cb, adminId, grant) {
    cb.disabled = true;
    const d = await apiPost({ api: 'set_access', admin_id: adminId, grant: grant ? '1' : '0' });
    cb.disabled = false;
    if (!d.success) {
        cb.checked = !grant;   // ibalik ang switch; hindi natuloy sa server
        showToastSafe(d.message || 'Could not change access.', 'error');
        return;
    }
    showToastSafe(grant ? 'Access granted.' : 'Access removed. Their gradebook is untouched.', 'success');
}

/* ── Clear all (danger zone) ─────────────────────────────────
   Ibinabalik sa walang laman ang isang gradebook — lahat ng section, lahat ng
   klase. Tatlong target: sarili, isang guro, o lahat (superadmin ang huling
   dalawa). Hindi ito mababawi, kaya:

   - Type-to-confirm, hindi confirm(): kailangang i-type nang eksakto ang
     parirala bago mag-enable ang button. Ang isang OK/Cancel ay masyadong
     malapit sa mga OK/Cancel na pinipindot mo maghapon.
   - IBA ang parirala kada target. Ang "CLEAR ALL" ay nakasanayan na at madaling
     maulit nang hindi iniisip — hindi dapat kasingdali niyon ang pagbura ng
     datos ng ibang tao, kaya ang username nila mismo ang tinitipa.
   - Sinusuri rin ng SERVER ang parehong parirala AT ang superadmin — hindi
     sapat na hadlang ang naka-disable na button o nakatagong dropdown
     (tingnan ang ResetController).
   - Buo ang FormFlow forms/sagot at ang attendance roster/scans: binabasa lang
     ang mga iyon ng eGradeBook, hindi kanya. */
const CLEAR_PHRASE = 'CLEAR ALL';
let clearOwners = [];   // [{id, username, full_name, activities, orphan}]
let CLEAR_ME = 0;       // sariling owner_id, galing sa reset_targets

function clearScopeValue() { return $('clearScope') ? $('clearScope').value : 'me'; }

/* Ang pariralang kailangan para sa kasalukuyang pinili. Kailangang tumugma ito
   sa kinakalkula ng ResetController — kung magkaiba, isang malinaw na "Type X
   to confirm" ang isasagot ng server sa halip na tumakbo. */
function clearPhraseFor() {
    const scope = clearScopeValue();
    if (scope === 'all') return 'CLEAR EVERYTHING';
    if (scope === 'owner') {
        const id = parseInt($('clearOwner').value);
        const o = clearOwners.find(x => x.id === id);
        /* Ang sarili mong id na pinili bilang "isang guro" ay itinuturing pa
           ring sarili — ganoon din ang server. */
        if (o && o.id !== CLEAR_ME) return 'CLEAR ' + o.username;
    }
    return CLEAR_PHRASE;
}

function refreshClearScope() {
    const scope = clearScopeValue();
    const ownerWrap = $('clearOwnerWrap');
    if (ownerWrap) ownerWrap.style.display = scope === 'owner' ? 'flex' : 'none';

    const phrase = clearPhraseFor();
    $('clearPhraseLbl').textContent = phrase;
    $('clearAllPhrase').placeholder = phrase;
    $('clearAllPhrase').value = '';
    $('clearAllApply').disabled = true;
    $('clearAllErr').style.display = 'none';

    /* Malinaw na babala kapag hindi ikaw ang tinatamaan. */
    const warn = $('clearScopeWarn');
    if (!warn) return;
    if (scope === 'all') {
        warn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> This wipes the gradebook of <b>every teacher</b>, including yours. They are not warned and cannot undo it.';
        warn.style.display = 'block';
    } else if (scope === 'owner' && parseInt($('clearOwner').value) !== CLEAR_ME) {
        const o = clearOwners.find(x => x.id === parseInt($('clearOwner').value));
        warn.innerHTML = `<i class="bi bi-exclamation-triangle"></i> This wipes <b>${escHtml(o ? o.full_name : 'that teacher')}</b>'s gradebook. They are not warned and cannot undo it.`;
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
}

async function openClearAll() {
    $('clearAllPhrase').value = '';
    $('clearAllErr').style.display = 'none';
    $('clearAllApply').disabled = true;
    if ($('clearScope')) $('clearScope').value = 'me';
    refreshClearScope();
    $('clearAllModal').classList.add('show');
    setTimeout(() => $('clearAllPhrase').focus(), 60);

    /* Ang listahan ng guro ay superadmin lang — walang #clearScope kung hindi
       ikaw iyon, kaya hindi na ito hinihingi. */
    if (!$('clearScope')) return;
    const d = await apiGet({ api: 'reset_targets' });
    if (!d.success) return;
    CLEAR_ME = d.me;
    clearOwners = d.owners || [];
    $('clearOwner').innerHTML = clearOwners.map(o => {
        const mine = o.id === CLEAR_ME ? ' (you)' : '';
        const n = `${o.activities} activit${o.activities === 1 ? 'y' : 'ies'}`;
        return `<option value="${o.id}">${escHtml(o.full_name)}${mine} — ${n}</option>`;
    }).join('') || '<option value="0">No other gradebooks yet</option>';
    refreshClearScope();
}
function closeClearAll() { $('clearAllModal').classList.remove('show'); }

async function applyClearAll() {
    const phrase = $('clearAllPhrase').value.trim();
    const err = $('clearAllErr');
    const want = clearPhraseFor();
    if (phrase !== want) {
        err.textContent = `Type ${want} exactly to confirm.`;
        err.style.display = 'block';
        return;
    }
    const scope = clearScopeValue();
    const btn = $('clearAllApply');
    btn.disabled = true;
    const d = await apiPost({
        api: 'reset_all',
        target: scope,
        owner_id: scope === 'owner' ? ($('clearOwner').value || 0) : 0,
        confirm: phrase,
    });
    if (!d.success) {
        btn.disabled = false;
        err.textContent = d.message || 'Could not clear the gradebook.';
        err.style.display = 'block';
        return;
    }
    closeClearAll();

    const n = Object.values(d.deleted || {}).reduce((a, b) => a + b, 0);
    const msg = `Cleared ${d.label || 'the gradebook'} — ${n} row${n === 1 ? '' : 's'} deleted.`;

    /* Kung hindi ang SARILI mong datos ang nabura, walang dahilan para i-reload
       — buo pa rin ang sheet na nakabukas sa iyo. Ang pag-reload ay para lang
       sa nabura mo mismo: kalimutan muna ang naka-save na klase, dahil wala na
       iyon at bubuksan itong muli ng app (at maire-rehistro ng SheetController). */
    const hitMe = scope === 'all' || (scope === 'me')
        || (scope === 'owner' && parseInt($('clearOwner').value) === CLEAR_ME);
    if (!hitMe) { showToastSafe(msg, 'success'); return; }

    try { localStorage.removeItem(CLASS_KEY); } catch (e) {}
    showToastSafe(msg + ' Reloading…', 'success');
    setTimeout(() => location.reload(), 900);
}

/* In-app confirm: bawat labasan ay dapat SUMAGOT, kung hindi ay mananatiling
   nakabitin ang await ng tumawag at parang na-freeze ang app. Apat lang ang
   labasan — OK, Cancel, backdrop, Escape. */
if ($('uiConfirmModal')) {
    $('uiConfirmYes').addEventListener('click', () => uiConfirmClose(true));
    $('uiConfirmNo').addEventListener('click', () => uiConfirmClose(false));
    $('uiConfirmModal').addEventListener('click', e => {
        if (e.target === $('uiConfirmModal')) uiConfirmClose(false);
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && uiConfirmResolve) uiConfirmClose(false);
    });
}

/* ── wire up ────────────────────────────────────────────── */
$('selSection').addEventListener('change', async e => {
    await loadClasses(e.target.value);   // refresh the class dropdown for this section
    loadSheet(e.target.value);
});
/* modern section dropdown: open/close, live search, outside-click + Esc */
(function () {
    const btn = $('mselBtn'), search = $('mselSearch'), wrap = $('mselSection');
    if (!btn || !wrap) return;
    btn.addEventListener('click', e => { e.stopPropagation(); toggleMsel(); });
    if (search) {
        search.addEventListener('input', renderMselList);
        search.addEventListener('click', e => e.stopPropagation());
        search.addEventListener('keydown', e => { if (e.key === 'Escape') { closeMsel(); btn.focus(); } });
    }
    document.addEventListener('click', e => { if (!wrap.contains(e.target)) closeMsel(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMsel(); });
})();
$('txtSearch').addEventListener('input', render);
$('numPass').addEventListener('input', render);
$('chkMissingZero').addEventListener('change', render);
$('chkTermMode').addEventListener('change', async () => {
    if (!SHEET) return;
    const on = $('chkTermMode').checked;
    const section = SHEET.section;
    await apiPost({ api: 'set_term_mode', section, value: on ? '1' : '0' });
    await loadSheet(section);   // reload to fetch the seeded categories
    if (on) showToastSafe('Term grading on. Open "Grade setup" to review Midterm/Final categories & weights.', 'success');
});
if ($('chkAttendance')) $('chkAttendance').addEventListener('change', async () => {
    if (!SHEET) { $('chkAttendance').checked = false; showToastSafe('Select a section first.', 'error'); return; }
    const on = $('chkAttendance').checked;
    const section = SHEET.section;
    const d = await apiPost({ api: 'set_attendance_enabled', section, value: on ? '1' : '0' });
    if (!d.success) {
        $('chkAttendance').checked = !on;   // revert on failure
        showToastSafe(d.message || 'Could not update.', 'error');
        return;
    }
    await loadSheet(section);   // reload to build/remove the attendance column
    showToastSafe(
        on ? 'Attendance column added (auto from QR scans). Set its weight or category in the header to include it in the grade.'
           : 'Attendance column removed.',
        on ? 'success' : 'info'
    );
});
$('btnGradeSetup').addEventListener('click', openSetupModal);
$('setupClose').addEventListener('click', closeSetupModal);
$('btnTransmute').addEventListener('click', openTmModal);
$('tmCancel').addEventListener('click', closeTmModal);
$('tmAddBand').addEventListener('click', tmAddBand);
$('tmReset').addEventListener('click', tmResetDefaults);
$('tmSave').addEventListener('click', saveTm);
$('bdClose').addEventListener('click', closeBreakdown);
$('bdPdf').addEventListener('click', exportStudentPDF);
/* View-only breakdown: also dismiss on backdrop click and Escape, so browsing
   student-to-student doesn't require aiming for the Close button. */
$('breakdownModal').addEventListener('click', e => {
    if (e.target === $('breakdownModal')) closeBreakdown();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape' && $('breakdownModal').classList.contains('show')) closeBreakdown();
});
/* bulk selection bar */
document.querySelectorAll('.sel-st-btn').forEach(btn => {
    btn.addEventListener('click', () => bulkSetStatus(btn.dataset.status));
});
$('selBarClear').addEventListener('click', () => {
    selectedStudents.clear();
    render();
});

/* ── Toolbar "More" overflow menu (open / outside-click / Escape) ── */
(function () {
    const more = $('gsMore');
    if (!more) return;
    const trigger = $('btnMore');
    const closeMore = () => { more.classList.remove('open'); if (trigger) trigger.setAttribute('aria-expanded', 'false'); };
    if (trigger) trigger.addEventListener('click', e => {
        e.stopPropagation();
        const open = more.classList.toggle('open');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    $('moreMenu').addEventListener('click', closeMore);                              // close after picking an item
    document.addEventListener('click', e => { if (!more.contains(e.target)) closeMore(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMore(); });
})();

$('btnExport').addEventListener('click', exportCSV);
$('btnBackup').addEventListener('click', exportBackup);
$('btnPdfSection').addEventListener('click', exportSectionPDF);
$('btnPdfAll').addEventListener('click', exportAllPDF);
$('btnPrint').addEventListener('click', () => {
    if (!SHEET) {
        showToastSafe('Select a section first before printing.', 'error');
        return;
    }
    window.print();
});
$('btnAddActivity').addEventListener('click', openActModal);
$('actCancel').addEventListener('click', closeActModal);
$('actSave').addEventListener('click', saveActivity);
/* Palit ng term → ibang hanay ng category. Dala ang PANGALAN ng kasalukuyang
   pili para manatili ang "Quiz" kapag Quiz din ang meron sa kabilang term. */
if ($('actTerm')) {
    $('actTerm').addEventListener('change', () => {
        const cur = $('actCat').selectedOptions[0];
        fillActCatOptions('', cur ? cur.textContent.trim() : '');
    });
}
/* Enter sa pangalan/max = Add column. Hindi <form> ang modal kaya walang
   implicit submit — sunod-sunod ang paggawa ng activity, sayang ang mouse. */
['actTitle', 'actMax'].forEach(id => {
    const el = $(id);
    if (el) el.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); saveActivity(); }
    });
});
$('delActCancel').addEventListener('click', closeDelModal);
$('delActConfirm').addEventListener('click', confirmDeleteActivity);
$('bulkFillCancel').addEventListener('click', closeBulkFillModal);
$('bulkFillApply').addEventListener('click', applyBulkFill);
$('bulkFillScore').addEventListener('keydown', e => {
    if (e.key === 'Enter') applyBulkFill();
});
$('btnImport').addEventListener('click', openImportModal);
$('importCancel').addEventListener('click', closeImportModal);
$('importApply').addEventListener('click', applyImport);
$('importActivity').addEventListener('change', () => { if (importRaw) validateImport(); });
$('importCapMax').addEventListener('change', () => { if (importRaw) validateImport(); });
$('importFile').addEventListener('change', e => handleImportFile(e.target.files[0]));
/* drag-and-drop onto the drop zone */
(function () {
    const drop = $('importDrop');
    if (!drop) return;
    ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => {
        e.preventDefault(); e.stopPropagation(); drop.classList.add('dragging');
    }));
    ['dragleave', 'dragend'].forEach(ev => drop.addEventListener(ev, e => {
        e.preventDefault(); e.stopPropagation(); drop.classList.remove('dragging');
    }));
    drop.addEventListener('drop', e => {
        e.preventDefault(); e.stopPropagation(); drop.classList.remove('dragging');
        const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
        if (f) handleImportFile(f);
    });
})();
$('importFileRemove').addEventListener('click', clearImportFileUI);
$('importSample').addEventListener('click', e => {
    e.preventDefault();
    downloadSampleCsv();
});
$('btnCopyFrom').addEventListener('click', openCopyModal);
$('btnRetag').addEventListener('click', openRetag);
$('retagCancel').addEventListener('click', closeRetag);
$('retagApply').addEventListener('click', applyRetag);
$('btnReportHdr').addEventListener('click', openReportHdr);
$('rhCancel').addEventListener('click', closeReportHdr);
$('rhSave').addEventListener('click', saveReportHdr);
/* Mungkahi lang ang pangalan sa account — madalas may titulo pa ang gustong
   lumabas sa report, kaya hindi ito basta ipinipilit. */
$('rhUseMyName').addEventListener('click', () => {
    const el = document.querySelector('.profile-name');
    $('rhFaculty').value = rhSuggestName || (el ? el.textContent.trim() : '');
    $('rhFaculty').focus();
});
['rhSchool', 'rhDept', 'rhTitle', 'rhFaculty', 'rhNote'].forEach(id => {
    $(id).addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); saveReportHdr(); }
    });
});

/* Superadmin lang ang may ganitong menu item at modal — kaya naka-guard. */
if ($('btnAccess')) $('btnAccess').addEventListener('click', openAccessModal);
if ($('accessClose')) $('accessClose').addEventListener('click', closeAccessModal);
$('btnClearAll').addEventListener('click', openClearAll);
$('clearAllCancel').addEventListener('click', closeClearAll);
$('clearAllApply').addEventListener('click', applyClearAll);
/* Ang button ay nabubuhay lang sa eksaktong parirala — ito ang buong bigat ng
   type-to-confirm, kaya walang trim-tolerance sa case o sa laman. Nagbabago ang
   parirala ayon sa target, kaya clearPhraseFor() ang tinatanong, hindi konstante. */
$('clearAllPhrase').addEventListener('input', e => {
    $('clearAllApply').disabled = e.target.value.trim() !== clearPhraseFor();
    $('clearAllErr').style.display = 'none';
});
/* Palit ng target → ibang parirala, ibang babala, at blangko ulit ang kahon
   (hindi dapat madala ang natipa na para sa ibang target). */
if ($('clearScope')) $('clearScope').addEventListener('change', refreshClearScope);
if ($('clearOwner')) $('clearOwner').addEventListener('change', refreshClearScope);
$('clearAllPhrase').addEventListener('keydown', e => {
    if (e.key === 'Enter' && !$('clearAllApply').disabled) applyClearAll();
});
$('copyCancel').addEventListener('click', closeCopyModal);
$('copyApply').addEventListener('click', applyCopy);
$('copyFromSection').addEventListener('change', () => {
    $('copyApply').disabled = !$('copyFromSection').value;
});
/* ── Form columns modal ─────────────────────────────────── */
$('btnFormCols').addEventListener('click', openFormColModal);
$('fcClose').addEventListener('click', closeFormColModal);
$('fcCopyFrom').addEventListener('change', applyCopyFormVisibility);
/* ── Pinned sections wiring ─────────────────────────────── */
$('pinViewToggle').addEventListener('click', toggleSectionView);
$('btnManageSections').addEventListener('click', openPinModal);
$('pinCancel').addEventListener('click', closePinModal);
$('pinSave').addEventListener('click', savePinnedSections);
$('pinSearch').addEventListener('input', e => buildPinList(e.target.value));
$('pinSelectAll').addEventListener('click', () => {
    /* select all currently-visible (filtered) rows */
    $('pinList').querySelectorAll('input[data-section]').forEach(cb => {
        cb.checked = true;
        pinDraft.add(cb.dataset.section);
    });
    updatePinCount();
});
$('pinClearAll').addEventListener('click', () => {
    $('pinList').querySelectorAll('input[data-section]').forEach(cb => {
        cb.checked = false;
        pinDraft.delete(cb.dataset.section);
    });
    updatePinCount();
});
$('pinList').addEventListener('change', e => {
    const cb = e.target.closest('input[data-section]');
    if (!cb) return;
    if (cb.checked) pinDraft.add(cb.dataset.section);
    else pinDraft.delete(cb.dataset.section);
    updatePinCount();
});
/* ── Modern tooltips (data-tip) ─────────────────────────────
   A single floating bubble positioned with fixed coordinates, so it
   never gets clipped by the scrolling grade sheet. Works for elements
   rendered later (event delegation on document). */
(function initTooltips() {
    let tip = null;
    let current = null;

    function ensure() {
        if (!tip) {
            tip = document.createElement('div');
            tip.className = 'tip-bubble';
            tip.setAttribute('role', 'tooltip');
            document.body.appendChild(tip);
        }
        return tip;
    }

    function show(target) {
        const text = target.getAttribute('data-tip');
        if (!text) return;
        current = target;
        const el = ensure();
        el.textContent = text;
        /* measure while visible-but-transparent for correct size */
        el.style.top = '-9999px';
        el.style.left = '0px';
        el.classList.add('show');
        const b = target.getBoundingClientRect();
        const t = el.getBoundingClientRect();
        let below = false;
        let top = b.top - t.height - 10;
        if (top < 6) { top = b.bottom + 10; below = true; }
        let left = b.left + b.width / 2 - t.width / 2;
        left = Math.max(6, Math.min(left, window.innerWidth - t.width - 6));
        el.style.top = Math.round(top) + 'px';
        el.style.left = Math.round(left) + 'px';
        el.classList.toggle('below', below);
        el.style.setProperty('--arrow-x', Math.round((b.left + b.width / 2) - left) + 'px');
    }

    function hide() {
        current = null;
        if (tip) tip.classList.remove('show');
    }

    document.addEventListener('mouseover', e => {
        const t = e.target.closest && e.target.closest('[data-tip]');
        if (t && t !== current) show(t);
    });
    document.addEventListener('mouseout', e => {
        const t = e.target.closest && e.target.closest('[data-tip]');
        if (t) hide();
    });
    document.addEventListener('focusin', e => {
        const t = e.target.closest && e.target.closest('[data-tip]');
        if (t) show(t);
    });
    document.addEventListener('focusout', hide);
    /* hide on scroll/resize so the bubble never floats away from its anchor */
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
})();

/* ── Class picker: restore saved selection + wire the dropdown/create form ── */
loadClassPref();
if ($('selClass')) $('selClass').addEventListener('change', onSelClassChange);
if ($('btnDeleteClass')) $('btnDeleteClass').addEventListener('click', deleteCurrentClass);
if ($('btnCreateClass')) $('btnCreateClass').addEventListener('click', onCreateClass);
if ($('btnCancelClass')) $('btnCancelClass').addEventListener('click', revertClassSelect);
/* Enter = Create, Esc = Cancel sa loob ng New-class form. Maliit lang ang
   form at hindi ito <form>, kaya walang implicit submit — walang mangyayari
   dati sa Enter, na parang sira ang pakiramdam habang nagta-type. */
['selSchoolYear', 'selSemester', 'selSubject'].forEach(id => {
    const el = $(id);
    if (!el) return;
    el.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); onCreateClass(); }
        else if (e.key === 'Escape') { e.preventDefault(); revertClassSelect(); }
    });
});
/* Kunin agad ang ulo ng report para tama na ang print header nang hindi
   kailangang magbukas muna ng PDF. Hindi ito hinihintay ng unang render —
   kapag may laman nga, isang re-render lang ang idinadagdag nito. */
loadReportHeader().then(h => {
    if (SHEET && (h.school || h.department || h.title || h.faculty)) render();
});
loadSections();