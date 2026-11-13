<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

$type     = $_GET['type']   ?? '';
$branch   = $_GET['branch'] ?? 'all';
$from     = validate_date($_GET['from'] ?? null, date('Y-01-01'));
$to       = validate_date($_GET['to']   ?? null, date('Y-m-d'));
$branchId = (int)$branch;
$generated = isset($_GET['type']) && $type !== '';

$sortOptions = [
    'volume'    => 'حجم التحويلات',
    'count'     => 'عدد التحويلات',
    'recurring' => 'التحويلات المتكررة',
];
$sort = $_GET['sort'] ?? 'volume';
if (!array_key_exists($sort, $sortOptions)) $sort = 'volume';

$branches = [];
$res = $conn->query("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_id");
while ($r = $res->fetch_assoc()) $branches[] = $r;

$rows    = [];
$headers = [];
$reportNames = [
    'customers'     => 'تقرير العملاء',
    'accounts'      => 'تقرير الحسابات',
    'transactions'  => 'تقرير العمليات المالية',
    'loans'         => 'تقرير القروض',
    'complaints'    => 'تقرير الشكاوى',
    'transfers'     => 'تقرير التحويلات (حسب الحساب)',
];

if ($generated) {
    $bFilter = $branch !== 'all' ? " AND {ALIAS}.branch_id = $branchId " : '';

    switch ($type) {
        case 'customers':
            $headers = ['رقم العميل','الفرع','الجنس','تاريخ الإضافة'];
            $sql = "SELECT c.customer_id, b.branch_name, c.gender, c.created_at
                    FROM customers c
                    JOIN branches b ON b.branch_id = c.branch_id
                    WHERE c.is_active=1
                      AND DATE(c.created_at) BETWEEN '$from' AND '$to'
                      " . ($branch!=='all' ? "AND c.branch_id=$branchId" : '') . "
                    ORDER BY c.created_at DESC";
            $res = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['customer_id'], $r['branch_name'], $r['gender']==='male'?'ذكر':'أنثى', $r['created_at']];
            break;

        case 'accounts':
            $headers = ['رقم الحساب','رقم العميل','الفرع','النوع','الرصيد (SDG)','الحالة','تاريخ الفتح'];
            $sql = "SELECT a.account_id, a.customer_id, b.branch_name, a.account_type, a.balance, a.status, a.opened_at
                    FROM accounts a
                    JOIN branches  b ON b.branch_id   = a.branch_id
                    WHERE DATE(a.opened_at) BETWEEN '$from' AND '$to'
                      " . ($branch!=='all' ? "AND a.branch_id=$branchId" : '') . "
                    ORDER BY a.balance DESC";
            $tMap = ['savings'=>'توفير','current'=>'جاري','investment'=>'استثماري'];
            $sMap = ['active'=>'نشط','frozen'=>'مجمّد','closed'=>'مغلق'];
            $res  = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['account_id'], $r['customer_id'], $r['branch_name'],
                           $tMap[$r['account_type']]??$r['account_type'],
                           number_format($r['balance']),
                           $sMap[$r['status']]??$r['status'],
                           $r['opened_at']];
            break;

        case 'transactions':
            $headers = ['#','رقم الحساب','الفرع','نوع العملية','طريقة التنفيذ','المبلغ (SDG)','التاريخ'];
            $sql = "SELECT t.txn_id, t.account_id, b.branch_name, t.txn_type, t.sub_type, t.amount, t.created_at
                    FROM transactions t
                    JOIN branches  b ON b.branch_id   = t.branch_id
                    WHERE t.status='completed'
                      AND DATE(t.created_at) BETWEEN '$from' AND '$to'
                      " . ($branch!=='all' ? "AND t.branch_id=$branchId" : '') . "
                    ORDER BY t.created_at DESC
                    LIMIT 200";
            $txMap  = ['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];
            $subMap = ['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','web_transfer'=>'ويب',
                       'internal_transfer'=>'داخلي','swift'=>'SWIFT','rtgs'=>'RTGS','bban'=>'BBAN','atm'=>'ATM','pos'=>'POS'];
            $res = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['txn_id'], $r['account_id'], $r['branch_name'],
                           $txMap[$r['txn_type']]??$r['txn_type'],
                           $subMap[$r['sub_type']]??$r['sub_type'],
                           number_format($r['amount']),
                           $r['created_at']];
            break;

        case 'loans':
            $headers = ['رقم العميل','الفرع','المبلغ (SDG)','المسدَّد (SDG)','المتبقي (SDG)','الحالة','تاريخ الإصدار','تاريخ الاستحقاق'];
            $sql = "SELECT l.customer_id, b.branch_name, l.amount, l.paid_amount, l.status, l.issued_at, l.due_at
                    FROM loans l
                    JOIN branches  b ON b.branch_id   = l.branch_id
                    WHERE DATE(l.issued_at) BETWEEN '$from' AND '$to'
                      " . ($branch!=='all' ? "AND l.branch_id=$branchId" : '') . "
                    ORDER BY l.issued_at DESC";
            $lMap = ['active'=>'نشط','paid'=>'مسدَّد','defaulted'=>'متعثر'];
            $res  = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['customer_id'], $r['branch_name'],
                           number_format($r['amount']),
                           number_format($r['paid_amount']),
                           number_format($r['amount'] - $r['paid_amount']),
                           $lMap[$r['status']]??$r['status'],
                           $r['issued_at'], $r['due_at']];
            break;

        case 'transfers':
            $headers = ['رقم الحساب','الفرع','عدد التحويلات','حجم التحويلات (SDG)','تحويلات متكررة'];
            $tblFilter = $branch !== 'all' ? "AND a.branch_id=$branchId" : '';
            $orderBy = [
                'volume'    => 'transfer_volume DESC',
                'count'     => 'transfer_count DESC',
                'recurring' => 'recurring_count DESC',
            ][$sort];
            $sql = "SELECT a.account_id, b.branch_name,
                           COALESCE(v.cnt,0) AS transfer_count,
                           COALESCE(v.vol,0) AS transfer_volume,
                           COALESCE(r.recurring_count,0) AS recurring_count
                    FROM accounts a
                    JOIN branches  b ON b.branch_id   = a.branch_id
                    LEFT JOIN (
                        SELECT account_id, COUNT(*) AS cnt, SUM(amount) AS vol FROM (
                            SELECT from_account_id AS account_id, amount, created_at FROM internal_transfers WHERE status='completed'
                            UNION ALL
                            SELECT to_account_id AS account_id, amount, created_at FROM internal_transfers WHERE status='completed'
                        ) u WHERE DATE(created_at) BETWEEN '$from' AND '$to'
                        GROUP BY account_id
                    ) v ON v.account_id = a.account_id
                    LEFT JOIN (
                        SELECT account_id, SUM(pair_cnt) AS recurring_count FROM (
                            SELECT from_account_id AS account_id, to_account_id AS counterpart, COUNT(*) AS pair_cnt
                            FROM internal_transfers
                            WHERE status='completed' AND DATE(created_at) BETWEEN '$from' AND '$to'
                            GROUP BY from_account_id, to_account_id
                            HAVING COUNT(*) > 1
                        ) p GROUP BY account_id
                    ) r ON r.account_id = a.account_id
                    WHERE 1=1 $tblFilter
                    HAVING transfer_count > 0
                    ORDER BY $orderBy
                    LIMIT 200";
            $res = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['account_id'], $r['branch_name'],
                           number_format($r['transfer_count']),
                           number_format($r['transfer_volume']),
                           number_format($r['recurring_count'])];
            break;

        case 'complaints':
            $headers = ['رقم العميل','الفرع','الموضوع','الحالة','تاريخ الفتح','تاريخ الإغلاق'];
            $sql = "SELECT k.customer_id, b.branch_name, k.subject, k.status, k.opened_at, k.resolved_at
                    FROM complaints k
                    JOIN branches  b ON b.branch_id   = k.branch_id
                    WHERE DATE(k.opened_at) BETWEEN '$from' AND '$to'
                      " . ($branch!=='all' ? "AND k.branch_id=$branchId" : '') . "
                    ORDER BY k.opened_at DESC";
            $cMap = ['open'=>'مفتوحة','in_progress'=>'قيد المعالجة','resolved'=>'محلولة','closed'=>'مغلقة'];
            $res  = $conn->query($sql);
            while ($r = $res->fetch_assoc())
                $rows[] = [$r['customer_id'], $r['branch_name'], $r['subject'],
                           $cMap[$r['status']]??$r['status'],
                           $r['opened_at'],
                           $r['resolved_at'] ?? '—'];
            break;
    }
}

