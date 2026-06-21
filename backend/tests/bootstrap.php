<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_X_CALLBACK_TOKEN'] = 'wh_callback_token_2024';

require_once __DIR__ . '/MockDatabase.php';
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/TestDataSeeder.php';

if (!class_exists('Database')) {
    class_alias('MockDatabase', 'Database');
}

require_once __DIR__ . '/../core/OrderNoGenerator.php';
require_once __DIR__ . '/../core/PermissionService.php';
require_once __DIR__ . '/../core/AuditService.php';
require_once __DIR__ . '/../core/WarehouseRouter.php';
require_once __DIR__ . '/../core/OrderService.php';
require_once __DIR__ . '/../core/FulfillmentCallbackService.php';
