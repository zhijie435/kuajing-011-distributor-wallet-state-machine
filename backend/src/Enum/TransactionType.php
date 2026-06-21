<?php

namespace Dealer\Wallet\Enum;

class TransactionType
{
    public const RECHARGE = 1;
    public const WITHDRAW = 2;
    public const CONSUME = 3;
    public const REFUND = 4;
    public const FREEZE = 5;
    public const UNFREEZE = 6;

    public static function getName(int $type): string
    {
        return [
            self::RECHARGE => '充值',
            self::WITHDRAW => '提现',
            self::CONSUME => '消费',
            self::REFUND => '退款',
            self::FREEZE => '冻结',
            self::UNFREEZE => '解冻',
        ][$type] ?? '未知';
    }

    public static function getDirection(int $type): string
    {
        return in_array($type, [self::RECHARGE, self::REFUND, self::UNFREEZE]) ? 'in' : 'out';
    }
}
