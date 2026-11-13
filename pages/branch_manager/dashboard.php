<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('branch_manager','branch_employee','admin');

$user = current_user();
$bid  = (int)$user['branch_id'];

$branch = $conn->query("SELECT * FROM vw_branch_summary WHERE branch_id=$bid")->fetch_assoc();
$bInfo  = $conn->query("SELECT * FROM branches WHERE branch_id=$bid")->fetch_assoc();

// Monthly activity for this branch
$monthly = []; $monthLabels = [];
$res = $conn->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS mo, COUNT(*) AS cnt, SUM(amount) AS vol FROM transactions WHERE branch_id=$bid AND status='completed' GROUP BY mo ORDER BY mo DESC LIMIT 6");
while ($r = $res->fetch_assoc()) { $monthly[] = $r['vol']; $monthLabels[] = $r['mo']; }
$monthly = array_reverse($monthly); $monthLabels = array_reverse($monthLabels);

// Recent transactions
$recentTxns = [];
$res = $conn->query("SELECT t.*, c.full_name, a.account_id FROM transactions t JOIN accounts a ON a.account_id=t.account_id JOIN customers c ON c.customer_id=a.customer_id WHERE t.branch_id=$bid AND t.status='completed' ORDER BY t.created_at DESC LIMIT 10");
while ($r = $res->fetch_assoc()) $recentTxns[] = $r;

open_layout('لوحة الفرع — '.(htmlspecialchars($bInfo['branch_name']??'')),'dashboard');
$txMap = ['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];
?>

<div class="kpi-grid">
  <div class="kpi-card gold"><div class="kpi-icon">👥</div><div class="kpi-body"><div class="kpi-value"><?= $branch['total_customers']??0 ?></div><div class="kpi-label">عملاء الفرع</div></div></div>
  <div class="kpi-card teal"><div class="kpi-icon">🏦</div><div class="kpi-body"><div class="kpi-value"><?= $branch['total_accounts']??0 ?></div><div class="kpi-label">الحسابات</div></div></div>
  <div class="kpi-card success"><div class="kpi-icon">💵</div><div class="kpi-body"><div class="kpi-value"><?= number_format($branch['total_deposits']??0) ?></div><div class="kpi-label">إجمالي الإيداعات</div></div></div>
  <div class="kpi-card danger"><div class="kpi-icon">💸</div><div class="kpi-body"><div class="kpi-value"><?= number_format($branch['total_withdrawals']??0) ?></div><div class="kpi-label">إجمالي السحوبات</div></div></div>
  <div class="kpi-card navy"><div class="kpi-icon">💳</div><div class="kpi-body"><div class="kpi-value"><?= ($branch['active_loans']??0) + ($branch['defaulted_loans']??0) ?></div><div class="kpi-label">القروض</div></div></div>
  <div class="kpi-card warn"><div class="kpi-icon">📣</div><div class="kpi-body"><div class="kpi-value"><?= $branch['open_complaints']??0 ?></div><div class="kpi-label">شكاوى مفتوحة</div></div></div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">📈</span> حجم العمليات الشهري (SDG)</div></div>
  <div class="card-body">
    <div class="chart-container" style="height:200px">
      <canvas id="branchTrend"></canvas>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header"><div class="card-title"><span class="icon">🕐</span> آخر العمليات</div></div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>#</th><th>العميل</th><th>النوع</th><th>المبلغ</th><th>الرصيد بعد</th><th>التاريخ</th></tr></thead>
        <tbody>
          <?php foreach ($recentTxns as $t): ?>
          <tr>
            <td><?= $t['txn_id'] ?></td>
            <td><?= htmlspecialchars($t['full_name']) ?></td>
            <td><span class="badge badge-<?= in_array($t['txn_type'],['deposit','transfer_in'])?'success':'danger' ?>"><?= $txMap[$t['txn_type']]??$t['txn_type'] ?></span></td>
            <td dir="ltr"><?= number_format($t['amount']) ?></td>
            <td dir="ltr"><?= number_format($t['balance_after']) ?></td>
            <td style="font-size:12px;color:var(--gray-500)"><?= $t['created_at'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
new Chart(document.getElementById('branchTrend').getContext('2d'),{
  type:'bar',
  data:{labels:<?= json_encode($monthLabels) ?>,datasets:[{label:'حجم العمليات',data:<?= json_encode($monthly) ?>,backgroundColor:'#C5A04ABB',borderColor:'#C5A04A',borderWidth:1}]},
  options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false}},y:{beginAtZero:true}}}
});
</script>
<?php close_layout(); ?>
