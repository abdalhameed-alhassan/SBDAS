<?php
require_once '../../includes/init.php';
require_once '../../includes/reports.php';
require_role('executive');
page_reports(null);   // null = كل الفروع
