<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

if (is_logged_in()) redirect_by_role($_SESSION['role']);

$error = '';

// ── الدخول التجريبي لأعضاء الفريق (بدون اسم مستخدم وكلمة مرور) ──
// يعمل فقط عند ضبط متغير البيئة DEMO_LOGIN=1، ولحسابين محددين فقط (الإدارة العليا ومدير الفرع الرئيسي).
$demoEnabled = getenv('DEMO_LOGIN') === '1';
$demoAccounts = ['executive' => 'executive', 'branch_manager' => 'manager1'];
if ($demoEnabled && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo'], $demoAccounts[$_POST['demo']])) {
    $stmt = $conn->prepare("SELECT user_id, full_name, role, branch_id FROM users WHERE username=? AND is_active=1");
    $stmt->bind_param('s', $demoAccounts[$_POST['demo']]);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $row['user_id'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['role']      = $row['role'];
        $_SESSION['branch_id'] = $row['branch_id'];
        redirect_by_role($row['role']);
    }
    $error = 'الحساب التجريبي غير متاح حالياً.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $conn->prepare("SELECT user_id, full_name, role, branch_id, password_hash FROM users WHERE username=? AND is_active=1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row && password_verify($password, $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = $row['user_id'];
        $_SESSION['full_name'] = $row['full_name'];
        $_SESSION['role']      = $row['role'];
        $_SESSION['branch_id'] = $row['branch_id'];
        redirect_by_role($row['role']);
    } else {
        $error = 'اسم المستخدم أو كلمة المرور غير صحيحة.';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تسجيل الدخول — SBDAS</title>
<link rel="icon" type="image/png" href="img/sbdas-mark.png">
<link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <img class="login-logo-img" src="img/sbdas-logo.png" alt="SBDAS — نظام دعم اتخاذ القرار والتحليل المصرفي للمصارف السودانية">
      <p>تسجيل الدخول إلى النظام</p>
    </div>

    <?php if ($error): ?>
    <div class="error-msg">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($demoEnabled): ?>
    <div style="text-align:center;font-size:13px;color:var(--gray-700);margin-bottom:12px;font-weight:700">
      للمراجعة: اختر الواجهة للدخول مباشرة (بدون اسم مستخدم أو كلمة مرور)
    </div>
    <form method="POST" style="display:grid;gap:10px;margin-bottom:6px">
      <button type="submit" name="demo" value="executive" class="btn btn-primary">📊 الدخول إلى واجهة الإدارة العليا</button>
      <button type="submit" name="demo" value="branch_manager" class="btn btn-gold">🏛️ الدخول إلى واجهة مدير الفرع</button>
    </form>
    <details style="margin-top:14px">
      <summary style="cursor:pointer;font-size:12px;color:var(--gray-500);text-align:center">الدخول بحساب (اسم مستخدم وكلمة مرور)</summary>
    <?php endif; ?>
    <form method="POST" style="margin-top:12px">
      <div class="form-group">
        <label>اسم المستخدم</label>
        <input type="text" name="username" placeholder="أدخل اسم المستخدم" required autofocus>
      </div>
      <div class="form-group">
        <label>كلمة المرور</label>
        <input type="password" name="password" placeholder="••••••••" required>
      </div>
      <button type="submit" class="btn btn-primary">دخول إلى النظام</button>
    </form>
    <?php if ($demoEnabled): ?></details><?php endif; ?>

    <?php if (!$demoEnabled): ?>
    <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--gray-100);font-size:11.5px;color:var(--gray-500);text-align:center">
      بيانات تجريبية: admin / password | executive / password
    </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
