<?php

namespace Dealer\Wallet\StateMachine;

use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\WalletStateException;
use Dealer\Wallet\Model\Wallet;

class WalletStateMachine
{
    private int $currentStatus;

    private static array $allowedTransitions = [
        WalletStatus::NORMAL => [
            WalletStatus::PARTIALLY_FROZEN,
            WalletStatus::FULLY_FROZEN,
        ],
        WalletStatus::PARTIALLY_FROZEN => [
            WalletStatus::NORMAL,
            WalletStatus::FULLY_FROZEN,
        ],
        WalletStatus::FULLY_FROZEN => [
            WalletStatus::PARTIALLY_FROZEN,
            WalletStatus::NORMAL,
        ],
    ];

    public function __construct(int $currentStatus)
    {
        if (!isset(self::$allowedTransitions[$currentStatus])) {
            throw new WalletStateException(
                "数据异常：无效的钱包状态值 ({$currentStatus})，合法状态：1-正常 2-部分冻结 3-全额冻结"
            );
        }
        $this->currentStatus = $currentStatus;
    }

    public static function fromWallet(Wallet $wallet): self
    {
        return new self($wallet->status);
    }

    public static function validateAmounts(float $balance, float $frozenAmount): void
    {
        if ($frozenAmount < -0.001) {
            throw new WalletStateException(
                "数据异常：冻结金额为负数 (¥" . number_format($frozenAmount, 2) . ")，" .
                "请检查钱包数据完整性，可能存在解冻/扣除操作超额问题。"
            );
        }
        if ($balance < -0.001) {
            throw new WalletStateException(
                "数据异常：账户余额为负数 (¥" . number_format($balance, 2) . ")，" .
                "请检查交易流水，可能存在消费/提现操作超额问题。"
            );
        }
        if ($frozenAmount > $balance + 0.001) {
            throw new WalletStateException(
                "数据异常：冻结金额 (¥" . number_format($frozenAmount, 2) .
                ") 超过账户余额 (¥" . number_format($balance, 2) . ")，" .
                "差额 ¥" . number_format($frozenAmount - $balance, 2) .
                "，请核查冻结记录与钱包余额是否一致。"
            );
        }
    }

    public static function calculateStatus(float $balance, float $frozenAmount, bool $strict = true): int
    {
        if ($strict) {
            self::validateAmounts($balance, $frozenAmount);
        }

        if ($frozenAmount <= 0.001) {
            return WalletStatus::NORMAL;
        }
        if ($frozenAmount >= $balance - 0.001) {
            return WalletStatus::FULLY_FROZEN;
        }
        return WalletStatus::PARTIALLY_FROZEN;
    }

    public function canTransitionTo(int $targetStatus): bool
    {
        return in_array($targetStatus, self::$allowedTransitions[$this->currentStatus], true);
    }

    public function transition(int $targetStatus): void
    {
        if (!$this->canTransitionTo($targetStatus)) {
            $currentName = WalletStatus::getName($this->currentStatus);
            $targetName = WalletStatus::getName($targetStatus);
            $allowedNames = array_map([WalletStatus::class, 'getName'], self::$allowedTransitions[$this->currentStatus] ?? []);
            $allowedStr = implode('、', $allowedNames);
            throw new WalletStateException(
                "状态流转校验失败：当前状态【{$currentName}】无法转换到【{$targetName}】。" .
                "允许的目标状态：" . ($allowedStr ?: '无') . "。" .
                "请先调整冻结金额或联系管理员处理异常冻结单。"
            );
        }
        $this->currentStatus = $targetStatus;
    }

    public function assertCanTransitionByAmount(float $currentBalance, float $currentFrozen, float $newFrozen): array
    {
        $targetStatus = self::calculateStatus($currentBalance, $newFrozen);
        $currentName = WalletStatus::getName($this->currentStatus);
        $targetName = WalletStatus::getName($targetStatus);

        if ($this->currentStatus === $targetStatus) {
            return [
                'changed' => false,
                'from_status' => $this->currentStatus,
                'from_status_name' => $currentName,
                'to_status' => $targetStatus,
                'to_status_name' => $targetName,
                'message' => "金额变更不影响状态，保持【{$currentName}】",
            ];
        }

        if (!$this->canTransitionTo($targetStatus)) {
            $allowedNames = array_map([WalletStatus::class, 'getName'], self::$allowedTransitions[$this->currentStatus] ?? []);
            $allowedStr = implode('、', $allowedNames);
            $diff = $newFrozen - $currentFrozen;
            $action = $diff > 0 ? '冻结' : '解冻';
            throw new WalletStateException(
                "金额操作校验失败：本次{$action}金额 ¥" . number_format(abs($diff), 2) .
                " 将导致钱包从【{$currentName}】变为【{$targetName}】，该状态流转不合法。" .
                "允许的目标状态：" . ($allowedStr ?: '无') . "。" .
                "建议：调整操作金额，或先处理现有冻结单。"
            );
        }

        return [
            'changed' => true,
            'from_status' => $this->currentStatus,
            'from_status_name' => $currentName,
            'to_status' => $targetStatus,
            'to_status_name' => $targetName,
            'message' => "状态变更成功：【{$currentName}】→【{$targetName}】",
        ];
    }

    public function applyToWallet(Wallet $wallet, float $newBalance, float $newFrozen, array $transition = null): array
    {
        if ($transition === null) {
            $transition = $this->assertCanTransitionByAmount($newBalance, $wallet->frozenAmount, $newFrozen);
        }

        if ($transition['changed']) {
            $this->transition($transition['to_status']);
        }

        $wallet->balance = $newBalance;
        $wallet->frozenAmount = $newFrozen;
        $wallet->availableAmount = (float)bcsub((string)$newBalance, (string)$newFrozen, 2);
        $wallet->status = $this->currentStatus;

        return $transition;
    }

    public function getCurrentStatus(): int
    {
        return $this->currentStatus;
    }

    public static function getAllowedTransitions(int $status): array
    {
        return self::$allowedTransitions[$status] ?? [];
    }

    public static function describeStatus(int $status, float $balance, float $frozenAmount): string
    {
        $available = bcsub($balance, $frozenAmount, 2);
        return sprintf(
            '状态【%s】：余额 ¥%s / 冻结 ¥%s / 可用 ¥%s',
            WalletStatus::getName($status),
            number_format($balance, 2),
            number_format($frozenAmount, 2),
            number_format((float)$available, 2)
        );
    }
}
