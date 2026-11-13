<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

// ── نقطة مرجعية "اليوم" = آخر معاملة مسجّلة فعلياً في البيانات (بدل NOW() الحقيقي) ──
// هذا يضمن أن فترات الأسبوع/الشهر/الربع/النصف تعكس بيانات حقيقية
// حتى لو كان تاريخ السيرفر الفعلي متأخراً عن آخر معاملة مولّدة
$refRow = $conn->query("SELECT MAX(created_at) AS d FROM transactions WHERE status='completed'")->fetch_assoc();
$refDate = $refRow['d'] ?? date('Y-m-d H:i:s');
$ref = $conn->real_escape_string($refDate);

// Withdrawal & Deposit totals (secondary breakdown) — نسبة إلى تاريخ آخر معاملة
$totals=$conn->query("SELECT
  SUM(CASE WHEN txn_type='withdrawal' AND DATE(created_at)=DATE('$ref') THEN amount END) AS today_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND YEARWEEK(created_at,1)=YEARWEEK('$ref',1) THEN amount END) AS week_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND MONTH(created_at)=MONTH('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS month_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND QUARTER(created_at)=QUARTER('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS quarter_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND CEIL(MONTH(created_at)/6)=CEIL(MONTH('$ref')/6) AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS half_wd,
  SUM(CASE WHEN txn_type='withdrawal' AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS year_wd,
  SUM(CASE WHEN txn_type='deposit' AND DATE(created_at)=DATE('$ref') THEN amount END) AS today_dep,
  SUM(CASE WHEN txn_type='deposit' AND YEARWEEK(created_at,1)=YEARWEEK('$ref',1) THEN amount END) AS week_dep,
  SUM(CASE WHEN txn_type='deposit' AND MONTH(created_at)=MONTH('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS month_dep,
  SUM(CASE WHEN txn_type='deposit' AND QUARTER(created_at)=QUARTER('$ref') AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS quarter_dep,
  SUM(CASE WHEN txn_type='deposit' AND CEIL(MONTH(created_at)/6)=CEIL(MONTH('$ref')/6) AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS half_dep,
  SUM(CASE WHEN txn_type='deposit' AND YEAR(created_at)=YEAR('$ref') THEN amount END) AS year_dep,
  SUM(CASE WHEN txn_type='deposit'    THEN amount END) AS total_dep,
  SUM(CASE WHEN txn_type='withdrawal' THEN amount END) AS total_wd
FROM transactions WHERE status='completed'")->fetch_assoc();

// ── سحوبات وإيرادات فترة زمنية مخصصة (تحديد يدوي من - إلى، كصفحة التقارير) ──
// الافتراضي: آخر 30 يوماً من تاريخ آخر معاملة فعلية بدل تاريخ اليوم الحقيقي
$rangeFrom = $_GET['from'] ?? date('Y-m-d', strtotime($refDate.' -30 days'));
$rangeTo   = $_GET['to']   ?? date('Y-m-d', strtotime($refDate));
$rangeGenerated = isset($_GET['from']) || isset($_GET['to']);
$rangeTotals = $conn->query("SELECT
  SUM(CASE WHEN txn_type='withdrawal' THEN amount ELSE 0 END) AS wd_amt,
  SUM(CASE WHEN txn_type='withdrawal' THEN 1 ELSE 0 END)      AS wd_cnt,
  SUM(CASE WHEN txn_type='deposit'    THEN amount ELSE 0 END) AS dep_amt,
  SUM(CASE WHEN txn_type='deposit'    THEN 1 ELSE 0 END)      AS dep_cnt
FROM transactions
WHERE status='completed' AND DATE(created_at) BETWEEN '".$conn->real_escape_string($rangeFrom)."' AND '".$conn->real_escape_string($rangeTo)."'")->fetch_assoc();

// Monthly withdrawals last 6m
$wdMonths=[];$wdAmts=[];
$res=$conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS m,SUM(amount) AS amt FROM transactions WHERE txn_type='withdrawal' AND status='completed' GROUP BY m ORDER BY m DESC LIMIT 6");
while($r=$res->fetch_assoc()){$wdMonths[]=$r['m'];$wdAmts[]=$r['amt'];}
$wdMonths=array_reverse($wdMonths);$wdAmts=array_reverse($wdAmts);

// Monthly revenue (deposits) last 6m
$revMonths=[];$revAmts=[];
$res=$conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS m,SUM(amount) AS amt FROM transactions WHERE txn_type='deposit' AND status='completed' GROUP BY m ORDER BY m DESC LIMIT 6");
while($r=$res->fetch_assoc()){$revMonths[]=$r['m'];$revAmts[]=$r['amt'];}
$revMonths=array_reverse($revMonths);$revAmts=array_reverse($revAmts);

// Operation type counts
$txnTypes=$conn->query("SELECT txn_type,sub_type,COUNT(*) AS cnt,SUM(amount) AS vol FROM transactions WHERE status='completed' GROUP BY txn_type,sub_type ORDER BY txn_type,cnt DESC")->fetch_all(MYSQLI_ASSOC);

open_layout('التحليل المالي','financial');
function fmtN($n){if($n>=1000000)return number_format($n/1000000,1).'م';if($n>=1000)return number_format($n/1000,1).'ألف';return number_format($n);}
?>

<!-- الإيراد والصادر حسب الفترة الزمنية -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">💰</span> الإيراد والصادر حسب الفترة الزمنية</div>
  </div>
  <div class="card-body" style="padding:0">
    <div style="font-size:11px;color:var(--gray-500);padding:14px 18px 0">
      * الفترات محسوبة نسبة لتاريخ آخر معاملة مسجّلة في النظام: <strong style="color:var(--navy)"><?=htmlspecialchars(date('Y-m-d', strtotime($refDate)))?></strong>
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
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['today_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['week_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['month_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['quarter_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['half_dep']??0)?></td>
            <td style="color:var(--success);font-weight:800"><?=fmtN($totals['year_dep']??0)?></td>
          </tr>
          <tr>
            <td><span class="badge badge-danger">📤 الصادر</span></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['today_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['week_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['month_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['quarter_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['half_wd']??0)?></td>
            <td style="color:var(--danger);font-weight:800"><?=fmtN($totals['year_wd']??0)?></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div style="text-align:center;padding:18px">
      <?php $ratio=$totals['total_dep']>0?round($totals['total_wd']/$totals['total_dep']*100,1):0; ?>
      <div style="display:inline-block;padding:14px 32px;background:var(--gold-pale);border-radius:var(--radius)">
        <div style="font-size:22px;font-weight:900;color:var(--gold)"><?=$ratio?>%</div>
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

<!-- الإيداعات مقابل السحوبات (شهرياً) -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">📊</span> الإيداعات مقابل السحوبات شهرياً</div></div>
  <div class="card-body"><div class="chart-container" style="height:240px"><canvas id="flowChart"></canvas></div></div>
</div>

<!-- Operation Type Breakdown -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">🗂️</span> تفصيل عدد العمليات حسب النوع</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>نوع العملية</th><th>طريقة التنفيذ</th><th>العدد</th><th>الحجم (SDG)</th><th>النسبة</th></tr></thead>
        <tbody>
        <?php
        $totalOps=array_sum(array_column($txnTypes,'cnt'))?:1;
        $typeMap=['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];
        $subMap=['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','web_transfer'=>'ويب','internal_transfer'=>'داخلي','swift'=>'SWIFT','rtgs'=>'RTGS','bban'=>'BBAN','atm'=>'صرف آلي','pos'=>'نقطة بيع'];
        foreach($txnTypes as $t):
            $pct=round($t['cnt']/$totalOps*100,1);
        ?>
        <tr>
          <td><span class="badge badge-<?=$t['txn_type']==='deposit'?'success':($t['txn_type']==='withdrawal'?'danger':'teal')?>"><?=$typeMap[$t['txn_type']]??$t['txn_type']?></span></td>
          <td><?=$subMap[$t['sub_type']]??$t['sub_type']?></td>
          <td><strong><?=number_format($t['cnt'])?></strong></td>
          <td><?=fmtN($t['vol'])?></td>
          <td>
            <div style="display:flex;align-items:center;gap:6px">
              <div class="progress-track" style="flex:1;height:7px"><div class="progress-fill fill-teal" style="width:<?=min($pct*3,100)?>%"></div></div>
              <span style="font-size:11px;color:var(--gray-500)"><?=$pct?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
<?php
$allM = array_values(array_unique(array_merge($revMonths,$wdMonths))); sort($allM);
$revMap = array_combine($revMonths,$revAmts) ?: []; $wdMap = array_combine($wdMonths,$wdAmts) ?: [];
$dSeries=[];$wSeries=[];
foreach($allM as $m){ $dSeries[]=(float)($revMap[$m]??0); $wSeries[]=(float)($wdMap[$m]??0); }
?>
new Chart(document.getElementById('flowChart').getContext('2d'),{
  type:'bar',
  data:{labels:<?=json_encode($allM)?>,datasets:[
    {label:'الإيداعات',data:<?=json_encode($dSeries)?>,backgroundColor:'#1A7A4ABB',borderColor:'#1A7A4A',borderWidth:1},
    {label:'السحوبات',data:<?=json_encode($wSeries)?>,backgroundColor:'#C0392BBB',borderColor:'#C0392B',borderWidth:1}
  ]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{family:'Cairo',size:11}}}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
</script>
<?php close_layout(); ?>