open_layout('التقارير', 'reports');
?>

<!-- فلتر التقرير -->
<div class="card" style="margin-bottom:20px">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">نوع التقرير</label>
        <select name="type" id="reportTypeSelect" class="form-control" style="width:210px" onchange="document.getElementById('sortFieldWrap').style.display = (this.value==='transfers') ? 'block' : 'none';">
          <?php foreach ($reportNames as $k => $v): ?>
          <option value="<?= $k ?>" <?= $type===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">الفرع</label>
        <select name="branch" class="form-control" style="width:170px">
          <option value="all">كل الفروع</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['branch_id'] ?>" <?= $branch==$b['branch_id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
          <?php endforeach; ?>
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
      <div id="sortFieldWrap" style="display:<?= $type==='transfers' ? 'block' : 'none' ?>">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">ترتيب حسب</label>
        <select name="sort" class="form-control" style="width:170px">
          <?php foreach ($sortOptions as $k => $v): ?>
          <option value="<?= $k ?>" <?= $sort===$k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="width:auto">🔍 توليد التقرير</button>
      <?php if ($generated): ?>
      <button type="button" onclick="window.print()" class="btn btn-gold" style="width:auto">🖨️ طباعة</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<div style="font-size:12px;color:var(--gray-500);margin:-8px 0 14px">🔒 حفاظاً على سرية بيانات العملاء، تعرض التقارير أرقام العملاء والحسابات دون الأسماء أو أرقام الهويات.</div>

<?php if (!$generated): ?>
<!-- شاشة الترحيب -->
<div class="card">
  <div class="card-body" style="text-align:center;padding:60px 20px">
    <div style="font-size:52px;margin-bottom:16px">📋</div>
    <div style="font-size:17px;font-weight:800;color:var(--navy);margin-bottom:8px">اختر نوع التقرير وانقر "توليد التقرير"</div>
    <div style="font-size:13px;color:var(--gray-500)">حدد الفترة الزمنية والفرع ثم اضغط الزر لعرض البيانات</div>
  </div>
</div>

<?php else: ?>
<!-- نتائج التقرير -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">📋</span> <?= $reportNames[$type] ?? '' ?></div>
    <span class="badge badge-teal"><?= count($rows) ?> سجل</span>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><?php foreach ($headers as $h): ?><th><?= $h ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
          <tr><td colspan="<?= count($headers) ?>" style="text-align:center;padding:36px;color:var(--gray-500)">
            لا توجد بيانات للفترة المحددة
          </td></tr>
          <?php else: ?>
          <?php foreach ($rows as $row): ?>
          <tr><?php foreach ($row as $cell): ?><td><?= htmlspecialchars((string)$cell) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php close_layout(); ?>
