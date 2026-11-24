<?php
/**
 * migrate_to_lite.php - optional migration of an existing SBDAS database (Aiven or XAMPP) to the simplified (Lite) structure:
 *   1) If the old SWIFT/RTGS values are still present: convert them to BBAN and drop the swift_code column (totals do not change).
 *   2) Drop the 14 views and the notifications table (the Lite version does not use them).
 *   3) Reduce the user roles to executive + branch_manager.
 * It does not touch the data tables, the sessions table or any other database. Safe to run repeatedly.
 * The current site code works both before and after the migration.
 * Connection variables: DB_HOST DB_USER DB_PASS DB_NAME DB_PORT [DB_SSL=1 DB_SSL_CA=path]
 * Usage: php migrate_to_lite.php
 */
require __DIR__ . '/cli_db.php';
$name = cli_db_name();
$db = cli_connect($name);
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

// The system-admin and branch-employee roles were removed: only the executive and branch-manager roles remain.
$col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch_assoc();
if ($col && (strpos($col['Type'], 'admin') !== false || strpos($col['Type'], 'branch_employee') !== false)) {
    $db->query("DELETE FROM users WHERE role IN ('admin','branch_employee')");
    $db->query("ALTER TABLE users MODIFY role ENUM('branch_manager','executive') NOT NULL");
    $steps += 2; echo "Roles reduced to executive + branch_manager.\n";
} else { echo "Roles: already reduced.\n"; }

$t = $db->query("SHOW FULL TABLES"); $tables = []; $nv = 0;
while ($r = $t->fetch_row()) { if ($r[1] === 'VIEW') $nv++; else $tables[] = $r[0]; }
echo "Done ($steps steps). Tables: " . count($tables) . " (" . implode(', ', $tables) . "), Views: $nv\n";
