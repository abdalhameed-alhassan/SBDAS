<?php
/**
 * migrate_to_lite.php — ترحيل اختياري لقاعدة SBDAS الموجودة (Aiven أو XAMPP) إلى بنية النسخة المبسطة:
 *   1) إن بقيت قيم SWIFT/RTGS القديمة: تتحول إلى BBAN ويُحذف عمود swift_code (الإجماليات لا تتغير).
 *   2) حذف الـ 14 View وجدول notifications (لا تستعملها النسخة المبسطة).
 * لا يمس جداول البيانات ولا جدول sessions ولا أي قاعدة أخرى. آمن للتكرار. كود الموقع الجديد يعمل قبل الترحيل وبعده.
 * متغيرات الاتصال: DB_HOST DB_USER DB_PASS DB_NAME DB_PORT [DB_SSL=1 DB_SSL_CA=path]
 * الاستعمال: php migrate_to_lite.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
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

$steps = 0;
if ($db->query("SHOW COLUMNS FROM external_transfers LIKE 'swift_code'")->num_rows > 0) {
    foreach ([
        "UPDATE transactions SET sub_type='bban' WHERE sub_type IN ('swift','rtgs')",
        "ALTER TABLE transactions MODIFY sub_type ENUM('cash','cheque','app_transfer','web_transfer','internal_transfer','bban','atm','pos') NOT NULL DEFAULT 'cash'",
        "UPDATE external_transfers SET channel='bban' WHERE channel IN ('swift','rtgs')",
        "ALTER TABLE external_transfers MODIFY channel ENUM('branch','app','bban') NOT NULL DEFAULT 'bban'",
        "ALTER TABLE external_transfers DROP COLUMN swift_code",
        "UPDATE complaints SET subject='مشكلة في تحويل بنكي' WHERE subject='مشكلة في تحويل SWIFT'",
    ] as $q) { $db->query($q); $steps++; }
    echo "SWIFT/RTGS removed.\n";
} else { echo "SWIFT/RTGS: already removed.\n"; }

$views = ['vw_branch_summary','vw_branch_withdrawal_breakdown','vw_branch_deposit_breakdown','vw_branch_transfer_breakdown',
          'vw_top_accounts_by_transfers','vw_top_accounts_by_activity','vw_app_summary','vw_channel_comparison','vw_app_monthly',
          'vw_internal_transfer_summary','vw_monthly_growth','vw_external_bank_summary','vw_external_flow_monthly','vw_inter_branch_flow'];
foreach ($views as $v) { $db->query("DROP VIEW IF EXISTS `$v`"); $steps++; }
$db->query("DROP TABLE IF EXISTS notifications"); $steps++;

$t = $db->query("SHOW FULL TABLES"); $tables = []; $nv = 0;
while ($r = $t->fetch_row()) { if ($r[1] === 'VIEW') $nv++; else $tables[] = $r[0]; }
echo "Done ($steps steps). Tables: " . count($tables) . " (" . implode(', ', $tables) . "), Views: $nv\n";
