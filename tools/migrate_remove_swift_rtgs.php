<?php
/**
 * migrate_remove_swift_rtgs.php — one-time migration: removes SWIFT/RTGS from an EXISTING SBDAS database
 * (old rows become BBAN, swift_code column dropped, views refreshed). Touches only the database named in DB_NAME.
 * Connection from env: DB_HOST DB_USER DB_PASS DB_NAME DB_PORT [DB_SSL=1 DB_SSL_CA=path]
 * Usage: php migrate_remove_swift_rtgs.php path\to\migrate_remove_swift_rtgs.sql
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "SQL file not found: $file\n"); exit(2); }
$name = getenv('DB_NAME') ?: 'sbdas';
if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) { fwrite(STDERR, "Invalid database name.\n"); exit(2); }
$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
$flags = 0;
if (in_array(strtolower((string)getenv('DB_SSL')), ['1', 'true', 'yes', 'on'], true)) {
    $db->ssl_set(null, null, getenv('DB_SSL_CA') ?: null, null, null);
    $flags = MYSQLI_CLIENT_SSL;
}
try {
    $db->real_connect(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), $name, (int)(getenv('DB_PORT') ?: 3306), null, $flags);
} catch (Throwable $e) { fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n"); exit(3); }
$db->set_charset('utf8mb4');
echo "Connected: " . $db->server_info . " - database: $name\n";
if ($db->query("SHOW TABLES LIKE 'external_transfers'")->num_rows === 0) { fwrite(STDERR, "STOP: no external_transfers table in `$name` (wrong database?).\n"); exit(4); }
if ($db->query("SHOW COLUMNS FROM external_transfers LIKE 'swift_code'")->num_rows === 0) { echo "Already migrated (no swift_code column). Nothing to do.\n"; exit(0); }
$db->multi_query(file_get_contents($file));
$n = 0;
try {
    do { $n++; if ($res = $db->store_result()) { $res->free(); } } while ($db->more_results() && $db->next_result());
} catch (Throwable $e) { fwrite(STDERR, "Error in statement #$n: " . $e->getMessage() . "\n"); exit(5); }
$left = $db->query("SELECT (SELECT COUNT(*) FROM transactions WHERE sub_type NOT IN ('cash','cheque','app_transfer','web_transfer','internal_transfer','bban','atm','pos')) AS a")->fetch_assoc()['a'];
echo "Done ($n statements). Rows with an unknown type: $left\n";
