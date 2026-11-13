<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/layout.php';
require_role('admin');

$msg = '';
$err = '';

// ── معالجة الإجراءات ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $username  = trim($_POST['username']  ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = $_POST['role']      ?? 'branch_employee';
        $branch_id = (int)($_POST['branch_id'] ?? 1);

        if (!$full_name || !$username || !$password) {
            $err = 'يرجى ملء جميع الحقول المطلوبة.';
        } else {
            // Check duplicate username
            $chk = $conn->prepare("SELECT user_id FROM users WHERE username=?");
            $chk->bind_param('s', $username);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $err = 'اسم المستخدم موجود مسبقاً، اختر اسماً آخر.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (branch_id, full_name, username, password_hash, role) VALUES (?,?,?,?,?)");
                $stmt->bind_param('issss', $branch_id, $full_name, $username, $hash, $role);
                $stmt->execute();
                $msg = 'تم إضافة المستخدم بنجاح.';
            }
        }
    }

    elseif ($action === 'toggle') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid && $uid !== (int)$_SESSION['user_id']) {
            $conn->query("UPDATE users SET is_active = 1 - is_active WHERE user_id = $uid");
            $msg = 'تم تحديث حالة المستخدم.';
        } else {
            $err = 'لا يمكن تعطيل حسابك الخاص.';
        }
    }

    elseif ($action === 'reset_password') {
        $uid      = (int)($_POST['user_id'] ?? 0);
        $new_pass = $_POST['new_password'] ?? '';
        if ($uid && strlen($new_pass) >= 6) {
            $hash = password_hash($new_pass, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE user_id=?");
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            $msg = 'تم إعادة تعيين كلمة المرور بنجاح.';
        } else {
            $err = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.';
        }
    }
}

// ── جلب البيانات ──
$filter_role   = $_GET['role']   ?? 'all';
$filter_branch = $_GET['branch'] ?? 'all';
$search        = trim($_GET['search'] ?? '');

$where = ['1=1'];
if ($filter_role !== 'all')   $where[] = "u.role = '" . $conn->real_escape_string($filter_role) . "'";
if ($filter_branch !== 'all') $where[] = "u.branch_id = " . (int)$filter_branch;
if ($search)                  $where[] = "(u.full_name LIKE '%" . $conn->real_escape_string($search) . "%' OR u.username LIKE '%" . $conn->real_escape_string($search) . "%')";
$whereSQL = implode(' AND ', $where);

$users = [];
$res = $conn->query("SELECT u.*, b.branch_name FROM users u LEFT JOIN branches b ON b.branch_id=u.branch_id WHERE $whereSQL ORDER BY u.created_at DESC");
while ($r = $res->fetch_assoc()) $users[] = $r;

$branches = [];
$res2 = $conn->query("SELECT branch_id, branch_name FROM branches WHERE is_active=1 ORDER BY branch_id");
while ($r = $res2->fetch_assoc()) $branches[] = $r;

