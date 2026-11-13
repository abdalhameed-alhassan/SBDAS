<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('branch_manager','branch_employee','admin');

$user  = current_user();
$bid   = (int)$user['branch_id'];
$bInfo = $conn->query("SELECT * FROM branches WHERE branch_id=$bid")->fetch_assoc();

// ── مستخدمو التطبيق في الفرع ──
$appStats = $conn->query("
    SELECT
      COUNT(DISTINCT au.app_user_id) AS total_app,
      COUNT(DISTINCT CASE WHEN au.is_active=1 THEN au.app_user_id END) AS active_app,
      COUNT(DISTINCT CASE WHEN au.device_type='android' THEN au.app_user_id END) AS android,
      COUNT(DISTINCT CASE WHEN au.device_type='ios'     THEN au.app_user_id END) AS ios,
      COUNT(DISTINCT CASE WHEN au.device_type='web'     THEN au.app_user_id END) AS web,
      COUNT(DISTINCT CASE WHEN au.last_login >= DATE_SUB(NOW(),INTERVAL 30 DAY) THEN au.app_user_id END) AS last30
    FROM app_users au
    JOIN customers c ON c.customer_id = au.customer_id
    WHERE c.branch_id = $bid
")->fetch_assoc();

// ── إجمالي عملاء الفرع ──
$totalCust = (int)$conn->query("SELECT COUNT(*) AS c FROM customers WHERE branch_id=$bid AND is_active=1")->fetch_assoc()['c'];
$adoptionRate = $totalCust > 0 ? round(($appStats['total_app']??0) / $totalCust * 100) : 0;

// ── جلسات التطبيق لعملاء هذا الفرع ──
$sessionStats = $conn->query("
    SELECT
      COUNT(*) AS total_sessions,
      COUNT(CASE WHEN s.action_type='transfer'        THEN 1 END) AS transfers,
      COUNT(CASE WHEN s.action_type='payment'         THEN 1 END) AS payments,
      COUNT(CASE WHEN s.action_type='view_balance'    THEN 1 END) AS balance_views,
      COUNT(CASE WHEN s.action_type='deposit_request' THEN 1 END) AS deposit_requests,
      COUNT(CASE WHEN s.action_type='loan_inquiry'    THEN 1 END) AS loan_inquiries,
      COUNT(CASE WHEN s.action_type='complaint'       THEN 1 END) AS complaints,
      COALESCE(SUM(CASE WHEN s.action_type='transfer' THEN s.amount END),0) AS transfer_vol,
      COALESCE(SUM(CASE WHEN s.action_type='payment'  THEN s.amount END),0) AS payment_vol
    FROM app_sessions s
    JOIN app_users au ON au.app_user_id = s.app_user_id
    JOIN customers c  ON c.customer_id  = au.customer_id
    WHERE c.branch_id = $bid
")->fetch_assoc();

// ── الحركة الشهرية عبر التطبيق ──
$appMonthly = []; $appMLabels = []; $appMSessions = []; $appMTransfers = [];
$res = $conn->query("
    SELECT DATE_FORMAT(s.created_at,'%Y-%m') AS mo,
           COUNT(*) AS sessions,
           COUNT(CASE WHEN s.action_type='transfer' THEN 1 END) AS transfers
    FROM app_sessions s
    JOIN app_users au ON au.app_user_id = s.app_user_id
    JOIN customers c  ON c.customer_id  = au.customer_id
    WHERE c.branch_id = $bid
    GROUP BY mo ORDER BY mo DESC LIMIT 6
");
while ($r = $res->fetch_assoc()) {
    $appMLabels[]    = $r['mo'];
    $appMSessions[]  = (int)$r['sessions'];
    $appMTransfers[] = (int)$r['transfers'];
}
$appMLabels    = array_reverse($appMLabels);
$appMSessions  = array_reverse($appMSessions);
$appMTransfers = array_reverse($appMTransfers);

// ── مقارنة القنوات: تطبيق vs فرع ──
$appOps    = (int)($sessionStats['transfers']??0) + (int)($sessionStats['payments']??0) + (int)($sessionStats['deposit_requests']??0);
$branchOps = (int)$conn->query("SELECT COUNT(*) AS c FROM transactions WHERE branch_id=$bid AND status='completed' AND channel='branch'")->fetch_assoc()['c'];
$atmOps    = (int)$conn->query("SELECT COUNT(*) AS c FROM transactions WHERE branch_id=$bid AND status='completed' AND channel='atm'")->fetch_assoc()['c'];
$totalOps  = $appOps + $branchOps + $atmOps ?: 1;

// ── تحويلات داخلية عبر التطبيق من/إلى هذا الفرع ──
$intAppStats = $conn->query("
    SELECT COUNT(*) AS cnt, SUM(amount) AS vol
    FROM internal_transfers
    WHERE (from_branch_id=$bid OR to_branch_id=$bid)
      AND channel='app' AND status='completed'
")->fetch_assoc();

// ── تحويلات مع بنوك أخرى عبر التطبيق ──
$extAppStats = $conn->query("
    SELECT COUNT(*) AS cnt, SUM(amount) AS vol,
           SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END) AS amt_in,
           SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS amt_out
    FROM external_transfers
    WHERE branch_id=$bid AND channel='app' AND status='completed'
")->fetch_assoc();

// ── عملاء التطبيق تفصيلي ──
$appCustomers = [];
$res = $conn->query("
    SELECT (SELECT MIN(x.account_id) FROM accounts x WHERE x.customer_id=au.customer_id) AS account_id, au.device_type, au.registered_at, au.last_login, au.is_active,
           COUNT(s.session_id) AS sessions,
           COUNT(CASE WHEN s.action_type='transfer' THEN 1 END) AS transfers
    FROM app_users au
    JOIN customers c ON c.customer_id = au.customer_id
    LEFT JOIN app_sessions s ON s.app_user_id = au.app_user_id
    WHERE c.branch_id = $bid
    GROUP BY au.app_user_id, au.customer_id, au.device_type, au.registered_at, au.last_login, au.is_active
    ORDER BY sessions DESC
");
while ($r = $res->fetch_assoc()) $appCustomers[] = $r;

open_layout('التحليل الرقمي — '.htmlspecialchars($bInfo['branch_name']??''), 'digital');

function fmtN($n){
    if($n>=1000000) return number_format($n/1000000,2).'م';
    if($n>=1000)    return number_format($n/1000,1).'ألف';
    return number_format($n);
}
?>

<!-- KPIs التطبيق -->
<div class="kpi-grid">
  <div class="kpi-card gold">
    <div class="kpi-icon">📱</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($appStats['total_app']??0) ?></div>
      <div class="kpi-label">مستخدمو التطبيق</div>
      <div class="kpi-trend up"><?= $adoptionRate ?>% من عملاء الفرع</div>
    </div>
  </div>
  <div class="kpi-card success">
    <div class="kpi-icon">🟢</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($appStats['active_app']??0) ?></div>
      <div class="kpi-label">مستخدمون نشطون</div>
      <div class="kpi-trend up"><?= number_format($appStats['last30']??0) ?> نشط آخر 30 يوم</div>
    </div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-icon">🔄</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($sessionStats['transfers']??0) ?></div>
      <div class="kpi-label">تحويلات عبر التطبيق</div>
      <div class="kpi-trend up"><?= fmtN($sessionStats['transfer_vol']??0) ?> SDG</div>
    </div>
  </div>
  <div class="kpi-card navy">
    <div class="kpi-icon">📊</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($sessionStats['total_sessions']??0) ?></div>
      <div class="kpi-label">إجمالي الجلسات</div>
    </div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-icon">🏦</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($intAppStats['cnt']??0) ?></div>
      <div class="kpi-label">تحويلات داخلية عبر التطبيق</div>
      <div class="kpi-trend up"><?= fmtN($intAppStats['vol']??0) ?> SDG</div>
    </div>
  </div>
  <div class="kpi-card <?= ($extAppStats['amt_in']??0) >= ($extAppStats['amt_out']??0) ? 'success' : 'danger' ?>">
    <div class="kpi-icon">🌐</div>
    <div class="kpi-body">
      <div class="kpi-value"><?= number_format($extAppStats['cnt']??0) ?></div>
      <div class="kpi-label">تحويلات خارجية عبر التطبيق</div>
      <div class="kpi-trend up">📥 <?= fmtN($extAppStats['amt_in']??0) ?> | 📤 <?= fmtN($extAppStats['amt_out']??0) ?></div>
    </div>
  </div>
</div>

<div class="grid-2">
  <!-- الحركة الشهرية -->
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">📈</span> نشاط التطبيق الشهري</div></div>
    <div class="card-body">
      <div class="chart-container" style="height:220px">
        <canvas id="appMonthChart"></canvas>
      </div>
    </div>
  </div>

  <!-- توزيع الأجهزة + مقارنة القنوات -->
  <div class="card">
    <div class="card-header"><div class="card-title"><span class="icon">📱</span> توزيع الأجهزة ومقارنة القنوات</div></div>
    <div class="card-body">
      <div class="chart-container" style="height:120px;margin-bottom:16px">
        <canvas id="deviceChart"></canvas>
      </div>
      <div style="border-top:1px solid var(--gray-100);padding-top:12px">
        <div style="font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:8px">مقارنة القنوات — عدد العمليات</div>
        <?php
        $channels = [
          ['التطبيق', $appOps,    'var(--teal)'],
          ['الفرع',   $branchOps, 'var(--navy)'],
          ['ATM',     $atmOps,    'var(--gold)'],
        ];
        foreach ($channels as [$cLabel, $cVal, $cColor]):
          $pct = round($cVal/$totalOps*100);
        ?>
        <div class="branch-bar-row" style="margin-bottom:6px">
          <div class="branch-bar-label" style="width:80px;font-size:12px"><?= $cLabel ?></div>
          <div class="branch-bar-track" style="height:18px">
            <div class="branch-bar-fill" style="width:<?= $pct ?>%;background:<?= $cColor ?>"><?= $cVal ?></div>
          </div>
          <div class="branch-bar-val"><?= $pct ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- تفصيل أنواع الجلسات -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">🗂️</span> تفصيل أنواع الجلسات عبر التطبيق</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>نوع الجلسة</th><th>العدد</th><th>الحجم (SDG)</th><th>النسبة</th></tr></thead>
        <tbody>
        <?php
        $sessionTypes = [
          ['تحويل مالي',         $sessionStats['transfers']??0,       $sessionStats['transfer_vol']??0],
          ['دفع فاتورة',         $sessionStats['payments']??0,        $sessionStats['payment_vol']??0],
          ['عرض الرصيد',        $sessionStats['balance_views']??0,   0],
          ['طلب إيداع',          $sessionStats['deposit_requests']??0,0],
          ['استفسار قرض',       $sessionStats['loan_inquiries']??0,  0],
          ['تسجيل شكوى',        $sessionStats['complaints']??0,      0],
        ];
        $totalSessions = $sessionStats['total_sessions'] ?: 1;
        foreach ($sessionTypes as [$sLabel,$sCnt,$sVol]):
          $sPct = round($sCnt/$totalSessions*100,1);
        ?>
        <tr>
          <td><?= $sLabel ?></td>
          <td><strong><?= number_format($sCnt) ?></strong></td>
          <td><?= $sVol > 0 ? fmtN($sVol) : '—' ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <div class="progress-track" style="flex:1;height:7px">
                <div class="progress-fill fill-teal" style="width:<?= min($sPct*3,100) ?>%"></div>
              </div>
              <span style="font-size:11px;color:var(--gray-500)"><?= $sPct ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- قائمة عملاء التطبيق -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">👥</span> عملاء التطبيق في الفرع</div>
    <span class="badge badge-teal"><?= count($appCustomers) ?> عميل</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>رقم الحساب</th><th>الجهاز</th><th>تاريخ التسجيل</th><th>آخر دخول</th><th>الجلسات</th><th>التحويلات</th><th>الحالة</th></tr></thead>
        <tbody>
        <?php foreach ($appCustomers as $ac):
          $daysSince = $ac['last_login'] ? floor((time()-strtotime($ac['last_login']))/86400) : 999;
          $actClass  = $daysSince <= 7 ? 'success' : ($daysSince <= 30 ? 'teal' : ($daysSince <= 90 ? 'warn' : 'danger'));
        ?>
        <tr>
          <td dir="ltr"><strong><?= $ac['account_id'] ? (int)$ac['account_id'] : '—' ?></strong></td>
          <td><?= $ac['device_type']==='android'?'🤖 Android':($ac['device_type']==='ios'?'🍎 iOS':'🌐 Web') ?></td>
          <td style="font-size:12px;color:var(--gray-500)"><?= date('Y-m-d',strtotime($ac['registered_at'])) ?></td>
          <td><span class="badge badge-<?= $actClass ?>"><?= $ac['last_login'] ? date('Y-m-d',strtotime($ac['last_login'])) : '—' ?></span></td>
          <td><?= number_format($ac['sessions']) ?></td>
          <td><?= number_format($ac['transfers']) ?></td>
          <td><?= $ac['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-danger">معطّل</span>' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$appCustomers): ?>
        <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--gray-500)">لا يوجد مستخدمون للتطبيق في هذا الفرع بعد</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('appMonthChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($appMLabels) ?>,
        datasets: [
            { label: 'الجلسات',   data: <?= json_encode($appMSessions) ?>,  borderColor: '#7F8691', backgroundColor: 'rgba(127,134,145,.14)', tension: 0.4, fill: true, pointRadius: 4 },
            { label: 'التحويلات', data: <?= json_encode($appMTransfers) ?>, borderColor: '#C5A04A', backgroundColor: 'transparent',         tension: 0.4, borderDash:[4,3], pointRadius: 4 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { family: 'Cairo', size: 11 } } } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('deviceChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Android','iOS','Web'],
        datasets: [{ data: [<?= $appStats['android']??0 ?>,<?= $appStats['ios']??0 ?>,<?= $appStats['web']??0 ?>], backgroundColor: ['#1A7A4A','#1E1E22','#7F8691'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { family: 'Cairo', size: 10 } } } } }
});
</script>
<?php close_layout(); ?>
