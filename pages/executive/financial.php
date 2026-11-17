<?php
require_once '../../includes/init.php';
require_once '../../includes/financial.php';
require_role('executive', 'admin');
page_financial(null);   // null = كل الفروع
