<?php
// "Banking and operational analysis and reports" page, shared by both roles:
// the executive sees all branches (or one chosen branch), a branch manager sees only their own branch.
// The top of the page shows the current-month indicators and the reports (any period) are listed below them.
// For customer privacy the reports show account numbers only, never names or national IDs.
function page_reports($bid) {
    $from   = valid_date($_GET['from'] ?? null, date('Y-01-01'));
    $to     = valid_date($_GET['to']   ?? null, date('Y-m-d'));
    $branch = $bid ?: ($_GET['branch'] ?? 'all');             // a branch manager is locked to their own branch
    $b      = $branch === 'all' ? '' : (int)$branch;           // requested branch id, or empty for all branches
    $acc    = fn($alias) => "(SELECT MIN(account_id) FROM accounts WHERE customer_id=$alias.customer_id)";  // the customer's account number
    $sort   = in_array($_GET['sort'] ?? '', ['volume','count','recurring']) ? $_GET['sort'] : 'volume';
    $by      = in_array($_GET['by'] ?? '', ['day','week','month']) ? $_GET['by'] : 'month';   // grouping of the period report
    $grp     = ['day' => 'DATE(t.created_at)', 'week' => "DATE_FORMAT(t.created_at,'أسبوع %v من %x')", 'month' => "DATE_FORMAT(t.created_at,'%Y-%m')"][$by];
    $between = fn($col) => "DATE($col) BETWEEN '$from' AND '$to'";
    $in      = fn($alias) => $b ? "AND $alias.branch_id=$b" : '';

    // Report type => [title, column headers, query]
    $reports = [
        // ── Banking reports ──
        'period' => ['الإيرادات والسحوبات حسب الفترة', ['الفترة','الإيراد: إيداعات (SDG)','عدد الإيداعات','الصادر: سحوبات (SDG)','عدد السحوبات','الصافي (SDG)'],
            "SELECT $grp AS p, SUM(CASE WHEN t.txn_type='deposit' THEN t.amount ELSE 0 END) AS v_in, SUM(t.txn_type='deposit') AS n_in,
                    SUM(CASE WHEN t.txn_type='withdrawal' THEN t.amount ELSE 0 END) AS v_out, SUM(t.txn_type='withdrawal') AS n_out,
                    SUM(CASE WHEN t.txn_type='deposit' THEN t.amount WHEN t.txn_type='withdrawal' THEN -t.amount ELSE 0 END) AS net
             FROM transactions t WHERE t.status='completed' AND {$between('t.created_at')} {$in('t')} GROUP BY p ORDER BY MIN(t.created_at) DESC LIMIT 400"],
        'breakdown' => ['تفصيل العمليات حسب النوع والقناة', ['نوع العملية','طريقة التنفيذ','العدد','الحجم (SDG)','النسبة'],
            "SELECT t.txn_type, t.sub_type, COUNT(*) AS cnt, SUM(t.amount) AS vol, CONCAT(ROUND(100*COUNT(*)/SUM(COUNT(*)) OVER (),1),'%') AS pct
             FROM transactions t WHERE t.status='completed' AND {$between('t.created_at')} {$in('t')} GROUP BY t.txn_type, t.sub_type ORDER BY t.txn_type, cnt DESC"],
        'top' => ['أكثر 10 حسابات نشاطاً', ['رقم الحساب','الفرع','النوع','الرصيد (SDG)','إيداعات','سحوبات','تحويلات','عدد العمليات','إجمالي الحركة (SDG)'],
            "SELECT a.account_id, br.branch_name, a.account_type, a.balance, SUM(t.txn_type='deposit') AS n_in, SUM(t.txn_type='withdrawal') AS n_out,
                    SUM(t.txn_type IN ('transfer_in','transfer_out')) AS n_tr, COUNT(*) AS cnt, SUM(t.amount) AS vol
             FROM transactions t JOIN accounts a ON a.account_id=t.account_id JOIN branches br ON br.branch_id=a.branch_id
             WHERE t.status='completed' AND {$between('t.created_at')} {$in('a')}
             GROUP BY a.account_id, br.branch_name, a.account_type, a.balance ORDER BY vol DESC LIMIT 10"],
        'transactions' => ['العمليات المالية', ['#','رقم الحساب','الفرع','النوع','الطريقة','المبلغ (SDG)','التاريخ'],
            "SELECT t.txn_id, t.account_id, br.branch_name, t.txn_type, t.sub_type, t.amount, t.created_at
             FROM transactions t JOIN branches br ON br.branch_id=t.branch_id
             WHERE t.status='completed' AND {$between('t.created_at')} {$in('t')} ORDER BY t.created_at DESC LIMIT 200"],
        'customers' => ['العملاء', ['رقم الحساب','الفرع','الجنس','تاريخ الإضافة'],
            "SELECT {$acc('c')} AS account_id, br.branch_name, c.gender, c.created_at
             FROM customers c JOIN branches br ON br.branch_id=c.branch_id
             WHERE c.is_active=1 AND {$between('c.created_at')} {$in('c')} ORDER BY c.created_at DESC LIMIT 300"],
        'accounts' => ['الحسابات', ['رقم الحساب','الفرع','النوع','الرصيد (SDG)','الحالة','تاريخ الفتح'],
            "SELECT a.account_id, br.branch_name, a.account_type, a.balance, a.status, a.opened_at
             FROM accounts a JOIN branches br ON br.branch_id=a.branch_id
             WHERE {$between('a.opened_at')} {$in('a')} ORDER BY a.balance DESC LIMIT 300"],
        'loans' => ['القروض', ['رقم الحساب','الفرع','المبلغ (SDG)','المسدَّد','المتبقي','الحالة','الإصدار','الاستحقاق'],
            "SELECT {$acc('l')} AS account_id, br.branch_name, l.amount, l.paid_amount, l.amount-l.paid_amount AS remaining, l.status, l.issued_at, l.due_at
             FROM loans l JOIN branches br ON br.branch_id=l.branch_id
             WHERE {$between('l.issued_at')} {$in('l')} ORDER BY l.issued_at DESC LIMIT 300"],
        'complaints' => ['الشكاوى', ['رقم الحساب','الفرع','الموضوع','الحالة','تاريخ الفتح','تاريخ الإغلاق'],
            "SELECT {$acc('k')} AS account_id, br.branch_name, k.subject, k.status, k.opened_at, k.resolved_at
             FROM complaints k JOIN branches br ON br.branch_id=k.branch_id
             WHERE {$between('k.opened_at')} {$in('k')} ORDER BY k.opened_at DESC LIMIT 300"],
        // Internal transfers per account (as sender or receiver), plus repeated transfers between the same two accounts
        'transfers' => ['التحويلات (حسب الحساب)', ['رقم الحساب','الفرع','عدد التحويلات','حجمها (SDG)','تحويلات متكررة'],
            "SELECT a.account_id, br.branch_name, v.cnt, v.vol, COALESCE(r.rec,0) AS rec
             FROM accounts a JOIN branches br ON br.branch_id=a.branch_id
             JOIN (SELECT account_id, COUNT(*) AS cnt, SUM(amount) AS vol FROM (
                       SELECT from_account_id AS account_id, amount, created_at FROM internal_transfers WHERE status='completed'
                       UNION ALL SELECT to_account_id, amount, created_at FROM internal_transfers WHERE status='completed') u
                   WHERE {$between('created_at')} GROUP BY account_id) v ON v.account_id=a.account_id
             LEFT JOIN (SELECT from_account_id AS account_id, SUM(n) AS rec FROM (
                            SELECT from_account_id, COUNT(*) AS n FROM internal_transfers
                            WHERE status='completed' AND {$between('created_at')} GROUP BY from_account_id, to_account_id HAVING n>1) p
                        GROUP BY from_account_id) r ON r.account_id=a.account_id
             WHERE 1=1 {$in('a')} ORDER BY " . ['volume' => 'v.vol', 'count' => 'v.cnt', 'recurring' => 'rec'][$sort] . " DESC LIMIT 200"],
        // ── Digital reports: monthly / per-bank detail only; the totals are in the digital strip on the executive dashboard ──
        'app' => ['نشاط تطبيق الجوال (شهرياً)', ['الشهر','مستخدمون نشطون','عمليات دخول','عمليات مالية','قيمتها (SDG)'],
            "SELECT DATE_FORMAT(s.created_at,'%Y-%m') AS m, COUNT(DISTINCT s.app_user_id) AS users,
                    SUM(s.action_type='login') AS logins, SUM(s.action_type IN ('transfer','payment','deposit_request')) AS ops,
                    COALESCE(SUM(s.amount),0) AS amt
             FROM app_sessions s WHERE {$between('s.created_at')} {$in('s')} GROUP BY m ORDER BY m DESC"],
        'banks' => ['الحركة مع البنوك الأخرى (حسب البنك)', ['البنك','عدد الوارد','قيمة الوارد (SDG)','عدد الصادر','قيمة الصادر (SDG)','الصافي (SDG)'],
            "SELECT e.external_bank, SUM(e.direction='inbound') AS n_in, SUM(CASE WHEN e.direction='inbound' THEN e.amount ELSE 0 END) AS v_in,
                    SUM(e.direction='outbound') AS n_out, SUM(CASE WHEN e.direction='outbound' THEN e.amount ELSE 0 END) AS v_out,
                    SUM(CASE WHEN e.direction='inbound' THEN e.amount ELSE -e.amount END) AS net
             FROM external_transfers e WHERE e.status='completed' AND {$between('e.created_at')} {$in('e')}
             GROUP BY e.external_bank ORDER BY SUM(e.amount) DESC"],
    ];
    $type = is_string($_GET['type'] ?? null) && isset($reports[$_GET['type']]) ? $_GET['type'] : 'period';
    [$title, $headers, $sql] = $reports[$type];

    // Format each value by its column name: Arabic labels for coded columns, thousands separators for numeric ones
    $labels = ['txn_type' => L_TXN, 'sub_type' => L_SUB, 'status' => L_STATUS, 'account_type' => L_ACCOUNT, 'gender' => L_GENDER];
    $money  = ['amount', 'balance', 'paid_amount', 'remaining', 'vol', 'cnt', 'rec', 'users', 'logins', 'ops', 'amt', 'n_in', 'v_in', 'n_out', 'v_out', 'net', 'n_tr'];
    $table  = [];
    $data = rows($sql);
    foreach ($data as $r) {
        $line = [];
        foreach ($r as $k => $v)
            $line[] = h(isset($labels[$k]) ? ($labels[$k][$v] ?? $v) : (in_array($k, $money) ? number_format((float)$v) : ($v ?? '—')));
        $table[] = $line;
    }

    if ($type === 'period' && $data) {     // total row for the selected period
        $sm = fn($k) => number_format(array_sum(array_map('floatval', array_column($data, $k))));
        array_unshift($table, ['<b>إجمالي الفترة</b>', '<b>' . $sm('v_in') . '</b>', '<b>' . $sm('n_in') . '</b>', '<b>' . $sm('v_out') . '</b>', '<b>' . $sm('n_out') . '</b>', '<b>' . $sm('net') . '</b>']);
    }

    // ── Current-month indicators (the latest month that has data, because the data is sample data), compared with the previous month ──
    $sc  = $b ? "AND branch_id=$b" : '';
    $ref = row("SELECT MAX(created_at) AS d FROM transactions WHERE status='completed' $sc")['d'] ?? date('Y-m-d H:i:s');
    $mo  = [];
    foreach (rows("SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, SUM(CASE WHEN txn_type='deposit' THEN amount ELSE 0 END) AS dep, SUM(txn_type='deposit') AS dep_n,
                          SUM(CASE WHEN txn_type='withdrawal' THEN amount ELSE 0 END) AS wd, SUM(txn_type='withdrawal') AS wd_n,
                          SUM(CASE WHEN txn_type IN ('transfer_in','transfer_out') THEN amount ELSE 0 END) AS tr, SUM(txn_type IN ('transfer_in','transfer_out')) AS tr_n
                   FROM transactions WHERE status='completed' $sc AND created_at >= DATE_FORMAT(DATE_SUB('$ref', INTERVAL 1 MONTH),'%Y-%m-01') GROUP BY m") as $r) $mo[$r['m']] = $r;
    $curM  = date('Y-m', strtotime($ref)); $prevM = date('Y-m', strtotime("$curM-01 -1 month"));
    $c = $mo[$curM] ?? []; $pv = $mo[$prevM] ?? [];
    $cv = fn($k) => (float)($c[$k] ?? 0);
    $chg = function ($k) use ($c, $pv) {
        $a = (float)($c[$k] ?? 0); $z = (float)($pv[$k] ?? 0);
        return $z > 0 ? ' · ' . ($a >= $z ? '▲ ' : '▼ ') . abs(round(($a - $z) / $z * 100)) . '% عن الشهر السابق' : '';
    };
    $net = $cv('dep') - $cv('wd');

    $scope = $bid ? ' — ' . (row("SELECT branch_name FROM branches WHERE branch_id=" . (int)$bid)['branch_name'] ?? '') : '';
    open_layout('التحليل المصرفي والتشغيلي والتقارير' . $scope, 'reports');

    echo '<div class="muted small mb">📅 مؤشرات الشهر الحالي (' . $curM . ' — آخر شهر فيه بيانات). للأشهر السابقة أو أي فترة أخرى استعملي التقارير أدناه.</div>';
    kpi_grid([['💵', fmt($cv('dep')), 'إيداعات الشهر (SDG)', 'success', number_format($cv('dep_n')) . ' عملية' . $chg('dep')],
              ['💸', fmt($cv('wd')),  'سحوبات الشهر (SDG)', 'danger',  number_format($cv('wd_n')) . ' عملية' . $chg('wd')],
              ['🔄', fmt($cv('tr')),  'تحويلات الشهر (SDG)', 'teal',    number_format($cv('tr_n')) . ' عملية' . $chg('tr')],
              ['🏧', fmt($net), 'صافي الحركة (إيداعات − سحوبات)', $net >= 0 ? 'gold' : 'danger']]);

    // Filter form
    $opt = fn($k, $v, $cur) => '<option value="' . $k . '"' . ($k == $cur ? ' selected' : '') . '>' . h($v) . '</option>';
    $typeOpts = ''; foreach ($reports as $k => $rp) if ($bid ? $k !== 'transfers' : true) $typeOpts .= $opt($k, $rp[0], $type);
    $form = field('نوع التقرير', '<select name="type" class="form-control" id="reportType">' . $typeOpts . '</select>');
    if (!$bid) {
        $brOpts = $opt('all', 'كل الفروع', $branch);
        foreach (rows("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_id") as $br) $brOpts .= $opt($br['branch_id'], $br['branch_name'], $branch);
        $form .= field('الفرع', '<select name="branch" class="form-control">' . $brOpts . '</select>');
    }
    $form .= field('من', '<input type="date" name="from" value="' . $from . '" class="form-control">')
           . field('إلى', '<input type="date" name="to" value="' . $to . '" class="form-control">');
    $form .= '<div id="groupBox"' . ($type === 'period' ? '' : ' style="display:none"') . '>'
           . field('التجميع حسب', '<select name="by" class="form-control">' . $opt('day', 'يوم', $by) . $opt('week', 'أسبوع', $by) . $opt('month', 'شهر', $by) . '</select>') . '</div>';
    if (!$bid)
        $form .= '<div id="sortBox"' . ($type === 'transfers' ? '' : ' style="display:none"') . '>'
               . field('ترتيب حسب', '<select name="sort" class="form-control">' . $opt('volume', 'حجم التحويلات', $sort) . $opt('count', 'عدد التحويلات', $sort) . $opt('recurring', 'التحويلات المتكررة', $sort) . '</select>') . '</div>';
    echo '<div class="card"><div class="card-body"><form method="get" class="filter-form">' . $form
       . '<button class="btn btn-primary">🔍 عرض التقرير</button><button type="button" onclick="window.print()" class="btn btn-gold">🖨️ طباعة</button></form></div></div>';
    if (!in_array($type, ['app', 'banks', 'period', 'breakdown'])) echo '<div class="muted small mb">🔒 حفاظاً على سرية بيانات العملاء، تعرض التقارير أرقام الحسابات دون الأسماء أو أرقام الهويات.</div>';

    table_card($title, '📋', $headers, $table, badge(count($table) . ' سجل'));
    echo '<script>var t=document.getElementById("reportType"),s=document.getElementById("sortBox"),g=document.getElementById("groupBox");t.onchange=function(){if(s)s.style.display=t.value==="transfers"?"block":"none";g.style.display=t.value==="period"?"block":"none"};</script>';
    close_layout();
}
