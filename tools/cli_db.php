<?php
/**
 * cli_db.php - shared helper for the command-line tools (import_database.php, migrate_to_lite.php, reset_password.php).
 * Refuses to run outside the CLI and opens the MySQL connection from the DB_* environment variables.
 * Variables: DB_HOST DB_USER DB_PASS DB_NAME DB_PORT [DB_SSL=1 DB_SSL_CA=path]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Target database name from DB_NAME (default "sbdas"); stops the script if the name is not a plain identifier.
function cli_db_name() {
    $name = getenv('DB_NAME') ?: 'sbdas';
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) { fwrite(STDERR, "Invalid database name.\n"); exit(2); }
    return $name;
}

// Connect to the server; pass null to connect without selecting a database (needed before CREATE DATABASE).
function cli_connect($database) {
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);
    $flags = 0;
    if (in_array(strtolower((string)getenv('DB_SSL')), ['1', 'true', 'yes', 'on'], true)) {
        $db->ssl_set(null, null, getenv('DB_SSL_CA') ?: null, null, null);
        $flags = MYSQLI_CLIENT_SSL;
    }
    try {
        $db->real_connect(getenv('DB_HOST'), getenv('DB_USER'), getenv('DB_PASS'), $database, (int)(getenv('DB_PORT') ?: 3306), null, $flags);
    } catch (Throwable $e) { fwrite(STDERR, "Connection failed: " . $e->getMessage() . "\n"); exit(3); }
    $db->set_charset('utf8mb4');
    return $db;
}
