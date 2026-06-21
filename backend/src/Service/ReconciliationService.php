<?php

namespace Dealer\Wallet\Service;

use Dealer\Wallet\Config\Database;
use Dealer\Wallet\Enum\FreezeStatus;
use Dealer\Wallet\Enum\TransactionType;
use Dealer\Wallet\Model\FreezeRecord;
use Dealer\Wallet\Model\Transaction;
use Dealer\Wallet\Model\Wallet;
use Dealer\Wallet\Repository\FreezeRecordRepository;
use Dealer\Wallet\Repository\TransactionRepository;
use Dealer\Wallet\Repository\WalletRepository;
use PDO;

class ReconciliationService
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

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

    public function reconcileFreezeRecords(int $dealerId): array
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            return $this->buildResult(false, [], ['钱包不存在']);
        }

        $anomalies = [];
        $freezeRecords = $this->freezeRecordRepository->findAllByDealerId($dealerId);
        $detailList = [];

        $totalFreezeAmount = 0.0;
        $totalUnfreezeAmount = 0.0;
        $totalDeductAmount = 0.0;
        $totalRemainingExpected = 0.0;

        foreach ($freezeRecords as $record) {
            $detail = $this->reconcileSingleFreezeRecord($record, $anomalies);
            $detailList[] = $detail;

            $totalFreezeAmount += $record->amount;
            $totalRemainingExpected += $record->remainingAmount;

            foreach ($detail['transactions'] as $tx) {
                if ($tx['type'] === TransactionType::UNFREEZE) {
                    $totalUnfreezeAmount += $tx['amount'];
                }
                if ($tx['type'] === TransactionType::CONSUME && !empty($tx['related_no'])) {
                    $totalDeductAmount += $tx['amount'];
                }
            }
        }

        $sumFrozenFromRecords = $this->freezeRecordRepository->sumFrozenByDealerId($dealerId);
        if (abs($sumFrozenFromRecords - $wallet->frozenAmount) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FREEZE_SUM_MISMATCH',
                'message' => sprintf(
                    '钱包冻结金额与冻结记录汇总不一致：钱包冻结¥%.2f，冻结记录剩余汇总¥%.2f，差额¥%.2f',
                    $wallet->frozenAmount,
                    $sumFrozenFromRecords,
                    $sumFrozenFromRecords - $wallet->frozenAmount
                ),
            ];
        }

        $reconcileTotal = bcsub(bcsub((string)$totalFreezeAmount, (string)$totalUnfreezeAmount, 2), (string)$totalDeductAmount, 2);
        if (abs((float)$reconcileTotal - $totalRemainingExpected) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FREEZE_TOTAL_MISMATCH',
                'message' => sprintf(
                    '冻结总额勾稽关系异常：冻结总额¥%.2f - 解冻总额¥%.2f - 扣除总额¥%.2f = ¥%.2f，与记录剩余汇总¥%.2f不一致',
                    $totalFreezeAmount,
                    $totalUnfreezeAmount,
                    $totalDeductAmount,
                    (float)$reconcileTotal,
                    $totalRemainingExpected
                ),
            ];
        }

        $hasErrors = $this->hasSeverity($anomalies, self::SEVERITY_ERROR);
        $summary = [
            'dealer_id' => $dealerId,
            'wallet_frozen_amount' => number_format($wallet->frozenAmount, 2, '.', ''),
            'records_remaining_sum' => number_format($sumFrozenFromRecords, 2, '.', ''),
            'total_freeze' => number_format($totalFreezeAmount, 2, '.', ''),
            'total_unfreeze' => number_format($totalUnfreezeAmount, 2, '.', ''),
            'total_deduct' => number_format($totalDeductAmount, 2, '.', ''),
            'total_expected_remaining' => number_format($totalRemainingExpected, 2, '.', ''),
            'record_count' => count($freezeRecords),
            'anomaly_count' => count($anomalies),
            'error_count' => $this->countSeverity($anomalies, self::SEVERITY_ERROR),
            'warning_count' => $this->countSeverity($anomalies, self::SEVERITY_WARNING),
        ];

        return $this->buildResult(!$hasErrors, $anomalies, [], [
            'summary' => $summary,
            'records' => $detailList,
        ]);
    }

    private function reconcileSingleFreezeRecord(FreezeRecord $record, array &$anomalies): array
    {
        $transactions = $this->transactionRepository->findByRelatedNo($record->freezeNo);
        $txArr = [];

        $freezeTxFound = false;
        $sumUnfreeze = 0.0;
        $sumDeduct = 0.0;

        foreach ($transactions as $tx) {
            $txArr[] = $tx->toArray();

            if ($tx->type === TransactionType::FREEZE) {
                $freezeTxFound = true;
                if (abs($tx->amount - $record->amount) > 0.001) {
                    $anomalies[] = [
                        'severity' => self::SEVERITY_ERROR,
                        'code' => 'FREEZE_AMOUNT_MISMATCH',
                        'freeze_no' => $record->freezeNo,
                        'message' => sprintf(
                            '冻结单【%s】金额不一致：冻结记录金额¥%.2f，交易流水冻结金额¥%.2f',
                            $record->freezeNo,
                            $record->amount,
                            $tx->amount
                        ),
                    ];
                }
                $expectedFrozenAfter = bcadd((string)$tx->frozenBefore, (string)$tx->amount, 2);
                if (abs((float)$expectedFrozenAfter - $tx->frozenAfter) > 0.001) {
                    $anomalies[] = [
                        'severity' => self::SEVERITY_ERROR,
                        'code' => 'FREEZE_FROZEN_MISMATCH',
                        'freeze_no' => $record->freezeNo,
                        'message' => sprintf(
                            '冻结单【%s】冻结金额变更勾稽异常：frozen_before+amount=¥%.2f，但frozen_after=¥%.2f',
                            $record->freezeNo,
                            (float)$expectedFrozenAfter,
                            $tx->frozenAfter
                        ),
                    ];
                }
            }

            if ($tx->type === TransactionType::UNFREEZE) {
                $sumUnfreeze += $tx->amount;
                $expectedFrozenAfter = bcsub((string)$tx->frozenBefore, (string)$tx->amount, 2);
                if (abs((float)$expectedFrozenAfter - $tx->frozenAfter) > 0.001) {
                    $anomalies[] = [
                        'severity' => self::SEVERITY_ERROR,
                        'code' => 'UNFREEZE_FROZEN_MISMATCH',
                        'freeze_no' => $record->freezeNo,
                        'message' => sprintf(
                            '冻结单【%s】解冻金额勾稽异常：frozen_before-unfreeze_amount=¥%.2f，但frozen_after=¥%.2f',
                            $record->freezeNo,
                            (float)$expectedFrozenAfter,
                            $tx->frozenAfter
                        ),
                    ];
                }
            }

            if ($tx->type === TransactionType::CONSUME) {
                $sumDeduct += $tx->amount;
                $expectedBalanceAfter = bcsub((string)$tx->balanceBefore, (string)$tx->amount, 2);
                if (abs((float)$expectedBalanceAfter - $tx->balanceAfter) > 0.001) {
                    $anomalies[] = [
                        'severity' => self::SEVERITY_ERROR,
                        'code' => 'DEDUCT_BALANCE_MISMATCH',
                        'freeze_no' => $record->freezeNo,
                        'message' => sprintf(
                            '冻结单【%s】扣除余额勾稽异常：balance_before-amount=¥%.2f，但balance_after=¥%.2f',
                            $record->freezeNo,
                            (float)$expectedBalanceAfter,
                            $tx->balanceAfter
                        ),
                    ];
                }
                $expectedFrozenAfter = bcsub((string)$tx->frozenBefore, (string)$tx->amount, 2);
                if (abs((float)$expectedFrozenAfter - $tx->frozenAfter) > 0.001) {
                    $anomalies[] = [
                        'severity' => self::SEVERITY_ERROR,
                        'code' => 'DEDUCT_FROZEN_MISMATCH',
                        'freeze_no' => $record->freezeNo,
                        'message' => sprintf(
                            '冻结单【%s】扣除冻结勾稽异常：frozen_before-amount=¥%.2f，但frozen_after=¥%.2f',
                            $record->freezeNo,
                            (float)$expectedFrozenAfter,
                            $tx->frozenAfter
                        ),
                    ];
                }
            }
        }

        if (!$freezeTxFound) {
            $anomalies[] = [
                'severity' => self::SEVERITY_WARNING,
                'code' => 'FREEZE_TX_MISSING',
                'freeze_no' => $record->freezeNo,
                'message' => sprintf('冻结单【%s】缺少对应的冻结交易流水', $record->freezeNo),
            ];
        }

        $releasedAmount = bcadd((string)$sumUnfreeze, (string)$sumDeduct, 2);
        $expectedRemaining = bcsub((string)$record->amount, (string)$releasedAmount, 2);
        if (abs((float)$expectedRemaining - $record->remainingAmount) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'REMAINING_AMOUNT_MISMATCH',
                'freeze_no' => $record->freezeNo,
                'message' => sprintf(
                    '冻结单【%s】剩余金额勾稽异常：冻结¥%.2f - 解冻¥%.2f - 扣除¥%.2f = 应剩¥%.2f，但记录剩余¥%.2f',
                    $record->freezeNo,
                    $record->amount,
                    $sumUnfreeze,
                    $sumDeduct,
                    (float)$expectedRemaining,
                    $record->remainingAmount
                ),
            ];
        }

        $isStatusConsistent = true;
        if ($record->status === FreezeStatus::UNFROZEN && abs($record->remainingAmount) > 0.001) {
            $isStatusConsistent = false;
        }
        if ($record->status === FreezeStatus::DEDUCTED && abs($record->remainingAmount) > 0.001) {
            $isStatusConsistent = false;
        }
        if ($record->status === FreezeStatus::FROZEN && abs($record->remainingAmount) <= 0.001) {
            $isStatusConsistent = false;
        }
        if (!$isStatusConsistent) {
            $anomalies[] = [
                'severity' => self::SEVERITY_WARNING,
                'code' => 'FREEZE_STATUS_INCONSISTENT',
                'freeze_no' => $record->freezeNo,
                'message' => sprintf(
                    '冻结单【%s】状态与剩余金额不匹配：状态【%s】，剩余¥%.2f',
                    $record->freezeNo,
                    FreezeStatus::getName($record->status),
                    $record->remainingAmount
                ),
            ];
        }

        return [
            'record' => $record->toArray(),
            'transactions' => $txArr,
            'sum_unfreeze' => number_format($sumUnfreeze, 2, '.', ''),
            'sum_deduct' => number_format($sumDeduct, 2, '.', ''),
            'expected_remaining' => number_format((float)$expectedRemaining, 2, '.', ''),
            'released_total' => number_format((float)$releasedAmount, 2, '.', ''),
        ];
    }

    public function reconcileBalanceChanges(int $dealerId): array
    {
        $wallet = $this->walletRepository->findByDealerId($dealerId);
        if (!$wallet) {
            return $this->buildResult(false, [], ['钱包不存在']);
        }

        $anomalies = [];
        $transactions = $this->transactionRepository->findAllByDealerIdOrdered($dealerId);
        $detailList = [];

        $calculatedBalance = 0.0;
        $calculatedFrozen = 0.0;
        $prevBalanceAfter = null;
        $prevFrozenAfter = null;

        $totalRecharge = 0.0;
        $totalWithdraw = 0.0;
        $totalConsume = 0.0;
        $totalRefund = 0.0;
        $totalFreeze = 0.0;
        $totalUnfreeze = 0.0;

        foreach ($transactions as $index => $tx) {
            $balanceCheck = $this->checkSingleTransactionBalance($tx, $anomalies);
            $chainCheck = $this->checkTransactionChain($tx, $prevBalanceAfter, $prevFrozenAfter, $anomalies, $index);

            if ($index === 0) {
                $calculatedBalance = $tx->balanceBefore;
                $calculatedFrozen = $tx->frozenBefore;
            }

            $this->applyTransactionToRunningTotal($tx, $calculatedBalance, $calculatedFrozen);
            $this->accumulateTypeTotals($tx, $totalRecharge, $totalWithdraw, $totalConsume, $totalRefund, $totalFreeze, $totalUnfreeze);

            if (abs($calculatedBalance - $tx->balanceAfter) > 0.001) {
                $anomalies[] = [
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'RUNNING_BALANCE_MISMATCH',
                    'transaction_id' => $tx->id,
                    'message' => sprintf(
                        '交易ID[%d]累计余额与balance_after不一致：累计¥%.2f，balance_after¥%.2f，差额¥%.2f',
                        $tx->id,
                        $calculatedBalance,
                        $tx->balanceAfter,
                        $calculatedBalance - $tx->balanceAfter
                    ),
                ];
            }

            if (abs($calculatedFrozen - $tx->frozenAfter) > 0.001) {
                $anomalies[] = [
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'RUNNING_FROZEN_MISMATCH',
                    'transaction_id' => $tx->id,
                    'message' => sprintf(
                        '交易ID[%d]累计冻结与frozen_after不一致：累计¥%.2f，frozen_after¥%.2f，差额¥%.2f',
                        $tx->id,
                        $calculatedFrozen,
                        $tx->frozenAfter,
                        $calculatedFrozen - $tx->frozenAfter
                    ),
                ];
            }

            $detailList[] = array_merge($tx->toArray(), [
                'running_balance' => number_format($calculatedBalance, 2, '.', ''),
                'running_frozen' => number_format($calculatedFrozen, 2, '.', ''),
                'checks' => [
                    'balance_ok' => $balanceCheck,
                    'chain_ok' => $chainCheck,
                ],
            ]);

            $prevBalanceAfter = $tx->balanceAfter;
            $prevFrozenAfter = $tx->frozenAfter;
        }

        if (abs($calculatedBalance - $wallet->balance) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FINAL_BALANCE_MISMATCH',
                'message' => sprintf(
                    '钱包最终余额不一致：交易流水累计余额¥%.2f，钱包当前余额¥%.2f，差额¥%.2f',
                    $calculatedBalance,
                    $wallet->balance,
                    $calculatedBalance - $wallet->balance
                ),
            ];
        }

        if (abs($calculatedFrozen - $wallet->frozenAmount) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_ERROR,
                'code' => 'FINAL_FROZEN_MISMATCH',
                'message' => sprintf(
                    '钱包最终冻结金额不一致：交易流水累计冻结¥%.2f，钱包当前冻结¥%.2f，差额¥%.2f',
                    $calculatedFrozen,
                    $wallet->frozenAmount,
                    $calculatedFrozen - $wallet->frozenAmount
                ),
            ];
        }

        $expectedAvailable = bcsub((string)$wallet->balance, (string)$wallet->frozenAmount, 2);
        if (abs((float)$expectedAvailable - $wallet->availableAmount) > 0.001) {
            $anomalies[] = [
                'severity' => self::SEVERITY_WARNING,
                'code' => 'AVAILABLE_AMOUNT_MISMATCH',
                'message' => sprintf(
                    '可用余额计算异常：余额¥%.2f - 冻结¥%.2f = 应得可用¥%.2f，但钱包记录可用¥%.2f',
                    $wallet->balance,
                    $wallet->frozenAmount,
                    (float)$expectedAvailable,
                    $wallet->availableAmount
                ),
            ];
        }

        $balanceChangeSummary = bcadd(
            bcsub(
                bcadd((string)$totalRecharge, (string)$totalRefund, 2),
                (string)$totalWithdraw,
                2
            ),
            bcsub((string)'0', (string)$totalConsume, 2),
            2
        );

        $hasErrors = $this->hasSeverity($anomalies, self::SEVERITY_ERROR);
        $summary = [
            'dealer_id' => $dealerId,
            'wallet_balance' => number_format($wallet->balance, 2, '.', ''),
            'wallet_frozen' => number_format($wallet->frozenAmount, 2, '.', ''),
            'wallet_available' => number_format($wallet->availableAmount, 2, '.', ''),
            'expected_available' => number_format((float)$expectedAvailable, 2, '.', ''),
            'running_balance_final' => number_format($calculatedBalance, 2, '.', ''),
            'running_frozen_final' => number_format($calculatedFrozen, 2, '.', ''),
            'total_recharge' => number_format($totalRecharge, 2, '.', ''),
            'total_withdraw' => number_format($totalWithdraw, 2, '.', ''),
            'total_consume' => number_format($totalConsume, 2, '.', ''),
            'total_refund' => number_format($totalRefund, 2, '.', ''),
            'total_freeze' => number_format($totalFreeze, 2, '.', ''),
            'total_unfreeze' => number_format($totalUnfreeze, 2, '.', ''),
            'net_balance_change' => number_format((float)$balanceChangeSummary, 2, '.', ''),
            'transaction_count' => count($transactions),
            'anomaly_count' => count($anomalies),
            'error_count' => $this->countSeverity($anomalies, self::SEVERITY_ERROR),
            'warning_count' => $this->countSeverity($anomalies, self::SEVERITY_WARNING),
        ];

        return $this->buildResult(!$hasErrors, $anomalies, [], [
            'summary' => $summary,
            'transactions' => $detailList,
        ]);
    }

    private function checkSingleTransactionBalance(Transaction $tx, array &$anomalies): bool
    {
        $ok = true;
        $type = $tx->type;

        if (in_array($type, [TransactionType::RECHARGE, TransactionType::REFUND], true)) {
            $expected = bcadd((string)$tx->balanceBefore, (string)$tx->amount, 2);
            if (abs((float)$expected - $tx->balanceAfter) > 0.001) {
                $anomalies[] = [
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'TX_BALANCE_ARITHMETIC',
                    'transaction_id' => $tx->id,
                    'message' => sprintf(
                        '交易ID[%d]【%s】余额计算异常：balance_before(¥%.2f) + amount(¥%.2f) = ¥%.2f ≠ balance_after(¥%.2f)',
                        $tx->id,
                        TransactionType::getName($type),
                        $tx->balanceBefore,
                        $tx->amount,
                        (float)$expected,
                        $tx->balanceAfter
                    ),
                ];
                $ok = false;
            }
        }

        if (in_array($type, [TransactionType::WITHDRAW, TransactionType::CONSUME], true)) {
            $expected = bcsub((string)$tx->balanceBefore, (string)$tx->amount, 2);
            if (abs((float)$expected - $tx->balanceAfter) > 0.001) {
                $anomalies[] = [
                    'severity' => self::SEVERITY_ERROR,
                    'code' => 'TX_BALANCE_ARITHMETIC',
                    'transaction_id' => $tx->id,
                    'message' => sprintf(
                        '交易ID[%d]【%s】余额计算异常：balance_before(¥%.2f) - amount(¥%.2f) = ¥%.2f ≠ balance_after(¥%.2f)',
                        $tx->id,
                        TransactionType::getName($type),
                        $tx->balanceBefore,
                        $tx->amount,
                        (float)$expected,
                        $tx->balanceAfter
                    ),
                ];
                $ok = false;
            }
        }

        if (in_array($type, [TransactionType::FREEZE, TransactionType::UNFREEZE], true)) {
            if (abs($tx->balanceBefore - $tx->balanceAfter) > 0.001) {
                $anomalies[] = [
                    'severity' => self::SEVERITY_WARNING,
                    'code' => 'TX_BALANCE_UNEXPECTED_CHANGE',
                    'transaction_id' => $tx->id,
                    'message' => sprintf(
                        '交易ID[%d]【%s】不应变更余额：balance_before(¥%.2f) ≠ balance_after(¥%.2f)',
                        $tx->id,
                        TransactionType::getName($type),
                        $tx->balanceBefore,
                        $tx->balanceAfter
                    ),
                ];
                $ok = false;
            }
        }

        if ($type === TransactionType::FREEZE) {
            $expected = bcadd((string)$tx->frozenBefore, (string)$tx->amount, 2);
            if (abs((float)$expected - $tx->frozenAfter) > 0.001) {
                $anomalies[] = [
