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
use Dealer\Wallet\Exception\WalletException;

class WalletFreezeTest extends TestCase
{
    private $walletService;
    private $testDealerId = 1001;

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

        $permissionService = new PermissionService();
        $permissionService->setOperatorContext([
            'operator_id' => 1,
            'dealer_id' => null,
            'roles' => ['super_admin'],
            'permissions' => ['*'],
            'scoped_dealer_ids' => null,
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

        $this->walletService->recharge($this->testDealerId, 1000.00, ['operator' => 'test_admin']);
    }

    public function testFreezeSuccessFromNormal()
    {
        $result = $this->walletService->freeze($this->testDealerId, 300.00, [
            'reason' => '测试冻结',
            'operator' => 'test_admin',
        ]);

        $this->assertTrue($result['success'] ?? true);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertNotEmpty($result['freeze_no']);
        $this->assertTrue($result['status_transition']['changed']);
    }

    public function testFreezeToFullyFrozen()
    {
        $result = $this->walletService->freeze($this->testDealerId, 1000.00, [
            'reason' => '全额冻结',
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('0.00', $result['available_amount']);
        $this->assertEquals('1000.00', $result['frozen_amount']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status']);
        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status_transition']['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status_transition']['to_status']);
    }

    public function testFreezeInsufficientAvailable()
    {
        $exception = null;
        try {
            $this->walletService->freeze($this->testDealerId, 1500.00, [
                'reason' => '超额冻结',
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('可用余额不足', $exception->getMessage());
    }

    public function testFreezeZeroAmount()
    {
        $exception = null;
        try {
            $this->walletService->freeze($this->testDealerId, 0, [
                'reason' => '零金额冻结',
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
    }

    public function testFreezeNegativeAmount()
    {
        $exception = null;
        try {
            $this->walletService->freeze($this->testDealerId, -100.00, [
                'reason' => '负金额冻结',
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
    }

    public function testFreezePartialFromPartiallyFrozen()
    {
        $this->walletService->freeze($this->testDealerId, 300.00, [
            'reason' => '第一次冻结',
            'operator' => 'test_admin',
        ]);

        $result = $this->walletService->freeze($this->testDealerId, 200.00, [
            'reason' => '第二次冻结',
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('500.00', $result['frozen_amount']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
    }

    public function testUnfreezeFullAmount()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, [
            'reason' => '测试解冻',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->unfreeze($freezeNo, null, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals('1000.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(FreezeStatus::UNFROZEN, $result['freeze_record_status']['new_status']);
    }

    public function testUnfreezePartialAmount()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, [
            'reason' => '测试部分解冻',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->unfreeze($freezeNo, 200.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('700.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
        $this->assertEquals(FreezeStatus::FROZEN, $result['freeze_record_status']['new_status']);
        $this->assertEquals('300.00', $result['freeze_record_status']['remaining_after']);
    }

    public function testUnfreezeFromFullyFrozenToPartiallyFrozen()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 1000.00, [
            'reason' => '全额冻结',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->unfreeze($freezeNo, 300.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('700.00', $result['frozen_amount']);
        $this->assertEquals('300.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status_transition']['from_status']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status_transition']['to_status']);
    }

    public function testUnfreezeExceedsRemaining()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 300.00, [
            'reason' => '测试超额解冻',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $exception = null;
        try {
            $this->walletService->unfreeze($freezeNo, 500.00, [
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('解冻金额超过剩余冻结金额', $exception->getMessage());
    }

    public function testUnfreezeInvalidFreezeNo()
    {
        $exception = null;
        try {
            $this->walletService->unfreeze('INVALID_FREEZE_NO', 100.00, [
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('冻结记录不存在', $exception->getMessage());
    }

    public function testDeductFrozenFullAmount()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, [
            'reason' => '测试扣除冻结资金',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->deductFrozen($freezeNo, null, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('500.00', $result['balance']);
        $this->assertEquals('0.00', $result['frozen_amount']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::NORMAL, $result['status']);
        $this->assertTrue($result['status_transition']['changed']);
        $this->assertEquals(FreezeStatus::DEDUCTED, $result['freeze_record_status']['new_status']);
    }

    public function testDeductFrozenPartialAmount()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, [
            'reason' => '测试部分扣除',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->deductFrozen($freezeNo, 200.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('800.00', $result['balance']);
        $this->assertEquals('300.00', $result['frozen_amount']);
        $this->assertEquals('500.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
        $this->assertEquals(FreezeStatus::FROZEN, $result['freeze_record_status']['new_status']);
    }

    public function testDeductFrozenFromFullyFrozen()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 1000.00, [
            'reason' => '全额冻结后扣除',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $result = $this->walletService->deductFrozen($freezeNo, 400.00, [
            'operator' => 'test_admin',
        ]);

        $this->assertEquals('600.00', $result['balance']);
        $this->assertEquals('600.00', $result['frozen_amount']);
        $this->assertEquals('0.00', $result['available_amount']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['status']);
        $this->assertFalse($result['status_transition']['changed']);
    }

    public function testDeductFrozenExceedsRemaining()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 300.00, [
            'reason' => '测试超额扣除',
            'operator' => 'test_admin',
        ]);

        $freezeNo = $freezeResult['freeze_no'];
        $exception = null;
        try {
            $this->walletService->deductFrozen($freezeNo, 500.00, [
                'operator' => 'test_admin',
            ]);
        } catch (Exception $e) {
            $exception = $e;
        }

        $this->assertNotNull($exception);
        $this->assertStringContainsString('扣除金额超过剩余冻结金额', $exception->getMessage());
    }

    public function testMultipleFreezeRecords()
    {
        $r1 = $this->walletService->freeze($this->testDealerId, 200.00, [
            'reason' => '冻结单1',
            'operator' => 'admin1',
        ]);
        $r2 = $this->walletService->freeze($this->testDealerId, 300.00, [
            'reason' => '冻结单2',
            'operator' => 'admin2',
        ]);

        $wallet = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('500.00', $wallet['frozen_amount']);
        $this->assertEquals('500.00', $wallet['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet['status']);

        $this->walletService->unfreeze($r1['freeze_no'], null, ['operator' => 'test_admin']);

        $wallet2 = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals('300.00', $wallet2['frozen_amount']);
        $this->assertEquals('700.00', $wallet2['available_amount']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet2['status']);
    }

    public function testFreezeUnfreezeStatusCycle()
    {
        $wallet = $this->walletService->getWallet($this->testDealerId);
        $this->assertEquals(WalletStatus::NORMAL, $wallet['status']);

        $r1 = $this->walletService->freeze($this->testDealerId, 400.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $r1['status']);

        $r2 = $this->walletService->freeze($this->testDealerId, 600.00, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $r2['status']);

        $r3 = $this->walletService->unfreeze($r1['freeze_no'], null, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $r3['status']);

        $r4 = $this->walletService->unfreeze($r2['freeze_no'], null, ['operator' => 'admin']);
        $this->assertEquals(WalletStatus::NORMAL, $r4['status']);
    }

    public function testGetFreezeRecords()
    {
        $this->walletService->freeze($this->testDealerId, 100.00, ['reason' => 'reason1', 'operator' => 'op1']);
        $this->walletService->freeze($this->testDealerId, 200.00, ['reason' => 'reason2', 'operator' => 'op2']);

        $result = $this->walletService->getFreezeRecords($this->testDealerId);
        $this->assertIsArray($result['items']);
        $this->assertGreaterThanOrEqual(2, count($result['items']));
    }

    public function testGetFreezeRecordsWithStatusFilter()
    {
        $r1 = $this->walletService->freeze($this->testDealerId, 100.00, ['operator' => 'admin']);
        $this->walletService->freeze($this->testDealerId, 200.00, ['operator' => 'admin']);
        $this->walletService->unfreeze($r1['freeze_no'], null, ['operator' => 'admin']);

        $frozenRecords = $this->walletService->getFreezeRecords($this->testDealerId, FreezeStatus::FROZEN);
        $this->assertEquals(1, count($frozenRecords['items']));

        $unfrozenRecords = $this->walletService->getFreezeRecords($this->testDealerId, FreezeStatus::UNFROZEN);
        $this->assertEquals(1, count($unfrozenRecords['items']));
    }

    public function testFreezeCreatesTransactionRecord()
    {
        $result = $this->walletService->freeze($this->testDealerId, 200.00, ['operator' => 'test_admin']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $found = false;
        foreach ($transactions['items'] as $tx) {
            if ($tx['type'] == TransactionType::FREEZE && $tx['amount'] == '200.00') {
                $found = true;
                $this->assertEquals($result['freeze_no'], $tx['related_no']);
                break;
            }
        }
        $this->assertTrue($found, '未找到冻结交易记录');
    }

    public function testUnfreezeCreatesTransactionRecord()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);
        $this->walletService->unfreeze($freezeResult['freeze_no'], 100.00, ['operator' => 'admin']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $found = false;
        foreach ($transactions['items'] as $tx) {
            if ($tx['type'] == TransactionType::UNFREEZE) {
                $found = true;
                $this->assertEquals('100.00', $tx['amount']);
                break;
            }
        }
        $this->assertTrue($found, '未找到解冻交易记录');
    }

    public function testDeductFrozenCreatesConsumeTransaction()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 300.00, ['operator' => 'admin']);
        $this->walletService->deductFrozen($freezeResult['freeze_no'], 150.00, ['operator' => 'admin']);

        $transactions = $this->walletService->getTransactions($this->testDealerId);
        $found = false;
        foreach ($transactions['items'] as $tx) {
            if ($tx['type'] == TransactionType::CONSUME && $tx['related_no'] == $freezeResult['freeze_no']) {
                $found = true;
                $this->assertEquals('150.00', $tx['amount']);
                break;
            }
        }
        $this->assertTrue($found, '未找到扣除冻结资金的消费交易记录');
    }

    public function testFreezeRecordStatusAfterUnfreezePartial()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->walletService->unfreeze($freezeResult['freeze_no'], 200.00, ['operator' => 'admin']);

        $records = $this->walletService->getFreezeRecords($this->testDealerId);
        $record = null;
        foreach ($records['items'] as $r) {
            if ($r['freeze_no'] == $freezeResult['freeze_no']) {
                $record = $r;
                break;
            }
        }

        $this->assertNotNull($record);
        $this->assertEquals(FreezeStatus::FROZEN, $record['status']);
        $this->assertEquals('300.00', $record['remaining_amount']);
    }

    public function testFreezeRecordStatusAfterDeductPartial()
    {
        $freezeResult = $this->walletService->freeze($this->testDealerId, 500.00, ['operator' => 'admin']);

        $this->walletService->deductFrozen($freezeResult['freeze_no'], 200.00, ['operator' => 'admin']);

        $records = $this->walletService->getFreezeRecords($this->testDealerId);
        $record = null;
        foreach ($records['items'] as $r) {
            if ($r['freeze_no'] == $freezeResult['freeze_no']) {
                $record = $r;
                break;
            }
        }

        $this->assertNotNull($record);
        $this->assertEquals(FreezeStatus::FROZEN, $record['status']);
        $this->assertEquals('300.00', $record['remaining_amount']);
    }
}
