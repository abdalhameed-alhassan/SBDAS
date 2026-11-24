<?php
require_once '../../includes/init.php';
require_role('executive');

// ── Bank-wide figures ──
$k = row("SELECT
    (SELECT COUNT(*) FROM customers WHERE is_active=1) AS customers,
    (SELECT COUNT(*) FROM accounts) AS accounts,
    (SELECT COUNT(*) FROM loans) AS loans,
    (SELECT COUNT(*) FROM loans WHERE status='active') AS active_loans,
    (SELECT COUNT(*) FROM loans WHERE status='defaulted') AS defaulted,
    (SELECT COUNT(*) FROM complaints) AS complaints,
    (SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_progress')) AS open_complaints,
    (SELECT AVG(DATEDIFF(resolved_at,opened_at)) FROM complaints WHERE resolved_at IS NOT NULL) AS avg_days,
    (SELECT COALESCE(SUM(amount),0) FROM transactions WHERE txn_type='deposit' AND status='completed') AS deposits");

// ── Digital channel strip ("active in the last 30 days" is measured from the latest login recorded in the data) ──
$app = row("SELECT
    (SELECT COUNT(*) FROM app_users) AS users,
    (SELECT COUNT(*) FROM app_users WHERE last_login >= DATE_SUB((SELECT MAX(last_login) FROM app_users), INTERVAL 30 DAY)) AS active30,
    (SELECT COUNT(*) FROM app_sessions WHERE action_type='transfer') AS transfers,
    (SELECT COUNT(*) FROM internal_transfers WHERE status='completed' AND channel='app') AS internal,
    (SELECT COUNT(*) FROM external_transfers WHERE status='completed' AND channel='app') AS external,
    (SELECT COALESCE(SUM(amount),0) FROM external_transfers WHERE status='completed' AND channel='app' AND direction='inbound') AS inbound,
    (SELECT COALESCE(SUM(amount),0) FROM external_transfers WHERE status='completed' AND channel='app' AND direction='outbound') AS outbound");

// ── Performance indicators ──
[$growthLabels, $growth] = monthly('COUNT(*)', 'customers', 'is_active=1');
[$depLabels, $dep]       = monthly('SUM(amount)', 'transactions', "txn_type='deposit' AND status='completed'");
// Default rate = defaulted loans / (active + defaulted loans); paid-off loans are not part of the denominator
$live = (int)$k['active_loans'] + (int)$k['defaulted'];
$defaultRate   = $live ? round($k['defaulted'] / $live * 100, 1) : 0;
$complaintRate = $k['complaints'] ? round($k['open_complaints'] / $k['complaints'] * 100, 1) : 0;
// Customer growth = registrations in the latest month versus the month before; undefined (null) when there is no earlier month or it is zero
$last = end($growth); $prev = prev($growth);
$growthRate = ($prev) ? round(($last - $prev) / $prev * 100, 1) : null;

open_layout('لوحة الإدارة العليا', 'dashboard');
?>

<div class="strip">
  <div class="strip-title">📱 ملخص القناة الرقمية</div>
  <?php foreach ([
      ['👥 مستخدمو التطبيق', number_format($app['users'])],
      ['🟢 نشط آخر 30 يوم', number_format($app['active30'])],
      ['🔄 عمليات التحويل عبر التطبيق', number_format($app['transfers'])],
      ['🏦 تحويلات داخلية عبر التطبيق', number_format($app['internal'])],
      ['🌐 تحويلات لبنوك أخرى عبر التطبيق', number_format($app['external'])],
      ['📥 وارد خارجي عبر التطبيق', fmt($app['inbound']) . ' SDG'],
      ['📤 صادر خارجي عبر التطبيق', fmt($app['outbound']) . ' SDG'],
  ] as [$label, $value]): ?>
  <div class="strip-item"><div class="strip-label"><?= $label ?></div><div class="strip-value"><?= $value ?></div></div>
  <?php endforeach; ?>
</div>

<?php section_title('📈 مؤشرات الأداء الرئيسية');
kpi_grid([
    ['📉', $defaultRate . '%', 'نسبة تعثر القروض', $defaultRate > 20 ? 'danger' : ($defaultRate > 10 ? 'warn' : 'success'),
        $defaultRate > 20 ? '▲ مرتفعة' : ($defaultRate > 10 ? '● تحت المراقبة' : '✔ ضمن الحد المقبول')],
    ['📣', $complaintRate . '%', 'نسبة الشكاوى المفتوحة', 'warn'],
    ['⏱️', $k['avg_days'] === null ? 'غير محدد' : round($k['avg_days'], 1), 'متوسط أيام معالجة الشكوى', 'teal'],
    ['📈', $growthRate === null ? 'غير محدد' : (($growthRate >= 0 ? '+' : '') . $growthRate . '%'), 'معدل نمو العملاء',
        $growthRate === null ? 'teal' : ($growthRate >= 0 ? 'success' : 'danger')],
]);
section_title('🏦 الأرقام الأساسية');
kpi_grid([
    ['👥', number_format($k['customers']), 'إجمالي العملاء', 'gold'],
    ['🏦', number_format($k['accounts']), 'إجمالي الحسابات', 'teal'],
    ['💳', number_format($k['loans']), 'إجمالي القروض', 'navy'],
    ['💵', fmt($k['deposits']), 'إجمالي الإيداعات (SDG)', 'success'],
]); ?>

<div class="grid-2">
  <div><?php card_start('نمو العملاء الشهري', '📈'); chart('growth', 'line', $growthLabels, [['عملاء جدد', $growth, '#7F8691']], 220); card_end(); ?></div>
  <div><?php card_start('الإيداعات حسب الشهر (SDG)', '💵'); chart('dep', 'bar', $depLabels, [['الإيداعات', $dep, '#C5A04A']], 220); card_end(); ?></div>
</div>
<?php close_layout();
