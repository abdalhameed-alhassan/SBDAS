<?php
// ── الجلسات: على Vercel (بيئة بلا ملفات دائمة) تُحفظ في جدول sessions بالقاعدة ──
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

// Detect project base URL automatically (works in any subfolder e.g. /sbdas_a/)
function base_url() {
    // نسخة النشر: الجذر العام للموقع من متغير البيئة APP_BASE_URL (على Vercel القيمة / فتصبح النتيجة نصاً فارغاً)
    $env = getenv('APP_BASE_URL');
    if ($env !== false && $env !== '') return rtrim($env, '/');
    // __FILE__ is always in /includes/auth.php — go up one level to project root
    $docRoot  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $projRoot = str_replace('\\', '/', dirname(__DIR__)); // parent of includes/
    $base     = str_replace($docRoot, '', $projRoot);
    return rtrim($base, '/');
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: ' . base_url() . '/login.php');
        exit;
    }
}

function require_role(...$roles) {
    require_login();
    if (!in_array($_SESSION['role'], $roles)) {
        redirect_by_role($_SESSION['role']);
    }
}

function current_user() {
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'name'      => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']      ?? '',
        'branch_id' => $_SESSION['branch_id'] ?? null,
    ];
}

function role_label($role) {
    $map = [
        'admin'           => 'مدير النظام',
        'executive'       => 'الإدارة العليا',
        'branch_manager'  => 'مدير الفرع',
        'branch_employee' => 'موظف الفرع',
    ];
    return $map[$role] ?? $role;
}

function redirect_by_role($role) {
    $map = [
        'admin'           => 'pages/admin/dashboard.php',
        'executive'       => 'pages/executive/dashboard.php',
        'branch_manager'  => 'pages/branch_manager/dashboard.php',
        'branch_employee' => 'pages/branch_manager/dashboard.php',
    ];
    $path = $map[$role] ?? 'login.php';
    header('Location: ' . base_url() . '/' . $path);
    exit;
}
