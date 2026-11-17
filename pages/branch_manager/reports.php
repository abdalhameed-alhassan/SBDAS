<?php
require_once '../../includes/init.php';
require_once '../../includes/reports.php';
require_role('branch_manager', 'branch_employee', 'admin');
page_reports((int)current_user()['branch_id']);   // فرع المستخدم فقط