// Stats
$stats = $conn->query("SELECT
    COUNT(*) AS total,
    SUM(is_active=1) AS active,
    SUM(role='admin') AS admins,
    SUM(role='executive') AS executives,
    SUM(role='branch_manager') AS managers,
    SUM(role='branch_employee') AS employees
FROM users")->fetch_assoc();

open_layout('إدارة المستخدمين', 'users');
?>

<!-- Stats -->
<div class="kpi-grid" style="margin-bottom:20px">
    <div class="kpi-card gold">
        <div class="kpi-icon">👥</div>
        <div class="kpi-body"><div class="kpi-value"><?= $stats['total'] ?></div><div class="kpi-label">إجمالي المستخدمين</div></div>
    </div>
    <div class="kpi-card success">
        <div class="kpi-icon">✅</div>
        <div class="kpi-body"><div class="kpi-value"><?= $stats['active'] ?></div><div class="kpi-label">نشطون</div></div>
    </div>
    <div class="kpi-card teal">
        <div class="kpi-icon">🏛️</div>
        <div class="kpi-body"><div class="kpi-value"><?= $stats['managers'] ?></div><div class="kpi-label">مديرو فروع</div></div>
    </div>
    <div class="kpi-card navy">
        <div class="kpi-icon">👤</div>
        <div class="kpi-body"><div class="kpi-value"><?= $stats['employees'] ?></div><div class="kpi-label">موظفو فروع</div></div>
    </div>
</div>

<!-- Alerts -->
<?php if ($msg): ?>
<div class="alert-box info" style="margin-bottom:16px">
    <span class="alert-icon">✅</span>
    <div><div class="alert-msg"><?= htmlspecialchars($msg) ?></div></div>
</div>
<?php endif; ?>
<?php if ($err): ?>
<div class="alert-box danger" style="margin-bottom:16px">
    <span class="alert-icon">⚠️</span>
    <div><div class="alert-msg"><?= htmlspecialchars($err) ?></div></div>
</div>
<?php endif; ?>

<!-- Filter + Add -->
<div class="card" style="margin-bottom:20px">
    <div class="card-body">
        <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">بحث</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم أو اسم مستخدم..." class="form-control" style="width:180px">
            </div>
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">الدور</label>
                <select name="role" class="form-control" style="width:160px">
                    <option value="all">كل الأدوار</option>
                    <option value="admin"           <?= $filter_role==='admin'?'selected':'' ?>>مدير النظام</option>
                    <option value="executive"       <?= $filter_role==='executive'?'selected':'' ?>>الإدارة العليا</option>
                    <option value="branch_manager"  <?= $filter_role==='branch_manager'?'selected':'' ?>>مدير فرع</option>
                    <option value="branch_employee" <?= $filter_role==='branch_employee'?'selected':'' ?>>موظف فرع</option>
                </select>
            </div>
            <div>
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;color:var(--gray-700)">الفرع</label>
                <select name="branch" class="form-control" style="width:160px">
                    <option value="all">كل الفروع</option>
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['branch_id'] ?>" <?= $filter_branch==$b['branch_id']?'selected':'' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary" style="width:auto">🔍 بحث</button>
            <a href="users.php" class="btn" style="width:auto;background:var(--gray-100);color:var(--gray-700)">إعادة تعيين</a>
            <button type="button" onclick="document.getElementById('addModal').classList.add('open')" class="btn btn-gold" style="width:auto;margin-right:auto">+ مستخدم جديد</button>
        </form>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <div class="card-title"><span class="icon">👥</span> المستخدمون</div>
        <span class="badge badge-teal"><?= count($users) ?> مستخدم</span>
    </div>
    <div class="card-body" style="padding:0">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>الاسم الكامل</th>
                        <th>اسم المستخدم</th>
                        <th>الدور</th>
                        <th>الفرع</th>
                        <th>الحالة</th>
                        <th>تاريخ الإضافة</th>
                        <th>إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$users): ?>
                <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--gray-500)">لا توجد نتائج</td></tr>
                <?php endif; ?>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td style="color:var(--gray-500);font-size:12px"><?= $u['user_id'] ?></td>
                    <td><strong><?= htmlspecialchars($u['full_name']) ?></strong></td>
                    <td dir="ltr" style="font-family:monospace;font-size:13px;color:var(--teal)"><?= htmlspecialchars($u['username']) ?></td>
                    <td>
                        <?php
                        $roleColors = ['admin'=>'navy','executive'=>'gold','branch_manager'=>'teal','branch_employee'=>'gray'];
                        $rc = $roleColors[$u['role']] ?? 'gray';
                        ?>
                        <span class="badge badge-<?= $rc ?>"><?= role_label($u['role']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($u['branch_name'] ?? '—') ?></td>
                    <td>
                        <?= $u['is_active']
                            ? '<span class="badge badge-success">نشط</span>'
                            : '<span class="badge badge-danger">معطّل</span>' ?>
                    </td>
                    <td style="font-size:11px;color:var(--gray-500)"><?= date('Y-m-d', strtotime($u['created_at'])) ?></td>
                    <td>
                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                            <!-- Toggle -->
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="user_id" value="<?= $u['user_id'] ?>">
                                <button type="submit" class="btn btn-sm"
                                    style="background:<?= $u['is_active']?'var(--danger-light)':'var(--success-light)' ?>;color:<?= $u['is_active']?'var(--danger)':'var(--success)' ?>"
                                    onclick="return confirm('هل أنت متأكد؟')">
                                    <?= $u['is_active'] ? 'تعطيل' : 'تفعيل' ?>
                                </button>
                            </form>
                            <!-- Reset Password -->
                            <button onclick="openReset(<?= $u['user_id'] ?>, '<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>')"
                                class="btn btn-sm" style="background:var(--warn-light);color:var(--warn)">
                                🔑 كلمة المرور
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Add User Modal ── -->
<div class="modal-backdrop" id="addModal">
    <div class="modal">
        <div class="modal-title">➕ إضافة مستخدم جديد</div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الاسم الكامل *</label>
                    <input type="text" name="full_name" class="form-control" required placeholder="محمد أحمد...">
                </div>
                <div class="form-group">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">اسم المستخدم *</label>
                    <input type="text" name="username" class="form-control" dir="ltr" required placeholder="user123">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">كلمة المرور *</label>
                    <input type="password" name="password" class="form-control" required placeholder="6 أحرف على الأقل">
                </div>
                <div class="form-group">
                    <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الدور</label>
                    <select name="role" class="form-control">
                        <option value="branch_employee">موظف فرع</option>
                        <option value="branch_manager">مدير فرع</option>
                        <option value="executive">إدارة عليا</option>
                        <option value="admin">مدير نظام</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">الفرع</label>
                <select name="branch_id" class="form-control">
                    <?php foreach ($branches as $b): ?>
                    <option value="<?= $b['branch_id'] ?>"><?= htmlspecialchars($b['branch_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;gap:10px;margin-top:14px">
                <button type="submit" class="btn btn-primary" style="flex:1">💾 حفظ</button>
                <button type="button" onclick="document.getElementById('addModal').classList.remove('open')"
                    class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reset Password Modal ── -->
<div class="modal-backdrop" id="resetModal">
    <div class="modal">
        <div class="modal-title">🔑 إعادة تعيين كلمة المرور</div>
        <p id="resetUserName" style="font-size:13px;color:var(--gray-500);margin-bottom:16px"></p>
        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">
            <div class="form-group">
                <label style="font-size:12px;font-weight:700;display:block;margin-bottom:5px">كلمة المرور الجديدة *</label>
                <input type="password" name="new_password" id="newPassword" class="form-control" required placeholder="6 أحرف على الأقل">
            </div>
            <div style="display:flex;gap:10px;margin-top:14px">
                <button type="submit" class="btn btn-primary" style="flex:1">تحديث</button>
                <button type="button" onclick="document.getElementById('resetModal').classList.remove('open')"
                    class="btn" style="flex:1;background:var(--gray-100);color:var(--text)">إلغاء</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReset(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = 'المستخدم: ' + name;
    document.getElementById('newPassword').value = '';
    document.getElementById('resetModal').classList.add('open');
}
['addModal','resetModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('open');
    });
});
</script>

<?php close_layout(); ?>
