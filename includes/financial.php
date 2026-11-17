<?php
// صفحة التحليل المالي — صفحة واحدة تخدم الإدارة العليا (كل الفروع) ومدير الفرع (فرعه فقط)
function page_financial($bid) {
    $w   = $bid ? "AND branch_id=" . (int)$bid : '';          // نطاق الفرع في الاستعلامات
    $tx  = "FROM transactions WHERE status='completed' $w";    // كل العمليات المكتملة في النطاق
    $sum = fn($type) => "SUM(CASE WHEN txn_type $type THEN amount ELSE 0 END)";
    $DEP = "='deposit'"; $WD = "='withdrawal'"; $TR = " IN ('transfer_in','transfer_out')";

    // "اليوم" = تاريخ آخر معاملة مسجّلة في البيانات (لا تاريخ الخادم) كي تعكس الفترات بيانات فعلية
    $ref = row("SELECT MAX(created_at) AS d $tx")['d'] ?? date('Y-m-d H:i:s');

    // ── الإيراد (إيداعات) والصادر (سحوبات) حسب الفترة ──
    $y = "YEAR(created_at)=YEAR('$ref')";
    $periods = ['اليوم' => "DATE(created_at)=DATE('$ref')", 'الأسبوع' => "YEARWEEK(created_at,1)=YEARWEEK('$ref',1)",
                'الشهر' => "$y AND MONTH(created_at)=MONTH('$ref')", 'ربع سنوي' => "$y AND QUARTER(created_at)=QUARTER('$ref')",
                'نصف سنوي' => "$y AND CEIL(MONTH(created_at)/6)=CEIL(MONTH('$ref')/6)", 'سنوي' => $y];
    $cols = []; $i = 0;
    foreach ($periods as $cond) { $cols[] = "SUM(CASE WHEN txn_type='deposit' AND $cond THEN amount ELSE 0 END) AS d$i, "
                                          . "SUM(CASE WHEN txn_type='withdrawal' AND $cond THEN amount ELSE 0 END) AS w$i"; $i++; }
    $p = row("SELECT " . implode(', ', $cols) . " $tx");

    // ── فترة مخصصة ──
    $from = valid_date($_GET['from'] ?? null, date('Y-m-d', strtotime("$ref -30 days")));
    $to   = valid_date($_GET['to']   ?? null, date('Y-m-d', strtotime($ref)));
    $range = row("SELECT {$sum($WD)} AS wd, SUM(txn_type='withdrawal') AS wd_n, {$sum($DEP)} AS dep, SUM(txn_type='deposit') AS dep_n
                  $tx AND DATE(created_at) BETWEEN '$from' AND '$to'");

    // ── مؤشرات + حركة شهرية + تفصيل ──
    $t = row("SELECT {$sum($DEP)} AS dep, {$sum($WD)} AS wd, {$sum($TR)} AS tr, SUM(txn_type='deposit') AS dep_n,
                     SUM(txn_type='withdrawal') AS wd_n, SUM(txn_type IN ('transfer_in','transfer_out')) AS tr_n $tx");
    $m = array_reverse(rows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, {$sum($DEP)} AS dep, {$sum($WD)} AS wd, {$sum($TR)} AS tr
                             $tx GROUP BY m ORDER BY m DESC LIMIT 6"));
    $types = rows("SELECT txn_type, sub_type, COUNT(*) AS c, SUM(amount) AS vol $tx GROUP BY txn_type, sub_type ORDER BY txn_type, c DESC");
    $total = array_sum(array_column($types, 'c')) ?: 1;

    // ── أكثر 10 حسابات نشاطاً ──
    $top = rows("SELECT a.account_id, b.branch_name, a.account_type, a.balance, COUNT(t.txn_id) AS ops, COALESCE(SUM(t.amount),0) AS vol,
                        SUM(t.txn_type='deposit') AS dep_n, SUM(t.txn_type='withdrawal') AS wd_n, SUM(t.txn_type IN ('transfer_in','transfer_out')) AS tr_n
                 FROM accounts a JOIN branches b ON b.branch_id=a.branch_id
                 LEFT JOIN transactions t ON t.account_id=a.account_id AND t.status='completed'
                 " . ($bid ? "WHERE a.branch_id=" . (int)$bid : '') . "
                 GROUP BY a.account_id, b.branch_name, a.account_type, a.balance ORDER BY vol DESC LIMIT 10");

    // ── القروض (بالرقم الحسابي دون اسم العميل) ──
    $loans = rows("SELECT (SELECT MIN(account_id) FROM accounts WHERE customer_id=l.customer_id) AS account_id, l.amount, l.paid_amount, l.status, l.due_at
                   FROM loans l " . ($bid ? "WHERE l.branch_id=" . (int)$bid : '') . " ORDER BY l.status DESC, l.due_at LIMIT 30");
    $ls = row("SELECT SUM(amount) AS total, SUM(paid_amount) AS paid, SUM(status='defaulted') AS defaulted, COUNT(*) AS n
               FROM loans " . ($bid ? "WHERE branch_id=" . (int)$bid : ''));

    $scope = $bid ? ' — ' . (row("SELECT branch_name FROM branches WHERE branch_id=" . (int)$bid)['branch_name'] ?? '') : '';
    open_layout('التحليل المالي' . $scope, 'financial');

    // 1) الفترات
    card_start('الإيراد والصادر حسب الفترة الزمنية', '💰');
    echo '<div class="muted small">* الفترات محسوبة نسبةً لتاريخ آخر معاملة مسجّلة: <strong>' . date('Y-m-d', strtotime($ref)) . '</strong></div>';
    $rowD = [badge('📥 الإيراد', 'success')]; $rowW = [badge('📤 الصادر', 'danger')];
    foreach (array_keys($periods) as $i => $_) { $rowD[] = '<b class="green">' . fmt($p["d$i"]) . '</b>'; $rowW[] = '<b class="red">' . fmt($p["w$i"]) . '</b>'; }
    data_table(array_merge([''], array_keys($periods)), [$rowD, $rowW]);
    $ratio = $t['dep'] > 0 ? round($t['wd'] / $t['dep'] * 100, 1) : 0;
    echo '<div class="center">'; stat_box('نسبة السحب إلى الإيداع', $ratio . '%', '', 'gold'); echo '</div>';
    card_end();

    // 2) فترة مخصصة
    card_start('السحوبات والإيرادات حسب فترة تحددها', '🗓️');
    echo '<form method="get" class="filter-form">' . field('من', '<input type="date" name="from" value="' . $from . '" class="form-control">')
       . field('إلى', '<input type="date" name="to" value="' . $to . '" class="form-control">')
       . '<button class="btn btn-primary">🔍 عرض الفترة</button></form><div class="grid-2">';
    stat_box('سحوبات الفترة', fmt($range['wd']) . ' SDG', number_format($range['wd_n']) . ' عملية سحب', 'red');
    stat_box('إيرادات (إيداعات) الفترة', fmt($range['dep']) . ' SDG', number_format($range['dep_n']) . ' عملية إيداع', 'green');
    echo '</div>';
    card_end();

    // 3) مؤشرات + مخطط
    kpi_grid([['💵', fmt($t['dep']), 'إجمالي الإيداعات (SDG)', 'success', number_format($t['dep_n']) . ' عملية'],
              ['💸', fmt($t['wd']),  'إجمالي السحوبات (SDG)', 'danger',  number_format($t['wd_n']) . ' عملية'],
              ['🔄', fmt($t['tr']),  'إجمالي التحويلات (SDG)', 'teal',    number_format($t['tr_n']) . ' عملية'],
              ['🏧', fmt($t['dep'] - $t['wd']), 'صافي الحركة (إيداعات − سحوبات)', $t['dep'] >= $t['wd'] ? 'gold' : 'danger']]);
    card_start('الحركة المالية الشهرية', '📈');
    chart('monthly', 'bar', array_column($m, 'm'), [['إيداعات', array_map('floatval', array_column($m, 'dep')), '#1A7A4A'],
          ['سحوبات', array_map('floatval', array_column($m, 'wd')), '#C0392B'], ['تحويلات', array_map('floatval', array_column($m, 'tr')), '#7F8691']]);
    card_end();

    // 4) تفصيل العمليات
    $rows = [];
    foreach ($types as $r) {
        $pct = round($r['c'] / $total * 100, 1);
        $rows[] = [badge(L_TXN[$r['txn_type']] ?? $r['txn_type'], $r['txn_type'] === 'deposit' ? 'success' : ($r['txn_type'] === 'withdrawal' ? 'danger' : 'teal')),
                   h(L_SUB[$r['sub_type']] ?? $r['sub_type']), '<b>' . number_format($r['c']) . '</b>', fmt($r['vol']),
                   '<div class="bar"><div class="progress-track"><div class="progress-fill fill-teal" style="width:' . min($pct * 3, 100) . '%"></div></div><span class="muted small">' . $pct . '%</span></div>'];
    }
    table_card('تفصيل العمليات حسب النوع والقناة', '🗂️', ['نوع العملية','طريقة التنفيذ','العدد','الحجم (SDG)','النسبة'], $rows);

    // 5) أكثر الحسابات نشاطاً
    $rows = [];
    foreach ($top as $i => $a)
        $rows[] = [$i + 1, '<b dir="ltr">' . (int)$a['account_id'] . '</b>', h($a['branch_name']), badge(L_ACCOUNT[$a['account_type']] ?? $a['account_type'], 'gray'),
                   fmt($a['balance']), '<b>' . fmt($a['vol']) . '</b>', badge($a['dep_n'], 'success'), badge($a['wd_n'], 'danger'), badge($a['tr_n']), '<b>' . $a['ops'] . '</b>'];
    table_card('أكثر 10 حسابات نشاطاً', '⚡', ['#','رقم الحساب','الفرع','النوع','الرصيد','إجمالي الحركة','إيداعات','سحوبات','تحويلات','العمليات'], $rows);

    // 6) القروض
    $defRate = $ls['n'] ? round($ls['defaulted'] / $ls['n'] * 100, 1) : 0;
    card_start('متابعة القروض والتحصيل', '💳');
    kpi_grid([['💰', fmt($ls['total']), 'إجمالي القروض (SDG)', 'navy'], ['✅', fmt($ls['paid']), 'المسدَّد (SDG)', 'success'],
              ['⚠️', number_format($ls['defaulted']), 'قروض متعثرة', 'danger'], ['📊', $defRate . '%', 'نسبة التعثر', 'warn']]);
    $rows = [];
    foreach ($loans as $l) {
        $pct = $l['amount'] > 0 ? round($l['paid_amount'] / $l['amount'] * 100, 1) : 0;
        $cls = $l['status'] === 'defaulted' ? 'danger' : ($l['status'] === 'paid' ? 'success' : 'teal');
        $rows[] = ['<b dir="ltr">' . ($l['account_id'] ?: '—') . '</b>', fmt($l['amount']), '<span class="green">' . fmt($l['paid_amount']) . '</span>',
                   '<span class="red">' . fmt($l['amount'] - $l['paid_amount']) . '</span>',
                   '<div class="bar"><div class="progress-track"><div class="progress-fill fill-' . $cls . '" style="width:' . min($pct, 100) . '%"></div></div><b class="small">' . $pct . '%</b></div>',
                   badge(L_STATUS[$l['status']] ?? $l['status'], $cls), h($l['due_at'])];
    }
    data_table(['رقم الحساب','المبلغ (SDG)','المسدَّد','المتبقي','نسبة السداد','الحالة','الاستحقاق'], $rows);
    card_end();
    close_layout();
}
