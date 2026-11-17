<?php
require_once '../../includes/init.php';
require_once '../../includes/reports.php';
require_role('executive', 'admin');
page_reports(null);   // null = كل الفروع
