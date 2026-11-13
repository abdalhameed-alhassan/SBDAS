<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    if ($_POST['action']==='add') {
        $bid = $_POST['branch_id']==='' ? null : (int)$_POST['branch_id'];
        $stmt = $conn->prepare("INSERT INTO notifications (branch_id,type,title,message) VALUES (?,?,?,?)");
        $stmt->bind_param('isss',$bid,$_POST['type'],$_POST['title'],$_POST['message']);
        $stmt->execute();
    } elseif ($_POST['action']==='mark_read') {
        $conn->query("UPDATE notifications SET is_read=1");
    }
    header('Location: ' . base_url() . '/pages/admin/notifications.php'); exit;
}

$notifs = [];
$res = $conn->query("SELECT n.*, b.branch_name FROM notifications n LEFT JOIN branches b ON b.branch_id=n.branch_id ORDER BY n.created_at DESC");
while ($r = $res->fetch_assoc()) $notifs[] = $r;

$branches = [];
$res2 = $conn->query("SELECT branch_id, branch_name FROM branches WHERE is_active=1");
while ($r = $res2->fetch_assoc()) $branches[] = $r;

open_layout('إدارة التنبيهات','notifications');
?>

<div class="page-header">
  <h2>🔔 التنبيهات الإدارية</h2>
  <div style="display:flex;gap:10px">
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="mark_read">
      <button type="submit" class="btn btn-sm" style="background:var(--gray-100);color:var(--gray-700)">تعيين الكل كمقروء</button>
    </form>
    <button onclick="document.getElementById('addModal').classList.add('open')" class="btn btn-gold btn-sm">+ تنبيه جديد</button>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if (!$notifs): ?>
    <div style="text-align:center;padding:40px;color:var(--gray-500)">لا توجد تنبيهات</div>
    <?php endif; ?>
    <?php foreach ($notifs as $n):
      $cls = $n['type']==='alert'?'danger':($n['type']==='warning'?'warning':'info');
      $ico = $n['type']==='alert'?'🚨':($n['type']==='warning'?'⚠️':'ℹ️');
    ?>
    <div class="alert-box <?= $cls ?>" style="opacity:<?= $n['is_read']?'.6':'1' ?>">
      <span class="alert-icon"><?= $ico ?></span>
      <div style="flex:1">
        <div class="alert-title">
          <?= htmlspecialchars($n['title']) ?>
          <?php if ($n['branch_name']): ?>
          <span style="font-weight:400;font-size:11px;margin-right:6px">(<?= htmlspecialchars($n['branch_name']) ?>)</span>
          <?php endif; ?>
          <?= !$n['is_read'] ? '<span class="badge badge-danger" style="font-size:10px;padding:2px 6px">جديد</span>' : '' ?>
        </div>
        <div class="alert-msg"><?= htmlspecialchars($n['message']) ?></div>
        <div style="font-size:10px;color:var(--gray-500);margin-top:4px"><?= $n['created_at'] ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Add Modal -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <div class="modal-title">➕ إضافة تنبيه</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">النوع</label>
        <select name="type" class="form-control">
          <option value="info">معلومة</option>
          <option value="warning">تحذير</option>
          <option value="alert">تنبيه عاجل</option>
        </select>
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الفرع (اختياري)</label>
        <select name="branch_id" class="form-control">
          <option value="">كل الفروع</option>
          <?php foreach ($branches as $b): ?>
          <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">العنوان</label>
        <input type="text" name="title" class="form-control" required>
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الرسالة</label>
        <textarea name="message" class="form-control" rows="3" required style="resize:vertical"></textarea>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">إرسال</button>
        <button type="button" onclick="document.getElementById('addModal').classList.remove('open')" class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
      </div>
    </form>
  </div>
</div>
<script>document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});</script>
<?php close_layout(); ?>
