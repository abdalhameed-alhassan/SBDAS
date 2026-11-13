<?php
require_once __DIR__ . '/db_conn.php';

try {
    $conn = newDbConnection();
} catch (Throwable $e) {
    error_log('SBDAS db connect failed: ' . $e->getMessage());
    http_response_code(500);
    die('<div style="font-family:Arial;color:red;padding:20px">خطأ في الاتصال بقاعدة البيانات</div>');
}

// يتحقق أن القيمة تاريخ صحيح بصيغة YYYY-MM-DD فقط، وإلا يرجع القيمة الافتراضية.
// يُستخدم لتنظيف أي تاريخ قادم من $_GET/$_POST قبل إدخاله في استعلام SQL،
// لأن التواريخ كانت تُدرج مباشرة بدون تحقق (ثغرة SQL Injection محتملة).
function validate_date($value, $default) {
    if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if ($d && $d->format('Y-m-d') === $value) {
            return $value;
        }
    }
    return $default;
}
