<!-- ===== GUIDED TOUR (para sa mga bagong guro) =====
     Isang spotlight tour sa mga TUNAY na kontrol ng grading sheet: dinidilim ang
     pahina, iniilawan ang isang elemento, at may maikling card ng paliwanag.
     Walang library — vanilla JS, gaya ng natitirang app.

     Kusang tumatakbo MINSAN para sa bagong user (walang `eg_tour_done` AT
     walang `eg_whatsnew_seen` sa localStorage — ang huli ay tanda na dati nang
     gumagamit ang guro, kaya hindi inaabala). Muling buksan anytime sa footer:
     Support ▸ Take the tour.

     Ang step na nakatago ang target (hal. walang napiling section) ay ipinapakita
     sa gitna ng screen sa halip na nilalampasan, para hindi nawawala ang paliwanag. -->
<div class="tour-block" id="tourBlock" hidden></div>
<div class="tour-spot" id="tourSpot" hidden></div>
<div class="tour-card" id="tourCard" role="dialog" aria-modal="true" aria-labelledby="tourTitle" hidden>
  <div class="tour-top">
    <span class="tour-step" id="tourStep"></span>
    <button type="button" class="tour-skip" id="tourSkip">Skip tour</button>
  </div>
  <h4 class="tour-title" id="tourTitle"></h4>
  <div class="tour-body" id="tourBody"></div>
  <div class="tour-foot">
    <div class="tour-dots" id="tourDots"></div>
    <div class="tour-btns">
      <button type="button" class="btn btn-ghost btn-sm" id="tourBack"><i class="bi bi-arrow-left"></i> Back</button>
      <button type="button" class="btn btn-primary btn-sm" id="tourNext">Next <i class="bi bi-arrow-right"></i></button>
    </div>
  </div>
</div>

<style>
.tour-block {
  position: fixed;
  inset: 0;
  z-index: 10000;
  background: transparent;           /* nahaharangan ang click; ang dilim ay nasa .tour-spot */
}
.tour-block.tour-dim { background: rgba(0, 0, 0, .6); }   /* step na walang target */
.tour-spot {
  position: fixed;
  z-index: 10001;
  border-radius: 12px;
  box-shadow: 0 0 0 9999px rgba(0, 0, 0, .6), 0 0 0 2px var(--accent);
  pointer-events: none;
  transition: top .25s ease, left .25s ease, width .25s ease, height .25s ease;
}
.tour-card {
  position: fixed;
  z-index: 10002;
  width: min(360px, calc(100vw - 32px));
  background: var(--surface2);
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: var(--shadow);
  padding: 1rem 1.1rem .9rem;
  animation: slideUp .2s ease;
}
.tour-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  margin-bottom: .35rem;
}
.tour-step {
  font-size: .72rem;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: var(--accent);
}
.tour-skip {
  background: none;
  border: none;
  color: var(--muted);
  font-size: .78rem;
  cursor: pointer;
  padding: 2px 4px;
  border-radius: 6px;
}
.tour-skip:hover { color: var(--text); }
.tour-title {
  margin: 0 0 .4rem;
  font-size: 1rem;
  display: flex;
  align-items: center;
  gap: 8px;
}
.tour-title i { color: var(--accent); }
.tour-body {
  font-size: .86rem;
  line-height: 1.55;
  color: var(--muted);
}
.tour-body p { margin: 0 0 .45rem; }
.tour-body ul { margin: 0 0 .45rem; padding-left: 1.05rem; }
.tour-body li { margin-bottom: .25rem; }
.tour-body strong { color: var(--text); }
.tour-body i.bi { color: var(--accent2); }
.tour-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: .5rem;
  flex-wrap: wrap;
  margin-top: .75rem;
}
.tour-dots { display: flex; gap: 5px; flex-wrap: wrap; }
.tour-dots span {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--border);
  outline: 1px solid var(--border);
}
.tour-dots span.on { background: var(--accent); outline-color: var(--accent); }
.tour-btns { display: flex; gap: .4rem; margin-left: auto; }

/* Telepono: ang card ay nakadaong sa ibaba, hindi lumulutang sa tabi ng target */
@media (max-width: 640px) {
  .tour-card {
    left: 16px !important;
    right: 16px;
    top: auto !important;
    bottom: 16px;
    width: auto;
  }
}
</style>

