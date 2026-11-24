<?php
/**
 * api/index.php - the single entry point (front controller) for the Vercel deployment through vercel-php,
 * and for local runs with:   php -S 127.0.0.1:8080 -t public api/index.php
 * It accepts only the known project pages (root + login/logout + pages/{executive,branch_manager}/*.php)
 * and includes them as they are. Any other path (includes/, database/, tools/ ...) is rejected with 404
 * and never executed. Static files (CSS / images) are served straight from public/ and do not pass through here.
 */
$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '/';

// Local run: let PHP's built-in server serve the static files from public/
if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file($root . '/public' . $path)) {
    return false;
}

$target = null;
if ($path === '/' || $path === '/index.php') {
    $target = $root . '/index.php';
} elseif (preg_match('~^/(login|logout)\.php$~', $path, $m)) {
    $target = $root . '/' . $m[1] . '.php';
} elseif (preg_match('~^/pages/(executive|branch_manager)/([a-z_]+)\.php$~', $path, $m)) {
    $target = $root . '/pages/' . $m[1] . '/' . $m[2] . '.php';
}

if ($target === null || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><title>404</title></head>'
       . '<body style="font-family:Arial,sans-serif;text-align:center;padding:60px"><h2>الصفحة غير موجودة</h2><p><a href="/login.php">العودة لتسجيل الدخول</a></p></body></html>';
    exit;
}

$_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = $path === '/' ? '/index.php' : $path;
$_SERVER['SCRIPT_FILENAME'] = $target;
chdir(dirname($target));
require $target;
