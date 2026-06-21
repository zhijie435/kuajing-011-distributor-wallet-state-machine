<?php

namespace Dealer\Wallet\Enum;

class FreezeStatus
{
    public const FROZEN = 1;
    public const UNFROZEN = 2;
    public const DEDUCTED = 3;

    public static function getName(int $status): string
    {
        return [
            self::FROZEN => '冻结中',
            self::UNFROZEN => '已解冻',
            self::DEDUCTED => '已扣除',
        ][$status] ?? '未知';
    }
}
