<?php
require_once '../../includes/init.php';
require_once '../../includes/reports.php';
require_role('branch_manager');
page_reports((int)current_user()['branch_id']);   // the user's own branch only
