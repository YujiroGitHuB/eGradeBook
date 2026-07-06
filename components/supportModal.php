<!-- ===== SUPPORT MODAL (Help Center / Privacy Policy / Terms of Use) ===== -->
<div class="modal-backdrop" id="supportModalBackdrop" onclick="if(event.target===this)closeSupportModal()">
  <div class="modal support-modal" role="dialog" aria-modal="true" aria-labelledby="supportModalTitle">
    <div class="support-modal-head">
      <h3 id="supportModalTitle"><i class="bi bi-info-circle" id="supportModalIcon"></i> <span id="supportModalTitleText">Help Center</span></h3>
      <button class="support-modal-close" type="button" onclick="closeSupportModal()" aria-label="Close">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>
    <div class="support-modal-body" id="supportModalBody"></div>
  </div>
</div>

<style>
.support-modal {
  max-width: 600px;
  max-height: 82vh;
  display: flex;
  flex-direction: column;
  padding: 0;
  overflow: hidden;
}
.support-modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1.25rem 1.5rem;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.support-modal-head h3 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 9px;
  font-size: 1.05rem;
}
.support-modal-head h3 i { color: var(--accent); }
.support-modal-close {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  font-size: 1.05rem;
  line-height: 1;
  padding: 6px;
  border-radius: 8px;
  transition: all .15s;
  flex-shrink: 0;
}
.support-modal-close:hover { color: var(--text); background: var(--surface); }

.support-modal-body {
  padding: 1.25rem 1.5rem 1.5rem;
  overflow-y: auto;
  font-size: .9rem;
  line-height: 1.6;
  color: var(--text);
}
.support-modal-body::-webkit-scrollbar { width: 6px; }
.support-modal-body::-webkit-scrollbar-track { background: transparent; }
.support-modal-body::-webkit-scrollbar-thumb { background: rgba(255,255,255,.12); border-radius: 3px; }

.support-modal-body h4 {
  font-size: .92rem;
  font-weight: 600;
  color: var(--text);
  margin: 1.15rem 0 .4rem;
}
.support-modal-body h4:first-child { margin-top: 0; }
.support-modal-body p { color: var(--muted); margin: 0 0 .6rem; }
.support-modal-body ul { margin: 0 0 .6rem; padding-left: 1.1rem; }
.support-modal-body li { color: var(--muted); margin-bottom: .35rem; }
.support-modal-body strong { color: var(--text); }
.support-modal-body .sm-meta {
  font-size: .78rem;
  color: var(--muted);
  opacity: .7;
  margin-top: 1.25rem;
  padding-top: .9rem;
  border-top: 1px solid var(--border);
}
.support-modal-body .sm-qa { margin-bottom: .9rem; }
.support-modal-body .sm-qa strong { display: block; margin-bottom: .15rem; }

/* Transmutation scale table */
.support-modal-body .sm-scale {
  width: 100%;
  border-collapse: collapse;
  margin: .4rem 0 .2rem;
  font-size: .82rem;
}
.support-modal-body .sm-scale th,
.support-modal-body .sm-scale td {
  padding: .3rem .55rem;
  text-align: left;
  border-bottom: 1px solid var(--border);
}
.support-modal-body .sm-scale th {
  color: var(--text);
  font-weight: 600;
  border-bottom: 1px solid var(--border);
}
.support-modal-body .sm-scale td { color: var(--muted); }
.support-modal-body .sm-scale td:last-child { text-align: right; font-variant-numeric: tabular-nums; }
.support-modal-body .sm-scale .sm-scale-fail td { color: var(--danger); font-weight: 600; }
</style>

