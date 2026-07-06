<?php
if (session_status() === PHP_SESSION_NONE) session_start();

if (!empty($_SESSION['admin_id'])) {
  header('Location: index.php');
  exit;
}

require_once 'inc/db.php';

$error = '';
$next  = $_GET['next'] ?? 'index.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if ($username && $password) {
    // ── BRIDGED LOGIN — same accounts as FormFlow ──────────────
    // eGradeBook has no admin_users of its own; it queries
    // FormFlow's admin_users table directly (same MySQL
    // server, different database). If the password is changed in FormFlow,
    // it reflects here immediately since there is a single source.
    $stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM " . FORMFLOW_DB . ".admin_users WHERE username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
      session_regenerate_id(true);
      $_SESSION['admin_id']       = $user['id'];
      $_SESSION['admin_username'] = $user['username'];
      $_SESSION['admin_name']     = $user['full_name'] ?: $user['username'];
      $_SESSION['admin_role']     = $user['role'] ?? 'admin';
      $next = $_POST['next'] ?? 'index.php';
      if (strpos($next, 'http') === 0) $next = 'index.php';
      header('Location: ' . $next);
      exit;
    } else {
      $error = 'Invalid username or password.';
    }
  } else {
    $error = 'Please fill in all fields.';
  }
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
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/login.css?v=<?= filemtime('assets/css/login.css') ?>">
</head>

<body>
  <div class="login-wrap">

    <div class="brand">
      <img src="assets/images/logo.png" width="70px" height="70px" alt="eGradeBook">
      <div class="brand-name">eGradeBook</div>
      <div class="brand-tagline">Uses your FormFlow account</div>
    </div>

    <div class="theme-row">
      <button class="theme-toggle" title="Toggle theme" onclick="toggleTheme()">
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
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" autocomplete="on">
        <input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>">

        <div class="field">
          <label>Username</label>
          <div class="field-wrap">
            <i class="bi bi-person fi"></i>
            <input
              type="text"
              name="username"
              placeholder="Enter your username"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
              autocomplete="username"
              required
              autofocus>
          </div>
        </div>

        <div class="field">
          <label>Password</label>
          <div class="field-wrap">
            <i class="bi bi-lock fi"></i>
            <input
              type="password"
              name="password"
              id="passInput"
              placeholder="Enter your password"
              autocomplete="current-password"
              required>
            <button type="button" class="toggle-pass" onclick="togglePass()" title="Show/hide password">
              <i class="bi bi-eye" id="eyeIcon"></i>
            </button>
          </div>
        </div>

        <button type="submit" class="btn-login">
          <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
      </form>

    </div>
    <!-- ── POWERED BY FOOTER ─────────────────────────────────── -->
    <div style="text-align:center;padding:1.5rem 1rem 2rem;
            font-size:.75rem;color:var(--muted);
            display:flex;flex-direction:column;align-items:center;gap:4px;">
      <div style="display:flex;align-items:center;gap:6px;">
        <i class="bi bi-lightning-charge-fill" style="color:var(--accent);font-size:.8rem;"></i>
        Powered by <strong style="color:var(--text);">eGradeBook</strong>
      </div>
      <div style="display:flex;align-items:center;gap:5px;opacity:.6;">
        <i class="bi bi-code-slash" style="font-size:.8rem;"></i>
        Developed by <strong style="color:var(--text);">Charles Nixon Cayading</strong>
      </div>
    </div>

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
