<?php
// الاتصال بقاعدة البيانات + دوال الاستعلام المشتركة (يُستدعى مرة واحدة من init.php)
// نسخة النشر: إعدادات الاتصال من متغيرات البيئة عبر db_conn.php (DB_HOST DB_PORT DB_USER DB_PASS DB_NAME DB_SSL ...)
// وبلا أي متغيرات يعمل على XAMPP بالقيم الافتراضية (localhost / root / sbdas).
require_once __DIR__ . '/db_conn.php';
try {
    $conn = newDbConnection();
} catch (Throwable $e) {
    error_log('SBDAS db connect failed: ' . $e->getMessage());
    http_response_code(500);
    die('خطأ في الاتصال بقاعدة البيانات');
}

// استعلام محضَّر بمعاملات آمنة: run('SELECT ... WHERE a=? AND b=?', [$a, $b])
function run($sql, $params = []) {
    global $conn;
    $st = $conn->prepare($sql);
    if ($params) $st->bind_param(str_repeat('s', count($params)), ...$params);
    $st->execute();
    return $st;
}

// كل الصفوف / الصف الأول / تنظيف نص قبل إدراجه في استعلام
function rows($sql) { global $conn; return $conn->query($sql)->fetch_all(MYSQLI_ASSOC); }
function row($sql)  { return rows($sql)[0] ?? []; }
function esc($v)    { global $conn; return $conn->real_escape_string($v); }

// تاريخ صحيح بصيغة YYYY-MM-DD فقط، وإلا القيمة الافتراضية (يحمي من حقن SQL في فلاتر التاريخ)
function valid_date($v, $default) {
    $d = is_string($v) ? DateTime::createFromFormat('Y-m-d', $v) : false;
    return ($d && $d->format('Y-m-d') === $v) ? $v : $default;
}

// سلسلة شهرية (آخر $n أشهر) لمخطط: [التسميات، القيم]
function monthly($expr, $table, $where = '1=1', $n = 6) {
    $r = array_reverse(rows("SELECT DATE_FORMAT(created_at,'%Y-%m') m, $expr v FROM $table WHERE $where GROUP BY m ORDER BY m DESC LIMIT $n"));
    return [array_column($r, 'm'), array_map('floatval', array_column($r, 'v'))];
}

// ملخص الفروع (يحل محل vw_branch_summary): فرع واحد إن مُرّر $bid، وإلا كل الفروع الفعالة مرتبة بالإيداعات
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
