<?php
// ============================================================
// القالب الوحيد لكل الصفحات + مكوّنات العرض المشتركة
//   open_layout(عنوان, مفتاح القائمة) ... محتوى الصفحة ... close_layout()
// ============================================================

// ── قوائم التسميات العربية (مكان واحد لكل الصفحات) ──
const L_TXN  = ['deposit'=>'إيداع','withdrawal'=>'سحب','transfer_in'=>'تحويل وارد','transfer_out'=>'تحويل صادر'];
const L_SUB  = ['cash'=>'نقدي','cheque'=>'شيك','app_transfer'=>'تطبيق','web_transfer'=>'ويب','internal_transfer'=>'داخلي',
                'bban'=>'BBAN','atm'=>'صرف آلي','pos'=>'نقطة بيع'];
const L_STATUS = ['active'=>'نشط','frozen'=>'مجمّد','closed'=>'مغلق','paid'=>'مسدَّد','defaulted'=>'متعثر',
                  'open'=>'مفتوحة','in_progress'=>'قيد المعالجة','resolved'=>'محلولة'];
const L_ACCOUNT = ['savings'=>'توفير','current'=>'جاري','investment'=>'استثماري'];
const L_GENDER  = ['male'=>'ذكر','female'=>'أنثى'];

// قائمة كل دور: [أيقونة، نص، صفحة]
const NAV = [
    'executive' => [['📊','لوحة الإدارة العليا','dashboard'], ['🏛️','مقارنة الفروع','branches'],
                    ['📋','التحليل المالي والتقارير','reports']],
    'branch_manager' => [['📊','لوحة الفرع','dashboard'], ['📋','التحليل المالي والتقارير','reports']],
];

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }

// رقم مختصر: 1.2م / 3.4ألف
function fmt($n) {
    $n = (float)$n;
    if (abs($n) >= 1000000) return number_format($n / 1000000, 1) . 'م';
    if (abs($n) >= 1000)    return number_format($n / 1000, 1) . 'ألف';
    return number_format($n);
}

// ── الهيكل: القائمة الجانبية + الشريط العلوي ──
function open_layout($title, $active = '') {
    $user   = current_user();
    $base   = base_url() . '/';
    $folder = ROLES[$user['role']][1];
    $nav    = NAV[$folder];
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($title) ?> — SBDAS</title>
<link rel="icon" type="image/png" href="<?= $base ?>img/sbdas-mark.png">
<link rel="stylesheet" href="<?= $base ?>css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
<div class="layout">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <a class="brand-logo" href="<?= $base ?>pages/<?= $folder ?>/dashboard.php"><img class="brand-img" src="<?= $base ?>img/sbdas-logo.png" alt="SBDAS"></a>
    </div>
    <div class="sidebar-user">
      <div class="avatar">👤</div>
      <div class="user-info"><div class="name"><?= h($user['name']) ?></div><div class="role"><?= role_label($user['role']) ?></div></div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">القائمة الرئيسية</div>
      <?php foreach ($nav as [$icon, $label, $page]): ?>
      <a class="nav-item <?= $active === $page ? 'active' : '' ?>" href="<?= $base ?>pages/<?= $folder ?>/<?= $page ?>.php">
        <span class="nav-icon"><?= $icon ?></span> <?= $label ?>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer"><a href="<?= $base ?>logout.php">🚪 تسجيل الخروج</a></div>
  </aside>
  <div class="main">
    <header class="topbar">
      <div class="topbar-start">
        <button type="button" class="menu-btn" id="menuBtn" aria-label="القائمة">☰</button>
        <div class="topbar-title"><?= h($title) ?></div>
      </div>
      <div class="topbar-date"><?= date('Y-m-d') ?></div>
    </header>
    <div class="page-content">
<?php
}

function close_layout() {
?>
    </div>
  </div>
</div>
<script>
// القائمة الجانبية على الجوال + النوافذ المنبثقة (زر data-modal يفتح، data-close أو النقر خارجها يغلق)
(function(){
  var sb=document.getElementById('sidebar'), ov=document.getElementById('sidebarOverlay');
  function side(open){ sb.classList.toggle('open',open); ov.classList.toggle('show',open); }
  document.getElementById('menuBtn').onclick=function(){ side(!sb.classList.contains('open')); };
  ov.onclick=function(){ side(false); };
  document.addEventListener('click',function(e){
    var o=e.target.closest('[data-modal]'); if(o) document.getElementById(o.dataset.modal).classList.add('open');
    if(e.target.closest('[data-close]')||e.target.classList.contains('modal-backdrop')) e.target.closest('.modal-backdrop').classList.remove('open');
  });
})();
</script>
</body>
</html>
<?php
}

