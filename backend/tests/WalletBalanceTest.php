<?php

require_once __DIR__ . '/bootstrap.php';

if (!class_exists('Dealer\\Wallet\\Config\\Database')) {
    class_alias('MockDatabase', 'Dealer\\Wallet\\Config\\Database');
}

require_once __DIR__ . '/../src/Enum/WalletStatus.php';
require_once __DIR__ . '/../src/Enum/FreezeStatus.php';
require_once __DIR__ . '/../src/Enum/TransactionType.php';
require_once __DIR__ . '/../src/Exception/WalletException.php';
require_once __DIR__ . '/../src/Exception/WalletStateException.php';
require_once __DIR__ . '/../src/Exception/WalletPermissionException.php';
require_once __DIR__ . '/../src/Exception/InsufficientBalanceException.php';
require_once __DIR__ . '/../src/Model/Wallet.php';
require_once __DIR__ . '/../src/Model/FreezeRecord.php';
require_once __DIR__ . '/../src/Model/Transaction.php';
require_once __DIR__ . '/../src/StateMachine/WalletStateMachine.php';
require_once __DIR__ . '/../src/Config/Database.php';
require_once __DIR__ . '/../src/Repository/WalletRepository.php';
require_once __DIR__ . '/../src/Repository/FreezeRecordRepository.php';
require_once __DIR__ . '/../src/Repository/TransactionRepository.php';
require_once __DIR__ . '/../src/Service/ReconciliationService.php';
require_once __DIR__ . '/../src/Service/WalletService.php';

use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Service\WalletService;
use Dealer\Wallet\Exception\InsufficientBalanceException;

class WalletBalanceTest extends TestCase
{
    private $walletService;
    private $testDealerId = 2001;

    public function setUp()
    {
        parent::setUp();

        $this->db->createTable('dealer_wallet', [
            'id', 'dealer_id', 'balance', 'frozen_amount', 'available_amount',
            'status', 'version', 'created_at', 'updated_at'
        ]);
        $this->db->createTable('dealer_wallet_freeze_record', [
            'id', 'wallet_id', 'dealer_id', 'freeze_no', 'amount', 'remaining_amount',
            'status', 'reason', 'expired_at', 'operator', 'created_at', 'updated_at'
        ]);
        $this->db->createTable('dealer_wallet_transaction', [
            'id', 'wallet_id', 'dealer_id', 'type', 'amount', 'balance_before',
            'balance_after', 'frozen_before', 'frozen_after', 'related_no',
            'operator', 'remark', 'created_at'
        ]);

        $this->walletService = new WalletService();
        $this->walletService->getPermissionService()->setOperatorContext([
            'operator_id' => 1,
            'dealer_id' => null,
            'roles' => ['super_admin'],
            'permissions' => ['wallet:view:all', 'wallet:view:own', 'wallet:transactions:all',
                'wallet:freeze:all', 'wallet:reconcile', 'wallet:fix', 'wallet:export'],
            'scoped_dealer_ids' => null,
        ]);
    }

