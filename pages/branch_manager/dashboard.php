<?php
require_once '../../includes/init.php';
require_role('branch_manager');

$bid = (int)current_user()['branch_id'];
$b   = branch_summary($bid)[0] ?? [];
[$labels, $volume] = monthly('SUM(amount)', 'transactions', "branch_id=$bid AND status='completed'");
$recent = rows("SELECT txn_id, account_id, txn_type, amount, balance_after, created_at FROM transactions
                WHERE branch_id=$bid AND status='completed' ORDER BY created_at DESC LIMIT 10");

open_layout('لوحة الفرع — ' . ($b['branch_name'] ?? ''), 'dashboard');

kpi_grid([
    ['👥', $b['customers'] ?? 0, 'عملاء الفرع', 'gold'],
    ['🏦', $b['accounts'] ?? 0, 'الحسابات', 'teal'],
    ['💵', number_format($b['deposits'] ?? 0), 'إجمالي الإيداعات', 'success'],
    ['💸', number_format($b['withdrawals'] ?? 0), 'إجمالي السحوبات', 'danger'],
    ['💳', ($b['active_loans'] ?? 0) + ($b['defaulted_loans'] ?? 0), 'القروض', 'navy'],
    ['📣', $b['open_complaints'] ?? 0, 'شكاوى مفتوحة', 'warn'],
]);

card_start('حجم العمليات الشهري (SDG)', '📈');
chart('trend', 'bar', $labels, [['حجم العمليات', $volume, '#C5A04A']], 200);
card_end();

$rows = [];
foreach ($recent as $t)
    $rows[] = [$t['txn_id'], '<span dir="ltr">' . (int)$t['account_id'] . '</span>',
               badge(L_TXN[$t['txn_type']] ?? $t['txn_type'], in_array($t['txn_type'], ['deposit', 'transfer_in']) ? 'success' : 'danger'),
               '<span dir="ltr">' . number_format($t['amount']) . '</span>', '<span dir="ltr">' . number_format($t['balance_after']) . '</span>',
               '<span class="muted small">' . $t['created_at'] . '</span>'];
table_card('آخر العمليات', '🕐', ['#','رقم الحساب','النوع','المبلغ','الرصيد بعد','التاريخ'], $rows);
close_layout();
