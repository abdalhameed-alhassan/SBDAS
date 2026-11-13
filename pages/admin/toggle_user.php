<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_role('admin');
$id = (int)($_GET['id'] ?? 0);
if ($id && $id != $_SESSION['user_id']) {
    $conn->query("UPDATE users SET is_active = 1 - is_active WHERE user_id = $id");
}
header('Location: ' . base_url() . '/pages/admin/dashboard.php');
exit;
