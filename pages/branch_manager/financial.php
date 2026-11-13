<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('branch_manager','branch_employee','admin');

$user = current_user();
$bid  = (int)$user['branch_id'];
$bInfo = $conn->query("SELECT * FROM branches WHERE branch_id=$bid")->fetch_assoc();

// ── نقطة مرجعية "اليوم" = آخر معاملة مسجّلة فعلياً في بيانات الفرع (بدل NOW() الحقيقي) ──
$refRow = $conn->query("SELECT MAX(created_at) AS d FROM transactions WHERE branch_id=$bid AND status='completed'")->fetch_assoc();
$refDate = $refRow['d'] ?? date('Y-m-d H:i:s');
$ref = $conn->real_escape_string($refDate);

// ── الإيراد والصادر حسب الفترة الزمنية (اليوم/الأسبوع/الشهر/ربع سنوي/نصف سنوي/سنوي) — نسبة إلى تاريخ آخر معاملة ──
$periodTotals = $conn->query("SELECT
  SUM(CASE WHEN txn_type='deposit' AND DATE(created_at)=DATE('$ref') THEN amount END) AS today_dep,
  SUM(CASE WHEN txn_type='deposit' AND YEARWEEK(created_at,1)=YEARWEEK('$ref',1) THEN amount END) AS week_dep,
  SUM(CASE WHEN txn_type='deposit' AND MONTH(created_at)=MONTH('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS month_dep,
  SUM(CASE WHEN txn_type='deposit' AND QUARTER(created_at)=QUARTER('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS quarter_dep,
  SUM(CASE WHEN txn_type='deposit' AND CEIL(MONTH(created_at)/6)=CEIL(MONTH('$ref')/6) AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS half_dep,
  SUM(CASE WHEN txn_type='deposit' AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS year_dep,
  SUM(CASE WHEN txn_type='withdrawal' AND DATE(created_at)=DATE('$ref') THEN amount END) AS today_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND YEARWEEK(created_at,1)=YEARWEEK('$ref',1) THEN amount END) AS week_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND MONTH(created_at)=MONTH('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS month_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND QUARTER(created_at)=QUARTER('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS quarter_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND CEIL(MONTH(created_at)/6)=CEIL(MONTH('$ref')/6) AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS half_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS year_wd,
  SUM(CASE WHEN txn_type='deposit'    THEN amount END) AS total_dep_all,
  SUM(CASE WHEN txn_type='withdrawal' THEN amount END) AS total_wd_all
FROM transactions WHERE branch_id=$bid AND status='completed'")->fetch_assoc();

// ── سحوبات وإيرادات فترة زمنية مخصصة (تحديد يدوي من - إلى، كصفحة التقارير) ──
$rangeFrom = $_GET['from'] ?? date('Y-m-d', strtotime($refDate.' -30 days'));
$rangeTo   = $_GET['to']   ?? date('Y-m-d', strtotime($refDate));
$rangeTotals = $conn->query("SELECT
  SUM(CASE WHEN txn_type='withdrawal' THEN amount ELSE 0 END) AS wd_amt,
  SUM(CASE WHEN txn_type='withdrawal' THEN 1 ELSE 0 END)      AS wd_cnt,
  SUM(CASE WHEN txn_type='deposit'    THEN amount ELSE 0 END) AS dep_amt,
  SUM(CASE WHEN txn_type='deposit'    THEN 1 ELSE 0 END)      AS dep_cnt
FROM transactions
WHERE branch_id=$bid AND status='completed' AND DATE(created_at) BETWEEN '".$conn->real_escape_string($rangeFrom)."' AND '".$conn->real_escape_string($rangeTo)."'")->fetch_assoc();

// ── KPIs الفرع ──
$kpis = $conn->query("
    SELECT
      SUM(CASE WHEN txn_type='deposit'    AND status='completed' THEN amount ELSE 0 END) AS total_dep,
      SUM(CASE WHEN txn_type='withdrawal' AND status='completed' THEN amount ELSE 0 END) AS total_wd,
      SUM(CASE WHEN txn_type IN('transfer_in','transfer_out') AND status='completed' THEN amount ELSE 0 END) AS total_tr,
      COUNT(CASE WHEN txn_type='deposit'    AND status='completed' THEN 1 END) AS cnt_dep,
      COUNT(CASE WHEN txn_type='withdrawal' AND status='completed' THEN 1 END) AS cnt_wd,
      COUNT(CASE WHEN txn_type IN('transfer_in','transfer_out') AND status='completed' THEN 1 END) AS cnt_tr,
      SUM(CASE WHEN txn_type='withdrawal' AND status='completed' AND DATE(created_at)=CURDATE() THEN amount ELSE 0 END) AS today_wd,
      SUM(CASE WHEN txn_type='deposit'    AND status='completed' AND DATE(created_at)=CURDATE() THEN amount ELSE 0 END) AS today_dep
    FROM transactions WHERE branch_id=$bid
")->fetch_assoc();

// ── الخزينة (رصيد نظري = إجمالي الإيداعات - السحوبات) ──
$vault = ($kpis['total_dep'] ?? 0) - ($kpis['total_wd'] ?? 0);
$vaultToday = ($kpis['today_dep'] ?? 0) - ($kpis['today_wd'] ?? 0);

// ── أكثر 10 حسابات نشاطاً في الفرع ──
$topAccounts = [];
$res = $conn->query("
    SELECT a.account_id, c.full_name, a.account_type, a.balance,
           COUNT(t.txn_id) AS op_count,
           SUM(t.amount)   AS vol,
           SUM(CASE WHEN t.txn_type='deposit'    THEN t.amount ELSE 0 END) AS dep_vol,
           SUM(CASE WHEN t.txn_type='withdrawal' THEN t.amount ELSE 0 END) AS wd_vol,
           SUM(CASE WHEN t.txn_type IN('transfer_in','transfer_out') THEN t.amount ELSE 0 END) AS tr_vol,
           COUNT(CASE WHEN t.txn_type='deposit'    THEN 1 END) AS dep_cnt,
           COUNT(CASE WHEN t.txn_type='withdrawal' THEN 1 END) AS wd_cnt,
           COUNT(CASE WHEN t.txn_type IN('transfer_in','transfer_out') THEN 1 END) AS tr_cnt
    FROM accounts a
    JOIN customers c ON c.customer_id = a.customer_id
    LEFT JOIN transactions t ON t.account_id = a.account_id AND t.status='completed'
    WHERE a.branch_id = $bid
    GROUP BY a.account_id, c.full_name, a.account_type, a.balance
    ORDER BY vol DESC LIMIT 10
");
while ($r = $res->fetch_assoc()) $topAccounts[] = $r;

// ── تفصيل العمليات حسب النوع ──
$subBreak = [];
$res = $conn->query("
    SELECT txn_type, sub_type, COUNT(*) AS cnt, SUM(amount) AS vol
    FROM transactions
    WHERE branch_id=$bid AND status='completed'
    GROUP BY txn_type, sub_type
    ORDER BY txn_type, cnt DESC
");
while ($r = $res->fetch_assoc()) $subBreak[] = $r;

// ── الحركة الشهرية (6 أشهر) ──
$monthly = []; $mLabels = []; $mDep = []; $mWd = []; $mTr = [];
$res = $conn->query("
    SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo,
           SUM(CASE WHEN txn_type='deposit'    THEN amount ELSE 0 END) AS dep,
           SUM(CASE WHEN txn_type='withdrawal' THEN amount ELSE 0 END) AS wd,
           SUM(CASE WHEN txn_type IN('transfer_in','transfer_out') THEN amount ELSE 0 END) AS tr
    FROM transactions
    WHERE branch_id=$bid AND status='completed'
    GROUP BY mo ORDER BY mo DESC LIMIT 6
");
while ($r = $res->fetch_assoc()) {
    $mLabels[] = $r['mo']; $mDep[] = (float)$r['dep'];
    $mWd[] = (float)$r['wd']; $mTr[] = (float)$r['tr'];
}
$mLabels = array_reverse($mLabels);
$mDep    = array_reverse($mDep);
$mWd     = array_reverse($mWd);
$mTr     = array_reverse($mTr);

// ── تحصيل القروض ──
$loans = $conn->query("
    SELECT c.full_name, l.amount, l.paid_amount, l.status, l.due_at,
           ROUND(l.paid_amount/l.amount*100,1) AS pct
    FROM loans l
    JOIN customers c ON c.customer_id=l.customer_id
    WHERE l.branch_id=$bid ORDER BY l.status DESC, l.due_at ASC
")->fetch_all(MYSQLI_ASSOC);

$loanStats = $conn->query("
    SELECT SUM(amount) AS total, SUM(paid_amount) AS paid,
           SUM(status='defaulted') AS defaulted, COUNT(*) AS cnt
    FROM loans WHERE branch_id=$bid
")->fetch_assoc();

open_layout('التحليل المالي — '.htmlspecialchars($bInfo['branch_name']??''), 'financial');

function fmtN($n){
    if($n>=1000000) return number_format($n/1000000,2).'م';
    if($n>=1000)    return number_format($n/1000,1).'ألف';
    return number_format($n);
}
$typeMap = ['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];
$subMap  = ['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','web_transfer'=>'ويب',
            'internal_transfer'=>'داخلي','bban'=>'BBAN','atm'=>'ATM','pos'=>'POS'];
$accTypeMap = ['savings'=>'توفير','current'=>'جاري','investment'=>'استثماري'];
?>

<!-- الإيراد والصادر حسب الفترة الزمنية -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">💰</span> الإيراد والصادر حسب الفترة الزمنية</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="font-size:11px;color:var(--gray-500);padding:14px 18px 0">
      * الفترات محسوبة نسبة لتاريخ آخر معاملة مسجّلة في الفرع: <strong style="color:var(--navy)"><?=htmlspecialchars(date('Y-m-d', strtotime($refDate)))?></strong>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th></th>
            <th>اليوم</th>
            <th>الأسبوع</th>
            <th>الشهر</th>
            <th>ربع سنوي</th>
            <th>نصف سنوي</th>
            <th>سنوي</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td><span class="badge badge-success">📥 الإيراد</span></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['today_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['week_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['month_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['quarter_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['half_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($periodTotals['year_dep']??0)?></td>
          </tr>
          <tr>
            <td><span class="badge badge-danger">📤 الصادر</span></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['today_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['week_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['month_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['quarter_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['half_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($periodTotals['year_wd']??0)?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div style="text-align:center;padding:18px">
      <?php $pRatio = ($periodTotals['total_dep_all']??0)>0 ? round(($periodTotals['total_wd_all']??0)/$periodTotals['total_dep_all']*100,1) : 0; ?>
      <div style="display:inline-block;padding:14px 32px;background:var(--gold-pale);border-radius:var(--radius)">
        <div style="font-size:22px;font-weight:900;color:var(--gold)"><?=$pRatio?>%</div>
        <div style="font-size:11px;color:var(--gray-700);font-weight:700;margin-top:4px">نسبة السحب إلى الإيداع</div>
      </div>
    </div>
  </div>
</div>

<!-- السحوبات والإيرادات حسب الفترة الزمنية (فترة مخصصة يدوياً) -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">🗓️</span> السحوبات والإيرادات حسب الفترة الزمنية</div>
  </div>
  <div class="card-body">
    <form method="get" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid var(--gray-100)">
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">من</label>
        <input type="date" name="from" value="<?=htmlspecialchars($rangeFrom)?>" class="form-control" style="width:150px;direction:ltr">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">إلى</label>
        <input type="date" name="to" value="<?=htmlspecialchars($rangeTo)?>" class="form-control" style="width:150px;direction:ltr">
      </div>
      <button type="submit" class="btn btn-primary" style="width:auto">🔍 عرض الفترة</button>
    </form>

    <div class="grid-2">
      <div style="text-align:center;padding:16px;background:var(--danger-light);border-radius:var(--radius)">
        <div style="font-size:12px;color:var(--danger);font-weight:700;margin-bottom:6px">سحوبات الفترة المحددة</div>
        <div style="font-size:26px;font-weight:900;color:var(--danger)"><?=fmtN($rangeTotals['wd_amt']??0)?> SDG</div>
        <div style="font-size:11px;color:var(--gray-500);margin-top:4px"><?=number_format($rangeTotals['wd_cnt']??0)?> عملية سحب</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--success-light);border-radius:var(--radius)">
        <div style="font-size:12px;color:var(--success);font-weight:700;margin-bottom:6px">إيرادات (إيداعات) الفترة المحددة</div>
        <div style="font-size:26px;font-weight:900;color:var(--success)"><?=fmtN($rangeTotals['dep_amt']??0)?> SDG</div>
        <div style="font-size:11px;color:var(--gray-500);margin-top:4px"><?=number_format($rangeTotals['dep_cnt']??0)?> عملية إيداع</div>
      </div>
    </div>
  </div>
</div>

<!-- KPI Cards -->
<div class="kpi-grid">
  <div class="kpi-card success">
    <div class="kpi-icon">💵</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN($kpis['total_dep']??0) ?></div>
      <div class="kpi-label">إجمالي الإيداعات (SDG)</div>
      <div class="kpi-trend up"><?= number_format($kpis['cnt_dep']??0) ?> عملية</div>
    </div>
  </div>
  <div class="kpi-card danger">
    <div class="kpi-icon">💸</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN($kpis['total_wd']??0) ?></div>
      <div class="kpi-label">إجمالي السحوبات (SDG)</div>
      <div class="kpi-trend down"><?= number_format($kpis['cnt_wd']??0) ?> عملية</div>
    </div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-icon">🔄</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN($kpis['total_tr']??0) ?></div>
      <div class="kpi-label">إجمالي التحويلات (SDG)</div>
      <div class="kpi-trend up"><?= number_format($kpis['cnt_tr']??0) ?> عملية</div>
    </div>
  </div>
  <div class="kpi-card <?= $vault >= 0 ? 'gold' : 'danger' ?>">
    <div class="kpi-icon">🏧</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN(abs($vault)) ?></div>
      <div class="kpi-label">صافي الخزينة (SDG)</div>
      <div class="kpi-trend <?= $vault >= 0 ? 'up' : 'down' ?>">
        <?= $vault >= 0 ? '▲ رصيد إيجابي' : '▼ سحوبات تتجاوز الإيداعات' ?>
      </div>
    </div>
  </div>
  <div class="kpi-card success">
    <div class="kpi-icon">📥</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN($kpis['today_dep']??0) ?></div>
      <div class="kpi-label">إيداعات اليوم (SDG)</div>
    </div>
  </div>
  <div class="kpi-card danger">
    <div class="kpi-icon">📤</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= fmtN($kpis['today_wd']??0) ?></div>
      <div class="kpi-label">سحوبات اليوم (SDG)</div>
    </div>
  </div>
</div>

<!-- الخزينة التفصيلية -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">🏧</span> حالة الخزينة</div>
  </div>
  <div class="card-body">
    <div class="grid-3">
      <div style="text-align:center;padding:16px;background:var(--success-light);border-radius:var(--radius)">
        <div style="font-size:11px;color:var(--success);font-weight:700;margin-bottom:6px">إجمالي الإيداعات</div>
        <div style="font-size:22px;font-weight:900;color:var(--success)"><?= fmtN($kpis['total_dep']??0) ?></div>
        <div style="font-size:10px;color:var(--gray-500);margin-top:4px">SDG</div>
      </div>
      <div style="text-align:center;padding:16px;background:var(--danger-light);border-radius:var(--radius)">
        <div style="font-size:11px;color:var(--danger);font-weight:700;margin-bottom:6px">إجمالي السحوبات</div>
        <div style="font-size:22px;font-weight:900;color:var(--danger)"><?= fmtN($kpis['total_wd']??0) ?></div>
        <div style="font-size:10px;color:var(--gray-500);margin-top:4px">SDG</div>
      </div>
      <div style="text-align:center;padding:16px;background:<?= $vault>=0?'var(--gold-pale)':'var(--danger-light)' ?>;border-radius:var(--radius)">
        <div style="font-size:11px;color:<?= $vault>=0?'var(--gold)':'var(--danger)' ?>;font-weight:700;margin-bottom:6px">صافي الخزينة</div>
        <div style="font-size:22px;font-weight:900;color:<?= $vault>=0?'var(--gold)':'var(--danger)' ?>"><?= ($vault>=0?'+':'') . fmtN($vault) ?></div>
        <div style="font-size:10px;color:var(--gray-500);margin-top:4px">SDG</div>
      </div>
    </div>
    <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--gray-100)">
      <div style="font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:8px">توزيع حركة الخزينة</div>
      <?php
      $total = ($kpis['total_dep']??0) + ($kpis['total_wd']??0);
      $depPct = $total > 0 ? round(($kpis['total_dep']??0)/$total*100) : 0;
      $wdPct  = 100 - $depPct;
      ?>
      <div style="height:24px;border-radius:12px;overflow:hidden;display:flex">
        <div style="width:<?= $depPct ?>%;background:var(--success);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700"><?= $depPct ?>%</div>
        <div style="width:<?= $wdPct ?>%;background:var(--danger);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px;font-weight:700"><?= $wdPct ?>%</div>
      </div>
      <div style="display:flex;gap:16px;margin-top:8px;font-size:11px">
        <span style="color:var(--success)">■ إيداعات <?= $depPct ?>%</span>
        <span style="color:var(--danger)">■ سحوبات <?= $wdPct ?>%</span>
      </div>
    </div>
  </div>
</div>

<!-- الحركة الشهرية -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">📈</span> الحركة المالية الشهرية</div>
  </div>
  <div class="card-body">
    <div class="chart-container" style="height:240px">
      <canvas id="monthlyChart"></canvas>
    </div>
  </div>
</div>

<!-- تفصيل العمليات -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">🗂️</span> تفصيل العمليات حسب النوع والقناة</div>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>نوع العملية</th><th>طريقة التنفيذ</th><th>العدد</th><th>الحجم (SDG)</th></tr>
        </thead>
        <tbody>
        <?php foreach ($subBreak as $s): ?>
        <tr>
          <td><span class="badge badge-<?= $s['txn_type']==='deposit'?'success':($s['txn_type']==='withdrawal'?'danger':'teal') ?>"><?= $typeMap[$s['txn_type']]??$s['txn_type'] ?></span></td>
          <td><?= $subMap[$s['sub_type']]??$s['sub_type'] ?></td>
          <td><strong><?= number_format($s['cnt']) ?></strong></td>
          <td><?= fmtN($s['vol']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- أكثر 10 حسابات نشاطاً -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">⚡</span> أكثر 10 حسابات نشاطاً في الفرع</div>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>العميل</th><th>نوع الحساب</th><th>الرصيد الحالي</th><th>إجمالي الحركة</th><th>إيداعات</th><th>سحوبات</th><th>تحويلات</th><th>إجمالي العمليات</th></tr>
        </thead>
        <tbody>
        <?php foreach ($topAccounts as $i => $a): ?>
        <tr>
          <td><?= $i===0?'🥇':($i===1?'🥈':($i===2?'🥉':$i+1)) ?></td>
          <td><strong><?= htmlspecialchars($a['full_name']) ?></strong></td>
          <td><span class="badge badge-gray"><?= $accTypeMap[$a['account_type']]??$a['account_type'] ?></span></td>
          <td dir="ltr"><?= fmtN($a['balance']) ?></td>
          <td style="font-weight:800;color:var(--teal)"><?= fmtN($a['vol']??0) ?></td>
          <td><span class="badge badge-success"><?= $a['dep_cnt']??0 ?></span></td>
          <td><span class="badge badge-danger"><?= $a['wd_cnt']??0 ?></span></td>
          <td><span class="badge badge-teal"><?= $a['tr_cnt']??0 ?></span></td>
          <td><strong><?= $a['op_count']??0 ?></strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- القروض -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">💳</span> متابعة القروض والتحصيل</div>
  </div>
  <div class="card-body">
    <div class="kpi-grid" style="margin-bottom:16px">
      <div class="kpi-card navy"><div class="kpi-icon">💰</div><div class="kpi-body"><div class="kpi-value"><?= fmtN($loanStats['total']??0) ?></div><div class="kpi-label">إجمالي القروض (SDG)</div></div></div>
      <div class="kpi-card success"><div class="kpi-icon">✅</div><div class="kpi-body"><div class="kpi-value"><?= fmtN($loanStats['paid']??0) ?></div><div class="kpi-label">المسدَّد (SDG)</div></div></div>
      <div class="kpi-card danger"><div class="kpi-icon">⚠️</div><div class="kpi-body"><div class="kpi-value"><?= number_format($loanStats['defaulted']??0) ?></div><div class="kpi-label">قروض متعثرة</div></div></div>
      <div class="kpi-card warn"><div class="kpi-icon">📊</div><div class="kpi-body">
        <?php $defRate = ($loanStats['cnt']??0)>0 ? round(($loanStats['defaulted']??0)/($loanStats['cnt']??1)*100,1):0; ?>
        <div class="kpi-value"><?= $defRate ?>%</div><div class="kpi-label">نسبة التعثر</div>
      </div></div>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th>العميل</th><th>المبلغ (SDG)</th><th>المسدَّد</th><th>المتبقي</th><th>نسبة السداد</th><th>الحالة</th><th>الاستحقاق</th></tr></thead>
        <tbody>
        <?php foreach ($loans as $l):
          $remaining = $l['amount'] - $l['paid_amount'];
          $pct = (float)$l['pct'];
          $lCls = $l['status']==='defaulted'?'danger':($l['status']==='paid'?'success':'teal');
          $lMap = ['active'=>'نشط','paid'=>'مسدَّد','defaulted'=>'متعثر'];
        ?>
        <tr>
          <td><strong><?= htmlspecialchars($l['full_name']) ?></strong></td>
          <td><?= fmtN($l['amount']) ?></td>
          <td style="color:var(--success)"><?= fmtN($l['paid_amount']) ?></td>
          <td style="color:var(--danger)"><?= fmtN($remaining) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <div class="progress-track" style="flex:1;height:7px">
                <div class="progress-fill fill-<?= $lCls==='danger'?'danger':($lCls==='success'?'success':'teal') ?>" style="width:<?= min($pct,100) ?>%"></div>
              </div>
              <span style="font-size:11px;font-weight:700"><?= $pct ?>%</span>
            </div>
          </td>
          <td><span class="badge badge-<?= $lCls ?>"><?= $lMap[$l['status']]??$l['status'] ?></span></td>
          <td style="font-size:12px;color:<?= strtotime($l['due_at']) < time() && $l['status']==='active' ? 'var(--danger)' : 'var(--gray-500)' ?>"><?= $l['due_at'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('monthlyChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($mLabels) ?>,
        datasets: [
            { label: 'إيداعات', data: <?= json_encode($mDep) ?>, backgroundColor: '#1A7A4ABB', borderColor: '#1A7A4A', borderWidth: 1 },
            { label: 'سحوبات',  data: <?= json_encode($mWd) ?>,  backgroundColor: '#C0392BBB', borderColor: '#C0392B', borderWidth: 1 },
            { label: 'تحويلات', data: <?= json_encode($mTr) ?>,  backgroundColor: '#7F8691BB', borderColor: '#7F8691', borderWidth: 1 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { family: 'Cairo', size: 11 } } } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
    }
});
</script>
<?php close_layout(); ?>
