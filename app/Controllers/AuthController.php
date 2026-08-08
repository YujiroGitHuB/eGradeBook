<?php

namespace App\Controllers;

use App\Core\Database;
use App\Models\UserRepo;

/* ============================================================
   AuthController — login handling. BRIDGED to FormFlow's admin_users
   (eGradeBook has no accounts of its own): if the password changes in
   FormFlow it reflects here immediately. Not superadmin-gated — any
   FormFlow account may sign in; index.php is what restricts the app
   itself to superadmins.
   ============================================================ */
class AuthController
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /* Handle a POSTed login. Returns an error string ('' if none);
       redirects and exits on success. */
    public function handle(): string
    {
        $next     = $_POST['next'] ?? 'index.php';
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$username || !$password) {
            return 'Please fill in all fields.';
        }

        $user = (new UserRepo($this->db))->findByUsername($username);
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id']       = $user['id'];
            $_SESSION['admin_username'] = $user['username'];
            $_SESSION['admin_name']     = $user['full_name'] ?: $user['username'];
            $_SESSION['admin_role']     = $user['role'] ?? 'admin';
            header('Location: ' . self::safeNext($next));
            exit;
        }
        return 'Invalid username or password.';
    }

    /* Panatilihing LOKAL ang post-login redirect.

       Ang dating tsek na `strpos($next,'http') === 0` ay hindi sapat: hindi nito
       nahuhuli ang protocol-relative na "//evil.com", na itinuturing ng browser
       na ganap na ibang site — kaya nagagamit ang login page bilang panakip sa
       phishing (`login.php?next=//evil.com`). Nahuhulog din dito ang "\\evil.com"
       dahil ginagawang "/" ng ilang browser ang backslash.

       Pinapayagan: relatibo ("index.php?x=1") at host-relative ("/eGradeBook/index.php")
       — iyon ang dalawang anyong ipinapasa ng app mismo (Auth::requireLogin ay
       gumagamit ng REQUEST_URI). Tinatanggihan: anumang may scheme, nagsisimula sa
       dalawang slash, o may backslash (ginagawa itong "/" ng ilang browser, kaya
       "/\evil.com" ay nagiging "//evil.com"). Anumang kahina-hinala → index.php. */
    private static function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '') return 'index.php';
        if (strpos($next, '\\') !== false) return 'index.php';          // /\evil.com
        if (strncmp($next, '//', 2) === 0) return 'index.php';          // //evil.com
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $next)) return 'index.php';  // http:, javascript:, data:
        return $next;
    }
}
