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
use Dealer\Wallet\StateMachine\WalletStateMachine;
use PDO;

class WalletService
{
    private WalletRepository $walletRepository;
    private TransactionRepository $transactionRepository;
    private FreezeRecordRepository $freezeRecordRepository;
    private PDO $pdo;

    public function __construct()
    {
        $this->walletRepository = new WalletRepository();
        $this->transactionRepository = new TransactionRepository();
        $this->freezeRecordRepository = new FreezeRecordRepository();
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
            $wallet = $this->getOrCreateWallet($dealerId);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($wallet->balance, $amount, 2);

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::RECHARGE, $amount, $balanceBefore, $balanceAfter, $options);

            return $wallet->toArray();
        });
    }

    public function withdraw(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount) {
                throw new InsufficientBalanceException("可用余额不足，可用：{$wallet->availableAmount}，需提现：{$amount}");
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($wallet->balance, $amount, 2);

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::WITHDRAW, $amount, $balanceBefore, $balanceAfter, $options);

            return $wallet->toArray();
        });
    }

    public function freeze(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount) {
                throw new InsufficientBalanceException("可用余额不足，可用：{$wallet->availableAmount}，需冻结：{$amount}");
            }

            $stateMachine = new WalletStateMachine($wallet->status);

            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = bcadd($wallet->frozenAmount, $amount, 2);

            $newStatus = WalletStateMachine::calculateStatus($wallet->balance, $frozenAfter);

            if ($newStatus !== $wallet->status) {
                $stateMachine->transition($newStatus);
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
                'remark' => $options['reason'] ?? '',
            ]);

            return array_merge($wallet->toArray(), ['freeze_no' => $freezeNo]);
        });
    }

    public function unfreeze(string $freezeNo, float $amount = null, array $options = []): array
    {
        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
            if (!$freezeRecord) {
                throw new WalletException("冻结记录不存在：{$freezeNo}");
            }
            if ($freezeRecord->status !== FreezeStatus::FROZEN) {
                throw new WalletException("冻结记录状态异常，当前状态：" . FreezeStatus::getName($freezeRecord->status));
            }

            $unfreezeAmount = $amount ?? $freezeRecord->remainingAmount;
            if ($unfreezeAmount > $freezeRecord->remainingAmount) {
                throw new WalletException("解冻金额超过剩余冻结金额，剩余：{$freezeRecord->remainingAmount}，需解冻：{$unfreezeAmount}");
            }

            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);
            $stateMachine = new WalletStateMachine($wallet->status);

            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = bcsub($wallet->frozenAmount, $unfreezeAmount, 2);

            $newStatus = WalletStateMachine::calculateStatus($wallet->balance, $frozenAfter);
            if ($newStatus !== $wallet->status) {
                $stateMachine->transition($newStatus);
            }

            $wallet->frozenAmount = $frozenAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $remainingAfter = bcsub($freezeRecord->remainingAmount, $unfreezeAmount, 2);
            $newFreezeStatus = $remainingAfter <= 0 ? FreezeStatus::UNFROZEN : FreezeStatus::FROZEN;
            $this->freezeRecordRepository->updateRemaining($freezeRecord, $remainingAfter, $newFreezeStatus);

            $this->recordTransaction($wallet, TransactionType::UNFREEZE, $unfreezeAmount, $wallet->balance, $wallet->balance, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => $options['remark'] ?? '',
            ]);

            return $wallet->toArray();
        });
    }

    public function consume(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            if ($wallet->availableAmount < $amount) {
                throw new InsufficientBalanceException("可用余额不足，可用：{$wallet->availableAmount}，需消费：{$amount}");
            }

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($wallet->balance, $amount, 2);

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::CONSUME, $amount, $balanceBefore, $balanceAfter, $options);

            return $wallet->toArray();
        });
    }

    public function refund(int $dealerId, float $amount, array $options = []): array
    {
        $this->validateAmount($amount);

        return $this->executeTransaction(function () use ($dealerId, $amount, $options) {
            $wallet = $this->getWalletForUpdate($dealerId);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcadd($wallet->balance, $amount, 2);

            $wallet->balance = $balanceAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $this->recordTransaction($wallet, TransactionType::REFUND, $amount, $balanceBefore, $balanceAfter, $options);

            return $wallet->toArray();
        });
    }

    public function deductFrozen(string $freezeNo, float $amount = null, array $options = []): array
    {
        return $this->executeTransaction(function () use ($freezeNo, $amount, $options) {
            $freezeRecord = $this->freezeRecordRepository->findByFreezeNo($freezeNo);
            if (!$freezeRecord) {
                throw new WalletException("冻结记录不存在：{$freezeNo}");
            }
            if ($freezeRecord->status !== FreezeStatus::FROZEN) {
                throw new WalletException("冻结记录状态异常，当前状态：" . FreezeStatus::getName($freezeRecord->status));
            }

            $deductAmount = $amount ?? $freezeRecord->remainingAmount;
            if ($deductAmount > $freezeRecord->remainingAmount) {
                throw new WalletException("扣除金额超过剩余冻结金额，剩余：{$freezeRecord->remainingAmount}，需扣除：{$deductAmount}");
            }

            $wallet = $this->getWalletForUpdate($freezeRecord->dealerId);
            $stateMachine = new WalletStateMachine($wallet->status);

            $balanceBefore = $wallet->balance;
            $balanceAfter = bcsub($wallet->balance, $deductAmount, 2);
            $frozenBefore = $wallet->frozenAmount;
            $frozenAfter = bcsub($wallet->frozenAmount, $deductAmount, 2);

            $newStatus = WalletStateMachine::calculateStatus($balanceAfter, $frozenAfter);
            if ($newStatus !== $wallet->status) {
                $stateMachine->transition($newStatus);
            }

            $wallet->balance = $balanceAfter;
            $wallet->frozenAmount = $frozenAfter;
            $wallet->calculateAvailable();

            $this->updateWallet($wallet);

            $remainingAfter = bcsub($freezeRecord->remainingAmount, $deductAmount, 2);
            $newFreezeStatus = $remainingAfter <= 0 ? FreezeStatus::DEDUCTED : FreezeStatus::FROZEN;
            $this->freezeRecordRepository->updateRemaining($freezeRecord, $remainingAfter, $newFreezeStatus);

            $this->recordTransaction($wallet, TransactionType::CONSUME, $deductAmount, $balanceBefore, $balanceAfter, [
                'frozen_before' => $frozenBefore,
                'frozen_after' => $frozenAfter,
                'related_no' => $freezeNo,
                'operator' => $options['operator'] ?? '',
                'remark' => $options['remark'] ?? '冻结资金扣除',
            ]);

            return $wallet->toArray();
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
            'allowed_transitions' => $transitions,
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
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            throw new WalletException("钱包不存在，经销商ID：{$dealerId}");
        }
        return $wallet;
    }

    private function updateWallet(Wallet $wallet): void
    {
        $maxRetries = 3;
        $retry = 0;
        while ($retry < $maxRetries) {
            if ($this->walletRepository->update($wallet)) {
                return;
            }
            $retry++;
            $freshWallet = $this->walletRepository->findById($wallet->id);
            if (!$freshWallet) {
                throw new WalletException("钱包更新失败，钱包不存在");
            }
            $wallet->version = $freshWallet->version;
        }
        throw new WalletException("钱包更新失败，乐观锁冲突，已重试{$maxRetries}次");
    }

    private function validateAmount(float $amount): void
    {
        if ($amount <= 0) {
            throw new WalletException("金额必须大于0，当前值：{$amount}");
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
            'balance_after' => $balanceAfter