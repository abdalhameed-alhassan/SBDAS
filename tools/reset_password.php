<?php
/**
 * reset_password.php — sets a new password for ONE existing SBDAS user (bcrypt). Run via sbdas_tools.ps1 only.
 * Env: DB_* (as import_database.php) + RESET_USERNAME + RESET_PASSWORD (min 8 chars).
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$u = (string)getenv('RESET_USERNAME'); $p = (string)getenv('RESET_PASSWORD');
if ($u === '' || strlen($p) < 8 || count(array_unique(str_split($p))) < 3) { fwrite(STDERR, "Username required; password must be 8+ chars and not trivial.\n"); exit(2); }
$db = mysqli_init(); $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15); $flags = 0;
if (in_array(strtolower((string)getenv('DB_SSL')), ['1', 'true', 'yes', 'on'], true)) { $db->ssl_set(null, null, getenv('DB_SSL_CA') ?: null, null, null); $flags = MYSQLI_CLIENT_SSL; }
$db->real_connect(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), getenv('DB_NAME') ?: 'sbdas', (int)(getenv('DB_PORT') ?: 3306), null, $flags);
$hash = password_hash($p, PASSWORD_BCRYPT);
$st = $db->prepare("UPDATE users SET password_hash=? WHERE username=? AND is_active=1");
$st->bind_param('ss', $hash, $u); $st->execute();
echo $st->affected_rows === 1 ? "Password updated for user '$u'.\n" : "No active user named '$u' (or password unchanged).\n";
