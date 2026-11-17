<?php
require_once '../../includes/init.php';
require_once '../../includes/financial.php';
require_role('branch_manager', 'branch_employee', 'admin');
page_financial((int)current_user()['branch_id']);   // فرع المستخدم فقط
