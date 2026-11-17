<?php
require_once '../../includes/init.php';
require_role('admin');

$s = row("SELECT (SELECT COUNT(*) FROM users WHERE is_active=1) AS users, (SELECT COUNT(*) FROM branches WHERE is_active=1) AS branches,
                 (SELECT COUNT(*) FROM customers WHERE is_active=1) AS customers, (SELECT COUNT(*) FROM accounts) AS accounts");
$latest = rows("SELECT u.full_name, u.username, u.role, b.branch_name, u.is_active FROM users u LEFT JOIN branches b ON b.branch_id=u.branch_id
                ORDER BY u.created_at DESC, u.user_id DESC LIMIT 6");

open_layout('لوحة مدير النظام', 'dashboard');
kpi_grid([['👥', $s['users'], 'المستخدمون النشطون', 'gold'], ['🏛️', $s['branches'], 'الفروع النشطة', 'teal'],
          ['👤', $s['customers'], 'العملاء', 'navy'], ['🏦', $s['accounts'], 'الحسابات', 'success']]);

$rows = [];
foreach ($latest as $u)
    $rows[] = ['<b>' . h($u['full_name']) . '</b>', '<span dir="ltr">' . h($u['username']) . '</span>', badge(role_label($u['role'])),
               h($u['branch_name'] ?? '—'), badge($u['is_active'] ? 'نشط' : 'معطّل', $u['is_active'] ? 'success' : 'danger')];
table_card('آخر المستخدمين المضافين', '👥', ['الاسم','اسم المستخدم','الدور','الفرع','الحالة'], $rows,
           '<a href="users.php" class="btn btn-gold btn-sm">إدارة المستخدمين ←</a>');
close_layout();
