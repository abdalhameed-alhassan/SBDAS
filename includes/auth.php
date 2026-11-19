<?php
// الجلسة والصلاحيات
// نسخة النشر: على Vercel (بلا ملفات دائمة) تُحفظ الجلسات في جدول sessions عند SESSION_STORE=db
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

// الأدوار: الاسم العربي + مجلد صفحاتها
const ROLES = [
    'executive'       => ['الإدارة العليا', 'executive'],
    'branch_manager'  => ['مدير الفرع',    'branch_manager'],
];

// مسار المشروع من جذر الخادم (يعمل داخل أي مجلد فرعي مثل /sbdas_lite)
function base_url() {
    // نسخة النشر: الجذر العام من APP_BASE_URL (على Vercel القيمة / فتصبح النتيجة نصاً فارغاً)
    $env = getenv('APP_BASE_URL');
    if ($env !== false && $env !== '') return rtrim($env, '/');
    $doc  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
    $root = str_replace('\\', '/', dirname(__DIR__));
    return rtrim(str_replace($doc, '', $root), '/');
}

function is_logged_in() { return isset($_SESSION['user_id'], $_SESSION['role'], ROLES[$_SESSION['role']]); }
function role_label($role) { return ROLES[$role][0] ?? $role; }

function current_user() {
    return ['id' => $_SESSION['user_id'] ?? null, 'name' => $_SESSION['full_name'] ?? '',
            'role' => $_SESSION['role'] ?? '', 'branch_id' => $_SESSION['branch_id'] ?? null];
}

function redirect_by_role($role) {
    $folder = ROLES[$role][1] ?? null;
    header('Location: ' . base_url() . '/' . ($folder ? "pages/$folder/dashboard.php" : 'login.php'));
    exit;
}

// تسمح الصفحة بالأدوار المذكورة فقط؛ غير المسجّل يذهب للدخول، وغير المصرّح له إلى لوحته
function require_role(...$roles) {
    if (!is_logged_in()) { header('Location: ' . base_url() . '/login.php'); exit; }
    if (!in_array($_SESSION['role'], $roles)) redirect_by_role($_SESSION['role']);
}
