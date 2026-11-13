<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('admin');

// Handle add
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    if ($_POST['action']==='add') {
        $stmt = $conn->prepare("INSERT INTO branches (branch_name,city,state,manager_name,phone) VALUES (?,?,?,?,?)");
        $stmt->bind_param('sssss',$_POST['branch_name'],$_POST['city'],$_POST['state'],$_POST['manager_name'],$_POST['phone']);
        $stmt->execute();
    } elseif ($_POST['action']==='edit') {
        $id = (int)$_POST['branch_id'];
        $stmt = $conn->prepare("UPDATE branches SET branch_name=?,city=?,state=?,manager_name=?,phone=? WHERE branch_id=?");
        $stmt->bind_param('sssssi',$_POST['branch_name'],$_POST['city'],$_POST['state'],$_POST['manager_name'],$_POST['phone'],$id);
        $stmt->execute();
    }
    header('Location: ' . base_url() . '/pages/admin/branches.php'); exit;
}

$branches = [];
$res = $conn->query("SELECT b.*, (SELECT COUNT(*) FROM customers WHERE branch_id=b.branch_id AND is_active=1) AS cust_count FROM branches b ORDER BY b.branch_id");
while ($r = $res->fetch_assoc()) $branches[] = $r;

open_layout('إدارة الفروع','branches');
?>

<div class="page-header">
  <h2>🏛️ إدارة الفروع</h2>
  <button onclick="document.getElementById('addModal').classList.add('open')" class="btn btn-gold">+ فرع جديد</button>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>اسم الفرع</th><th>المدينة</th><th>الولاية</th><th>المدير</th><th>الهاتف</th><th>العملاء</th><th>الحالة</th><th>إجراءات</th></tr>
        </thead>
        <tbody>
          <?php foreach ($branches as $b): ?>
          <tr>
            <td><?= $b['branch_id'] ?></td>
            <td><strong><?= htmlspecialchars($b['branch_name']) ?></strong></td>
            <td><?= htmlspecialchars($b['city']) ?></td>
            <td><?= htmlspecialchars($b['state']) ?></td>
            <td><?= htmlspecialchars($b['manager_name']??'—') ?></td>
            <td dir="ltr"><?= htmlspecialchars($b['phone']??'—') ?></td>
            <td><?= $b['cust_count'] ?></td>
            <td><?= $b['is_active'] ? '<span class="badge badge-success">نشط</span>' : '<span class="badge badge-danger">معطّل</span>' ?></td>
            <td>
              <button onclick='editBranch(<?= json_encode($b) ?>)' class="btn btn-sm btn-gold">تعديل</button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Modal -->
<div class="modal-backdrop" id="addModal">
  <div class="modal">
    <div class="modal-title">➕ إضافة فرع جديد</div>
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-row">
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">اسم الفرع</label><input type="text" name="branch_name" class="form-control" required></div>
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">المدينة</label><input type="text" name="city" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الولاية</label><input type="text" name="state" class="form-control" required></div>
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">اسم المدير</label><input type="text" name="manager_name" class="form-control"></div>
      </div>
      <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الهاتف</label><input type="text" name="phone" class="form-control" dir="ltr"></div>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">حفظ</button>
        <button type="button" onclick="document.getElementById('addModal').classList.remove('open')" class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div class="modal-backdrop" id="editModal">
  <div class="modal">
    <div class="modal-title">✏️ تعديل بيانات الفرع</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit">
      <input type="hidden" name="branch_id" id="edit_id">
      <div class="form-row">
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">اسم الفرع</label><input type="text" name="branch_name" id="edit_name" class="form-control" required></div>
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">المدينة</label><input type="text" name="city" id="edit_city" class="form-control" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الولاية</label><input type="text" name="state" id="edit_state" class="form-control" required></div>
        <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">اسم المدير</label><input type="text" name="manager_name" id="edit_manager" class="form-control"></div>
      </div>
      <div class="form-group"><label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الهاتف</label><input type="text" name="phone" id="edit_phone" class="form-control" dir="ltr"></div>
      <div style="display:flex;gap:10px;margin-top:10px">
        <button type="submit" class="btn btn-primary" style="flex:1">تحديث</button>
        <button type="button" onclick="document.getElementById('editModal').classList.remove('open')" class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
      </div>
    </form>
  </div>
</div>

<script>
function editBranch(b) {
  document.getElementById('edit_id').value      = b.branch_id;
  document.getElementById('edit_name').value    = b.branch_name;
  document.getElementById('edit_city').value    = b.city;
  document.getElementById('edit_state').value   = b.state;
  document.getElementById('edit_manager').value = b.manager_name||'';
  document.getElementById('edit_phone').value   = b.phone||'';
  document.getElementById('editModal').classList.add('open');
}
['addModal','editModal'].forEach(id=>{
  document.getElementById(id).addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});
</script>
<?php close_layout(); ?>
