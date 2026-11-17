<?php
// صفحة التقارير — صفحة واحدة للإدارة العليا (كل الفروع أو فرع يختاره) ولمدير الفرع (فرعه فقط)
// حفاظاً على سرية العملاء تُعرض أرقام الحسابات دون الأسماء أو أرقام الهويات.
function page_reports($bid) {
    $from   = valid_date($_GET['from'] ?? null, date('Y-01-01'));
    $to     = valid_date($_GET['to']   ?? null, date('Y-m-d'));
    $branch = $bid ?: ($_GET['branch'] ?? 'all');             // مدير الفرع مقيَّد بفرعه
    $b      = $branch === 'all' ? '' : (int)$branch;           // رقم الفرع المطلوب أو فارغ للكل
    $acc    = fn($alias) => "(SELECT MIN(account_id) FROM accounts WHERE customer_id=$alias.customer_id)";  // رقم حساب العميل
    $sort   = in_array($_GET['sort'] ?? '', ['volume','count','recurring']) ? $_GET['sort'] : 'volume';
    $between = fn($col) => "DATE($col) BETWEEN '$from' AND '$to'";
    $in      = fn($alias) => $b ? "AND $alias.branch_id=$b" : '';

    // نوع التقرير => [العنوان، العناوين، الاستعلام]
    $reports = [
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
        // التحويلات الداخلية لكل حساب (كمرسِل أو مستلِم) + التحويلات المتكررة بين الطرفين نفسيهما
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
        // ── التقارير الرقمية (بديل صفحة التحليل الرقمي): تفصيل شهري/حسب البنك فقط؛ الإجماليات في الشريط الرقمي بلوحة الإدارة ──
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
    $type = isset($reports[$_GET['type'] ?? '']) ? $_GET['type'] : 'transactions';
    [$title, $headers, $sql] = $reports[$type];

    // تحويل القيم إلى نص عربي/أرقام منسّقة بحسب اسم العمود
    $labels = ['txn_type' => L_TXN, 'sub_type' => L_SUB, 'status' => L_STATUS, 'account_type' => L_ACCOUNT, 'gender' => L_GENDER];
    $money  = ['amount', 'balance', 'paid_amount', 'remaining', 'vol', 'cnt', 'rec', 'users', 'logins', 'ops', 'amt', 'n_in', 'v_in', 'n_out', 'v_out', 'net'];
    $table  = [];
    foreach (rows($sql) as $r) {
        $line = [];
        foreach ($r as $k => $v)
            $line[] = h(isset($labels[$k]) ? ($labels[$k][$v] ?? $v) : (in_array($k, $money) ? number_format((float)$v) : ($v ?? '—')));
        $table[] = $line;
    }

    $scope = $bid ? ' — ' . (row("SELECT branch_name FROM branches WHERE branch_id=" . (int)$bid)['branch_name'] ?? '') : '';
    open_layout(($bid ? 'تقارير الفرع' : 'التقارير') . $scope, 'reports');

    // نموذج الفلترة
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
    if (!$bid)
        $form .= '<div id="sortBox"' . ($type === 'transfers' ? '' : ' style="display:none"') . '>'
               . field('ترتيب حسب', '<select name="sort" class="form-control">' . $opt('volume', 'حجم التحويلات', $sort) . $opt('count', 'عدد التحويلات', $sort) . $opt('recurring', 'التحويلات المتكررة', $sort) . '</select>') . '</div>';
    echo '<div class="card"><div class="card-body"><form method="get" class="filter-form">' . $form
       . '<button class="btn btn-primary">🔍 عرض التقرير</button><button type="button" onclick="window.print()" class="btn btn-gold">🖨️ طباعة</button></form></div></div>';
    if (!in_array($type, ['app', 'banks'])) echo '<div class="muted small mb">🔒 حفاظاً على سرية بيانات العملاء، تعرض التقارير أرقام الحسابات دون الأسماء أو أرقام الهويات.</div>';

    table_card($title, '📋', $headers, $table, badge(count($table) . ' سجل'));
    echo '<script>var t=document.getElementById("reportType"),s=document.getElementById("sortBox");if(s)t.onchange=function(){s.style.display=t.value==="transfers"?"block":"none"};</script>';
    close_layout();
}
