<?php
/**
 * includes/db_conn.php - builds the MySQL connection from environment variables.
 *
 * Variables: DB_HOST DB_PORT DB_USER DB_PASS DB_NAME
 *            [DB_SSL=1] [DB_SSL_CA_PEM=certificate text] [DB_SSL_CA=path] [DB_SSL_VERIFY=0]
 * The defaults (localhost / root / sbdas) are meant for local XAMPP only.
 */

// Read an environment variable (getenv, then $_ENV, then $_SERVER); empty values count as unset.
function envOr($name, $default = null) {
    $v = getenv($name);
    if ($v === false || $v === '') { $v = $_ENV[$name] ?? $_SERVER[$name] ?? false; }
    return ($v === false || $v === '') ? $default : $v;
}

function newDbConnection() {
    if (envOr('VERCEL') && !getenv('DB_HOST')) {
        error_log('SBDAS: DB_HOST is not set in the environment variables');
        throw new RuntimeException('database environment is not configured');
    }
    $conn = mysqli_init();
    $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    $flags = 0;
    $ssl = strtolower((string)envOr('DB_SSL', '0'));
    if (in_array($ssl, ['1', 'true', 'on', 'yes', 'required'], true)) {
        $ca = null;
        if (envOr('DB_SSL_CA_PEM')) {
            // The certificate text is written once to a temp file because mysqli needs a file path.
            $ca = sys_get_temp_dir() . '/sbdas_db_ca_' . md5((string)envOr('DB_SSL_CA_PEM')) . '.pem';
            if (!is_file($ca)) { file_put_contents($ca, (string)envOr('DB_SSL_CA_PEM')); }
        } elseif (envOr('DB_SSL_CA')) {
            $ca = (string)envOr('DB_SSL_CA');
            if (!preg_match('~^(/|[A-Za-z]:[\\\\/])~', $ca)) { $ca = __DIR__ . '/../' . $ca; }   // relative path: resolve from the project root
        }
        $conn->ssl_set(null, null, $ca, null, null);
        $flags = MYSQLI_CLIENT_SSL;
        if (strtolower((string)envOr('DB_SSL_VERIFY', '1')) === '0') {
            $flags |= MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;
        }
    }
    $conn->real_connect(
        (string)envOr('DB_HOST', 'localhost'), (string)envOr('DB_USER', 'root'), (string)envOr('DB_PASS', ''),
        (string)envOr('DB_NAME', 'sbdas'), (int)envOr('DB_PORT', 3306), null, $flags
    );
    $conn->set_charset('utf8mb4');
    return $conn;
}
