<?php
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']  ?? '';
    $role      = $_POST['role']      ?? 'branch_employee';
    $branch_id = (int)($_POST['branch_id'] ?? 1);
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $conn->prepare("INSERT INTO users (branch_id,full_name,username,password_hash,role) VALUES (?,?,?,?,?)");
    $stmt->bind_param('issss', $branch_id, $full_name, $username, $hash, $role);
    $stmt->execute();
}
header('Location: ' . base_url() . '/pages/admin/dashboard.php');
exit;
