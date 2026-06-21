<?php

require_once __DIR__ . '/tests/MockDatabase.php';
require_once __DIR__ . '/tests/TestCase.php';
require_once __DIR__ . '/tests/TestDataSeeder.php';

if (!class_exists('Dealer\\Wallet\\Config\\Database')) {
    class_alias('MockDatabase', 'Dealer\\Wallet\\Config\\Database');
}

require_once __DIR__ . '/src/Enum/WalletStatus.php';
require_once __DIR__ . '/src/Enum/FreezeStatus.php';
require_once __DIR__ . '/src/Enum/TransactionType.php';
require_once __DIR__ . '/src/Exception/WalletException.php';
require_once __DIR__ . '/src/Exception/WalletStateException.php';
require_once __DIR__ . '/src/Exception/WalletPermissionException.php';
require_once __DIR__ . '/src/Exception/InsufficientBalanceException.php';
require_once __DIR__ . '/src/Model/Wallet.php';
require_once __DIR__ . '/src/Model/FreezeRecord.php';
require_once __DIR__ . '/src/Model/Transaction.php';
require_once __DIR__ . '/src/StateMachine/WalletStateMachine.php';
require_once __DIR__ . '/src/Repository/WalletRepository.php';
require_once __DIR__ . '/src/Repository/FreezeRecordRepository.php';
require_once __DIR__ . '/src/Repository/TransactionRepository.php';
require_once __DIR__ . '/src/Service/ReconciliationService.php';
require_once __DIR__ . '/src/Service/WalletService.php';

echo "Checking property types...\n";

$classes = [
    'Dealer\\Wallet\\Repository\\WalletRepository',
    'Dealer\\Wallet\\Repository\\FreezeRecordRepository',
    'Dealer\\Wallet\\Repository\\TransactionRepository',
    'Dealer\\Wallet\\Service\\ReconciliationService',
    'Dealer\\Wallet\\Service\\WalletService',
];

foreach ($classes as $className) {
    $r = new ReflectionClass($className);
    if ($r->hasProperty('pdo')) {
        $p = $r->getProperty('pdo');
        $type = $p->getType();
        echo "{$className}->\$pdo type: " . ($type ? $type->getName() : 'none') . "\n";
    }
}

echo "\nDone.\n";
