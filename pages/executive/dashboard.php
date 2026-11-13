<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

// ── KPI Totals ──
$kpis = $conn->query("
    SELECT
      (SELECT COUNT(*) FROM customers WHERE is_active=1)          AS total_customers,
      (SELECT COUNT(*) FROM accounts)                             AS total_accounts,
      (SELECT COUNT(*) FROM loans)                                AS total_loans,
      (SELECT COUNT(*) FROM loans WHERE status='defaulted')       AS defaulted_loans,
      (SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_progress')) AS open_complaints,
      (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE txn_type='withdrawal' AND status='completed') AS total_withdrawn,
      (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE txn_type='deposit' AND status='completed')    AS total_deposits,
      (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE txn_type IN('transfer_in','transfer_out') AND status='completed') AS total_transfers,
      (SELECT COUNT(*) FROM transactions WHERE status='completed') AS total_operations
")->fetch_assoc();

// ── Digital Summary (عمليات التطبيق فقط) ──
$appSummary        = null;
$appTransfersCount = 0;
$appInternalCount  = 0;
$appExternalCount  = 0;
$appInboundAmt     = 0;
$appOutboundAmt    = 0;

$tableCheck = $conn->query("SHOW TABLES LIKE 'app_users'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $appSummary = $conn->query("SELECT * FROM vw_app_summary")->fetch_assoc();

    // تحويلات نُفِّذت عبر التطبيق (جلسات)
    $appTransfersCount = (int)($appSummary['app_transfers'] ?? 0);

    // تحويلات داخلية بين عملاء البنك عبر التطبيق فقط
    $r = $conn->query("SELECT COUNT(*) AS c FROM internal_transfers WHERE status='completed' AND channel='app'")->fetch_assoc();
    $appInternalCount = (int)$r['c'];

    // تحويلات مع بنوك أخرى عبر التطبيق فقط
    $r = $conn->query("SELECT COUNT(*) AS c, SUM(CASE WHEN direction='inbound' THEN amount ELSE 0 END) AS amt_in, SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS amt_out FROM external_transfers WHERE status='completed' AND channel='app'")->fetch_assoc();
    $appExternalCount = (int)$r['c'];
    $appInboundAmt    = (float)$r['amt_in'];
    $appOutboundAmt   = (float)$r['amt_out'];
}

// ── Monthly new customers (last 6 months) ──
$months_data = []; $months_labels = [];
$res = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS cnt FROM customers WHERE is_active=1 GROUP BY mo ORDER BY mo DESC LIMIT 6");
while ($r = $res->fetch_assoc()) { $months_data[] = $r['cnt']; $months_labels[] = $r['mo']; }
$months_data = array_reverse($months_data); $months_labels = array_reverse($months_labels);

// ── مؤشرات الأداء الرئيسية الأربعة (كانت في صفحة kpis.php) ──
// نسبة تعثر القروض = القروض المتعثرة ÷ إجمالي القروض × 100
$defaultRate = $kpis['total_loans'] > 0 ? round($kpis['defaulted_loans'] / $kpis['total_loans'] * 100, 1) : 0;
// نسبة الشكاوى المفتوحة = الشكاوى (مفتوحة + قيد المعالجة) ÷ إجمالي الشكاوى × 100
$cmpTotal      = (int)$conn->query("SELECT COUNT(*) AS c FROM complaints")->fetch_assoc()['c'];
$complaintRate = $cmpTotal > 0 ? round($kpis['open_complaints'] / $cmpTotal * 100, 1) : 0;
// متوسط أيام معالجة الشكوى = متوسط (تاريخ الحل − تاريخ الفتح) للشكاوى المحلولة
$avgRes     = $conn->query("SELECT AVG(DATEDIFF(resolved_at,opened_at)) AS d FROM complaints WHERE resolved_at IS NOT NULL")->fetch_assoc();
$avgResDays = round($avgRes['d'] ?? 0, 1);
// معدل نمو العملاء = (عملاء الشهر الأخير − عملاء الشهر السابق) ÷ عملاء الشهر السابق × 100
$gd = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS cnt FROM customers WHERE is_active=1 GROUP BY mo ORDER BY mo DESC LIMIT 2")->fetch_all(MYSQLI_ASSOC);
$thisMonth  = $gd[0]['cnt'] ?? 0;
$lastMonth  = $gd[1]['cnt'] ?? 1;
$growthRate = round(($thisMonth - $lastMonth) / max($lastMonth, 1) * 100, 1);

// ── الإيداعات الشهرية (آخر 6 أشهر) ──
$depLabels = []; $depData = [];
$res = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, SUM(amount) AS total FROM transactions WHERE txn_type='deposit' AND status='completed' GROUP BY mo ORDER BY mo DESC LIMIT 6");
while ($r = $res->fetch_assoc()) { $depLabels[] = $r['mo']; $depData[] = (float)$r['total']; }
$depLabels = array_reverse($depLabels); $depData = array_reverse($depData);

open_layout('لوحة الإدارة العليا','dashboard');

function fmt($n) {
    if ($n >= 1000000) return number_format($n/1000000,1).'م';
    if ($n >= 1000)    return number_format($n/1000,1).'ألف';
    return number_format($n);
}
?>

<!-- ── القناة الرقمية — شريط علوي ── -->
<?php if ($appSummary): ?>
<div style="background:linear-gradient(135deg,var(--navy),var(--navy-mid));border-radius:var(--radius);padding:16px 22px;margin-bottom:20px;display:flex;flex-wrap:wrap;gap:20px;align-items:center">
  <div style="color:var(--gold);font-size:14px;font-weight:800;display:flex;align-items:center;gap:8px">
    <span>📱</span> ملخص القناة الرقمية
  </div>
  <?php
  $digitalStrip = [
    ['مستخدمو التطبيق',                  number_format($appSummary['total_app_users']),'👥'],
    ['نشط آخر 30 يوم',                  number_format($appSummary['active_last_30d']),'🟢'],
    ['عمليات التحويل عبر التطبيق',       number_format($appTransfersCount),'🔄'],
    ['تحويلات داخلية عبر التطبيق',       number_format($appInternalCount),'🏦'],
    ['تحويلات لبنوك أخرى عبر التطبيق',  number_format($appExternalCount),'🌐'],
    ['وارد خارجي عبر التطبيق',           fmt($appInboundAmt).' SDG','📥'],
    ['صادر خارجي عبر التطبيق',           fmt($appOutboundAmt).' SDG','📤'],
  ];
  foreach ($digitalStrip as [$label,$val,$icon]): ?>
  <div style="border-right:1px solid rgba(255,255,255,.1);padding-right:20px;flex:1;min-width:110px">
    <div style="color:rgba(255,255,255,.55);font-size:10px;margin-bottom:3px"><?= $label ?></div>
    <div style="color:var(--white);font-size:16px;font-weight:800"><?= $icon ?> <?= $val ?></div>
  </div>
  <?php endforeach; ?>
  <a href="digital.php" style="background:var(--gold);color:var(--navy);padding:8px 16px;border-radius:8px;font-size:12px;font-weight:800;text-decoration:none;white-space:nowrap;margin-right:auto">تفاصيل ←</a>
</div>
<?php endif; ?>

<!-- ── مؤشرات الأداء الرئيسية ── -->
<div class="section-label" style="font-weight:800;color:var(--navy);margin:4px 0 10px">📈 مؤشرات الأداء الرئيسية</div>
<div class="kpi-grid">
  <div class="kpi-card danger"><div class="kpi-icon">📉</div><div class="kpi-body"><div class="kpi-value"><?= $defaultRate ?>%</div><div class="kpi-label">نسبة تعثر القروض</div><div class="kpi-trend <?= $defaultRate > 15 ? 'down' : 'up' ?>"><?= $defaultRate > 15 ? '▲ مرتفعة' : '✔ ضمن الحد المقبول' ?></div></div></div>
  <div class="kpi-card warn"><div class="kpi-icon">📣</div><div class="kpi-body"><div class="kpi-value"><?= $complaintRate ?>%</div><div class="kpi-label">نسبة الشكاوى المفتوحة</div></div></div>
  <div class="kpi-card teal"><div class="kpi-icon">⏱️</div><div class="kpi-body"><div class="kpi-value"><?= $avgResDays ?></div><div class="kpi-label">متوسط أيام معالجة الشكوى</div></div></div>
  <div class="kpi-card <?= $growthRate >= 0 ? 'success' : 'danger' ?>"><div class="kpi-icon">📈</div><div class="kpi-body"><div class="kpi-value"><?= ($growthRate>=0?'+':'') . $growthRate ?>%</div><div class="kpi-label">معدل نمو العملاء</div></div></div>
</div>

<!-- ── الأرقام الأساسية ── -->
<div class="section-label" style="font-weight:800;color:var(--navy);margin:4px 0 10px">🏦 الأرقام الأساسية</div>
<div class="kpi-grid">
  <div class="kpi-card gold"><div class="kpi-icon">👥</div><div class="kpi-body"><div class="kpi-value"><?= number_format($kpis['total_customers']) ?></div><div class="kpi-label">إجمالي العملاء</div></div></div>
  <div class="kpi-card teal"><div class="kpi-icon">🏦</div><div class="kpi-body"><div class="kpi-value"><?= number_format($kpis['total_accounts']) ?></div><div class="kpi-label">إجمالي الحسابات</div></div></div>
  <div class="kpi-card navy"><div class="kpi-icon">💳</div><div class="kpi-body"><div class="kpi-value"><?= number_format($kpis['total_loans']) ?></div><div class="kpi-label">إجمالي القروض</div></div></div>
  <div class="kpi-card success"><div class="kpi-icon">💵</div><div class="kpi-body"><div class="kpi-value"><?= fmt($kpis['total_deposits']) ?></div><div class="kpi-label">إجمالي الإيداعات (SDG)</div></div></div>
</div>

<!-- ── Charts ── -->
<div class="grid-2">
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">📈</span> نمو العملاء الشهري</div></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="growthChart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">💵</span> الإيداعات حسب الشهر (SDG)</div></div>
    <div class="card-body"><div class="chart-container" style="height:220px"><canvas id="depChart"></canvas></div></div>
  </div>
</div>

<script>
new Chart(document.getElementById('growthChart').getContext('2d'),{
  type:'line',
  data:{labels:<?= json_encode($months_labels) ?>,datasets:[{label:'عملاء جدد',data:<?= json_encode($months_data) ?>,borderColor:'#7F8691',backgroundColor:'rgba(127,134,145,.14)',tension:0.4,fill:true,pointBackgroundColor:'#7F8691',pointRadius:5}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
new Chart(document.getElementById('depChart').getContext('2d'),{
  type:'bar',
  data:{labels:<?= json_encode($depLabels) ?>,datasets:[{label:'الإيداعات',data:<?= json_encode($depData) ?>,backgroundColor:'#C5A04A',borderRadius:6}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
</script>
<?php close_layout(); ?>
