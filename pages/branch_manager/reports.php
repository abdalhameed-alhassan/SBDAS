<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('branch_manager','branch_employee','admin');

$user = current_user();
$bid  = (int)$user['branch_id'];
$type = $_GET['type'] ?? 'transactions';
$from = validate_date($_GET['from'] ?? null, date('Y-01-01'));
$to   = validate_date($_GET['to']   ?? null, date('Y-m-d'));

$rows = []; $headers = [];
$txMap = ['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];

switch ($type) {
    case 'transactions':
        $headers = ['#','رقم الحساب','النوع','المبلغ','الرصيد بعد','التاريخ'];
        $res = $conn->query("SELECT t.txn_id, t.account_id, t.txn_type, t.amount, t.balance_after, t.created_at FROM transactions t WHERE t.branch_id=$bid AND t.status='completed' AND DATE(t.created_at) BETWEEN '$from' AND '$to' ORDER BY t.created_at DESC LIMIT 200");
        while ($r = $res->fetch_assoc()) $rows[] = [$r['txn_id'],$r['account_id'],$txMap[$r['txn_type']]??$r['txn_type'],number_format($r['amount']),number_format($r['balance_after']),$r['created_at']];
        break;
    case 'customers':
        $headers = ['رقم الحساب','الجنس','تاريخ الإضافة'];
        $res = $conn->query("SELECT (SELECT MIN(x.account_id) FROM accounts x WHERE x.customer_id=customers.customer_id) AS account_id, gender, created_at FROM customers WHERE branch_id=$bid AND is_active=1 AND created_at >= '$from 00:00:00' AND created_at < '$to 23:59:59' ORDER BY created_at DESC LIMIT 300");
        while ($r = $res->fetch_assoc()) $rows[] = [$r['account_id']??'—',$r['gender']==='male'?'ذكر':'أنثى',$r['created_at']];
        break;
    case 'loans':
        $headers = ['رقم الحساب','المبلغ','المسدّد','الحالة','الاستحقاق'];
        $lMap = ['active'=>'نشط','paid'=>'مسدّد','defaulted'=>'متعثر'];
        $res = $conn->query("SELECT (SELECT MIN(x.account_id) FROM accounts x WHERE x.customer_id=l.customer_id) AS account_id, l.amount, l.paid_amount, l.status, l.due_at FROM loans l WHERE l.branch_id=$bid AND l.issued_at >= '$from' AND l.issued_at <= '$to' ORDER BY l.issued_at DESC LIMIT 300");
        while ($r = $res->fetch_assoc()) $rows[] = [$r['account_id']??'—',number_format($r['amount']),number_format($r['paid_amount']),$lMap[$r['status']]??$r['status'],$r['due_at']];
        break;
    case 'complaints':
        $headers = ['رقم الحساب','الموضوع','الحالة','تاريخ الفتح'];
        $cMap = ['open'=>'مفتوحة','in_progress'=>'قيد المعالجة','resolved'=>'محلولة','closed'=>'مغلقة'];
        $res = $conn->query("SELECT (SELECT MIN(x.account_id) FROM accounts x WHERE x.customer_id=k.customer_id) AS account_id, k.subject, k.status, k.opened_at FROM complaints k WHERE k.branch_id=$bid AND k.opened_at >= '$from 00:00:00' AND k.opened_at < '$to 23:59:59' ORDER BY k.opened_at DESC LIMIT 300");
        while ($r = $res->fetch_assoc()) $rows[] = [$r['account_id']??'—',$r['subject'],$cMap[$r['status']]??$r['status'],$r['opened_at']];
        break;
}

$bInfo = $conn->query("SELECT branch_name FROM branches WHERE branch_id=$bid")->fetch_assoc();
open_layout('تقارير الفرع — '.htmlspecialchars($bInfo['branch_name']??''),'reports');
?>

<div class="card" style="margin-bottom:20px">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">نوع التقرير</label>
        <select name="type" class="form-control" style="width:190px">
          <option value="transactions" <?= $type==='transactions'?'selected':'' ?>>العمليات المالية</option>
          <option value="customers"    <?= $type==='customers'?'selected':'' ?>>العملاء</option>
          <option value="loans"        <?= $type==='loans'?'selected':'' ?>>القروض</option>
          <option value="complaints"   <?= $type==='complaints'?'selected':'' ?>>الشكاوى</option>
        </select>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">من</label>
        <input type="date" name="from" value="<?= $from ?>" class="form-control" style="width:150px;direction:ltr">
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">إلى</label>
        <input type="date" name="to" value="<?= $to ?>" class="form-control" style="width:150px;direction:ltr">
      </div>
      <button type="submit" class="btn btn-primary" style="width:auto">🔍 عرض</button>
      <button type="button" onclick="window.print()" class="btn btn-gold" style="width:auto">🖨️ طباعة</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">📋</span> نتائج التقرير</div>
    <span class="badge badge-teal"><?= count($rows) ?> سجل<?= count($rows) >= 300 ? ' (أول 300 — ضيّق نطاق التاريخ لعرض كل النتائج)' : '' ?></span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><?php foreach ($headers as $h): ?><th><?= $h ?></th><?php endforeach; ?></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="<?= count($headers) ?>" style="text-align:center;padding:30px;color:var(--gray-500)">لا توجد بيانات</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
          <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php close_layout(); ?>