<script>
(function () {
  if (window.egTour) return;

  var DONE_KEY = 'eg_tour_done';

  /* Mga step. `target` = CSS selector; null = card sa gitna ng screen. */
  var STEPS = [
    {
      target: null,
      icon: 'bi-stars',
      title: 'Welcome to eGradeBook!',
      html:
        '<p>eGradeBook builds your grading sheet for you. <strong>Students</strong> come from the QR attendance roster, <strong>form and quiz scores</strong> come from FormFlow, and <strong>grades are computed automatically</strong>.</p>' +
        '<p>This quick tour shows what you can do. It takes about a minute. You can leave anytime with <strong>Skip tour</strong> or the Esc key.</p>'
    },
    {
      target: '#mselSection',
      icon: 'bi-people',
      title: 'Pick a section',
      html:
        '<p>Choose a section and its students load automatically, no typing names.</p>' +
        '<p>Use the <i class="bi bi-gear"></i> gear to pin only the sections you teach, and <strong>My sections</strong> to switch between those and all sections.</p>'
    },
    {
      target: '.gs-class-field',
      icon: 'bi-journal-bookmark',
      title: 'Choose or create a class',
      html:
        '<p>A class is a <strong>school year, semester and subject</strong> within the section. Pick <strong>➕ New class…</strong> to start one.</p>' +
        '<p>This keeps each year separate, so reusing a section name next year gives you a clean sheet. A named class also keeps its students even if the roster changes later.</p>'
    },
    {
      target: '.gs-opts',
      icon: 'bi-sliders',
      title: 'Grading options',
      html:
        '<ul>' +
          '<li><strong>Count missing as 0</strong>: unanswered items count as zero.</li>' +
          '<li><strong>Term grading</strong>: Midterm and Final, each with weighted categories such as Quiz, Activity and Exam. Adjust them in <strong>Grade setup</strong>.</li>' +
          '<li><strong>Attendance</strong>: adds a column from the QR scans automatically. With term grading, set <strong>Midterm ends</strong> to split it into Midterm and Final.</li>' +
        '</ul>'
    },
    {
      target: '.gs-czone-right .gs-field',
      icon: 'bi-search',
      title: 'Search and passing mark',
      html:
        '<p>Search by name, student number, status (INC, DRP, W) or <em>passed</em> or <em>failed</em>.</p>' +
        '<p>Set the <strong>Passing %</strong> next to it (default 75). Passing and failing grades are highlighted.</p>'
    },
    {
      target: '#btnAddActivity',
      icon: 'bi-plus-circle',
      title: 'Add your own activities',
      html:
        '<p>Add a column for anything you score by hand, such as recitation, seatwork or a project. Set its max points and, in term grading, its term and category.</p>' +
        '<p>Forms your students answered in FormFlow appear as columns <strong>on their own</strong>, already scored.</p>'
    },
    {
      target: '#gsArea',
      icon: 'bi-table',
      title: 'The grading sheet',
      html:
        '<ul>' +
          '<li><strong>Type a score</strong> in a cell. <strong>Enter</strong> moves to the next student, like a spreadsheet.</li>' +
          '<li>In an activity header: <i class="bi bi-grip-vertical"></i> drag to reorder, click the name to rename, <i class="bi bi-arrow-bar-down"></i> fill a score for everyone, <i class="bi bi-link-45deg"></i> sync same scores.</li>' +
          '<li><strong>Click a student\'s name</strong> to see how their grade is computed, save a grade slip PDF, or mark INC, DRP or W.</li>' +
          '<li>Tick several students to set a status for all of them at once.</li>' +
        '</ul>'
    },
    {
      target: '#btnMore',
      icon: 'bi-three-dots',
      title: 'More tools',
      html:
        '<ul>' +
          '<li><strong>Setup</strong>: copy another section\'s columns, import scores from CSV, tag a sheet as a class, choose which forms belong to this class.</li>' +
          '<li><strong>Grading</strong>: edit the transmutation table (percent to 1.00–5.00).</li>' +
          '<li><strong>Output</strong>: Excel backup, section or all-sections PDF, your report header and letterhead, and print.</li>' +
        '</ul>'
    },
    {
      target: '#btnExport',
      icon: 'bi-filetype-csv',
      title: 'Export CSV',
      html: '<p>Download the current sheet as a CSV file you can open in Excel or Google Sheets.</p>'
    },
    {
      target: '.site-footer .footer-links:last-child',
      icon: 'bi-life-preserver',
      title: 'Help is always here',
      html:
        '<p><strong>Help Center</strong> answers common questions, and <strong>What\'s New</strong> lists recent updates.</p>' +
        '<p>Want to see this tour again? Click <strong>Take the tour</strong> here anytime. Happy grading! 🎉</p>'
    }
  ];

  var idx = 0, active = false, curEl = null, rafId = 0;
  var $ = function (id) { return document.getElementById(id); };

  function visible(el) {
    if (!el) return false;
    var r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 && getComputedStyle(el).visibility !== 'hidden';
  }

  function place() {
    rafId = 0;
    if (!active) return;
    var card = $('tourCard'), spot = $('tourSpot'), block = $('tourBlock');
    var vw = window.innerWidth, vh = window.innerHeight, M = 16, PAD = 6;

    if (!curEl) {
      spot.hidden = true;
      block.classList.add('tour-dim');
      card.style.left = Math.max(M, (vw - card.offsetWidth) / 2) + 'px';
      card.style.top = Math.max(M, (vh - card.offsetHeight) / 2) + 'px';
      return;
    }

    block.classList.remove('tour-dim');
    spot.hidden = false;
    var r = curEl.getBoundingClientRect();
    // Ang #gsArea ay maaaring mas mataas kaysa screen — putulin ang spotlight sa viewport.
    var top = Math.max(r.top - PAD, 4), bottom = Math.min(r.bottom + PAD, vh - 4);
    spot.style.left = (r.left - PAD) + 'px';
    spot.style.top = top + 'px';
    spot.style.width = (r.width + PAD * 2) + 'px';
    spot.style.height = Math.max(bottom - top, 0) + 'px';

    if (vw <= 640) return;   // naka-dock sa ibaba sa CSS

    var cw = card.offsetWidth, ch = card.offsetHeight;
    var cTop = bottom + 12;                                   // sa ilalim ng target
    var cLeft = Math.min(Math.max(M, r.left), vw - cw - M);
    if (cTop + ch > vh - M) cTop = top - ch - 12;             // o sa itaas
    if (cTop < M) {
      // Mataas na target (hal. ang talahanayan): walang puwang sa itaas o ibaba.
      // Sa kanang-ibabang sulok, para nakikita ang mga pangalan sa kaliwa.
      cTop = Math.max(M, vh - ch - M);
      cLeft = vw - cw - M;
    }
    card.style.left = cLeft + 'px';
    card.style.top = cTop + 'px';
  }

  function schedule() { if (active && !rafId) rafId = requestAnimationFrame(place); }

  function render() {
    var s = STEPS[idx];
    var el = s.target ? document.querySelector(s.target) : null;
    curEl = visible(el) ? el : null;

    $('tourStep').textContent = 'Step ' + (idx + 1) + ' of ' + STEPS.length;
    $('tourTitle').innerHTML = '<i class="bi ' + s.icon + '"></i> ' + s.title;
    $('tourBody').innerHTML = s.html;
    $('tourBack').style.visibility = idx === 0 ? 'hidden' : 'visible';
    var last = idx === STEPS.length - 1;
    $('tourNext').innerHTML = last ? 'Finish <i class="bi bi-check-lg"></i>'
                                   : (idx === 0 ? 'Start tour' : 'Next') + ' <i class="bi bi-arrow-right"></i>';
    var dots = '';
    for (var i = 0; i < STEPS.length; i++) dots += '<span class="' + (i === idx ? 'on' : '') + '"></span>';
    $('tourDots').innerHTML = dots;

    if (curEl) {
      var r = curEl.getBoundingClientRect();
      var fits = r.top >= 70 && r.bottom <= window.innerHeight - 20;
      if (!fits) curEl.scrollIntoView({ behavior: 'smooth', block: r.height > window.innerHeight * .6 ? 'start' : 'center' });
    }
    place();
    try { $('tourNext').focus({ preventScroll: true }); } catch (e) {}
  }

  function go(d) {
    var n = idx + d;
    if (n < 0) return;
    if (n >= STEPS.length) { end(); return; }
    idx = n;
    render();
  }

  function start() {
    if (active) return;
    // Isara ang mga bukas na dropdown/modal na maaaring nakatakip sa target.
    if (window.closeSupportModal) window.closeSupportModal();
    document.querySelectorAll('.gs-more.open, .profile.open').forEach(function (m) { m.classList.remove('open'); });
    active = true;
    idx = 0;
    $('tourBlock').hidden = false;
    $('tourCard').hidden = false;
    document.body.style.overflow = '';
    render();
  }

  function end() {
    active = false;
    curEl = null;
    $('tourBlock').hidden = true;
    $('tourSpot').hidden = true;
    $('tourCard').hidden = true;
    $('tourBlock').classList.remove('tour-dim');
    try { localStorage.setItem(DONE_KEY, '1'); } catch (e) {}
    // Bagong user: ang listang What's New ay kasaysayan na, hindi balita — huwag nang ipakita.
    if (window.egMarkWhatsNewSeen) window.egMarkWhatsNewSeen();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  $('tourNext').addEventListener('click', function () { go(1); });
  $('tourBack').addEventListener('click', function () { go(-1); });
  $('tourSkip').addEventListener('click', end);
  window.addEventListener('resize', schedule);
  window.addEventListener('scroll', schedule, true);
  document.addEventListener('keydown', function (e) {
    if (!active) return;
    if (e.key === 'Escape') { e.preventDefault(); end(); }
    else if (e.key === 'ArrowRight') { e.preventDefault(); go(1); }
    else if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); }
  }, true);

  /* Kusang pagsisimula — itinatakda SA PAG-PARSE (hindi sa load) para mabasa
     ng auto-show ng What's New (supportModal.php) at hindi sila magsapawan. */
  var autoStart = false;
  try {
    autoStart = !localStorage.getItem(DONE_KEY) && !localStorage.getItem('eg_whatsnew_seen');
  } catch (e) { autoStart = false; }

  window.egTour = { start: start, autoStarting: autoStart };

  if (autoStart) {
    window.addEventListener('load', function () {
      setTimeout(function () {
        // Huwag sapawan ang ibang bukas na modal (hal. SweetAlert ng detection.js); susubok sa susunod na load.
        if (document.querySelector('.modal-backdrop.show, .swal2-container')) return;
        start();
      }, 800);
    });
  }
})();
</script>
