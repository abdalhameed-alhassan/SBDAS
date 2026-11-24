<?php
require_once '../../includes/init.php';
require_role('executive');

$branches = branch_summary();

// All branch transactions grouped in one query: [branch][transaction type][execution method] => [amount, count]
$by = [];
foreach (rows("SELECT branch_id, txn_type, sub_type, SUM(amount) AS amt, COUNT(*) AS c FROM transactions
               WHERE status='completed' GROUP BY branch_id, txn_type, sub_type") as $r)
    $by[$r['branch_id']][$r['txn_type']][$r['sub_type']] = $r;

// Breakdown table: one row per branch and one column per execution method, each cell shown as "amount (count)"
function breakdown_card($title, $icon, $branches, $by, $types, $cols) {
    $out = [];
    foreach ($branches as $b) {
        $line = ['<strong>' . h($b['branch_name']) . '</strong>'];
        foreach (array_keys($cols) as $sub) {
            $amt = $cnt = 0;
            foreach ($types as $t) { $amt += $by[$b['branch_id']][$t][$sub]['amt'] ?? 0; $cnt += $by[$b['branch_id']][$t][$sub]['c'] ?? 0; }
            $line[] = fmt($amt) . ' <small class="muted">(' . $cnt . ')</small>';
        }
        $out[] = $line;
    }
    table_card($title, $icon, array_merge(['الفرع'], array_values($cols)), $out);
}

open_layout('مقارنة الفروع', 'branches');

$rows = [];
foreach ($branches as $b) {
    $loans = $b['active_loans'] + $b['defaulted_loans'];
    $rate  = $loans ? round($b['defaulted_loans'] / $loans * 100, 1) : 0;
    $adopt = $b['customers'] ? round($b['app_users'] / $b['customers'] * 100) : 0;
    $rows[] = [
        '<strong>' . h($b['branch_name']) . '</strong><br><small class="muted">' . h($b['city']) . '</small>',
        $b['customers'], $b['accounts'], badge(number_format($b['txn_count'])),
        '<span class="green">' . fmt($b['deposits']) . '</span>', '<span class="red">' . fmt($b['withdrawals']) . '</span>', fmt($b['transfers']),
        $b['active_loans'], $b['defaulted_loans'] ? badge($b['defaulted_loans'], 'danger') : 0,
        badge($rate . '%', $rate > 20 ? 'danger' : ($rate > 10 ? 'warn' : 'success')),
        badge($b['open_complaints'], $b['open_complaints'] ? 'warn' : 'success'),
        number_format($b['app_users']), badge($adopt . '%', $adopt >= 70 ? 'success' : ($adopt >= 50 ? 'teal' : ($adopt >= 30 ? 'warn' : 'danger'))),
    ];
}
table_card('مقارنة الفروع', '🏛️',
    ['الفرع','العملاء','الحسابات','المعاملات','الإيداعات','السحوبات','التحويلات','قروض نشطة','متعثرة','نسبة التعثر','شكاوى','مستخدمو التطبيق','التبني الرقمي'], $rows);

breakdown_card('تفصيل السحوبات حسب النوع', '💸', $branches, $by, ['withdrawal'], ['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','atm'=>'صرف آلي','pos'=>'نقطة بيع']);
breakdown_card('تفصيل الإيداعات حسب النوع', '💵', $branches, $by, ['deposit'], ['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','bban'=>'BBAN']);
breakdown_card('تفصيل التحويلات حسب النوع', '🔄', $branches, $by, ['transfer_in','transfer_out'], ['app_transfer'=>'عبر التطبيق','internal_transfer'=>'داخلي (بين الفروع)','bban'=>'BBAN']);
close_layout();
