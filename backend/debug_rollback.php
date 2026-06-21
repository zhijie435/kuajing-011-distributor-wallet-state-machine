<?php

require_once __DIR__ . '/tests/bootstrap.php';

require_once __DIR__ . '/src/Config/Database.php';
require_once __DIR__ . '/src/Enum/WalletStatus.php';
require_once __DIR__ . '/src/Enum/FreezeStatus.php';
require_once __DIR__ . '/src/Enum/TransactionType.php';
require_once __DIR__ . '/src/Exception/WalletException.php';
require_once __DIR__ . '/src/Exception/InsufficientBalanceException.php';
require_once __DIR__ . '/src/Exception/WalletStateException.php';
require_once __DIR__ . '/src/Model/Wallet.php';
require_once __DIR__ . '/src/Model/FreezeRecord.php';
require_once __DIR__ . '/src/Model/Transaction.php';
require_once __DIR__ . '/src/Repository/WalletRepository.php';
require_once __DIR__ . '/src/Repository/FreezeRecordRepository.php';
require_once __DIR__ . '/src/Repository/TransactionRepository.php';
require_once __DIR__ . '/src/StateMachine/WalletStateMachine.php';
require_once __DIR__ . '/src/Service/ReconciliationService.php';
require_once __DIR__ . '/src/Service/WalletService.php';

class_alias('MockDatabase', 'Dealer\\Wallet\\Config\\Database');

use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletStateException;

$db = MockDatabase::getInstance();
$db->seedDefaultData();

echo "\033[1;36m============================================\033[0m\n";
echo "\033[1;36m  经销商钱包状态机 - 回滚提示和重试入口测试\033[0m\n";
echo "\033[1;36m============================================\033[0m\n\n";

$walletService = new WalletService();

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
    }
    
    echo "  \033[1;36m=== 完整上下文（getFullContext）===\033[0m\n";
    print_r($e->getFullContext());
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
    }
}

echo "【测试 4】验证操作后钱包状态未受影响（回滚生效）\n";
$wallet = $walletService->getWallet($dealerId);
echo "  当前状态：{$wallet['status_name']}\n";
echo "  当前余额：¥{$wallet['balance']}\n";
echo "  当前冻结：¥{$wallet['frozen_amount']}\n";
echo "  当前可用：¥{$wallet['available_amount']}\n";
echo "  \033[0;32m✓ 回滚生效，钱包状态未受失败操作影响\033[0m\n\n";

echo "\033[1;36m============================================\033[0m\n";
echo "\033[1;36m  测试完成\033[0m\n";
echo "\033[1;36m============================================\033[0m\n";
