<?php

require_once __DIR__ . '/tests/bootstrap.php';

require_once __DIR__ . '/core/OrderNoGenerator.php';
require_once __DIR__ . '/core/PermissionService.php';
require_once __DIR__ . '/core/AuditService.php';
require_once __DIR__ . '/core/WarehouseRouter.php';
require_once __DIR__ . '/core/OrderService.php';
require_once __DIR__ . '/core/FulfillmentCallbackService.php';

class_alias('MockDatabase', 'Dealer\\Wallet\\Config\\Database');

require_once __DIR__ . '/src/Enum/WalletStatus.php';
require_once __DIR__ . '/src/Enum/FreezeStatus.php';
require_once __DIR__ . '/src/Enum/TransactionType.php';
require_once __DIR__ . '/src/Exception/WalletException.php';
require_once __DIR__ . '/src/Exception/InsufficientBalanceException.php';
require_once __DIR__ . '/src/Exception/WalletStateException.php';
require_once __DIR__ . '/src/Exception/WalletPermissionException.php';
require_once __DIR__ . '/src/Model/Wallet.php';
require_once __DIR__ . '/src/Model/FreezeRecord.php';
require_once __DIR__ . '/src/Model/Transaction.php';
require_once __DIR__ . '/src/Repository/WalletRepository.php';
require_once __DIR__ . '/src/Repository/FreezeRecordRepository.php';
require_once __DIR__ . '/src/Repository/TransactionRepository.php';
require_once __DIR__ . '/src/StateMachine/WalletStateMachine.php';
require_once __DIR__ . '/src/Service/ReconciliationService.php';
require_once __DIR__ . '/src/Service/WalletService.php';

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Exception\WalletPermissionException;

$db = MockDatabase::getInstance();
MockDatabase::resetInstance();
$db = MockDatabase::getInstance();

$db->createTable('dealer_wallet', [
    'id', 'dealer_id', 'balance', 'frozen_amount', 'available_amount',
    'status', 'version', 'created_at', 'updated_at'
]);
$db->createTable('dealer_wallet_freeze_record', [
    'id', 'wallet_id', 'dealer_id', 'freeze_no', 'amount', 'remaining_amount',
    'status', 'reason', 'expired_at', 'operator', 'created_at', 'updated_at'
]);
$db->createTable('dealer_wallet_transaction', [
    'id', 'wallet_id', 'dealer_id', 'type', 'amount', 'balance_before',
    'balance_after', 'frozen_before', 'frozen_after', 'related_no',
    'operator', 'remark', 'created_at'
]);

echo "\033[1;36m============================================\033[0m\n";
echo "\033[1;36m  经销商钱包状态机 - 回滚提示和重试入口测试\033[0m\n";
echo "\033[1;36m============================================\033[0m\n\n";

$walletService = new WalletService();

$walletService->getPermissionService()->setOperatorContext([
    'operator_id' => 1,
    'dealer_id' => null,
    'roles' => ['super_admin'],
    'permissions' => ['*'],
    'scoped_dealer_ids' => '*',
]);

$dealerId = 1;

echo "【测试 1】创建钱包并充值，确保初始数据正常\n";
$result = $walletService->recharge($dealerId, 1000.00, ['operator' => 'admin', 'remark' => '初始充值']);
echo "  充值成功：余额 ¥{$result['balance']}\n\n";

echo "【测试 2】测试余额不足时的回滚提示和重试入口\n";
try {
    $walletService->withdraw($dealerId, 9999.00, ['operator' => 'admin', 'remark' => '超额提现']);
    echo "  \033[0;31m错误：应该抛出异常但没有\033[0m\n";
} catch (InsufficientBalanceException $e) {
    echo "  \033[0;32m✓ 成功捕获余额不足异常\033[0m\n";
    echo "  异常消息：{$e->getMessage()}\n\n";
    
    if ($e->hasRollbackInfo()) {
        $rollback = $e->getRollbackInfo();
        echo "  \033[1;33m=== 回滚信息 ===\033[0m\n";
        echo "  回滚成功：" . ($rollback['rollback_success'] ? '是' : '否') . "\n";
        echo "  回滚时间：{$rollback['rollback_time']}\n";
        echo "  操作名称：{$rollback['operation_name']}\n";
        echo "  回滚提示：{$rollback['rollback_message']}\n";
        echo "  钱包快照：\n";
        echo "    - 状态：{$rollback['wallet_snapshot']['status_before_name']}\n";
        echo "    - 余额：¥{$rollback['wallet_snapshot']['balance_before']}\n";
        echo "    - 冻结：¥{$rollback['wallet_snapshot']['frozen_amount_before']}\n";
        echo "    - 可用：¥{$rollback['wallet_snapshot']['available_amount_before']}\n";
        echo "  回滚详情：\n";
        foreach ($rollback['rollback_details'] as $detail) {
            echo "    - {$detail}\n";
        }
        echo "\n";
    } else {
        echo "  \033[0;31m✗ 缺少回滚信息\033[0m\n\n";
    }
    
    if ($e->hasRetryInfo()) {
        $retry = $e->getRetryInfo();
        echo "  \033[1;33m=== 重试入口 ===\033[0m\n";
        echo "  是否可重试：" . ($retry['retryable'] ? '是' : '否') . "\n";
        echo "  重试策略：{$retry['retry_strategy']}\n";
        echo "  最大重试次数：{$retry['max_retries']}\n";
        echo "  重试按钮文字：{$retry['retry_entry']['retry_button_text']}\n";
        echo "  重试提示：{$retry['retry_entry']['retry_hint']}\n";
        echo "  建议：\n";
        foreach ($retry['suggestions'] as $suggestion) {
            echo "    - {$suggestion}\n";
        }
        if (isset($retry['retry_entry']['retry_params'])) {
            echo "  重试参数：\n";
            foreach ($retry['retry_entry']['retry_params'] as $key => $value) {
                echo "    - {$key}: {$value}\n";
            }
        }
        echo "\n";
    } else {
        echo "  \033[0;31m✗ 缺少重试信息\033[0m\n\n";
    }
    
    echo "  \033[1;36m=== 完整上下文（getFullContext）===\033[0m\n";
    $ctx = $e->getFullContext();
    echo "  message: {$ctx['message']}\n";
    echo "  rollback 存在: " . (empty($ctx['rollback']) ? '否' : '是') . "\n";
    echo "  retry 存在: " . (empty($ctx['retry']) ? '否' : '是') . "\n";
    echo "\n";
}

