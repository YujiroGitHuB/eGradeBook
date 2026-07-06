/* ============================================================
   global.js — Shared utilities + Dynamic Island v2
   ============================================================ */
var AJAX = location.hostname === 'localhost'
  ? (location.pathname.includes('/actions/') ? '../inc/ajax.php' : 'inc/ajax.php')
  : '/forms/inc/ajax.php';

/* ── ESCAPE HTML ─────────────────────────────────────────── */
function escHtml(s) {
  return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── AJAX HELPERS ────────────────────────────────────────── */
async function ajaxPost(action, data = {}) {
  const fd = new FormData();
  fd.append('action', action);
  for (const [k, v] of Object.entries(data)) fd.append(k, v);
  const res  = await fetch(AJAX, { method: 'POST', body: fd });
  const text = await res.text();
  try {
    const json = JSON.parse(text);
    if (json.redirect) { window.location.href = json.redirect; return json; }
    return json;
  } catch(e) { console.error('ajaxPost non-JSON:', text); return { success:false, message:'Server error: '+text.substring(0,200) }; }
}

async function ajaxGet(action, params = {}) {
  const qs   = new URLSearchParams({ action, ...params });
  const res  = await fetch(`${AJAX}?${qs}`);
  const text = await res.text();
  try {
    const json = JSON.parse(text);
    if (json.redirect) { window.location.href = json.redirect; return json; }
    return json;
  } catch(e) { console.error('ajaxGet non-JSON:', text); return { success:false, message:'Server error: '+text.substring(0,200) }; }
}

/* ============================================================
   DYNAMIC ISLAND v2
   - Sub-text support
   - Tinted ambient background (no glow)
   - Icon wrap with type color
   - Right-side score display
   - Progress bar timer
   - Overrides window.alert()
   ============================================================ */
(function () {
  let _timer   = null;
  let _mounted = false;

  /* type config — no box-shadow/glow */
  const TYPE = {
    success: { bg:'rgba(16,185,129,.07)',  iw:'rgba(16,185,129,.18)',  ic:'#10b981', bar:'#10b981', icon:'bi-check-circle-fill'         },
    error:   { bg:'rgba(239,68,68,.07)',   iw:'rgba(239,68,68,.18)',   ic:'#ef4444', bar:'#ef4444', icon:'bi-x-circle-fill'              },
    warning: { bg:'rgba(245,158,11,.06)',  iw:'rgba(245,158,11,.15)',  ic:'#f59e0b', bar:'#f59e0b', icon:'bi-exclamation-triangle-fill'  },
    info:    { bg:'rgba(6,182,212,.06)',   iw:'rgba(6,182,212,.15)',   ic:'#06b6d4', bar:'#06b6d4', icon:'bi-info-circle-fill'           },
    score:   { bg:'rgba(36,112,196,.08)',  iw:'rgba(36,112,196,.2)',   ic:'#67aae8', bar:'#2470c4', icon:'bi-trophy-fill'               },
  };

  /* ── build island DOM once ── */
  function _mount() {
    if (_mounted) return;
    _mounted = true;

    const el = document.createElement('div');
    el.id    = 'dynamic-island';
    el.innerHTML = `
      <div id="di-bg"></div>
      <div id="di-dots">
        <div class="di-d"></div>
        <div class="di-d"></div>
        <div class="di-d"></div>
      </div>
      <div id="di-content">
        <div class="di-icon-wrap" id="_diIW">
          <i class="bi" id="_diIco"></i>
        </div>
        <div id="di-label">
          <div id="_diTitle"></div>
          <div id="_diSub"></div>
        </div>
        <div id="di-right">
          <div id="_diScore"></div>
          <div id="_diTs"></div>
        </div>
      </div>
      <div id="di-prog-wrap">
        <div id="di-prog"></div>
      </div>`;
    document.body.appendChild(el);
    el.addEventListener('click', dismissIsland);
  }

  /* ── main show function ── */
  window.showToast = function(msg, type = 'success', sub = '', score = '') {
    _mount();
    clearTimeout(_timer);

    const cfg   = TYPE[type] || TYPE.info;
    const el    = document.getElementById('dynamic-island');
    const bg    = document.getElementById('di-bg');
    const iw    = document.getElementById('_diIW');
    const ico   = document.getElementById('_diIco');
    const title = document.getElementById('_diTitle');
    const subEl = document.getElementById('_diSub');
    const scEl  = document.getElementById('_diScore');
    const tsEl  = document.getElementById('_diTs');
    const prog  = document.getElementById('di-prog');

    // Reset progress bar
    prog.style.transition = 'none';
    prog.style.transform  = 'scaleX(0)';
    prog.style.background = cfg.bar;

    // Apply config
    bg.style.background  = cfg.bg;
    iw.style.background  = cfg.iw;
    ico.style.color      = cfg.ic;
    ico.className        = 'bi ' + cfg.icon;
    title.textContent    = msg;
    subEl.textContent    = sub;
    scEl.textContent     = score;
    scEl.style.color     = cfg.ic;

    // Force text white — stays readable in both light and dark mode
    // Island is always dark (like iOS), text must always be white
    title.style.color = '#fff';
    subEl.style.color = 'rgba(255,255,255,.5)';
    tsEl.style.color  = 'rgba(255,255,255,.3)';

    // Force island background dark regardless of page theme
    const islandEl = document.getElementById('dynamic-island');
    islandEl.style.background   = '#0e0e16';
    islandEl.style.borderColor  = 'rgba(255,255,255,.1)';

    const now = new Date();
    tsEl.textContent = now.getHours() + ':' + String(now.getMinutes()).padStart(2, '0');

    // Expand
    el.className = 'di-show';

    // Fit height so the full long text is visible (up to 3 lines)
    requestAnimationFrame(() => {
      const titleH = Math.min(title.scrollHeight, 48); // cap ~3 lines
      const subH   = sub ? 14 : 0;
      el.style.height = Math.max(46, titleH + subH + 20) + 'px';
    });

    // Animate progress bar
    requestAnimationFrame(() => requestAnimationFrame(() => {
      prog.style.transition = 'transform 3.3s linear';
      prog.style.transform  = 'scaleX(1)';
    }));

    _timer = setTimeout(dismissIsland, 3500);
  };

  /* ── dismiss ── */
  window.dismissIsland = function() {
    clearTimeout(_timer);
    const el   = document.getElementById('dynamic-island');
    const prog = document.getElementById('di-prog');
    if (!el) return;
    prog.style.transition = 'none';
    prog.style.transform  = 'scaleX(0)';
    el.className = '';
    el.style.height = ''; // reset the dynamic height back to the pill
  };

  /* ── shorthand for score notification ── */
  window.showScoreToast = function(studentName, score, max) {
    const pct    = max > 0 ? Math.round(score / max * 100) : 0;
    const remark = pct >= 75 ? 'Passed!' : pct >= 50 ? 'Fair' : 'Needs improvement';
    showToast(`${score} / ${max} — ${remark}`, 'score', studentName, `${pct}%`);
  };

  /* ── override native alert() ── */
  window.alert = function(msg) {
    showToast(String(msg), 'warning');
  };
})();

/* ── MOBILE HAMBURGER MENU ───────────────────────────────── */
(function () {
  function initHamburger() {
    const btn      = document.getElementById('navHamburger');
    const menu     = document.getElementById('navMobileMenu');
    const backdrop = document.getElementById('navBackdrop');
    if (!btn || !menu) return;

    function openMenu() {
      btn.classList.add('open');
      menu.classList.add('open');
      if (backdrop) backdrop.classList.add('show');
    }
    function closeMenu() {
      btn.classList.remove('open');
      menu.classList.remove('open');
      if (backdrop) backdrop.classList.remove('show');
    }

    btn.addEventListener('click', e => {
      e.stopPropagation();
      menu.classList.contains('open') ? closeMenu() : openMenu();
    });

    if (backdrop) backdrop.addEventListener('click', closeMenu);
    menu.querySelectorAll('a, button').forEach(el => {
      el.addEventListener('click', closeMenu);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initHamburger);
  } else {
    initHamburger();
  }
})();

/* ============================================================
   THEME TOGGLE — dark / light (admin pages only)
   ============================================================ */
(function () {
  // Skip entirely on fill/student page
  if (window.__fillPage || document.body?.dataset?.page === 'fill') return;

  const STORAGE_KEY = 'ff_theme';

  function applyTheme(theme) {
    document.body.classList.toggle('light', theme === 'light');
    // Update all toggle buttons
    document.querySelectorAll('.theme-toggle').forEach(btn => {
      btn.innerHTML = theme === 'light'
        ? '<i class="bi bi-moon-fill"></i>'
        : '<i class="bi bi-sun-fill"></i>';
      btn.title = theme === 'light' ? 'Switch to Dark Mode' : 'Switch to Light Mode';
    });
    // Update all theme-toggle items in mobile menu
    document.querySelectorAll('.menu-theme-toggle').forEach(el => {
      el.querySelector('i').className = theme === 'light' ? 'bi bi-moon-fill' : 'bi bi-sun-fill';
      el.querySelector('span').textContent = theme === 'light' ? 'Dark Mode' : 'Light Mode';
    });
  }

  function toggleTheme() {
    const current = localStorage.getItem(STORAGE_KEY) || 'dark';
    const next    = current === 'dark' ? 'light' : 'dark';
    localStorage.setItem(STORAGE_KEY, next);
    // brief whole-page color fade, only for this deliberate switch
    const root = document.documentElement;
    root.classList.add('theme-anim');
    clearTimeout(window.__themeAnimTimer);
    window.__themeAnimTimer = setTimeout(() => root.classList.remove('theme-anim'), 450);
    applyTheme(next);
  }

  function initTheme() {
    // Double-check — guard against late evaluation
    if (window.__fillPage) return;
    const saved = localStorage.getItem(STORAGE_KEY) || 'dark';
    applyTheme(saved);

    document.documentElement.classList.remove('preload-light');

    // Attach to all .theme-toggle buttons
    document.querySelectorAll('.theme-toggle').forEach(btn => {
      btn.addEventListener('click', toggleTheme);
    });
    // Attach to mobile menu theme item
    document.querySelectorAll('.menu-theme-toggle').forEach(el => {
      el.addEventListener('click', () => { toggleTheme(); });
    });
  }

  // Expose globally
  window.toggleTheme = toggleTheme;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initTheme);
  } else {
    initTheme();
  }
})();
/* ============================================================
   PROFILE DROPDOWN — navbar (open on click, close on outside/Esc)
   ============================================================ */
(function () {
  const dd = document.getElementById('profileDropdown');
  if (!dd) return;
  const trigger = document.getElementById('profileTrigger');

  function close() {
    dd.classList.remove('open');
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }
  function toggle(e) {
    e.stopPropagation();
    const open = dd.classList.toggle('open');
    if (trigger) trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  if (trigger) trigger.addEventListener('click', toggle);
  document.addEventListener('click', e => { if (!dd.contains(e.target)) close(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
})();