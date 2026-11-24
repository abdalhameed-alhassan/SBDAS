<?php
// Session setup, role definitions and access control.
// On Vercel there is no persistent disk, so when SESSION_STORE=db the sessions
// are kept in the `sessions` table instead of local files.
if (session_status() === PHP_SESSION_NONE) {
    if (strtolower((string)getenv('SESSION_STORE')) === 'db') {
        require_once __DIR__ . '/session_handler.php';
        session_set_save_handler(new SbdasDbSessionHandler(), true);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '28800');
    $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
    unset($__https);
    session_start();
}

// Roles: role key => Arabic display name. The key is also the name of the role's pages folder.
const ROLES = [
    'executive'      => 'الإدارة العليا',
    'branch_manager' => 'مدير الفرع',
];

// Project path relative to the web root (works inside any sub-folder such as /sbdas_lite).
function base_url() {
    // Deployment: the public root comes from APP_BASE_URL (on Vercel it is "/", which yields an empty string).
    $env = getenv('APP_BASE_URL');
    if ($env !== false && $env !== '') return rtrim($env, '/');
    $doc  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $root = str_replace('\\', '/', dirname(__DIR__));
    return rtrim(str_replace($doc, '', $root), '/');
}

function is_logged_in() { return isset($_SESSION['user_id'], $_SESSION['role'], ROLES[$_SESSION['role']]); }
function role_label($role) { return ROLES[$role] ?? $role; }

function current_user() {
    return ['id' => $_SESSION['user_id'] ?? null, 'name' => $_SESSION['full_name'] ?? '',
            'role' => $_SESSION['role'] ?? '', 'branch_id' => $_SESSION['branch_id'] ?? null];
}

// Store the authenticated user in a fresh session, then send them to their dashboard.
function login_user($u) {
    session_regenerate_id(true);
    $_SESSION['user_id']   = $u['user_id'];
    $_SESSION['full_name'] = $u['full_name'];
    $_SESSION['role']      = $u['role'];
    $_SESSION['branch_id'] = $u['branch_id'];
    redirect_by_role($u['role']);
}

function redirect_by_role($role) {
    header('Location: ' . base_url() . '/' . (isset(ROLES[$role]) ? "pages/$role/dashboard.php" : 'login.php'));
    exit;
}

// A page allows only the listed roles: visitors who are not logged in go to the login page,
// and users with another role are sent back to their own dashboard.
function require_role(...$roles) {
    if (!is_logged_in()) { header('Location: ' . base_url() . '/login.php'); exit; }
    if (!in_array($_SESSION['role'], $roles)) redirect_by_role($_SESSION['role']);
}
