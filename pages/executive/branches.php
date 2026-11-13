<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

$branches = [];
$res = $conn->query("SELECT * FROM vw_branch_summary ORDER BY total_deposits DESC");
while ($r = $res->fetch_assoc()) $branches[] = $r;

// Breakdown views
$wdBreak  = []; $res = $conn->query("SELECT * FROM vw_branch_withdrawal_breakdown");
while ($r=$res->fetch_assoc()) $wdBreak[$r['branch_id']] = $r;

$depBreak = []; $res = $conn->query("SELECT * FROM vw_branch_deposit_breakdown");
while ($r=$res->fetch_assoc()) $depBreak[$r['branch_id']] = $r;

$trBreak  = []; $res = $conn->query("SELECT * FROM vw_branch_transfer_breakdown");
while ($r=$res->fetch_assoc()) $trBreak[$r['branch_id']] = $r;

// مستخدمو التطبيق لكل فرع (كان في صفحة القناة الرقمية)
$appByBranch = [];
$res = $conn->query("
    SELECT b.branch_id, COUNT(DISTINCT au.app_user_id) AS app_users
    FROM branches b
    LEFT JOIN customers c  ON c.branch_id = b.branch_id AND c.is_active=1
    LEFT JOIN app_users au ON au.customer_id = c.customer_id
    WHERE b.is_active=1
    GROUP BY b.branch_id
");
while ($r = $res->fetch_assoc()) $appByBranch[$r['branch_id']] = (int)$r['app_users'];

$colors  = ['#1E1E22','#7F8691','#C5A04A','#1A7A4A','#C0392B','#D9822B','#8B6B2E','#B9BDC4'];

open_layout('مقارنة الفروع','branches');

function fmtN($n){
    if($n>=1000000) return number_format($n/1000000,1).'م';
    if($n>=1000) return number_format($n/1000,1).'ألف';
    return number_format($n);
}
?>

<!-- جدول تفصيلي -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">🏛️</span> مقارنة الفروع</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>الفرع</th><th>العملاء</th><th>الحسابات</th><th>عدد المعاملات</th><th>الإيداعات</th><th>السحوبات</th><th>التحويلات</th><th>قروض نشطة</th><th>متعثرة</th><th>نسبة التعثر</th><th>شكاوى</th><th>مستخدمو التطبيق</th><th>نسبة التبني الرقمي</th><th>التقييم</th></tr>
        </thead>
        <tbody>
        <?php foreach($branches as $b):
            $totalL=$b['active_loans']+$b['defaulted_loans'];
            $rate=$totalL>0?round($b['defaulted_loans']/$totalL*100,1):0;
            $score=max(0,min(100,100-$rate*3-$b['open_complaints']*4));
            $sc=$score>=70?'success':($score>=45?'warn':'danger');
            $appU=$appByBranch[$b['branch_id']] ?? 0;
            $adopt=$b['total_customers']>0?round($appU/$b['total_customers']*100):0;
            $ac=$adopt>=70?'success':($adopt>=50?'teal':($adopt>=30?'warn':'danger'));
        ?>
        <tr>
          <td><strong><?=htmlspecialchars($b['branch_name'])?></strong><br><small style="color:var(--gray-500)"><?=htmlspecialchars($b['city'])?></small></td>
          <td><?=$b['total_customers']?></td><td><?=$b['total_accounts']?></td>
          <td><span class="badge badge-teal"><?=number_format($b['total_txn_count']??0)?></span></td>
          <td style="color:var(--success)"><?=fmtN($b['total_deposits'])?></td>
          <td style="color:var(--danger)"><?=fmtN($b['total_withdrawals'])?></td>
          <td><?=fmtN($b['total_transfers'])?></td>
          <td><?=$b['active_loans']?></td>
          <td><?=$b['defaulted_loans']>0?'<span class="badge badge-danger">'.$b['defaulted_loans'].'</span>':'0'?></td>
          <td><span class="badge badge-<?=$rate>20?'danger':($rate>10?'warn':'success')?>"><?=$rate?>%</span></td>
          <td><?=$b['open_complaints']>0?'<span class="badge badge-warn">'.$b['open_complaints'].'</span>':'<span class="badge badge-success">0</span>'?></td>
          <td><?=number_format($appU)?></td>
          <td><span class="badge badge-<?=$ac?>"><?=$adopt?>%</span></td>
          <td><span class="badge badge-<?=$sc?>"><?=$score?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- تفصيل السحوبات -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">💸</span> تفصيل السحوبات حسب النوع</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>الفرع</th><th>نقدي</th><th>شيك</th><th>تطبيق</th><th>صرف آلي</th><th>نقطة بيع</th></tr></thead>
        <tbody>
        <?php foreach($branches as $b): $wd=$wdBreak[$b['branch_id']]??[]; ?>
        <tr>
          <td><strong><?=htmlspecialchars($b['branch_name'])?></strong></td>
          <td><?=fmtN($wd['wd_cash']??0)?> <small style="color:var(--gray-500)">(<?=$wd['wd_cash_count']??0?>)</small></td>
          <td><?=fmtN($wd['wd_cheque']??0)?> <small style="color:var(--gray-500)">(<?=$wd['wd_cheque_count']??0?>)</small></td>
          <td><?=fmtN($wd['wd_app']??0)?> <small style="color:var(--gray-500)">(<?=$wd['wd_app_count']??0?>)</small></td>
          <td><?=fmtN($wd['wd_atm']??0)?> <small style="color:var(--gray-500)">(<?=$wd['wd_atm_count']??0?>)</small></td>
          <td><?=fmtN($wd['wd_pos']??0)?> <small style="color:var(--gray-500)">(<?=$wd['wd_pos_count']??0?>)</small></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- تفصيل الإيداعات -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">💵</span> تفصيل الإيداعات حسب النوع</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>الفرع</th><th>نقدي</th><th>شيك</th><th>تطبيق</th><th>BBAN</th><th>SWIFT</th><th>RTGS</th></tr></thead>
        <tbody>
        <?php foreach($branches as $b): $dep=$depBreak[$b['branch_id']]??[]; ?>
        <tr>
          <td><strong><?=htmlspecialchars($b['branch_name'])?></strong></td>
          <td><?=fmtN($dep['dep_cash']??0)?> <small>(<?=$dep['dep_cash_count']??0?>)</small></td>
          <td><?=fmtN($dep['dep_cheque']??0)?> <small>(<?=$dep['dep_cheque_count']??0?>)</small></td>
          <td><?=fmtN($dep['dep_app']??0)?> <small>(<?=$dep['dep_app_count']??0?>)</small></td>
          <td><?=fmtN($dep['dep_bban']??0)?> <small>(<?=$dep['dep_bban_count']??0?>)</small></td>
          <td><?=fmtN($dep['dep_swift']??0)?> <small>(<?=$dep['dep_swift_count']??0?>)</small></td>
          <td><?=fmtN($dep['dep_rtgs']??0)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- تفصيل التحويلات -->
<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">🔄</span> تفصيل التحويلات حسب النوع</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>الفرع</th><th>عبر التطبيق</th><th>داخلي (بين الفروع)</th><th>BBAN</th><th>SWIFT</th><th>RTGS</th></tr></thead>
        <tbody>
        <?php foreach($branches as $b): $tr=$trBreak[$b['branch_id']]??[]; ?>
        <tr>
          <td><strong><?=htmlspecialchars($b['branch_name'])?></strong></td>
          <td><?=fmtN($tr['tr_app']??0)?> <small>(<?=$tr['tr_app_count']??0?>)</small></td>
          <td><?=fmtN($tr['tr_internal']??0)?> <small>(<?=$tr['tr_internal_count']??0?>)</small></td>
          <td><?=fmtN($tr['tr_bban']??0)?> <small>(<?=$tr['tr_bban_count']??0?>)</small></td>
          <td><?=fmtN($tr['tr_swift']??0)?> <small>(<?=$tr['tr_swift_count']??0?>)</small></td>
          <td><?=fmtN($tr['tr_rtgs']??0)?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php close_layout(); ?>
