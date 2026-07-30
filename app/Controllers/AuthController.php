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
            if (strpos($next, 'http') === 0) $next = 'index.php';
            header('Location: ' . $next);
            exit;
        }
        return 'Invalid username or password.';
    }
}
