<?php
/**
 * import_database.php — imports SBDAS (database.sql) into its OWN database on the MySQL server.
 * Safe: creates the database only if missing (CREATE DATABASE IF NOT EXISTS), refuses to run if the
 * database already has a `users` table, never drops or alters anything, never touches other databases.
 * Connection from env: DB_HOST DB_USER DB_PASS DB_NAME DB_PORT [DB_SSL=1 DB_SSL_CA=path]
 * Usage: php import_database.php path\to\database.sql
 */
require __DIR__ . '/cli_db.php';
$file = $argv[1] ?? '';
if (!is_file($file)) { fwrite(STDERR, "SQL file not found: $file\n"); exit(2); }
$name = cli_db_name();
$db = cli_connect(null);   // connect WITHOUT selecting a database first, so we can create it if needed
echo "Connected: " . $db->server_info . " - target database: $name\n";
$db->query("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->select_db($name);
$r = $db->query("SHOW TABLES LIKE 'users'");
if ($r->num_rows > 0) { fwrite(STDERR, "STOP: database `$name` already has a users table. Import only into an empty database.\n"); exit(4); }
$sql = file_get_contents($file);
$db->multi_query($sql);
$n = 0;
try {
    do { $n++; if ($res = $db->store_result()) { $res->free(); } } while ($db->more_results() && $db->next_result());
} catch (Throwable $e) { fwrite(STDERR, "Error in statement #$n: " . $e->getMessage() . "\n"); exit(5); }
$t = $db->query("SHOW FULL TABLES"); $tables = 0; $views = 0;
while ($row = $t->fetch_row()) { ($row[1] === 'VIEW') ? $views++ : $tables++; }
echo "Done. Tables: $tables, Views: $views\n";
foreach (['branches', 'customers', 'accounts', 'transactions', 'loans', 'complaints', 'users'] as $tb) {
    echo "  $tb: " . $db->query("SELECT COUNT(*) c FROM `$tb`")->fetch_assoc()['c'] . "\n";
}