// ── مكوّنات العرض ──

function badge($text, $cls = 'teal') { return '<span class="badge badge-' . $cls . '">' . h($text) . '</span>'; }

// شبكة بطاقات مؤشرات: كل عنصر [أيقونة، قيمة، تسمية، لون، سطر إضافي اختياري]
function kpi_grid($items) {
    echo '<div class="kpi-grid">';
    foreach ($items as $i) {
        echo '<div class="kpi-card ' . ($i[3] ?? 'navy') . '"><div class="kpi-icon">' . $i[0] . '</div><div class="kpi-body">'
           . '<div class="kpi-value">' . $i[1] . '</div><div class="kpi-label">' . h($i[2]) . '</div>'
           . (isset($i[4]) ? '<div class="kpi-trend">' . h($i[4]) . '</div>' : '') . '</div></div>';
    }
    echo '</div>';
}

// بطاقة: card_start('عنوان','🏛️') ... card_end()؛ $flush=true لجدول يملأ البطاقة
function card_start($title, $icon = '', $right = '', $flush = false) {
    echo '<div class="card"><div class="card-header"><div class="card-title"><span class="icon">' . $icon . '</span> ' . h($title)
       . '</div>' . $right . '</div><div class="card-body"' . ($flush ? ' style="padding:0"' : '') . '>';
}
function card_end() { echo '</div></div>'; }

// جدول: العناوين نصوص، والخلايا HTML جاهز (استخدم h() و badge() عند الحاجة)
function data_table($headers, $rows, $empty = 'لا توجد بيانات') {
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headers as $t) echo '<th>' . h($t) . '</th>';
    echo '</tr></thead><tbody>';
    if (!$rows) echo '<tr><td colspan="' . count($headers) . '" class="empty">' . h($empty) . '</td></tr>';
    foreach ($rows as $r) { echo '<tr>'; foreach ($r as $c) echo '<td>' . $c . '</td>'; echo '</tr>'; }
    echo '</tbody></table></div>';
}

// بطاقة جدول جاهزة (بطاقة + جدول)
function table_card($title, $icon, $headers, $rows, $right = '') {
    card_start($title, $icon, $right, true);
    data_table($headers, $rows);
    card_end();
}

// مخطط Chart.js: $sets = [[تسمية، بيانات، لون]]
function chart($id, $type, $labels, $sets, $height = 240) {
    $ds = array_map(fn($s) => ['label' => $s[0], 'data' => $s[1], 'backgroundColor' => $s[2] . ($type === 'line' ? '33' : 'BB'),
                               'borderColor' => $s[2], 'borderWidth' => 1, 'tension' => .4, 'fill' => $type === 'line'], $sets);
    echo '<div class="chart-container" style="height:' . $height . 'px"><canvas id="' . $id . '"></canvas></div><script>new Chart(document.getElementById("' . $id . '"),'
       . json_encode(['type' => $type, 'data' => ['labels' => $labels, 'datasets' => $ds],
                      'options' => ['responsive' => true, 'maintainAspectRatio' => false,
                                    'plugins' => ['legend' => ['display' => count($sets) > 1, 'position' => 'bottom']],
                                    'scales' => ['x' => ['grid' => ['display' => false]], 'y' => ['beginAtZero' => true]]]],
                     JSON_UNESCAPED_UNICODE) . ');</script>';
}

// صندوق رقم ملوّن (قيمة كبيرة + سطر صغير)
function stat_box($label, $value, $sub, $color) {
    echo '<div class="stat-box ' . $color . '"><div class="stat-label">' . h($label) . '</div><div class="stat-value">' . $value
       . '</div><div class="stat-sub">' . h($sub) . '</div></div>';
}

// حقل نموذج: label + عنصر إدخال جاهز
function field($label, $input) { return '<div class="field"><label>' . h($label) . '</label>' . $input . '</div>'; }

// عنوان فرعي داخل الصفحة
function section_title($text) { echo '<div class="section-title">' . h($text) . '</div>'; }
