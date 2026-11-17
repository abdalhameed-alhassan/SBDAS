<?php
require_once '../../includes/init.php';
require_role('admin');

// إضافة فرع أو تعديله (نفس النموذج للحالتين: branch_id فارغ = إضافة)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $f = [$_POST['branch_name'], $_POST['city'], $_POST['state'], $_POST['manager_name'], $_POST['phone']];
    if ($id = (int)$_POST['branch_id'])
        run("UPDATE branches SET branch_name=?, city=?, state=?, manager_name=?, phone=? WHERE branch_id=?", [...$f, $id]);
    else
        run("INSERT INTO branches (branch_name, city, state, manager_name, phone) VALUES (?,?,?,?,?)", $f);
    header('Location: branches.php');
    exit;
}

$branches = rows("SELECT b.*, (SELECT COUNT(*) FROM customers WHERE branch_id=b.branch_id AND is_active=1) AS customers FROM branches b ORDER BY branch_id");

open_layout('إدارة الفروع', 'branches');
$rows = [];
foreach ($branches as $b)
    $rows[] = [$b['branch_id'], '<b>' . h($b['branch_name']) . '</b>', h($b['city']), h($b['state']), h($b['manager_name'] ?? '—'),
               '<span dir="ltr">' . h($b['phone'] ?? '—') . '</span>', $b['customers'], badge($b['is_active'] ? 'نشط' : 'معطّل', $b['is_active'] ? 'success' : 'danger'),
               "<button class='btn btn-sm btn-gold' data-modal='branchModal' onclick='editBranch(" . h(json_encode($b)) . ")'>تعديل</button>"];
table_card('الفروع', '🏛️', ['#','اسم الفرع','المدينة','الولاية','المدير','الهاتف','العملاء','الحالة','إجراءات'], $rows,
           "<button class='btn btn-gold btn-sm' data-modal='branchModal' onclick='editBranch({})'>+ فرع جديد</button>");
?>
<div class="modal-backdrop" id="branchModal"><div class="modal">
  <div class="modal-title" id="branchTitle">فرع</div>
  <form method="POST">
    <input type="hidden" name="branch_id" id="f_id">
    <div class="form-row"><?= field('اسم الفرع', '<input name="branch_name" id="f_name" class="form-control" required>') . field('المدينة', '<input name="city" id="f_city" class="form-control" required>') ?></div>
    <div class="form-row"><?= field('الولاية', '<input name="state" id="f_state" class="form-control" required>') . field('اسم المدير', '<input name="manager_name" id="f_manager" class="form-control">') ?></div>
    <?= field('الهاتف', '<input name="phone" id="f_phone" class="form-control" dir="ltr">') ?>
    <div class="modal-actions"><button class="btn btn-primary">حفظ</button><button type="button" class="btn btn-cancel" data-close>إلغاء</button></div>
  </form>
</div></div>
<script>
function editBranch(b) {   // نموذج واحد للإضافة والتعديل
  document.getElementById('branchTitle').textContent = b.branch_id ? '✏️ تعديل بيانات الفرع' : '➕ إضافة فرع جديد';
  [['id','branch_id'],['name','branch_name'],['city','city'],['state','state'],['manager','manager_name'],['phone','phone']]
    .forEach(function(p){ document.getElementById('f_'+p[0]).value = b[p[1]] || ''; });
}
</script>
<?php close_layout();
