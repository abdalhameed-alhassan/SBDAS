<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('executive','admin');

// Rule engine — 4 supervisor-approved rules; each alert carries its own recommendation
$alerts = [];

// Fetch branch data for rules
$branches = [];
$res = $conn->query("SELECT * FROM vw_branch_summary");
while ($r = $res->fetch_assoc()) $branches[] = $r;

foreach ($branches as $b) {
    $totalLoans = $b['active_loans'] + $b['defaulted_loans'];
    $defRate = $totalLoans > 0 ? $b['defaulted_loans'] / $totalLoans * 100 : 0;

    // Rule 1: High default rate
    if ($defRate > 20) {
        $alerts[] = ['type'=>'danger','title'=>'نسبة تعثر مرتفعة — '.$b['branch_name'],'msg'=>'نسبة التعثر وصلت '.round($defRate,1).'%.','rec'=>'مراجعة ملفات القروض المتعثرة في '.$b['branch_name'].' وتفعيل إجراءات الاسترداد.'];
    }
    // Rule 2: High complaints
    if ($b['open_complaints'] >= 3) {
        $alerts[] = ['type'=>'warning','title'=>'ارتفاع الشكاوى — '.$b['branch_name'],'msg'=>'هناك '.$b['open_complaints'].' شكاوى مفتوحة.','rec'=>'زيادة عدد موظفي خدمة العملاء في '.$b['branch_name'].' أو تدريبهم على بروتوكولات المعالجة السريعة.'];
    }
    // Rule 3: Low customer base
    if ($b['total_customers'] < 5) {
        $alerts[] = ['type'=>'info','title'=>'قاعدة عملاء محدودة — '.$b['branch_name'],'msg'=>'عدد العملاء أقل من المعدل.','rec'=>'إطلاق حملة تسويقية لاستقطاب عملاء جدد في منطقة '.$b['city'].'.'];
    }
}

// Rule 4: High withdrawal ratio globally
$ratio = $conn->query("SELECT
    SUM(CASE WHEN txn_type='withdrawal' THEN amount END)/NULLIF(SUM(CASE WHEN txn_type='deposit' THEN amount END),0)*100 AS ratio
FROM transactions WHERE status='completed'")->fetch_assoc();
if (($ratio['ratio']??0) > 50) {
    $alerts[] = ['type'=>'warning','title'=>'نسبة سحوبات مرتفعة','msg'=>'السحوبات تمثل أكثر من 50% من الإيداعات.','rec'=>'دراسة أسباب ارتفاع السحوبات وتقييم السيولة الإجمالية للمصرف.'];
}

// Static notifications from DB
$dbNotifs = [];
$res = $conn->query("SELECT n.*, b.branch_name FROM notifications n LEFT JOIN branches b ON b.branch_id=n.branch_id ORDER BY n.created_at DESC");
while ($r = $res->fetch_assoc()) $dbNotifs[] = $r;

open_layout('التنبيهات والتوصيات','alerts');
?>

<!-- Dynamic Alerts (each with its recommendation) -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">🚨</span> التنبيهات والتوصيات</div>
    <span class="badge badge-danger"><?= count($alerts) ?></span>
  </div>
  <div class="card-body">
    <?php if (!$alerts): ?>
      <div style="text-align:center;padding:30px;color:var(--gray-500)">✅ لا توجد تنبيهات حالياً</div>
    <?php endif; ?>
    <?php foreach ($alerts as $a): ?>
    <div class="alert-box <?= $a['type'] ?>">
      <span class="alert-icon"><?= $a['type']==='danger'?'🚨':($a['type']==='warning'?'⚠️':'ℹ️') ?></span>
      <div>
        <div class="alert-title"><?= htmlspecialchars($a['title']) ?></div>
        <div class="alert-msg"><?= htmlspecialchars($a['msg']) ?></div>
        <div style="font-size:12.5px;margin-top:6px;font-weight:700">💡 التوصية: <?= htmlspecialchars($a['rec']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- DB Notifications -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">🔔</span> تنبيهات النظام</div>
  </div>
  <div class="card-body">
    <?php foreach ($dbNotifs as $n):
        $cls = $n['type']==='alert'?'danger':($n['type']==='warning'?'warning':'info');
        $ico = $n['type']==='alert'?'🚨':($n['type']==='warning'?'⚠️':'ℹ️');
    ?>
    <div class="alert-box <?= $cls ?>">
      <span class="alert-icon"><?= $ico ?></span>
      <div>
        <div class="alert-title"><?= htmlspecialchars($n['title']) ?>
          <?php if ($n['branch_name']): ?>
            <span style="font-weight:400;font-size:11px;margin-right:6px">(<?= htmlspecialchars($n['branch_name']) ?>)</span>
          <?php endif; ?>
        </div>
        <div class="alert-msg"><?= htmlspecialchars($n['message']) ?></div>
        <div style="font-size:10px;color:var(--gray-500);margin-top:4px"><?= $n['created_at'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php close_layout(); ?>
