<?php

namespace Dealer\Wallet\Exception;

class WalletPermissionException extends WalletException
{
    public const CODE_DEALER_MISMATCH = 1001;
    public const CODE_ADMIN_REQUIRED = 1002;
    public const CODE_SCOPE_DENIED = 1003;

    public static function forDealerMismatch(int $targetDealerId, int $currentDealerId): self
    {
        return new self(
            "权限校验失败：当前经销商ID【{$currentDealerId}】无权访问经销商【{$targetDealerId}】的钱包数据。" .
            "如需跨经销商查询，请使用管理员账号或申请数据权限授权。",
            self::CODE_DEALER_MISMATCH
        );
    }

    public static function forAdminRequired(string $operation): self
    {
        return new self(
            "权限校验失败：操作【{$operation}】需要管理员权限，当前账号无该操作权限。" .
            "请联系超级管理员申请 WALLET_ADMIN 角色。",
            self::CODE_ADMIN_REQUIRED
        );
    }

    public static function forScopeDenied(int $dealerId, string $scope): self
    {
        return new self(
            "权限校验失败：经销商【{$dealerId}】不在当前账号的【{$scope}】数据范围内。" .
            "请调整查询条件或联系管理员扩大数据范围。",
            self::CODE_SCOPE_DENIED
        );
    }
}
