<?php

namespace Dealer\Wallet\Enum;

class WalletStatus
{
    public const NORMAL = 1;
    public const PARTIALLY_FROZEN = 2;
    public const FULLY_FROZEN = 3;

    public static function getName(int $status): string
    {
        return [
            self::NORMAL => '正常',
            self::PARTIALLY_FROZEN => '部分冻结',
            self::FULLY_FROZEN => '全额冻结',
        ][$status] ?? '未知';
    }

    public static function getColor(int $status): string
    {
        return [
            self::NORMAL => '#52c41a',
            self::PARTIALLY_FROZEN => '#faad14',
            self::FULLY_FROZEN => '#f5222d',
        ][$status] ?? '#999';
    }
}
