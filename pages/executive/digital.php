<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

// ── 1. App Summary ──
$app = $conn->query("SELECT * FROM vw_app_summary")->fetch_assoc();

// ── 2. Channel Comparison ──
$channels = [];
$res = $conn->query("SELECT * FROM vw_channel_comparison");
while ($r = $res->fetch_assoc()) $channels[] = $r;

// ── 3. App Monthly Trend (last 6 months) ──
$appMonths = []; $appSessions = []; $appTransfers = [];
$res = $conn->query("SELECT * FROM vw_app_monthly ORDER BY month DESC LIMIT 6");
while ($r = $res->fetch_assoc()) {
    $appMonths[]    = $r['month'];
    $appSessions[]  = (int)$r['sessions'];
    $appTransfers[] = (int)$r['transfers'];
}
$appMonths = array_reverse($appMonths);
$appSessions = array_reverse($appSessions);
$appTransfers = array_reverse($appTransfers);

// ── 4. App Users by Branch ──
// ── 5. Internal Transfer Summary ──
$intSummary = [];
$res = $conn->query("SELECT * FROM vw_internal_transfer_summary");
while ($r = $res->fetch_assoc()) $intSummary[] = $r;

// ── 6. Inter-Branch Flow ──
$branchFlow = [];
$res = $conn->query("SELECT * FROM vw_inter_branch_flow ORDER BY ABS(net_flow) DESC");
while ($r = $res->fetch_assoc()) $branchFlow[] = $r;

// ── 7. External Bank Summary ──
$extBanks = [];
// تجميع حسب البنك فقط (وليس حسب رقم الحساب) حتى يظهر كل بنك في صف واحد
$res = $conn->query("SELECT external_bank,
    SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END) AS total_inbound,
    SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS total_outbound,
    SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END)
      - SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS net_balance,
    COUNT(CASE WHEN direction='inbound'  THEN 1 END) AS inbound_count,
    COUNT(CASE WHEN direction='outbound' THEN 1 END) AS outbound_count
  FROM external_transfers WHERE status='completed'
  GROUP BY external_bank
  ORDER BY (total_inbound + total_outbound) DESC");
while ($r = $res->fetch_assoc()) $extBanks[] = $r;

// ── 8. External Flow Monthly ──
$extMonths = []; $extInbound = []; $extOutbound = [];
$res = $conn->query("SELECT * FROM vw_external_flow_monthly ORDER BY month DESC LIMIT 6");
while ($r = $res->fetch_assoc()) {
    $extMonths[]   = $r['month'];
    $extInbound[]  = (float)$r['inbound'];
    $extOutbound[] = (float)$r['outbound'];
}
$extMonths   = array_reverse($extMonths);
$extInbound  = array_reverse($extInbound);
$extOutbound = array_reverse($extOutbound);

