<?php
require_once '../../includes/init.php';
require_role('admin');

$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $uid    = (int)($_POST['user_id'] ?? 0);
    if ($action === 'add') {
        $name = trim($_POST['full_name']); $user = trim($_POST['username']); $pass = $_POST['password'];
        if (!$name || !$user || strlen($pass) < 6) {
            $err = 'يرجى ملء كل الحقول، وكلمة المرور 6 أحرف على الأقل.';
        } elseif (run("SELECT 1 FROM users WHERE username=?", [$user])->get_result()->fetch_row()) {
            $err = 'اسم المستخدم موجود مسبقاً.';
        } else {
            run("INSERT INTO users (branch_id, full_name, username, password_hash, role) VALUES (?,?,?,?,?)",
                [$_POST['branch_id'], $name, $user, password_hash($pass, PASSWORD_BCRYPT), $_POST['role']]);
            $msg = 'تمت إضافة المستخدم.';
        }
    } elseif ($action === 'toggle' && $uid !== (int)$_SESSION['user_id']) {
        $conn->query("UPDATE users SET is_active = 1 - is_active WHERE user_id = $uid");
        $msg = 'تم تحديث حالة المستخدم.';
    } elseif ($action === 'toggle') {
        $err = 'لا يمكن تعطيل حسابك الخاص.';
    } elseif ($action === 'reset_password') {
        if (strlen($_POST['new_password']) < 6) {
            $err = 'كلمة المرور 6 أحرف على الأقل.';
        } else {
            run("UPDATE users SET password_hash=? WHERE user_id=?", [password_hash($_POST['new_password'], PASSWORD_BCRYPT), $uid]);
            $msg = 'تمت إعادة تعيين كلمة المرور.';
        }
    }
}

// البحث والتصفية
$role = $_GET['role'] ?? 'all'; $branch = $_GET['branch'] ?? 'all'; $q = trim($_GET['search'] ?? '');
$where = ['1=1'];
if (isset(ROLES[$role])) $where[] = "u.role='$role'";
if ($branch !== 'all')   $where[] = 'u.branch_id=' . (int)$branch;
if ($q !== '')           $where[] = "(u.full_name LIKE '%" . esc($q) . "%' OR u.username LIKE '%" . esc($q) . "%')";
$users    = rows("SELECT u.*, b.branch_name FROM users u LEFT JOIN branches b ON b.branch_id=u.branch_id WHERE " . implode(' AND ', $where) . " ORDER BY u.created_at DESC, u.user_id DESC");
$branches = rows("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_id");
$s = row("SELECT COUNT(*) AS total, SUM(is_active=1) AS active, SUM(role='branch_manager') AS managers, SUM(role='branch_employee') AS employees FROM users");

open_layout('إدارة المستخدمين', 'users');
kpi_grid([['👥', $s['total'], 'إجمالي المستخدمين', 'gold'], ['✅', $s['active'], 'نشطون', 'success'],
          ['🏛️', $s['managers'], 'مديرو فروع', 'teal'], ['👤', $s['employees'], 'موظفو فروع', 'navy']]);
if ($msg) echo '<div class="alert-box info">✅ ' . h($msg) . '</div>';
if ($err) echo '<div class="alert-box danger">⚠️ ' . h($err) . '</div>';

$opt = fn($k, $v, $cur) => '<option value="' . $k . '"' . ($k == $cur ? ' selected' : '') . '>' . h($v) . '</option>';
$roleOpts = $opt('all', 'كل الأدوار', $role); foreach (ROLES as $k => $r) $roleOpts .= $opt($k, $r[0], $role);
$brOpts = $opt('all', 'كل الفروع', $branch);   foreach ($branches as $b) $brOpts .= $opt($b['branch_id'], $b['branch_name'], $branch);
echo '<div class="card"><div class="card-body"><form method="get" class="filter-form">'
   . field('بحث', '<input name="search" value="' . h($q) . '" placeholder="اسم أو اسم مستخدم" class="form-control">')
   . field('الدور', '<select name="role" class="form-control">' . $roleOpts . '</select>')
   . field('الفرع', '<select name="branch" class="form-control">' . $brOpts . '</select>')
   . '<button class="btn btn-primary">🔍 بحث</button><button type="button" class="btn btn-gold" data-modal="addModal">+ مستخدم جديد</button></form></div></div>';

$rows = [];
foreach ($users as $u) {
    $id = (int)$u['user_id'];
    $rows[] = [$id, '<b>' . h($u['full_name']) . '</b>', '<span dir="ltr">' . h($u['username']) . '</span>', badge(role_label($u['role'])),
               h($u['branch_name'] ?? '—'), badge($u['is_active'] ? 'نشط' : 'معطّل', $u['is_active'] ? 'success' : 'danger'),
               '<form method="post" class="inline"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="' . $id . '">'
               . '<button class="btn btn-sm" onclick="return confirm(\'هل أنت متأكد؟\')">' . ($u['is_active'] ? 'تعطيل' : 'تفعيل') . '</button></form> '
               . '<button class="btn btn-sm" data-modal="resetModal" onclick="resetFor(' . $id . ',this.dataset.name)" data-name="' . h($u['full_name']) . '">🔑 كلمة المرور</button>'];
}
table_card('المستخدمون', '👥', ['#','الاسم الكامل','اسم المستخدم','الدور','الفرع','الحالة','إجراءات'], $rows, badge(count($users) . ' مستخدم'));
?>
<div class="modal-backdrop" id="addModal"><div class="modal">
  <div class="modal-title">➕ إضافة مستخدم جديد</div>
  <form method="post"><input type="hidden" name="action" value="add">
    <div class="form-row"><?= field('الاسم الكامل', '<input name="full_name" class="form-control" required>') . field('اسم المستخدم', '<input name="username" class="form-control" dir="ltr" required>') ?></div>
    <div class="form-row"><?= field('كلمة المرور', '<input type="password" name="password" class="form-control" placeholder="6 أحرف على الأقل" required>')
        . field('الدور', '<select name="role" class="form-control">' . implode('', array_map(fn($k) => $opt($k, ROLES[$k][0], 'branch_employee'), array_keys(ROLES))) . '</select>') ?></div>
    <?= field('الفرع', '<select name="branch_id" class="form-control">' . implode('', array_map(fn($b) => $opt($b['branch_id'], $b['branch_name'], 0), $branches)) . '</select>') ?>
    <div class="modal-actions"><button class="btn btn-primary">حفظ</button><button type="button" class="btn btn-cancel" data-close>إلغاء</button></div>
  </form>
</div></div>
<div class="modal-backdrop" id="resetModal"><div class="modal">
  <div class="modal-title">🔑 إعادة تعيين كلمة المرور</div><p class="muted" id="resetName"></p>
  <form method="post"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" id="resetId">
    <?= field('كلمة المرور الجديدة', '<input type="password" name="new_password" class="form-control" placeholder="6 أحرف على الأقل" required>') ?>
    <div class="modal-actions"><button class="btn btn-primary">تحديث</button><button type="button" class="btn btn-cancel" data-close>إلغاء</button></div>
  </form>
</div></div>
<script>function resetFor(id, name){ document.getElementById('resetId').value=id; document.getElementById('resetName').textContent='المستخدم: '+name; }</script>
<?php close_layout();
