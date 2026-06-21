<?php

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../src/Enum/WalletStatus.php';
require_once __DIR__ . '/../src/Enum/FreezeStatus.php';
require_once __DIR__ . '/../src/Enum/TransactionType.php';
require_once __DIR__ . '/../src/Exception/WalletException.php';
require_once __DIR__ . '/../src/Exception/WalletStateException.php';
require_once __DIR__ . '/../src/Exception/InsufficientBalanceException.php';
require_once __DIR__ . '/../src/Model/Wallet.php';
require_once __DIR__ . '/../src/Model/FreezeRecord.php';
require_once __DIR__ . '/../src/Model/Transaction.php';
require_once __DIR__ . '/../src/StateMachine/WalletStateMachine.php';

use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\StateMachine\WalletStateMachine;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Exception\WalletStateException;

class WalletStateMachineTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();
    }

    public function testConstructWithValidStatus()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $sm->getCurrentStatus());

        $sm2 = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $sm2->getCurrentStatus());

        $sm3 = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $sm3->getCurrentStatus());
    }

    public function testConstructWithInvalidStatus()
    {
        $exception = null;
        try {
            new WalletStateMachine(999);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
        $this->assertStringContainsString('无效的钱包状态值', $exception->getMessage());
    }

    public function testFromWallet()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1001,
            'balance' => 1000.00,
            'frozen_amount' => 0.00,
            'available_amount' => 1000.00,
            'status' => WalletStatus::NORMAL,
            'version' => 0,
        ], true);

        $sm = WalletStateMachine::fromWallet($wallet);
        $this->assertEquals(WalletStatus::NORMAL, $sm->getCurrentStatus());
    }

    public function testCalculateStatusNormal()
    {
        $status = WalletStateMachine::calculateStatus(1000.00, 0.00);
        $this->assertEquals(WalletStatus::NORMAL, $status);
    }

    public function testCalculateStatusPartiallyFrozen()
    {
        $status = WalletStateMachine::calculateStatus(1000.00, 300.00);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $status);
    }

    public function testCalculateStatusFullyFrozen()
    {
        $status = WalletStateMachine::calculateStatus(1000.00, 1000.00);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $status);
    }

    public function testCalculateStatusWithZeroBalance()
    {
        $status = WalletStateMachine::calculateStatus(0.00, 0.00);
        $this->assertEquals(WalletStatus::NORMAL, $status);
    }

    public function testValidateAmountsNegativeFrozen()
    {
        $exception = null;
        try {
            WalletStateMachine::validateAmounts(1000.00, -100.00);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
        $this->assertStringContainsString('冻结金额为负数', $exception->getMessage());
    }

    public function testValidateAmountsNegativeBalance()
    {
        $exception = null;
        try {
            WalletStateMachine::validateAmounts(-100.00, 0.00);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
        $this->assertStringContainsString('账户余额为负数', $exception->getMessage());
    }

    public function testValidateAmountsFrozenExceedsBalance()
    {
        $exception = null;
        try {
            WalletStateMachine::validateAmounts(500.00, 1000.00);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
        $this->assertStringContainsString('冻结金额', $exception->getMessage());
        $this->assertStringContainsString('超过账户余额', $exception->getMessage());
    }

    public function testValidateAmountsValid()
    {
        $exception = null;
        try {
            WalletStateMachine::validateAmounts(1000.00, 500.00);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNull($exception);
    }

    public function testCanTransitionToFromNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $this->assertTrue($sm->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
        $this->assertTrue($sm->canTransitionTo(WalletStatus::FULLY_FROZEN));
        $this->assertFalse($sm->canTransitionTo(WalletStatus::NORMAL));
    }

    public function testCanTransitionToFromPartiallyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $this->assertTrue($sm->canTransitionTo(WalletStatus::NORMAL));
        $this->assertTrue($sm->canTransitionTo(WalletStatus::FULLY_FROZEN));
        $this->assertFalse($sm->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
    }

    public function testCanTransitionToFromFullyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $this->assertTrue($sm->canTransitionTo(WalletStatus::PARTIALLY_FROZEN));
        $this->assertTrue($sm->canTransitionTo(WalletStatus::NORMAL));
        $this->assertFalse($sm->canTransitionTo(WalletStatus::FULLY_FROZEN));
    }

    public function testTransitionNormalToPartiallyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $sm->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $sm->getCurrentStatus());
    }

    public function testTransitionNormalToFullyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $sm->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $sm->getCurrentStatus());
    }

    public function testTransitionPartiallyFrozenToNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $sm->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $sm->getCurrentStatus());
    }

    public function testTransitionPartiallyFrozenToFullyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $sm->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $sm->getCurrentStatus());
    }

    public function testTransitionFullyFrozenToPartiallyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $sm->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $sm->getCurrentStatus());
    }

    public function testTransitionFullyFrozenToNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $sm->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $sm->getCurrentStatus());
    }

    public function testTransitionInvalidThrowsException()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $exception = null;
        try {
            $sm->transition(WalletStatus::NORMAL);
        } catch (Exception $e) {
            $exception = $e;
        }
        $this->assertNotNull($exception);
        $this->assertStringContainsString('状态流转校验失败', $exception->getMessage());
    }

    public function testAssertCanTransitionByAmountNoChange()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $sm->assertCanTransitionByAmount(1000.00, 0.00, 0.00);

        $this->assertIsArray($result);
        $this->assertFalse($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountNormalToPartiallyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $sm->assertCanTransitionByAmount(1000.00, 0.00, 300.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountNormalToFullyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);
        $result = $sm->assertCanTransitionByAmount(1000.00, 0.00, 1000.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::NORMAL, $result['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountPartiallyFrozenToNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $result = $sm->assertCanTransitionByAmount(1000.00, 300.00, 0.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountPartiallyFrozenToFullyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::PARTIALLY_FROZEN);
        $result = $sm->assertCanTransitionByAmount(1000.00, 300.00, 1000.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountFullyFrozenToPartiallyFrozen()
    {
        $sm = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $result = $sm->assertCanTransitionByAmount(1000.00, 1000.00, 500.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $result['to_status']);
    }

    public function testAssertCanTransitionByAmountFullyFrozenToNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::FULLY_FROZEN);
        $result = $sm->assertCanTransitionByAmount(1000.00, 1000.00, 0.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $result['from_status']);
        $this->assertEquals(WalletStatus::NORMAL, $result['to_status']);
    }

    public function testApplyToWalletWithStatusChange()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1001,
            'balance' => 1000.00,
            'frozen_amount' => 0.00,
            'available_amount' => 1000.00,
            'status' => WalletStatus::NORMAL,
            'version' => 0,
        ], true);

        $sm = WalletStateMachine::fromWallet($wallet);
        $result = $sm->applyToWallet($wallet, 1000.00, 500.00);

        $this->assertTrue($result['changed']);
        $this->assertEquals(1000.00, $wallet->balance);
        $this->assertEquals(500.00, $wallet->frozenAmount);
        $this->assertEquals(500.00, $wallet->availableAmount);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }

    public function testApplyToWalletWithoutStatusChange()
    {
        $wallet = new Wallet([
            'id' => 1,
            'dealer_id' => 1001,
            'balance' => 1000.00,
            'frozen_amount' => 300.00,
            'available_amount' => 700.00,
            'status' => WalletStatus::PARTIALLY_FROZEN,
            'version' => 0,
        ], true);

        $sm = WalletStateMachine::fromWallet($wallet);
        $result = $sm->applyToWallet($wallet, 1000.00, 500.00);

        $this->assertFalse($result['changed']);
        $this->assertEquals(1000.00, $wallet->balance);
        $this->assertEquals(500.00, $wallet->frozenAmount);
        $this->assertEquals(500.00, $wallet->availableAmount);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $wallet->status);
    }

    public function testGetAllowedTransitions()
    {
        $transitions = WalletStateMachine::getAllowedTransitions(WalletStatus::NORMAL);
        $this->assertIsArray($transitions);
        $this->assertCount(2, $transitions);
        $this->assertContains(WalletStatus::PARTIALLY_FROZEN, $transitions);
        $this->assertContains(WalletStatus::FULLY_FROZEN, $transitions);
    }

    public function testGetAllowedTransitionsInvalidStatus()
    {
        $transitions = WalletStateMachine::getAllowedTransitions(999);
        $this->assertIsArray($transitions);
        $this->assertEmpty($transitions);
    }

    public function testDescribeStatus()
    {
        $description = WalletStateMachine::describeStatus(WalletStatus::NORMAL, 1000.00, 0.00);
        $this->assertIsString($description);
        $this->assertStringContainsString('正常', $description);
        $this->assertStringContainsString('1,000.00', $description);
    }

    public function testFullStateCycleNormalToFrozenToNormal()
    {
        $sm = new WalletStateMachine(WalletStatus::NORMAL);

        $sm->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $sm->getCurrentStatus());

        $sm->transition(WalletStatus::FULLY_FROZEN);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $sm->getCurrentStatus());

        $sm->transition(WalletStatus::PARTIALLY_FROZEN);
        $this->assertEquals(WalletStatus::PARTIALLY_FROZEN, $sm->getCurrentStatus());

        $sm->transition(WalletStatus::NORMAL);
        $this->assertEquals(WalletStatus::NORMAL, $sm->getCurrentStatus());
    }

    public function testCalculateStatusWithEpsilon()
    {
        $status = WalletStateMachine::calculateStatus(1000.00, 0.0001);
        $this->assertEquals(WalletStatus::NORMAL, $status);

        $status2 = WalletStateMachine::calculateStatus(1000.00, 999.999);
        $this->assertEquals(WalletStatus::FULLY_FROZEN, $status2);
    }
}
