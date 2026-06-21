<?php

namespace Dealer\Wallet\StateMachine;

use Dealer\Wallet\Enum\WalletStatus;
use Dealer\Wallet\Exception\WalletStateException;

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
            throw new WalletStateException("无效的钱包状态: {$currentStatus}");
        }
        $this->currentStatus = $currentStatus;
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
            throw new WalletStateException("无法从状态【{$currentName}】转换到【{$targetName}】");
        }
        $this->currentStatus = $targetStatus;
    }

    public function getCurrentStatus(): int
    {
        return $this->currentStatus;
    }

    public static function calculateStatus(float $balance, float $frozenAmount): int
    {
        if ($frozenAmount <= 0) {
            return WalletStatus::NORMAL;
        }
        if ($frozenAmount >= $balance) {
            return WalletStatus::FULLY_FROZEN;
        }
        return WalletStatus::PARTIALLY_FROZEN;
    }

    public static function getAllowedTransitions(int $status): array
    {
        return self::$allowedTransitions[$status] ?? [];
    }
}
