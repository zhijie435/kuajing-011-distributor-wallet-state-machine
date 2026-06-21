<?php

namespace Dealer\Wallet\Service;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Exception\WalletPermissionException;
use Dealer\Wallet\Model\FreezeRecord;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Repository\TransactionRepository;
use Dealer\Wallet\Repository\WalletRepository;
use Dealer\Wallet\Service\ReconciliationService;
use Dealer\Wallet\StateMachine\WalletStateMachine;
use PDO;
use PermissionService;

class WalletService
{
    private WalletRepository $walletRepository;
    private TransactionRepository $transactionRepository;
    private FreezeRecordRepository $freezeRecordRepository;
    private ReconciliationService $reconciliationService;
    private PermissionService $permissionService;
    /** @var PDO|MockDatabase */
    private $pdo;

    public function __construct()
    {
        $this->walletRepository = new WalletRepository();
        $this->transactionRepository = new TransactionRepository();
        $this->freezeRecordRepository = new FreezeRecordRepository();
        $this->reconciliationService = new ReconciliationService();
        $this->permissionService = new PermissionService();
        $this->pdo = Database::getConnection();
    }

    public function getPermissionService(): PermissionService
    {
        return $this->permissionService;
    }

    public function getWallet(int $dealerId): array
    {
        $this->assertCanViewWallet($dealerId);

        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            $this->assertCanCreateWallet($dealerId);
            $wallet = $this->walletRepository->create($dealerId);
        }
        return $wallet->toArray();
    }

    public function getAllWallets(int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissionService->canViewAllWallets()) {
            throw WalletPermissionException::forAdminRequired('查询全部钱包列表');
        }

        $items = $this->walletRepository->findAll($page, $pageSize);
        $total = $this->walletRepository->count();
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function recharge(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '钱包充值');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::RECHARGE,
            true,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount]
        );
    }

    public function withdraw(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '余额提现');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::WITHDRAW,
            false,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount],
            true
        );
    }

    public function consume(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '余额消费');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::CONSUME,
            false,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount],
            true
        );
    }

    public function refund(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '消费退款');

        return $this->executeBalanceChange(
            $dealerId,
            $amount,
            TransactionType::REFUND,
            true,
            $options,
            ['dealer_id' => $dealerId, 'amount' => $amount]
        );
    }

    public function freeze(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);
        $this->assertCanOperateWallet($dealerId, '资金冻结');

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);
            $this->assertSufficientAvailable($wallet, $amount, '冻结');

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcadd((string)$frozenBefore, (string)$amount, 2);

            $transition = $stateMachine->applyToWallet($wallet, $wallet->balance, $frozenAfter);
            $this->updateWallet($wallet);

            $freezeNo = $this->freezeRecordRepository->generateFreezeNo();
            $this->freezeRecordRepository->create([
                'wallet_id' => $wallet->id,
                'dealer_id' => $dealerId,
                'freeze_no' => $freezeNo,
                'amount' => $amount,
                'reason' => $options['reason'] ?? '',
                'expired_at' => $options['expired_at'] ?? null,
                'operator' => $options['operator'] ?? '',
            ]);

            $this->recordTransaction($wallet, TransactionType::FREEZE, $amount, $wallet->balance, $wallet->balance, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['reason'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['freeze_no'] = $freezeNo;
            $result['status_transition'] = $transition;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function unfreeze(string $freezeNo, float $amount = null, array $options = []): array
    {
        if ($amount !== null) {
            $this->validateAmount($amount);
        }

        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $freezeRecord = $this->findFreezeRecordForUpdate($freezeNo, '解冻');
            $this->assertCanOperateWallet($freezeRecord->dealerId, '资金解冻');

            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);

            $unfreezeAmount = $amount ?? $freezeRecord->remainingAmount;
            if ($unfreezeAmount > $freezeRecord->remainingAmount + 0.001) {
                throw new WalletException(
                    "解冻金额超过剩余冻结金额：剩余冻结 ¥{$freezeRecord->remainingAmount}，申请解冻 ¥{$unfreezeAmount}。" .
                    "请调整解冻金额或分多次解冻。"
                );
            }

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$frozenBefore, (string)$unfreezeAmount, 2);

            $transition = $stateMachine->applyToWallet($wallet, $wallet->balance, $frozenAfter);
            $this->updateWallet($wallet);

            $remainingAfter = (float)bcsub((string)$freezeRecord->remainingAmount, (string)$unfreezeAmount, 2);
            $newFreezeStatus = $remainingAfter <= 0.001 ? FreezeStatus::UNFROZEN : FreezeStatus::FROZEN;
            $this->freezeRecordRepository->updateRemaining($freezeRecord, $remainingAfter, $newFreezeStatus);

            $this->recordTransaction($wallet, TransactionType::UNFREEZE, $unfreezeAmount, $wallet->balance, $wallet->balance, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            $result['freeze_record_status'] = [
                'new_status' => $newFreezeStatus,
                'new_status_name' => FreezeStatus::getName($newFreezeStatus),
                'remaining_after' => number_format($remainingAfter, 2, '.', ''),
            ];
            return $result;
        }, ['freeze_no' => $freezeNo, 'amount' => $amount]);
    }

    public function deductFrozen(string $freezeNo, float $amount = null, array $options = []): array
    {
        if ($amount !== null) {
            $this->validateAmount($amount);
        }

        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $freezeRecord = $this->findFreezeRecordForUpdate($freezeNo, '扣除');
            $this->assertCanOperateWallet($freezeRecord->dealerId, '冻结资金扣除');

            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);

            $deductAmount = $amount ?? $freezeRecord->remainingAmount;
            if ($deductAmount > $freezeRecord->remainingAmount + 0.001) {
                throw new WalletException(
                    "扣除金额超过剩余冻结金额：剩余冻结 ¥{$freezeRecord->remainingAmount}，申请扣除 ¥{$deductAmount}。" .
                    "请调整扣除金额。"
                );
            }

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $balanceBefore = $wallet->balance;
            $balanceAfter = (float)bcsub((string)$balanceBefore, (string)$deductAmount, 2);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$frozenBefore, (string)$deductAmount, 2);

            $transition = $stateMachine->applyToWallet($wallet, $balanceAfter, $frozenAfter);
            $this->updateWallet($wallet);

            $remainingAfter = (float)bcsub((string)$freezeRecord->remainingAmount, (string)$deductAmount, 2);
            $newFreezeStatus = $remainingAfter <= 0.001 ? FreezeStatus::DEDUCTED : FreezeStatus::FROZEN;
            $this->freezeRecordRepository->updateRemaining($freezeRecord, $remainingAfter, $newFreezeStatus);

            $this->recordTransaction($wallet, TransactionType::CONSUME, $deductAmount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '冻结资金扣除') . ($transition['changed'] ? " | {$transition['message']}" : ''),
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            $result['freeze_record_status'] = [
                'new_status' => $newFreezeStatus,
                'new_status_name' => FreezeStatus::getName($newFreezeStatus),
                'remaining_after' => number_format($remainingAfter, 2, '.', ''),
            ];
            return $result;
        }, ['freeze_no' => $freezeNo, 'amount' => $amount]);
    }

    public function getTransactions(int $dealerId, int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissionService->canViewTransactions($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired('查询交易流水');
        }

        $wallet = $this->getOrCreateWallet($dealerId);
        $items = $this->transactionRepository->findByWalletId($wallet->id, $page, $pageSize);
        $total = $this->transactionRepository->countByWalletId($wallet->id);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getFreezeRecords(int $dealerId, int $status = null, int $page = 1, int $pageSize = 20): array
    {
        if (!$this->permissionService->canViewFreezeRecords($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired('查询冻结记录');
        }

        $wallet = $this->getOrCreateWallet($dealerId);
        $items = $this->freezeRecordRepository->findByWalletId($wallet->id, $status, $page, $pageSize);
        $total = count($items);
        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getStateTransitions(int $dealerId): array
    {
        $this->assertCanViewWallet($dealerId);

        $wallet = $this->getOrCreateWallet($dealerId);
        $allowedTransitions = WalletStateMachine::getAllowedTransitions($wallet->status);
        $transitions = [];
        foreach ($allowedTransitions as $targetStatus) {
            $transitions[] = [
                'target_status' => $targetStatus,
                'target_status_name' => \Dealer\Wallet\Enum\WalletStatus::getName($targetStatus),
            ];
        }
        return [
            'current_status' => $wallet->status,
            'current_status_name' => \Dealer\Wallet\Enum\WalletStatus::getName($wallet->status),
            'current_status_description' => WalletStateMachine::describeStatus($wallet->status, $wallet->balance, $wallet->frozenAmount),
            'allowed_transitions' => $transitions,
        ];
    }

    public function reconcileFreezeRecords(int $dealerId): array
    {
        if (!$this->permissionService->canReconcile($dealerId)) {
            throw WalletPermissionException::forAdminRequired('冻结释放对账');
        }
        return $this->reconciliationService->reconcileFreezeRecords($dealerId);
    }

    public function reconcileBalanceChanges(int $dealerId): array
    {
        if (!$this->permissionService->canReconcile($dealerId)) {
            throw WalletPermissionException::forAdminRequired('余额变更对账');
        }
        return $this->reconciliationService->reconcileBalanceChanges($dealerId);
    }

    public function getAnomalySummary(int $dealerId): array
    {
        if (!$this->permissionService->canReconcile($dealerId)) {
            throw WalletPermissionException::forAdminRequired('异常汇总查询');
        }
        return $this->reconciliationService->getAnomalySummary($dealerId);
    }

    public function exportFreezeReconciliation(int $dealerId): array
    {
        if (!$this->permissionService->canExport($dealerId)) {
            throw WalletPermissionException::forAdminRequired('冻结对账导出');
        }
        $csv = $this->reconciliationService->exportFreezeReconciliationCsv($dealerId);
        $filename = sprintf('freeze_reconciliation_dealer_%d_%s.csv', $dealerId, date('YmdHis'));
        return [
            'filename' => $filename,
            'content' => $csv,
            'content_type' => 'text/csv; charset=utf-8',
        ];
    }

    public function exportBalanceReconciliation(int $dealerId): array
    {
        if (!$this->permissionService->canExport($dealerId)) {
            throw WalletPermissionException::forAdminRequired('余额对账导出');
        }
        $csv = $this->reconciliationService->exportBalanceReconciliationCsv($dealerId);
        $filename = sprintf('balance_reconciliation_dealer_%d_%s.csv', $dealerId, date('YmdHis'));
        return [
            'filename' => $filename,
            'content' => $csv,
            'content_type' => 'text/csv; charset=utf-8',
        ];
    }

    public function fixWalletInconsistency(int $dealerId, string $operator = 'reconciliation'): array
    {
        if (!$this->permissionService->canFixWallet($dealerId)) {
            throw WalletPermissionException::forAdminRequired('钱包不一致修复');
        }
        return $this->reconciliationService->fixWalletInconsistency($dealerId, $operator);
    }

    private function executeBalanceChange(
        int $dealerId,
        float $amount,
        int $txType,
        bool $isIncrease,
        array $options,
        array $context,
        bool $checkAvailable = false
    ): array {
        return $this->executeTransaction(function () use ($dealerId, $amount, $txType, $isIncrease, $options, $checkAvailable) {
            $wallet = $isIncrease
                ? $this->getOrCreateWalletForUpdate($dealerId)
                : $this->getWalletForUpdate($dealerId);

            if ($checkAvailable) {
                $actionName = $txType === TransactionType::WITHDRAW ? '提现' : '消费';
                $this->assertSufficientAvailable($wallet, $amount, $actionName);
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = $isIncrease
                ? (float)bcadd((string)$balanceBefore, (string)$amount, 2)
                : (float)bcsub((string)$balanceBefore, (string)$amount, 2);

            $stateMachine = WalletStateMachine::fromWallet($wallet);
            $transition = $stateMachine->applyToWallet($wallet, $balanceAfter, $frozenBefore);

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, $txType, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, $context);
    }

    private function findFreezeRecordForUpdate(string $freezeNo, string $actionName): FreezeRecord
    {
        $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
        if (!$freezeRecord) {
            throw new WalletException("冻结记录不存在：冻结单号【{$freezeNo}】，请核对单号是否正确。");
        }
        if ($freezeRecord->status !== FreezeStatus::FROZEN) {
            throw new WalletException(
                "冻结记录状态异常：当前状态【" . FreezeStatus::getName($freezeRecord->status) . "】，" .
                "仅【冻结中】的记录允许{$actionName}。如需重新操作请创建新的冻结单。"
            );
        }
        return $freezeRecord;
    }

    private function assertCanViewWallet(int $dealerId): void
    {
        if (!$this->permissionService->canViewWallet($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired('查询钱包信息');
        }
    }

    private function assertCanOperateWallet(int $dealerId, string $operationName): void
    {
        if ($this->permissionService->isAdmin()) {
            return;
        }
        if (!$this->permissionService->canViewWallet($dealerId)) {
            $currentDealerId = $this->permissionService->getCurrentDealerId();
            if ($currentDealerId !== null) {
                throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
            }
            throw WalletPermissionException::forAdminRequired($operationName);
        }
    }

    private function assertCanCreateWallet(int $dealerId): void
    {
        if ($this->permissionService->isAdmin()) {
            return;
        }
        $currentDealerId = $this->permissionService->getCurrentDealerId();
        if ($currentDealerId === null) {
            throw WalletPermissionException::forAdminRequired('创建钱包');
        }
        if ($dealerId !== $currentDealerId) {
            throw WalletPermissionException::forDealerMismatch($dealerId, $currentDealerId);
        }
    }

    private function assertSufficientAvailable(Wallet $wallet, float $amount, string $action): void
    {
        if ($wallet->availableAmount < $amount - 0.001) {
            throw new InsufficientBalanceException(
                "可用余额不足：当前可用 ¥{$wallet->availableAmount}，需{$action} ¥{$amount}。" .
                "建议：先充值或解冻部分冻结资金。"
            );
        }
    }

    private function executeTransaction(callable $callback, array $operationContext = [])
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = $trace[2]['function'] ?? 'unknown';
        $operationName = $operationContext['operation_name'] ?? $this->mapMethodToOperationName($caller);
        $operationType = $operationContext['operation_type'] ?? $this->mapMethodToOperationType($caller);
        $dealerId = $operationContext['dealer_id'] ?? null;

        $walletBefore = null;
        if ($dealerId) {
            try {
                $walletBefore = $this->walletRepository->findByDealerId($dealerId);
            } catch (\Exception $e) {
            }
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();

            $rollbackInfo = $this->buildRollbackInfo($e, $operationName, $operationType, $dealerId, $walletBefore, $operationContext);
            $retryInfo = $this->buildRetryInfo($e, $operationName, $operationContext);

            if ($e instanceof WalletException) {
                $e->setRollbackInfo($rollbackInfo);
                $e->setRetryInfo($retryInfo);
            }

            throw $e;
        }
    }

    private function mapMethodToOperationName(string $method): string
    {
        $map = [
            'recharge' => '钱包充值',
            'withdraw' => '余额提现',
            'freeze' => '资金冻结',
            'unfreeze' => '资金解冻',
            'consume' => '余额消费',
            'refund' => '消费退款',
            'deductFrozen' => '冻结资金扣除',
            'executeBalanceChange' => '余额变更',
        ];
        return $map[$method] ?? $method;
    }

    private function mapMethodToOperationType(string $method): string
    {
        $map = [
            'recharge' => 'balance_increase',
            'withdraw' => 'balance_decrease',
            'freeze' => 'freeze_increase',
            'unfreeze' => 'freeze_decrease',
            'consume' => 'balance_decrease',
            'refund' => 'balance_increase',
            'deductFrozen' => 'freeze_decrease',
            'executeBalanceChange' => 'balance_change',
        ];
        return $map[$method] ?? 'unknown';
    }

    private function buildRollbackInfo(\Exception $e, string $operationName, string $operationType, ?int $dealerId, $walletBefore, array $context): array
    {
        $info = [
            'rollback_success' => true,
            'rollback_time' => date('Y-m-d H:i:s'),
            'operation_name' => $operationName,
            'operation_type' => $operationType,
            'error_type' => get_class($e),
            'error_message' => $e->getMessage(),
            'rollback_message' => "【回滚提示】{$operationName}操作失败，所有变更已回滚。",
            'rollback_details' => [],
        ];

        if ($dealerId) {
            $info['dealer_id'] = $dealerId;
        }

        if ($walletBefore) {
            $info['wallet_snapshot'] = [
                'balance_before' => number_format($walletBefore->balance, 2, '.', ''),
                'frozen_amount_before' => number_format($walletBefore->frozenAmount, 2, '.', ''),
                'available_amount_before' => number_format($walletBefore->availableAmount, 2, '.', ''),
                'status_before' => $walletBefore->status,
                'status_before_name' => \Dealer\Wallet\Enum\WalletStatus::getName($walletBefore->status),
            ];
            $info['rollback_details'][] = sprintf(
                '钱包状态已恢复至：%s，余额 ¥%s，冻结 ¥%s，可用 ¥%s',
                $info['wallet_snapshot']['status_before_name'],
                $info['wallet_snapshot']['balance_before'],
                $info['wallet_snapshot']['frozen_amount_before'],
                $info['wallet_snapshot']['available_amount_before']
            );
        }

        if (isset($context['amount'])) {
            $info['operation_amount'] = number_format($context['amount'], 2, '.', '');
            $info['rollback_details'][] = "操作金额 ¥{$info['operation_amount']} 未实际生效";
        }

        if (isset($context['freeze_no'])) {
            $info['freeze_no'] = $context['freeze_no'];
            $info['rollback_details'][] = "冻结单号【{$context['freeze_no']}】状态未变更";
        }

        if ($e instanceof \Dealer\Wallet\Exception\InsufficientBalanceException) {
            $info['rollback_details'][] = '失败原因：可用余额不足，本次操作未执行';
        } elseif ($e instanceof \Dealer\Wallet\Exception\WalletStateException) {
            $info['rollback_details'][] = '失败原因：状态流转校验失败，请检查金额或处理现有冻结单';
        } elseif ($e instanceof \Dealer\Wallet\Exception\WalletPermissionException) {
            $info['rollback_details'][] = '失败原因：权限校验失败，请使用有权限的账号操作';
        }

        return $info;
    }

    private function buildRetryInfo(\Exception $e, string $operationName, array $context): array
    {
        $retryable = true;
        $retryStrategy = 'immediate';
        $maxRetries = 3;
        $retryDelayMs = 0;
        $suggestions = [];

        if ($e instanceof \Dealer\Wallet\Exception\InsufficientBalanceException) {
            $retryable = false;
            $suggestions[] = '请先充值或解冻部分冻结资金后再试';
            $suggestions[] = '可适当减小操作金额';
        } elseif ($e instanceof \Dealer\Wallet\Exception\WalletStateException) {
            $retryable = false;
            $suggestions[] = '请调整操作金额，避免触发非法状态流转';
            $suggestions[] = '如需继续操作，请先处理现有异常冻结单';
        } elseif ($e instanceof \Dealer\Wallet\Exception\WalletPermissionException) {
            $retryable = false;
            $suggestions[] = '请使用有权限的账号重新登录后操作';
            $suggestions[] = '如需临时授权，请联系管理员开通数据访问权限';
        } elseif (strpos($e->getMessage(), '乐观锁冲突') !== false) {
            $retryable = true;
            $retryStrategy = 'exponential_backoff';
            $retryDelayMs = 500;
            $suggestions[] = '并发操作冲突，请稍后重试';
            $suggestions[] = '建议使用指数退避策略，重试间隔 500ms、1s、2s';
        } elseif (strpos($e->getMessage(), '不存在') !== false) {
            $retryable = false;
            $suggestions[] = '请核对相关单号或ID是否正确';
        } else {
            $suggestions[] = '请检查参数后重试';
            $suggestions[] = '如问题持续，请联系技术支持';
        }

        $info = [
            'retryable' => $retryable,
            'retry_strategy' => $retryStrategy,
            'max_retries' => $maxRetries,
            'retry_delay_ms' => $retryDelayMs,
            'retry_entry' => $retryable ? [
                'operation_name' => $operationName,
                'can_retry' => true,
                'retry_button_text' => "重新{$operationName}",
                'retry_hint' => $retryDelayMs > 0 ? "建议 {$retryDelayMs}ms 后重试" : '可立即重试',
            ] : [
                'operation_name' => $operationName,
                'can_retry' => false,
                'retry_button_text' => '无法重试',
                'retry_hint' => '请根据错误提示调整后再操作',
            ],
            'suggestions' => $suggestions,
        ];

        if (isset($context['amount'])) {
            $info['retry_entry']['retry_params'] = [
                'amount' => number_format($context['amount'], 2, '.', ''),
            ];
        }

        return $info;
    }

    private function getOrCreateWallet(int $dealerId): Wallet
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            $wallet = $this->walletRepository->create($dealerId);
        }
        return $wallet;
    }

    private function getWalletForUpdate(int $dealerId): Wallet
    {
        $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在：经销商ID【{$dealerId}】，请先为该经销商创建钱包。");
        }
        return $wallet;
    }

    private function getOrCreateWalletForUpdate(int $dealerId): Wallet
    {
        $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        if (!$wallet) {
            $this->walletRepository->create($dealerId);
            $wallet = $this->walletRepository->findByDealerIdForUpdate($dealerId);
        }
        return $wallet;
    }

    private function updateWallet(Wallet $wallet): void
    {
        $maxRetries = 3;
        $retry = 0;
        $retryDelays = [0, 100, 300];
        while ($retry < $maxRetries) {
            if ($this->walletRepository->update($wallet)) {
                $this->refreshWallet($wallet);
                return;
            }
            if ($retry < $maxRetries - 1) {
                usleep($retryDelays[$retry] * 1000);
            }
            $retry++;
            $freshWallet = $this->walletRepository->findById($wallet->id);
            if (!$freshWallet) {
                throw new WalletException("钱包更新失败：钱包ID【{$wallet->id}】不存在。");
            }
            $this->syncWalletProperties($wallet, $freshWallet);
        }

        $exception = new WalletException("钱包更新失败：乐观锁冲突，已重试{$maxRetries}次。请稍后重试。");
        $exception->setRetryInfo([
            'retryable' => true,
            'retry_strategy' => 'exponential_backoff',
            'max_retries' => 3,
            'retry_delay_ms' => 500,
            'retry_entry' => [
                'operation_name' => '钱包更新',
                'can_retry' => true,
                'retry_button_text' => '重新提交',
                'retry_hint' => '建议 500ms 后重试，使用指数退避策略',
            ],
            'suggestions' => [
                '并发操作冲突，请稍后重试',
                '建议使用指数退避策略，重试间隔 500ms、1s、2s',
                '如多次失败，请检查是否有高频操作',
            ],
            'conflict_details' => [
                'wallet_id' => $wallet->id,
                'dealer_id' => $wallet->dealerId,
                'current_version' => $wallet->version,
                'retries_attempted' => $maxRetries,
            ],
        ]);
        $exception->setRollbackInfo([
            'rollback_success' => true,
            'rollback_time' => date('Y-m-d H:i:s'),
            'operation_name' => '钱包更新',
            'operation_type' => 'wallet_update',
            'error_type' => get_class($exception),
            'error_message' => $exception->getMessage(),
            'rollback_message' => '【回滚提示】钱包更新操作因并发冲突失败，所有变更已回滚。',
            'rollback_details' => [
                '钱包数据未变更，保持原有状态',
                '请重新操作或等待几秒后重试',
            ],
            'wallet_snapshot' => [
                'balance' => number_format($wallet->balance, 2, '.', ''),
                'frozen_amount' => number_format($wallet->frozenAmount, 2, '.', ''),
                'available_amount' => number_format($wallet->availableAmount, 2, '.', ''),
                'status' => $wallet->status,
                'status_name' => \Dealer\Wallet\Enum\WalletStatus::getName($wallet->status),
            ],
        ]);
        throw $exception;
    }

    private function refreshWallet(Wallet $wallet): void
    {
        $freshWallet = $this->walletRepository->findById($wallet->id);
        if (!$freshWallet) {
            throw new WalletException("钱包刷新失败：钱包ID【{$wallet->id}】不存在。");
        }
        $this->syncWalletProperties($wallet, $freshWallet);
    }

    private function syncWalletProperties(Wallet $target, Wallet $source): void
    {
        $target->balance = (float)$source->balance;
        $target->frozenAmount = (float)$source->frozenAmount;
        $target->availableAmount = (float)$source->availableAmount;
        $target->status = $source->status;
        $target->version = $source->version;
        $target->updatedAt = $source->updatedAt;
    }

    private function refreshAndReturn(Wallet $wallet): array
    {
        $this->refreshWallet($wallet);
        return $wallet->toArray();
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0.001) {
            throw new WalletException("金额校验失败：操作金额必须大于 0，当前值 ¥{$amount}。");
        }
    }

    private function recordTransaction(Wallet $wallet, int $type, float $amount, float $balanceBefore, float $balanceAfter, array $options = []): void
    {
        $this->transactionRepository->create([
            'wallet_id' => $wallet->id,
            'dealer_id' => $wallet->dealerId,
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'frozen_before' => $options['frozen_before'] ?? 0,
            'frozen_after' => $options['frozen_after'] ?? 0,
            'related_no' => $options['related_no'] ?? '',
            'operator' => $options['operator'] ?? '',
            'remark' => $options['remark'] ?? '',
        ]);
    }
}
