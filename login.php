<?php

require_once __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Controllers\AuthController;

Auth::start();
if (!empty($_SESSION['admin_id'])) {
  header('Location: index.php');
  exit;
}

/* Ang Database ay nag-t-throw na kapag hindi maabot ang MySQL (dating nag-e-echo
   ng JSON kahit sa page load). Dito ito nagiging maayos na pahina — hindi
   puwedeng blangko ang login screen kapag patay ang DB. */
try {
    $db = new Database();
} catch (\Throwable $e) {
    error_log('eGradeBook login boot failed: ' . $e);
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Database unavailable</title>'
        . '<link rel="stylesheet" href="assets/css/global.css"></head>'
        . '<body class="bg-glow" style="display:flex;align-items:center;justify-content:center;height:100vh;text-align:center;">'
        . '<div><h2>Database unavailable</h2><p style="color:var(--muted);">eGradeBook could not reach MySQL. '
        . 'Check that the server is running, then reload.</p></div></body></html>';
    exit;
}

$error = '';
$next  = $_GET['next'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ── BRIDGED LOGIN — same accounts as FormFlow (see App\Controllers\AuthController).
  //    eGradeBook has no admin_users of its own; a password changed in FormFlow
  //    reflects here immediately since there is a single source of truth.
  $error = (new AuthController($db))->handle();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <script>
    if (localStorage.getItem("ff_theme") === "light") document.documentElement.classList.add("preload-light");
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>eGradeBook — Login</title>
  <?php include __DIR__ . "/components/favico.php" ?>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime('assets/css/login.css') ?>">
</head>

<body>
  <div class="shell">

    <!-- ══ KALIWA — ang produkto mismo ════════════════════════════════
         Hindi abstract na dekorasyon: isang piraso ng grading matrix,
         napupunan pagbukas ng pahina kagaya ng totoong sheet. HALIMBAWANG
         datos ito (kaya may "Sample sheet" na pill) — walang binabasang
         tunay na marka ang login page, wala pa ngang sesyon dito. -->
    <section class="slab">

      <div class="lockup">
        <img src="assets/images/logo.png" alt="" width="38" height="38">
        <span class="wordmark">e<em>Grade</em>Book</span>
      </div>

      <div>
        <h1 class="lede">One sheet, from roster to final grade.</h1>
        <p class="lede-sub">
          Your section loads with its students already in it. Quizzes, form
          columns and QR attendance land in the same matrix, and the transmuted
          1.00–5.00 point follows every score you type.
        </p>
      </div>

      <div class="sheet">
        <div class="sheet-bar">
          <span class="sheet-ctx">BSIT-1A · Computer Programming 2</span>
          <span class="pill pill-term">Midterm</span>
          <span class="pill">Sample sheet</span>
        </div>

        <div class="sheet-scroll">
          <table class="matrix">
            <thead>
              <tr>
                <th class="who">Student</th>
                <th>Quiz 1<span class="of">/ 20</span></th>
                <th>Activity 2<span class="of">/ 30</span></th>
                <th>Exam<span class="of">/ 50</span></th>
                <th>Attendance<span class="of">/ 12 sessions</span></th>
                <th>Equivalent</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td class="who"><b>Bautista, Marianne</b><span class="sno">025-217</span></td>
                <td class="mark fill" style="--d:.10s">20</td>
                <td class="mark fill" style="--d:.16s">29</td>
                <td class="mark fill" style="--d:.22s">47</td>
                <td class="mark fill" style="--d:.28s">12</td>
                <td><span class="eq fill" style="--d:.60s">1.25</span></td>
              </tr>
              <tr>
                <td class="who"><b>Abellera, Jonas</b><span class="sno">025-104</span></td>
                <td class="mark fill" style="--d:.14s">18</td>
                <td class="mark fill" style="--d:.20s">27</td>
                <td class="mark fill" style="--d:.26s">42</td>
                <td class="mark fill" style="--d:.32s">11</td>
                <td><span class="eq fill" style="--d:.66s">1.75</span></td>
              </tr>
              <tr>
                <td class="who"><b>Gutierrez, Aira</b><span class="sno">025-451</span></td>
                <td class="mark fill" style="--d:.18s">17</td>
                <td class="mark fill" style="--d:.24s">25</td>
                <td class="mark fill" style="--d:.30s">40</td>
                <td class="mark fill" style="--d:.36s">10</td>
                <td><span class="eq fill" style="--d:.72s">2.00</span></td>
              </tr>
              <tr>
                <td class="who"><b>Dela Cruz, Rio</b><span class="sno">025-338</span></td>
                <td class="mark fill" style="--d:.22s">14</td>
                <td class="mark fill" style="--d:.28s">22</td>
                <td class="mark fill" style="--d:.34s">33</td>
                <td class="mark fill" style="--d:.40s">9</td>
                <td><span class="eq edge fill" style="--d:.78s">2.50</span></td>
              </tr>
              <tr>
                <td class="who"><b>Ocampo, Lester</b><span class="sno">025-612</span></td>
                <td class="mark fill" style="--d:.26s"><s>—</s></td>
                <td class="mark fill" style="--d:.32s"><s>—</s></td>
                <td class="mark fill" style="--d:.38s"><s>—</s></td>
                <td class="mark fill" style="--d:.44s">4</td>
                <td><span class="eq drp fill" style="--d:.84s">DRP</span></td>
              </tr>
            </tbody>
          </table>
        </div>

        <p class="sheet-foot">
          <i class="bi bi-calendar-check"></i>
          Attendance counts itself from the QR scans — present ÷ sessions. Set a
          Midterm end date and it splits across both terms.
        </p>
      </div>
    </section>

    <!-- ══ KANAN — sign in ═══════════════════════════════════════════ -->
    <section class="side">

      <div class="side-top">
        <button class="theme-toggle" type="button" title="Toggle theme" aria-label="Toggle theme" onclick="toggleTheme()">
          <i class="bi bi-sun-fill"></i>
        </button>
      </div>

      <div class="login-card">
        <div class="card-head">
          <h2>Welcome back</h2>
          <p>Sign in with your FormFlow username and password.</p>
        </div>

        <?php if ($error): ?>
          <div class="error-banner">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= htmlspecialchars($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="POST" autocomplete="on">
          <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

          <div class="field">
            <label for="userInput">Username</label>
            <div class="field-wrap">
              <i class="bi bi-person fi"></i>
              <input
                type="text"
                id="userInput"
                name="username"
                placeholder="Enter your username"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                autocomplete="username"
                required
                autofocus>
            </div>
          </div>

          <div class="field">
            <label for="passInput">Password</label>
            <div class="field-wrap">
              <i class="bi bi-lock fi"></i>
              <input
                type="password"
                name="password"
                id="passInput"
                placeholder="Enter your password"
                autocomplete="current-password"
                required>
              <button type="button" class="toggle-pass" onclick="togglePass()" title="Show/hide password" aria-label="Show or hide password">
                <i class="bi bi-eye" id="eyeIcon"></i>
              </button>
            </div>
          </div>

          <button type="submit" class="btn-login">
            Sign In <i class="bi bi-arrow-right"></i>
          </button>
        </form>

        <p class="bridge-note">
          eGradeBook has no separate account. It signs you in against
          <strong>FormFlow</strong>, so a password changed there works here on
          the next try — and your gradebook stays yours alone.
        </p>
      </div>

      <!-- ── POWERED BY FOOTER ─────────────────────────────────── -->
      <div class="side-foot">
        <span><i class="bi bi-lightning-charge-fill"></i> Powered by <strong>eGradeBook</strong></span>
        <span><i class="bi bi-code-slash"></i> Developed by <strong>Charles Nixon Cayading</strong></span>
      </div>
    </section>

  </div>

  <script>
    /* ── Password visibility toggle ── */
    function togglePass() {
      const inp = document.getElementById('passInput');
      const icon = document.getElementById('eyeIcon');
      const show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      icon.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
    }

    /* ── Theme toggle (self-contained, works without global.js) ── */
    (function() {
      const STORAGE_KEY = 'ff_theme';
      const ICON_DARK = 'bi bi-sun-fill';
      const ICON_LIGHT = 'bi bi-moon-fill';

      function applyTheme(theme) {
        const isLight = theme === 'light';
        document.body.classList.toggle('light-mode', isLight);
        /* Ang `preload-light` sa <html> ang pumipigil sa pagkislap bago
           tumakbo ito; kapag naipasa na sa body, dapat na itong alisin —
           kung hindi, mananaig pa rin ito kapag nag-switch pabalik sa dark. */
        document.documentElement.classList.remove('preload-light');
        document.querySelectorAll('.theme-toggle i').forEach(i => {
          i.className = isLight ? ICON_LIGHT : ICON_DARK;
        });
      }

      applyTheme(localStorage.getItem(STORAGE_KEY) || 'dark');

      window.toggleTheme = function() {
        const next = document.body.classList.contains('light-mode') ? 'dark' : 'light';
        localStorage.setItem(STORAGE_KEY, next);
        applyTheme(next);
      };
    })();
  </script>
  <script src="assets/js/detection.js?v=<?= filemtime('assets/js/detection.js') ?>"></script>
</body>

</html>
