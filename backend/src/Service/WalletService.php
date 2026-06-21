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
        });
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
        });
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
        });
    }

    public function unfreeze(string $freezeNo, float $amount = null, array $options = []): array
    {
        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
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
        });
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
        });
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
        });
    }

    public function deductFrozen(string $freezeNo, float $amount = null, array $options = []): array
    {
        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
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
        });
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

    private function executeTransaction(callable $callback)
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
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
        while ($retry < $maxRetries) {
            if ($this->walletRepository->update($wallet)) {
                $this->refreshWallet($wallet);
                return;
            }
            $retry++;
            $freshWallet = $this->walletRepository->findById($wallet->id);
            if (!$freshWallet) {
                throw new WalletException("钱包更新失败：钱包ID【{$wallet->id}】不存在。");
            }
            $this->syncWalletProperties($wallet, $freshWallet);
        }
        throw new WalletException("钱包更新失败：乐观锁冲突，已重试{$maxRetries}次。请稍后重试。");
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