echo "【测试 3】测试状态流转失败时的回滚提示和重试入口\n";
try {
    $walletService->freeze($dealerId, 1000.00, ['operator' => 'admin', 'reason' => '全额冻结']);
    $walletService->freeze($dealerId, 1.00, ['operator' => 'admin', 'reason' => '超额冻结测试']);
    echo "  \033[0;31m错误：应该抛出异常但没有\033[0m\n";
} catch (WalletStateException $e) {
    echo "  \033[0;32m✓ 成功捕获状态流转异常\033[0m\n";
    echo "  异常消息：{$e->getMessage()}\n\n";
    
    if ($e->hasRollbackInfo()) {
        $rollback = $e->getRollbackInfo();
        echo "  \033[1;33m=== 回滚信息 ===\033[0m\n";
        echo "  回滚成功：" . ($rollback['rollback_success'] ? '是' : '否') . "\n";
        echo "  操作名称：{$rollback['operation_name']}\n";
        echo "  回滚提示：{$rollback['rollback_message']}\n";
        echo "  操作金额：¥{$rollback['operation_amount']}\n";
        echo "  回滚详情：\n";
        foreach ($rollback['rollback_details'] as $detail) {
            echo "    - {$detail}\n";
        }
        echo "\n";
    } else {
        echo "  \033[0;31m✗ 缺少回滚信息\033[0m\n\n";
    }
    
    if ($e->hasRetryInfo()) {
        $retry = $e->getRetryInfo();
        echo "  \033[1;33m=== 重试入口 ===\033[0m\n";
        echo "  是否可重试：" . ($retry['retryable'] ? '是' : '否') . "\n";
        echo "  重试按钮文字：{$retry['retry_entry']['retry_button_text']}\n";
        echo "  重试提示：{$retry['retry_entry']['retry_hint']}\n";
        echo "  建议：\n";
        foreach ($retry['suggestions'] as $suggestion) {
            echo "    - {$suggestion}\n";
        }
        echo "\n";
    } else {
        echo "  \033[0;31m✗ 缺少重试信息\033[0m\n\n";
    }
}

echo "【测试 4】测试权限异常时的回滚提示和重试入口\n";
$walletService->getPermissionService()->setOperatorContext([
    'operator_id' => 2,
    'dealer_id' => 999,
    'roles' => ['dealer'],
    'permissions' => ['wallet:view:own'],
    'scoped_dealer_ids' => [999],
]);
try {
    $walletService->getWallet($dealerId);
    echo "  \033[0;31m错误：应该抛出异常但没有\033[0m\n";
} catch (WalletPermissionException $e) {
    echo "  \033[0;32m✓ 成功捕获权限异常\033[0m\n";
    echo "  异常消息：{$e->getMessage()}\n";
    if ($e->hasRollbackInfo()) {
        echo "  回滚信息: 存在\n";
        $rollback = $e->getRollbackInfo();
        echo "  回滚提示：{$rollback['rollback_message']}\n";
        foreach ($rollback['rollback_details'] as $detail) {
            echo "    - {$detail}\n";
        }
    } else {
        echo "  \033[0;33m⚠ 权限异常通常不涉及回滚（还未开始事务）\033[0m\n";
    }
    if ($e->hasRetryInfo()) {
        $retry = $e->getRetryInfo();
        echo "  重试入口: 存在\n";
        echo "  重试按钮文字：{$retry['retry_entry']['retry_button_text']}\n";
        echo "  建议：\n";
        foreach ($retry['suggestions'] as $suggestion) {
            echo "    - {$suggestion}\n";
        }
    } else {
        echo "  \033[0;33m⚠ 权限异常不包含重试信息（在事务外抛出）\033[0m\n";
    }
    echo "\n";
}

$walletService->getPermissionService()->setOperatorContext([
    'operator_id' => 1,
    'dealer_id' => null,
    'roles' => ['super_admin'],
    'permissions' => ['*'],
    'scoped_dealer_ids' => '*',
]);

echo "【测试 5】验证操作后钱包状态未受影响（回滚生效）\n";
$wallet = $walletService->getWallet($dealerId);
echo "  当前状态：{$wallet['status_name']}\n";
echo "  当前余额：¥{$wallet['balance']}\n";
echo "  当前冻结：¥{$wallet['frozen_amount']}\n";
echo "  当前可用：¥{$wallet['available_amount']}\n";
echo "  \033[0;32m✓ 回滚生效，钱包状态未受失败操作影响\033[0m\n\n";

echo "\033[1;36m============================================\033[0m\n";
echo "\033[1;36m  测试完成\033[0m\n";
echo "\033[1;36m============================================\033[0m\n";