<script>
(function () {
  if (window.__supportModalInit) return;   // avoid double-init if two footers were included
  window.__supportModalInit = true;

  var YEAR = new Date().getFullYear();

  var SUPPORT_CONTENT = {
    help: {
      title: 'Help Center',
      icon: 'bi-question-circle',
      html:
        '<p>Welcome to eGradeBook — a standalone grading sheet that bridges your student roster and form scores into one computed grade. Here are answers to the most common questions.</p>' +
        '<div class="sm-qa"><strong>How do I start grading?</strong>Pick a <strong>Section / Class</strong> from the selector. Students are pulled automatically from attendance, and their scores are read from your forms — you don\'t need to encode names manually.</div>' +
        '<div class="sm-qa"><strong>How do I add a graded item?</strong>Click <strong>Add Activity</strong> to insert a new column. You can rename it, set its points, and (in weighted mode) assign it to a category.</div>' +
        '<div class="sm-qa"><strong>How do I set up weighted coursework?</strong>Open <strong>Grade Setup — Categories &amp; Weights</strong> to define categories (e.g. Quizzes, Activities, Exams) and the percentage each contributes to the term.</div>' +
        '<div class="sm-qa"><strong>What is Term grading?</strong>Toggle <strong>Term grading</strong> to switch to the Excel-style computation: <strong>Midterm</strong> and <strong>Final</strong> terms, each with weighted categories, then averaged into the final grade. Leave it off for a single-term average.</div>' +
        '<div class="sm-qa"><strong>How does the defense grade get included?</strong>When defense integration is enabled for a section, the student\'s defense result is factored into the final grade automatically — no separate encoding needed.</div>' +
        '<div class="sm-qa"><strong>Can I fill or import many scores at once?</strong>Yes. Use <strong>Fill Column</strong> to bulk-set a column, or <strong>Import CSV</strong> / <strong>Import Scores from CSV</strong> to load scores in bulk. A header row is optional. Use <strong>Export CSV</strong> to download the sheet.</div>' +
        '<div class="sm-qa"><strong>How is the final grade transmuted?</strong>Computed percentages are converted to a 1.00–5.00 equivalent using the transmutation scale below (percentages are rounded to 1 decimal first). The default <strong>Passing %</strong> is 75; you can adjust the passing threshold and choose whether to <strong>count missing as 0</strong>.' +
          /* NOTE: keep this table in sync with EXCEL_BANDS in assets/js/grades.js */
          '<table class="sm-scale"><thead><tr><th>Average</th><th>Equivalent</th></tr></thead><tbody>' +
            '<tr><td>99 – 100</td><td>1.00</td></tr>' +
            '<tr><td>96 – 98.9</td><td>1.25</td></tr>' +
            '<tr><td>93 – 95.9</td><td>1.50</td></tr>' +
            '<tr><td>90 – 92.9</td><td>1.75</td></tr>' +
            '<tr><td>87 – 89.9</td><td>2.00</td></tr>' +
            '<tr><td>84 – 86.9</td><td>2.25</td></tr>' +
            '<tr><td>81 – 83.9</td><td>2.50</td></tr>' +
            '<tr><td>78 – 80.9</td><td>2.75</td></tr>' +
            '<tr><td>75 – 77.9</td><td>3.00</td></tr>' +
            '<tr class="sm-scale-fail"><td>Below 75</td><td>Failed</td></tr>' +
          '</tbody></table></div>' +
        '<div class="sm-qa"><strong>Can I reorder columns?</strong>Yes — drag a column header to reorder it, and the order is saved for that section.</div>' +
        '<p>Still need help? Reach out to your institution\'s administrator or the <a href="https://cncc.vercel.app" target="_blank" rel="noopener" style="color:var(--accent);text-decoration:none;">eGradeBook developer</a>.</p>'
    },
    privacy: {
      title: 'Privacy Policy',
      icon: 'bi-shield-check',
      html:
        '<p>eGradeBook respects your privacy. This policy explains what information it uses and how.</p>' +
        '<h4>Information it uses</h4>' +
        '<ul>' +
          '<li><strong>Sign-in details</strong> — eGradeBook uses your existing FormFlow account; it does not create separate passwords.</li>' +
          '<li><strong>Student &amp; score data</strong> — the roster is read from attendance and the scores are read from your forms, through a secure database bridge.</li>' +
          '<li><strong>Grading data you enter</strong> — the activities, weights, categories, passing threshold, and per-section settings you configure.</li>' +
        '</ul>' +
        '<h4>How it is used</h4>' +
        '<p>Your information is used only to operate the grading sheet — computing coursework, term, defense, and final grades, and displaying results back to you.</p>' +
        '<h4>Storage &amp; security</h4>' +
        '<p>Data is stored in your institution\'s database. Access is restricted to superadmin accounts, and passwords are managed by FormFlow using secure hashing. eGradeBook does not sell or share your data with third parties for advertising.</p>' +
        '<h4>Your rights</h4>' +
        '<p>You can view, edit, and export your grade sheets at any time. To change access or remove an account, contact your administrator.</p>' +
        '<p class="sm-meta">Last updated: ' + YEAR + '. eGradeBook is intended for educational use.</p>'
    },
    terms: {
      title: 'Terms of Use',
      icon: 'bi-file-text',
      html:
        '<p>By using eGradeBook, you agree to the following terms.</p>' +
        '<h4>1. Acceptance</h4>' +
        '<p>Accessing or using the platform means you accept these terms. If you do not agree, please do not use the service.</p>' +
        '<h4>2. Account responsibility</h4>' +
        '<p>Access requires a valid FormFlow account with the appropriate role. You are responsible for keeping your credentials secure and for all activity under your account.</p>' +
        '<h4>3. Acceptable use</h4>' +
        '<ul>' +
          '<li>Use eGradeBook only for legitimate grading and academic record-keeping.</li>' +
          '<li>Do not attempt to access sections or records outside your authorization.</li>' +
          '<li>Do not attempt to disrupt, hack, or abuse the system or its bridged data sources.</li>' +
        '</ul>' +
        '<h4>4. Grade accuracy</h4>' +
        '<p>eGradeBook automates computation, but you remain responsible for reviewing and verifying final grades before they are officially submitted or released.</p>' +
        '<h4>5. Limitation of liability</h4>' +
        '<p>The service is provided "as is" for educational use. The developers are not liable for any data loss, miscomputation from incorrect input, or service interruption.</p>' +
        '<h4>6. Changes to these terms</h4>' +
        '<p>These terms may be updated from time to time. Continued use after changes means you accept the updated terms.</p>' +
        '<p class="sm-meta">Last updated: ' + YEAR + '. eGradeBook is an educational project.</p>'
    }
  };

  window.openSupportModal = function (key) {
    var data = SUPPORT_CONTENT[key];
    if (!data) return;
    document.getElementById('supportModalTitleText').textContent = data.title;
    var icon = document.getElementById('supportModalIcon');
    icon.className = 'bi ' + data.icon;
    document.getElementById('supportModalBody').innerHTML = data.html;
    document.getElementById('supportModalBackdrop').classList.add('show');
    document.body.style.overflow = 'hidden';
  };

  window.closeSupportModal = function () {
    document.getElementById('supportModalBackdrop').classList.remove('show');
    document.body.style.overflow = '';
  };

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') window.closeSupportModal();
  });
})();
</script>