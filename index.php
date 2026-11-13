<?php
require_once 'includes/auth.php';
if (is_logged_in()) redirect_by_role($_SESSION['role']);
header('Location: login.php');
exit;
