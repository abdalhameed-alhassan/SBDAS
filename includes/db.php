<?php
// Database connection and the shared query helpers (loaded once, through init.php).
// Connection settings come from environment variables via db_conn.php (DB_HOST, DB_PORT, DB_USER, DB_PASS, DB_NAME, DB_SSL ...).
// With no variables set it falls back to the XAMPP defaults (localhost / root / sbdas).
require_once __DIR__ . '/db_conn.php';
try {
    $conn = newDbConnection();
} catch (Throwable $e) {
    error_log('SBDAS db connect failed: ' . $e->getMessage());
    http_response_code(500);
    die('خطأ في الاتصال بقاعدة البيانات');
}

// Prepared statement with bound parameters: run('SELECT ... WHERE a=? AND b=?', [$a, $b])
function run($sql, $params = []) {
    global $conn;
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param(str_repeat('s', count($params)), ...$params);
    $st->execute();
    return $st;
}

// All rows / first row of a query. Only for SQL built from trusted values (casts, whitelists, valid_date()).
function rows($sql) { global $conn; return $conn->query($sql)->fetch_all(MYSQLI_ASSOC); }
function row($sql)  { return rows($sql)[0] ?? []; }

// Return $v only when it is a real date in YYYY-MM-DD format, otherwise $default
// (this keeps the date filters safe to embed in SQL).
function valid_date($v, $default) {
    $d = is_string($v) ? DateTime::createFromFormat('Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}

// Monthly series for a chart (last $n months, oldest first): [labels, values]
function monthly($expr, $table, $where = '1=1', $n = 6) {
    $r = array_reverse(rows("SELECT DATE_FORMAT(created_at,'%Y-%m') m, $expr v FROM $table WHERE $where GROUP BY m ORDER BY m DESC LIMIT $n"));
    return [array_column($r, 'm'), array_map('floatval', array_column($r, 'v'))];
}

// Branch summary: one branch when $bid is given, otherwise every active branch ordered by deposits.
// It replaces the old vw_branch_summary view.
function branch_summary($bid = null) {
    $t = "(SELECT COALESCE(SUM(amount),0) FROM transactions WHERE branch_id=b.branch_id AND status='completed' AND ";
    return rows("SELECT b.branch_id, b.branch_name, b.city,
        (SELECT COUNT(*) FROM customers WHERE branch_id=b.branch_id AND is_active=1) AS customers,
        (SELECT COUNT(*) FROM accounts  WHERE branch_id=b.branch_id) AS accounts,
        (SELECT COUNT(*) FROM transactions WHERE branch_id=b.branch_id AND status='completed') AS txn_count,
        {$t}txn_type='deposit') AS deposits,
        {$t}txn_type='withdrawal') AS withdrawals,
        {$t}txn_type IN ('transfer_in','transfer_out')) AS transfers,
        (SELECT COUNT(*) FROM loans WHERE branch_id=b.branch_id AND status='active')    AS active_loans,
        (SELECT COUNT(*) FROM loans WHERE branch_id=b.branch_id AND status='defaulted') AS defaulted_loans,
        (SELECT COUNT(*) FROM complaints WHERE branch_id=b.branch_id AND status IN ('open','in_progress')) AS open_complaints,
        (SELECT COUNT(*) FROM app_users au JOIN customers c ON c.customer_id=au.customer_id WHERE c.branch_id=b.branch_id AND c.is_active=1) AS app_users
        FROM branches b WHERE b.is_active=1 " . ($bid ? 'AND b.branch_id=' . (int)$bid : '') . " ORDER BY deposits DESC");
}