// ── 9. Total external flow ──
$extTotals = $conn->query("
    SELECT
        SUM(CASE WHEN direction='inbound'  THEN amount ELSE 0 END) AS total_in,
        SUM(CASE WHEN direction='outbound' THEN amount ELSE 0 END) AS total_out,
        COUNT(CASE WHEN direction='inbound'  THEN 1 END) AS count_in,
        COUNT(CASE WHEN direction='outbound' THEN 1 END) AS count_out
    FROM external_transfers WHERE status='completed'
")->fetch_assoc();

$netFlow = ($extTotals['total_in'] ?? 0) - ($extTotals['total_out'] ?? 0);

// ── 10. Internal total ──
$intTotals = $conn->query("
    SELECT
        COUNT(*) AS total_count,
        SUM(amount) AS total_amount,
        SUM(transfer_type='same_branch') AS same_branch_count,
        SUM(transfer_type='inter_branch') AS inter_branch_count,
        SUM(channel='app') AS app_channel,
        SUM(channel='branch') AS branch_channel
    FROM internal_transfers WHERE status='completed'
")->fetch_assoc();

open_layout('التحليل الرقمي وحركة الأرصدة', 'digital');

function fmtN($n) {
    if ($n >= 1000000) return number_format($n/1000000,2).' م';
    if ($n >= 1000)    return number_format($n/1000,1).' ألف';
    return number_format($n);
}
?>

<!-- ══════════════════════════════════════════
     القسم الأول: تطبيق الجوال
══════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
    <div style="width:4px;height:28px;background:var(--gold);border-radius:2px"></div>
    <h2 style="font-size:17px;font-weight:800;color:var(--navy)">📱 تطبيق الجوال — الإحصائيات والنشاط</h2>
</div>

<div class="kpi-grid">
    <?php
    render_kpi_card('📱', number_format($app['total_app_users'] ?? 0), 'إجمالي مستخدمي التطبيق', 'gold');
    render_kpi_card('✅', number_format($app['active_app_users'] ?? 0), 'مستخدمون نشطون', 'success');
    render_kpi_card('🕐', number_format($app['active_last_30d'] ?? 0), 'نشطون آخر 30 يوم', 'teal');
    render_kpi_card('🔄', number_format($app['total_sessions'] ?? 0), 'إجمالي الجلسات', 'navy');
    render_kpi_card('💸', number_format($app['app_transfers'] ?? 0), 'تحويلات عبر التطبيق', 'teal');
    render_kpi_card('💰', fmtN($app['app_transfer_volume'] ?? 0), 'حجم التحويلات (SDG)', 'success');
    ?>
</div>

<div class="grid-2">
    <!-- App Monthly Trend -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="icon">📈</span> نشاط التطبيق الشهري</div>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height:220px">
                <canvas id="appMonthChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Device Type + Channel Comparison -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="icon">📊</span> توزيع الأجهزة</div>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height:130px;margin-bottom:16px">
                <canvas id="deviceChart"></canvas>
            </div>
            <div style="border-top:1px solid var(--gray-100);padding-top:14px">
                <div style="font-size:12px;font-weight:700;color:var(--gray-700);margin-bottom:10px">مقارنة القنوات — عدد العمليات</div>
                <?php
                $chTotal = array_sum(array_column($channels,'operation_count')) ?: 1;
                foreach ($channels as $ch):
                    $pct = round($ch['operation_count']/$chTotal*100);
                    $isApp = str_contains($ch['channel'], 'تطبيق');
                    render_bar_row($ch['channel'], $pct, number_format($ch['operation_count']), $isApp ? 'var(--teal)' : 'var(--gold)');
                endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     القسم الثاني: الحركة الداخلية
══════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:10px;margin:28px 0 16px">
    <div style="width:4px;height:28px;background:var(--teal);border-radius:2px"></div>
    <h2 style="font-size:17px;font-weight:800;color:var(--navy)">🔄 الحركة الداخلية بين الحسابات</h2>
</div>

<div class="kpi-grid">
    <?php
    render_kpi_card('🔄', number_format($intTotals['total_count'] ?? 0), 'إجمالي التحويلات الداخلية', 'teal');
    render_kpi_card('💰', fmtN($intTotals['total_amount'] ?? 0), 'إجمالي الحجم (SDG)', 'gold');
    render_kpi_card('🏢', number_format($intTotals['same_branch_count'] ?? 0), 'داخل نفس الفرع', 'navy');
    render_kpi_card('🏛️', number_format($intTotals['inter_branch_count'] ?? 0), 'بين الفروع المختلفة', 'success');
    ?>
</div>

<div class="grid-2">
    <!-- Channel breakdown -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="icon">📊</span> قناة التحويل الداخلي</div>
        </div>
        <div class="card-body">
            <div class="chart-container" style="height:220px">
                <canvas id="intChannelChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Inter-branch flow table -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><span class="icon">🔀</span> صافي تدفق الأموال بين الفروع</div>
        </div>
        <div class="card-body" style="padding:0">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>الفرع</th><th>مُرسَل</th><th>مستقبَل</th><th>الصافي</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($branchFlow as $b):
                        $net = $b['net_flow'];
                        $netClass = $net > 0 ? 'success' : ($net < 0 ? 'danger' : 'gray');
                        $netIcon  = $net > 0 ? '▲' : ($net < 0 ? '▼' : '—');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($b['branch_name']) ?></strong></td>
                        <td style="color:var(--danger)"><?= fmtN($b['sent']) ?></td>
                        <td style="color:var(--success)"><?= fmtN($b['received']) ?></td>
                        <td>
                            <span class="badge badge-<?= $netClass ?>">
                                <?= $netIcon ?> <?= fmtN(abs($net)) ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════
     القسم الثالث: التحويلات مع البنوك الأخرى
══════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:10px;margin:28px 0 16px">
    <div style="width:4px;height:28px;background:var(--danger);border-radius:2px"></div>
    <h2 style="font-size:17px;font-weight:800;color:var(--navy)">🏦 حركة الأرصدة مع البنوك الأخرى</h2>
</div>

<div class="kpi-grid">
    <?php
    render_kpi_card('📥', fmtN($extTotals['total_in'] ?? 0), 'إجمالي الوارد (SDG)', 'success',
        ['dir'=>'up', 'text'=> number_format($extTotals['count_in'] ?? 0).' تحويل']);
    render_kpi_card('📤', fmtN($extTotals['total_out'] ?? 0), 'إجمالي الصادر (SDG)', 'danger',
        ['dir'=>'down', 'text'=> number_format($extTotals['count_out'] ?? 0).' تحويل']);
    render_kpi_card($netFlow >= 0 ? '📈' : '📉', ($netFlow>=0?'+':'').fmtN($netFlow), 'صافي التدفق (SDG)',
        $netFlow >= 0 ? 'success' : 'danger',
        ['dir'=> $netFlow >= 0 ? 'up' : 'down', 'text'=> $netFlow >= 0 ? '▲ يستقبل أكثر مما يرسل' : '▼ يرسل أكثر مما يستقبل']);
    render_kpi_card('🏦', count($extBanks), 'بنوك شريكة', 'navy');
    ?>
</div>

<div class="card">
    <div class="card-header">
        <div class="card-title"><span class="icon">📅</span> التدفق الشهري مع البنوك الأخرى</div>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead><tr><th>الشهر</th><th>الوارد (SDG)</th><th>الصادر (SDG)</th><th>الصافي</th></tr></thead>
                <tbody>
                <?php foreach ($extMonths as $i => $m):
                    $net = ($extInbound[$i] ?? 0) - ($extOutbound[$i] ?? 0);
                    $cls = $net > 0 ? 'success' : ($net < 0 ? 'danger' : 'gray');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($m) ?></strong></td>
                    <td style="color:var(--success);font-weight:700"><?= number_format($extInbound[$i] ?? 0) ?></td>
                    <td style="color:var(--danger);font-weight:700"><?= number_format($extOutbound[$i] ?? 0) ?></td>
                    <td><span class="badge badge-<?= $cls ?>"><?= ($net>=0?'+':'') . number_format($net) ?></span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- External banks detail table -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><span class="icon">🏦</span> تفاصيل الحركة مع كل بنك</div>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>البنك</th>
                        <th>عدد الوارد</th>
                        <th>حجم الوارد (SDG)</th>
                        <th>عدد الصادر</th>
                        <th>حجم الصادر (SDG)</th>
                        <th>صافي التدفق</th>
                        <th>الوضع</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($extBanks as $bank):
                    $net = $bank['net_balance'];
                    $cls = $net > 0 ? 'success' : ($net < 0 ? 'danger' : 'gray');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($bank['external_bank']) ?></strong></td>
                    <td><?= $bank['inbound_count'] ?></td>
                    <td style="color:var(--success);font-weight:700"><?= number_format($bank['total_inbound']) ?></td>
                    <td><?= $bank['outbound_count'] ?></td>
                    <td style="color:var(--danger);font-weight:700"><?= number_format($bank['total_outbound']) ?></td>
                    <td><span class="badge badge-<?= $cls ?>"><?= ($net>=0?'+':'') . number_format($net) ?></span></td>
                    <td>
                        <?php if ($net > 0): ?>
                            <span class="badge badge-success">📥 مستقبِل صافي</span>
                        <?php elseif ($net < 0): ?>
                            <span class="badge badge-danger">📤 مُرسِل صافي</span>
                        <?php else: ?>
                            <span class="badge badge-gray">متوازن</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// ── App Monthly Chart ──
new Chart(document.getElementById('appMonthChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: <?= json_encode($appMonths) ?>,
        datasets: [
            { label: 'الجلسات', data: <?= json_encode($appSessions) ?>, borderColor: '#7F8691', backgroundColor: 'rgba(127,134,145,.14)', tension: 0.4, fill: true, yAxisID: 'y' },
            { label: 'التحويلات', data: <?= json_encode($appTransfers) ?>, borderColor: '#C5A04A', backgroundColor: 'transparent', tension: 0.4, borderDash:[4,3], yAxisID: 'y' }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { font: { family: 'Cairo', size: 11 } } } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true } }
    }
});

// ── Device Chart ──
new Chart(document.getElementById('deviceChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Android', 'iOS', 'Web'],
        datasets: [{ data: [<?= $app['android_users']??0 ?>, <?= $app['ios_users']??0 ?>, <?= $app['web_users']??0 ?>], backgroundColor: ['#1A7A4A','#1E1E22','#7F8691'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { family: 'Cairo', size: 11 } } } } }
});

// ── Internal Channel Chart ──
<?php
$chData = []; $chLabels = [];
foreach ($intSummary as $s) {
    $chLabels[] = ($s['transfer_type']==='same_branch'?'داخل الفرع':'بين الفروع') . ' — ' . $s['channel'];
    $chData[]   = (float)$s['total_amount'];
}
?>
new Chart(document.getElementById('intChannelChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chLabels) ?>,
        datasets: [{ label: 'الحجم (SDG)', data: <?= json_encode($chData) ?>, backgroundColor: ['#1E1E22BB','#7F8691BB','#C5A04ABB','#1A7A4ABB','#C0392BBB','#D9822BBB'] }]
    },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true } } }
});


</script>

<?php close_layout(); ?>
