<?php
// Call open_layout($title, $active) to start, close_layout() to end
function open_layout($title = 'SBDAS', $active = '') {
    $user = current_user();
    $role = $user['role'];
    $base = base_url() . '/';
    $nav = [];
    if ($role === 'admin') {
        $nav[] = ['icon'=>'📊','label'=>'لوحة التحكم','href'=>$base.'pages/admin/dashboard.php','key'=>'dashboard'];
        $nav[] = ['icon'=>'👥','label'=>'إدارة المستخدمين','href'=>$base.'pages/admin/users.php','key'=>'users'];
        $nav[] = ['icon'=>'🏛️','label'=>'إدارة الفروع','href'=>$base.'pages/admin/branches.php','key'=>'branches'];
        $nav[] = ['icon'=>'🔔','label'=>'التنبيهات','href'=>$base.'pages/admin/notifications.php','key'=>'notifications'];
    } elseif ($role === 'executive') {
        $nav[] = ['icon'=>'📊','label'=>'لوحة الإدارة العليا','href'=>$base.'pages/executive/dashboard.php','key'=>'dashboard'];
        $nav[] = ['icon'=>'🏛️','label'=>'مقارنة الفروع','href'=>$base.'pages/executive/branches.php','key'=>'branches'];
        $nav[] = ['icon'=>'💰','label'=>'التحليل المالي','href'=>$base.'pages/executive/financial.php','key'=>'financial'];
        $nav[] = ['icon'=>'📱','label'=>'التحليل الرقمي','href'=>$base.'pages/executive/digital.php','key'=>'digital'];
        $nav[] = ['icon'=>'📋','label'=>'التقارير','href'=>$base.'pages/executive/reports.php','key'=>'reports'];
        $nav[] = ['icon'=>'🔔','label'=>'التنبيهات والتوصيات','href'=>$base.'pages/executive/alerts.php','key'=>'alerts'];
    } else {
        $nav[] = ['icon'=>'📊','label'=>'لوحة الفرع',     'href'=>$base.'pages/branch_manager/dashboard.php','key'=>'dashboard'];
        $nav[] = ['icon'=>'💰','label'=>'التحليل المالي',  'href'=>$base.'pages/branch_manager/financial.php', 'key'=>'financial'];
        $nav[] = ['icon'=>'📱','label'=>'التحليل الرقمي',  'href'=>$base.'pages/branch_manager/digital.php',  'key'=>'digital'];
        $nav[] = ['icon'=>'📋','label'=>'تقارير الفرع',    'href'=>$base.'pages/branch_manager/reports.php',  'key'=>'reports'];
    }
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> — SBDAS</title>
<link rel="icon" type="image/png" href="<?= $base ?>img/sbdas-mark.png">
<link rel="stylesheet" href="<?= $base ?>css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-brand">
      <a class="brand-logo" href="<?= $base ?>pages/<?= $role === 'admin' ? 'admin' : ($role === 'executive' ? 'executive' : 'branch_manager') ?>/dashboard.php">
        <img class="brand-img" src="<?= $base ?>img/sbdas-logo.png" alt="SBDAS — نظام دعم اتخاذ القرار والتحليل المصرفي للمصارف السودانية">
      </a>
    </div>

    <div class="sidebar-user">
      <div class="avatar">👤</div>
      <div class="user-info">
        <div class="name"><?= htmlspecialchars($user['name']) ?></div>
        <div class="role"><?= role_label($role) ?></div>
      </div>
    </div>

    <nav class="sidebar-nav">
      <div class="nav-section-label">القائمة الرئيسية</div>
      <?php foreach ($nav as $item): ?>
      <a class="nav-item <?= $active === $item['key'] ? 'active' : '' ?>" href="<?= $item['href'] ?>">
        <span class="nav-icon"><?= $item['icon'] ?></span>
        <?= $item['label'] ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <a href="<?= $base ?>logout.php">🚪 تسجيل الخروج</a>
    </div>
  </aside>

  <div class="main">
    <header class="topbar">
      <div class="topbar-title"><?= htmlspecialchars($title) ?></div>
      <div class="topbar-right">
        <div class="topbar-date"><?= date('l، d F Y') ?></div>
        <div class="notif-badge">🔔<span class="dot"></span></div>
      </div>
    </header>
    <div class="page-content">
<?php
} // end open_layout

// ── Reusable UI helpers ──
// بطاقة KPI موحّدة: تُغني عن تكرار نفس بلوك الـ HTML في كل صفحة
function render_kpi_card($icon, $value, $label, $color = 'navy', $trend = null) {
    ?>
    <div class="kpi-card <?= htmlspecialchars($color) ?>">
        <div class="kpi-icon"><?= $icon ?></div>
        <div class="kpi-body">
            <div class="kpi-value"><?= $value ?></div>
            <div class="kpi-label"><?= htmlspecialchars($label) ?></div>
            <?php if ($trend !== null): ?>
                <div class="kpi-trend <?= $trend['dir'] === 'up' ? 'up' : 'down' ?>"><?= htmlspecialchars($trend['text']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// صف شريط مقارنة (label + شريط تقدّم + قيمة) — يُستخدم في مقارنة الفروع/القنوات/البنوك
// ملاحظة: القيمة تُعرض خارج الشريط دائماً (وليس بداخله) لأن الشريط قد يكون
// ضيقاً جداً (نسبة صغيرة) فتُقصّ القيمة ولا تظهر بسبب overflow:hidden على المسار.
function render_bar_row($label, $pct, $displayValue, $color = 'var(--gold)', $labelWidth = '140px') {
    $pct = max(0, min(100, (int)$pct));
    ?>
    <div class="branch-bar-row" style="margin-bottom:8px">
        <div class="branch-bar-label" style="width:<?= htmlspecialchars($labelWidth) ?>;font-size:12px"><?= htmlspecialchars($label) ?></div>
        <div class="branch-bar-track" style="height:18px">
            <div class="branch-bar-fill" style="width:<?= $pct ?>%;background:<?= htmlspecialchars($color) ?>"></div>
        </div>
        <div class="branch-bar-val" style="width:auto;min-width:60px"><?= $displayValue ?> <span style="opacity:.6">(<?= $pct ?>%)</span></div>
    </div>
    <?php
}

function close_layout() {
?>
    </div><!-- page-content -->
  </div><!-- main -->
</div><!-- layout -->
</body>
</html>
<?php
} // end close_layout
