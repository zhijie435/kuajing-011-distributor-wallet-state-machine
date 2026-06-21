<?php

namespace Dealer\Wallet\Service;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Exception\InsufficientBalanceException;
use Dealer\Wallet\Exception\WalletException;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Repository\TransactionRepository;
use Dealer\Wallet\Repository\WalletRepository;
use Dealer\Wallet\Service\ReconciliationService;
use Dealer\Wallet\StateMachine\WalletStateMachine;
use PDO;

class WalletService
{
    private WalletRepository $walletRepository;
    private TransactionRepository $transactionRepository;
    private FreezeRecordRepository $freezeRecordRepository;
    private ReconciliationService $reconciliationService;
    private PDO $pdo;

    public function __construct()
    {
        $this->walletRepository = new WalletRepository();
        $this->transactionRepository = new TransactionRepository();
        $this->freezeRecordRepository = new FreezeRecordRepository();
        $this->reconciliationService = new ReconciliationService();
        $this->pdo = Database::getConnection();
    }

    public function getWallet(int $dealerId): array
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            $wallet = $this->walletRepository->create($dealerId);
        }
        return $wallet->toArray();
    }

    public function getAllWallets(int $page = 1, int $pageSize = 20): array
    {
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

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getOrCreateWalletForUpdate($dealerId);

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = (float)bcadd((string)$wallet->balance, (string)$amount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($balanceAfter, $frozenBefore, $frozenBefore);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::RECHARGE, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function withdraw(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount - 0.001) {
                throw new InsufficientBalanceException(
                    "可用余额不足：当前可用 ¥{$wallet->availableAmount}，需提现 ¥{$amount}。" .
                    "建议：先充值或解冻部分冻结资金。"
                );
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = (float)bcsub((string)$wallet->balance, (string)$amount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($balanceAfter, $frozenBefore, $frozenBefore);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::WITHDRAW, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function freeze(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount - 0.001) {
                throw new InsufficientBalanceException(
                    "可用余额不足：当前可用 ¥{$wallet->availableAmount}，需冻结 ¥{$amount}。" .
                    "建议：先充值或解冻部分冻结资金。"
                );
            }

            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcadd((string)$wallet->frozenAmount, (string)$amount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($wallet->balance, $frozenBefore, $frozenAfter);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->frozenAmount = $frozenAfter;
            $wallet->calculateAvailable();

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
        $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
        if (!$freezeRecord) {
            throw new WalletException("冻结记录不存在：冻结单号【{$freezeNo}】，请核对单号是否正确。");
        }
        if ($freezeRecord->status !== FreezeStatus::FROZEN) {
            throw new WalletException(
                "冻结记录状态异常：当前状态【" . FreezeStatus::getName($freezeRecord->status) . "】，" .
                "仅【冻结中】的记录允许解冻。如需重新操作请创建新的冻结单。"
            );
        }

        $unfreezeAmount = $amount ?? $freezeRecord->remainingAmount;
        if ($unfreezeAmount > $freezeRecord->remainingAmount + 0.001) {
            throw new WalletException(
                "解冻金额超过剩余冻结金额：剩余冻结 ¥{$freezeRecord->remainingAmount}，申请解冻 ¥{$unfreezeAmount}。" .
                "请调整解冻金额或分多次解冻。"
            );
        }

        $context = [
            'dealer_id' => $freezeRecord->dealerId,
            'amount' => $unfreezeAmount,
            'freeze_no' => $freezeNo,
        ];

        return $this->executeTransaction(function () use ($freezeNo, $unfreezeAmount, $amount, $options, $freezeRecord) {
            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);

            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$wallet->frozenAmount, (string)$unfreezeAmount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($wallet->balance, $frozenBefore, $frozenAfter);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->frozenAmount = $frozenAfter;
            $wallet->calculateAvailable();

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
        }, $context);
    }

    public function consume(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount - 0.001) {
                throw new InsufficientBalanceException(
                    "可用余额不足：当前可用 ¥{$wallet->availableAmount}，需消费 ¥{$amount}。" .
                    "建议：先充值或联系管理员核实可用余额。"
                );
            }

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = (float)bcsub((string)$wallet->balance, (string)$amount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($balanceAfter, $frozenBefore, $frozenBefore);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::CONSUME, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function refund(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            $balanceBefore = $wallet->balance;
            $frozenBefore = $wallet->frozenAmount;
            $balanceAfter = (float)bcadd((string)$wallet->balance, (string)$amount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($balanceAfter, $frozenBefore, $frozenBefore);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::REFUND, $amount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $wallet->frozenAmount,
                'operator' => $options['operator'] ?? '',
                'remark' => ($options['remark'] ?? '') . ($transition['changed'] ? " | {$transition['message']}" : ''),
                'related_no' => $options['related_no'] ?? '',
            ]);

            $result = $this->refreshAndReturn($wallet);
            $result['status_transition'] = $transition;
            return $result;
        }, ['dealer_id' => $dealerId, 'amount' => $amount]);
    }

    public function deductFrozen(string $freezeNo, float $amount = null, array $options = []): array
    {
        $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
        if (!$freezeRecord) {
            throw new WalletException("冻结记录不存在：冻结单号【{$freezeNo}】，请核对单号是否正确。");
        }
        if ($freezeRecord->status !== FreezeStatus::FROZEN) {
            throw new WalletException(
                "冻结记录状态异常：当前状态【" . FreezeStatus::getName($freezeRecord->status) . "】，" .
                "仅【冻结中】的记录允许扣除。如需重新操作请创建新的冻结单。"
            );
        }

        $deductAmount = $amount ?? $freezeRecord->remainingAmount;
        if ($deductAmount > $freezeRecord->remainingAmount + 0.001) {
            throw new WalletException(
                "扣除金额超过剩余冻结金额：剩余冻结 ¥{$freezeRecord->remainingAmount}，申请扣除 ¥{$deductAmount}。" .
                "请调整扣除金额。"
            );
        }

        $context = [
            'dealer_id' => $freezeRecord->dealerId,
            'amount' => $deductAmount,
            'freeze_no' => $freezeNo,
        ];

        return $this->executeTransaction(function () use ($freezeNo, $deductAmount, $amount, $options, $freezeRecord) {
            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);

            $balanceBefore = $wallet->balance;
            $balanceAfter = (float)bcsub((string)$wallet->balance, (string)$deductAmount, 2);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = (float)bcsub((string)$wallet->frozenAmount, (string)$deductAmount, 2);

            $stateMachine = new WalletStateMachine($wallet->status);
            $transition = $stateMachine->assertCanTransitionByAmount($balanceAfter, $frozenBefore, $frozenAfter);

            if ($transition['changed']) {
                $stateMachine->transition($transition['to_status']);
            }

            $wallet->balance = $balanceAfter;
            $wallet->frozenAmount = $frozenAfter;
            $wallet->calculateAvailable();

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
        }, $context);
    }

    public function getTransactions(int $dealerId, int $page = 1, int $pageSize = 20): array
    {
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
        $wallet = $this->getOrCreateWallet($dealerId);
        $items = $this->freezeRecordRepository->findByWalletId($wallet->id, $status, $page, $pageSize);
        return [
            'items' => $items,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getStateTransitions(int $dealerId): array
    {
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
        return $this->reconciliationService->reconcileFreezeRecords($dealerId);
    }

    public function reconcileBalanceChanges(int $dealerId): array
    {
        return $this->reconciliationService->reconcileBalanceChanges($dealerId);
    }

    public function getAnomalySummary(int $dealerId): array
    {
        return $this->reconciliationService->getAnomalySummary($dealerId);
    }

    public function exportFreezeReconciliation(int $dealerId): array
    {
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
        return $this->reconciliationService->fixWalletInconsistency($dealerId, $operator);
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