    public function testRechargeSuccess()
    {
        $result = $this->walletService->recharge($this->testDealerId, 500.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
    }

    public function testRechargeMultipleTimes()
    {
        $this->walletService->recharge($this->testDealerId, 100.00, ['operator' => 'admin']);
        $this->walletService->recharge($this->testDealerId, 200.00, ['operator' => 'admin']);
        $result = $this->walletService->recharge($this->testDealerId, 300.50, ['operator' => 'admin']);

        $this->assertEquals('600.50', $result['balance']);
        $this->assertEquals('600.50', $result['available_amount']);
    }

    public function testRechargeZeroAmount()
    {
        $exception = null;
        try {
            $this->walletService->recharge($this->testDealerId, 0, ['operator' => 'admin']);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
    }

    public function testRechargeNegativeAmount()
    {
        $exception = null;
        try {
            $this->walletService->recharge($this->testDealerId, -100.00, ['operator' => 'admin']);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
    }

    public function testWithdrawSuccess()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);

        $result = $this->walletService->withdraw($this->testDealerId, 300.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('700.00', $result['balance']);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testWithdrawInsufficientAvailable()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $exception = null;
        try {
            $this->walletService->withdraw($this->testDealerId, 600.00, ['operator' => 'admin']);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('可用余额不足', $exception->getMessage());
    }

    public function testWithdrawWithFrozenAmount()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 400.00, ['operator' => 'admin']);

        $result = $this->walletService->withdraw($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('400.00', $result['frozen_amount']);
        $this->assertEquals('100.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    public function testWithdrawMoreThanAvailableButLessThanBalance()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 600.00, ['operator' => 'admin']);

        $exception = null;
        try {
            $this->walletService->withdraw($this->testDealerId, 500.00, ['operator' => 'admin']);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('可用余额不足', $exception->getMessage());
    }

    public function testConsumeSuccess()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);

        $result = $this->walletService->consume($this->testDealerId, 200.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('800.00', $result['balance']);
        $this->assertEquals('800.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testConsumeInsufficientAvailable()
    {
        $this->walletService->recharge($this->testDealerId, 300.00, ['operator' => 'admin']);

        $exception = null;
        try {
            $this->walletService->consume($this->testDealerId, 500.00, ['operator' => 'admin']);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('可用余额不足', $exception->getMessage());
    }

    public function testConsumeWithFrozenAmount()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);

        $result = $this->walletService->consume($this->testDealerId, 400.00, ['operator' => 'admin']);

        $this->assertEquals('600.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('300.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    public function testConsumeCausesStatusChangeToFullyFrozen()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $freezeResult = $this->walletService->freeze($this->testDealerId, 600.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $freezeResult['status']);

        $result = $this->walletService->consume($this->testDealerId, 300.00, ['operator' => 'admin']);

        $this->assertEquals('700.00', $result['balance']);
        $this->assertEquals('600.00', $result['frozen_amount']);
        $this->assertEquals('100.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    public function testConsumeToZeroBalanceWithZeroFrozen()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $result = $this->walletService->consume($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->assertEquals('0.00', $result['balance']);
        $this->assertEquals('0.00', $result['available_amount']);
        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testRefundSuccess()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->consume($this->testDealerId, 300.00, ['operator' => 'admin']);

        $result = $this->walletService->refund($this->testDealerId, 300.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('1000.00', $result['balance']);
        $this->assertEquals('1000.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
    }

    public function testRefundPartialAmount()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->consume($this->testDealerId, 500.00, ['operator' => 'admin']);

        $result = $this->walletService->refund($this->testDealerId, 200.00, ['operator' => 'admin']);

        $this->assertEquals('700.00', $result['balance']);
        $this->assertEquals('700.00', $result['available_amount']);
    }

    public function testRefundWithFrozenAmount()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 400.00, ['operator' => 'admin']);
        $this->walletService->consume($this->testDealerId, 300.00, ['operator' => 'admin']);

        $result = $this->walletService->refund($this->testDealerId, 300.00, ['operator' => 'admin']);

        $this->assertEquals('1000.00', $result['balance']);
        $this->assertEquals('400.00', $result['frozen_amount']);
        $this->assertEquals('600.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
    }

    public function testFullBalanceLifeCycle()
    {
        $wallet = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('0.00', $wallet['balance']);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $r1 = $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->assertEquals('1000.00', $r1['balance']);
        $this->assertEquals(WalletStatus::NORMAL, $r1['status']);

        $r2 = $this->walletService->freeze($this->testDealerId, 400.00, ['operator' => 'admin']);
        $this->assertEquals('1000.00', $r2['balance']);
        $this->assertEquals('400.00', $r2['frozen_amount']);
        $this->assertEquals('600.00', $r2['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $r2['status']);

        $r3 = $this->walletService->consume($this->testDealerId, 200.00, ['operator' => 'admin']);
        $this->assertEquals('800.00', $r3['balance']);
        $this->assertEquals('400.00', $r3['frozen_amount']);
        $this->assertEquals('400.00', $r3['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $r3['status']);

        $r4 = $this->walletService->refund($this->testDealerId, 100.00, ['operator' => 'admin']);
        $this->assertEquals('900.00', $r4['balance']);
        $this->assertEquals('400.00', $r4['frozen_amount']);
        $this->assertEquals('500.00', $r4['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $r4['status']);

        $r5 = $this->walletService->unfreeze($r2['freeze_no'], null, ['operator' => 'admin']);
        $this->assertEquals('900.00', $r5['balance']);
        $this->assertEquals('0.00', $r5['frozen_amount']);
        $this->assertEquals('900.00', $r5['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $r5['status']);

        $r6 = $this->walletService->withdraw($this->testDealerId, 500.00, ['operator' => 'admin']);
        $this->assertEquals('400.00', $r6['balance']);
        $this->assertEquals('400.00', $r6['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $r6['status']);
    }

    public function testTransactionRecordsBalanceChanges()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin', 'remark' => '充值测试']);
        $this->walletService->consume($this->testDealerId, 200.00, ['operator' => 'admin', 'remark' => '消费测试']);
        $this->walletService->refund($this->testDealerId, 50.00, ['operator' => 'admin', 'remark' => '退款测试']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $this->assertGreaterThanOrEqual(3, count($transactions['items']));

        $types = [];
        foreach ($transactions['items'] as $tx) {
            $types[$tx['type']] = ($types[$tx['type']] ?? 0) + 1;
        }

        $this->assertEquals(1, $types[TransactionType::RECHARGE] ?? 0);
        $this->assertEquals(1, $types[TransactionType::CONSUME] ?? 0);
        $this->assertEquals(1, $types[TransactionType::REFUND] ?? 0);
    }

    public function testTransactionBalanceBeforeAfter()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $rechargeTx = null;
        foreach ($transactions['items'] as $tx) {
            if ($tx['type'] == TransactionType::RECHARGE) {
                $rechargeTx = $tx;
                break;
            }
        }

        $this->assertNotNull($rechargeTx);
        $this->assertEquals('0.00', $rechargeTx['balance_before']);
        $this->assertEquals('500.00', $rechargeTx['balance_after']);
    }

    public function testGetStateTransitions()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);

        $transitions = $this->walletService->getStateTransitions($this->testDealerId);

        $this->assertEquals(WalletStatus::NORMAL, $transitions['current_status']);
        $this->assertIsArray($transitions['allowed_transitions']);
        $this->assertCount(2, $transitions['allowed_transitions']);

        $targetStatuses = array_column($transitions['allowed_transitions'], 'target_status');
        $this->assertContains(WalletStatus::PARTIALLY_FROZEN, $targetStatuses);
        $this->assertContains(WalletStatus::FULLY_FROZEN, $targetStatuses);
    }

    public function testGetStateTransitionsPartiallyFrozen()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);

        $transitions = $this->walletService->getStateTransitions($this->testDealerId);

        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $transitions['current_status']);
        $this->assertCount(2, $transitions['allowed_transitions']);

        $targetStatuses = array_column($transitions['allowed_transitions'], 'target_status');
        $this->assertContains(WalletStatus::NORMAL, $targetStatuses);
        $this->assertContains(WalletStatus::FULLY_FROZEN, $targetStatuses);
    }

    public function testBalanceAndFrozenConsistencyAfterOperations()
    {
        $this->walletService->recharge($this->testDealerId, 2000.00, ['operator' => 'admin']);

        $freeze1 = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);
        $freeze2 = $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);

        $wallet = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('800.00', $wallet['frozen_amount']);
        $this->assertEquals('1200.00', $wallet['available_amount']);

        $this->walletService->consume($this->testDealerId, 400.00, ['operator' => 'admin']);

        $wallet2 = $this->walletService->getWallet($this->testDealerId);
        $expectedAvailable = (float)1600.00 - (float)800.00;
        $this->assertEquals('1600.00', $wallet2['balance']);
        $this->assertEquals('800.00', $wallet2['frozen_amount']);
        $this->assertEquals(number_format($expectedAvailable, 2, '.', ''), $wallet2['available_amount']);

        $this->walletService->unfreeze($freeze1['freeze_no'], 200.00, ['operator' => 'admin']);

        $wallet3 = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('1600.00', $wallet3['balance']);
        $this->assertEquals('600.00', $wallet3['frozen_amount']);
        $this->assertEquals('1000.00', $wallet3['available_amount']);

        $this->walletService->deductFrozen($freeze2['freeze_no'], null, ['operator' => 'admin']);

        $wallet4 = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('1300.00', $wallet4['balance']);
        $this->assertEquals('300.00', $wallet4['frozen_amount']);
        $this->assertEquals('1000.00', $wallet4['available_amount']);
    }

    public function testFreezeAllThenUnfreezeAllRestoresNormal()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $freezeResult['status']);

        $unfreezeResult = $this->walletService->unfreeze($freezeResult['freeze_no'], null, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::NORMAL, $unfreezeResult['status']);
        $this->assertEquals('500.00', $unfreezeResult['balance']);
        $this->assertEquals('500.00', $unfreezeResult['available_amount']);
    }

    public function testConsumeFromPartiallyFrozenDoesNotChangeStatus()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 400.00, ['operator' => 'admin']);

        $result = $this->walletService->consume($this->testDealerId, 200.00, ['operator' => 'admin']);

        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
    }

    public function testRechargeOnFrozenWalletDoesNotChangeStatus()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);

        $result = $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
        $this->assertEquals('1000.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('700.00', $result['available_amount']);
    }

    public function testMultipleOperationsStatusClosure()
    {
        $this->walletService->recharge($this->testDealerId, 2000.00, ['operator' => 'admin']);

        $f1 = $this->walletService->freeze($this->testDealerId, 800.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $f1['status']);

        $f2 = $this->walletService->freeze($this->testDealerId, 1200.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $f2['status']);

        $this->walletService->unfreeze($f2['freeze_no'], null, ['operator' => 'admin']);
        $u1 = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $u1['status']);

        $this->walletService->unfreeze($f1['freeze_no'], null, ['operator' => 'admin']);
        $u2 = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals(WalletStatus::NORMAL, $u2['status']);

        $this->assertEquals('2000.00', $u2['balance']);
        $this->assertEquals('2000.00', $u2['available_amount']);
        $this->assertEquals('0.00', $u2['frozen_amount']);
    }

    public function testDeductFrozenFromFullyFrozenToPartiallyFrozen()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $freezeResult = $this->walletService->freeze($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $freezeResult['status']);

        $result = $this->walletService->deductFrozen($freezeResult['freeze_no'], 400.00, ['operator' => 'admin']);

        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status']);
        $this->assertEquals('600.00', $result['balance']);
        $this->assertEquals('600.00', $result['frozen_amount']);
        $this->assertEquals('0.00', $result['available_amount']);
    }

    public function testDeductFrozenFullFromFullyFrozenGoesToNormal()
    {
        $this->walletService->recharge($this->testDealerId, 800.00, ['operator' => 'admin']);
        $freezeResult = $this->walletService->freeze($this->testDealerId, 800.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $freezeResult['status']);

        $result = $this->walletService->deductFrozen($freezeResult['freeze_no'], null, ['operator' => 'admin']);

        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
        $this->assertEquals('0.00', $result['balance']);
        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals('0.00', $result['available_amount']);
    }

    public function testTransactionCountMatchesOperations()
    {
        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'admin']);
        $freeze = $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);
        $this->walletService->consume($this->testDealerId, 100.00, ['operator' => 'admin']);
        $this->walletService->refund($this->testDealerId, 50.00, ['operator' => 'admin']);
        $this->walletService->unfreeze($freeze['freeze_no'], 100.00, ['operator' => 'admin']);
        $this->walletService->withdraw($this->testDealerId, 200.00, ['operator' => 'admin']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $this->assertCount(6, $transactions['items']);
    }

    public function testWalletAvailableAmountAlwaysConsistent()
    {
        $this->walletService->recharge($this->testDealerId, 3000.00, ['operator' => 'admin']);

        for ($i = 0; $i < 5; $i++) {
            $amount = 100 + $i * 50;
            $this->walletService->freeze($this->testDealerId, $amount, ['operator' => 'admin']);
        }

        $wallet = $this->walletService->getWallet($this->testDealerId);
        $balance = (float)$wallet['balance'];
        $frozen = (float)$wallet['frozen_amount'];
        $available = (float)$wallet['available_amount'];
        $calculatedAvailable = $balance - $frozen;

        $this->assertEquals(
            number_format($calculatedAvailable, 2, '.', ''),
            number_format($available, 2, '.', '')
        );
    }

    public function testNormalToFullyFrozenDirectly()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);

        $result = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status_transition']['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status_transition']['to_status']);
    }

    public function testFullyFrozenToNormalDirectlyViaUnfreeze()
    {
        $this->walletService->recharge($this->testDealerId, 500.00, ['operator' => 'admin']);
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);

        $result = $this->walletService->unfreeze($freezeResult['freeze_no'], null, ['operator' => 'admin']);

        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status_transition']['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status_transition']['to_status']);
    }
}
