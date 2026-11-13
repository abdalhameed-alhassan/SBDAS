<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('admin');

$stats = $conn->query("SELECT
  (SELECT COUNT(*) FROM users WHERE is_active=1) AS total_users,
  (SELECT COUNT(*) FROM branches WHERE is_active=1) AS total_branches,
  (SELECT COUNT(*) FROM customers WHERE is_active=1) AS total_customers,
  (SELECT COUNT(*) FROM notifications WHERE is_read=0) AS unread_notifs
")->fetch_assoc();

$users = [];
$res = $conn->query("SELECT u.*, b.branch_name FROM users u LEFT JOIN branches b ON b.branch_id=u.branch_id ORDER BY u.created_at DESC");
while ($r = $res->fetch_assoc()) $users[] = $r;

open_layout('لوحة مدير النظام','dashboard');
?>

<div class="kpi-grid">
  <div class="kpi-card gold">
    <div class="kpi-icon">👥</div>
    <div class="kpi-body"><div class="kpi-value"><?= $stats['total_users'] ?></div><div class="kpi-label">المستخدمون النشطون</div></div>
  </div>
  <div class="kpi-card teal">
    <div class="kpi-icon">🏛️</div>
    <div class="kpi-body"><div class="kpi-value"><?= $stats['total_branches'] ?></div><div class="kpi-label">الفروع النشطة</div></div>
  </div>
  <div class="kpi-card navy">
    <div class="kpi-icon">👤</div>
    <div class="kpi-body"><div class="kpi-value"><?= $stats['total_customers'] ?></div><div class="kpi-label">العملاء</div></div>
  </div>
  <div class="kpi-card warn">
    <div class="kpi-icon">🔔</div>
    <div class="kpi-body"><div class="kpi-value"><?= $stats['unread_notifs'] ?></div><div class="kpi-label">تنبيهات غير مقروءة</div></div>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title"><span class="icon">👥</span> إدارة المستخدمين</div>
    <button onclick="document.getElementById('addModal').classList.add('open')" class="btn btn-gold btn-sm">+ مستخدم جديد</button>
  </div>
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>الاسم</th><th>اسم المستخدم</th><th>الدور</th><th>الفرع</th><th>الحالة</th><th>تاريخ الإضافة</th><th>إجراءات</th></tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
          <tr>
            <td><?= $u['user_id'] ?></td>
            <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
            <td dir="ltr"><?= htmlspecialchars($u['username']) ?></td>
            <td><span class="badge badge-teal"><?= role_label($u['role']) ?></span></td>
            <td><?= htmlspecialchars($u['branch_name'] ?? '—') ?></td>
            <td><?= $u['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-danger">معطّل</span>' ?></td>
            <td style="font-size:12px;color:var(--gray-500)"><?= $u['created_at'] ?></td>
            <td>
              <a href="toggle_user.php?id=<?= $u['user_id'] ?>" class="btn btn-sm" style="background:var(--gray-100);color:var(--gray-700)">
                <?= $u['is_active'] ? 'تعطيل' : 'تفعيل' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <div class="modal-title">➕ إضافة مستخدم جديد</div>
    <form method="POST" action="add_user.php">
      <div class="form-row">
        <div class="form-group">
          <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:5px">الاسم الكامل</label>
          <input type="text" name="full_name" class="form-control" required>
        </div>
        <div class="form-group">
          <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:5px">اسم المستخدم</label>
          <input type="text" name="username" class="form-control" dir="ltr" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:5px">كلمة المرور</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <div class="form-group">
          <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:5px">الدور</label>
          <select name="role" class="form-control">
            <option value="branch_employee">موظف فرع</option>
            <option value="branch_manager">مدير فرع</option>
            <option value="executive">إدارة عليا</option>
            <option value="admin">مدير نظام</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label style="font-size:12px;font-weight:700;color:var(--gray-700);display:block;margin-bottom:5px">الفرع</label>
        <select name="branch_id" class="form-control">
          <?php
          $bRes = $conn->query("SELECT branch_id, branch_name FROM branches WHERE is_active=1");
          while ($b = $bRes->fetch_assoc()):
          ?><option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option><?php endwhile; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">حفظ</button>
        <button type="button" onclick="document.getElementById('addModal').classList.remove('open')" class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
      </div>
    </form>
  </div>
</div>
<script>
document.getElementById('addModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
</script>
<?php close_layout(); ?>
