<?php
/**
 * reset_password.php — sets a new password for ONE existing SBDAS user (bcrypt). Run via sbdas_tools.ps1 only.
 * Env: DB_* (as import_database.php) + RESET_USERNAME + RESET_PASSWORD (min 8 chars).
 */
require __DIR__ . '/cli_db.php';
$u = (string)getenv('RESET_USERNAME'); $p = (string)getenv('RESET_PASSWORD');
if ($u === '' || strlen($p) < 8 || count(array_unique(str_split($p))) < 3) { fwrite(STDERR, "Username required; password must be 8+ chars and not trivial.\n"); exit(2); }
$db = cli_connect(cli_db_name());
$hash = password_hash($p, PASSWORD_BCRYPT);
$st = $db->prepare("UPDATE users SET password_hash=? WHERE username=? AND is_active=1");
$st->bind_param('ss', $hash, $u); $st->execute();
echo $st->affected_rows === 1 ? "Password updated for user '$u'.\n" : "No active user named '$u' (or password unchanged).\n";
