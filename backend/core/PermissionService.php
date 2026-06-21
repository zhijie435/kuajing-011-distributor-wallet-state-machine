<?php

class PermissionService
{
    public const ROLE_SUPER_ADMIN = 'super_admin';
    public const ROLE_WALLET_ADMIN = 'wallet_admin';
    public const ROLE_DEALER = 'dealer';
    public const ROLE_AUDITOR = 'auditor';

    public const PERM_WALLET_VIEW_OWN = 'wallet:view:own';
    public const PERM_WALLET_VIEW_ALL = 'wallet:view:all';
    public const PERM_WALLET_TRANSACTIONS_OWN = 'wallet:transactions:own';
    public const PERM_WALLET_TRANSACTIONS_ALL = 'wallet:transactions:all';
    public const PERM_WALLET_FREEZE_RECORDS_OWN = 'wallet:freeze:own';
    public const PERM_WALLET_FREEZE_RECORDS_ALL = 'wallet:freeze:all';
    public const PERM_WALLET_RECONCILE = 'wallet:reconcile';
    public const PERM_WALLET_FIX = 'wallet:fix';
    public const PERM_WALLET_EXPORT = 'wallet:export';

    private $config;

    private ?array $operatorContext = null;

    public function __construct()
    {
        $this->config = require __DIR__ . "/../config/config.php";
    }

    public function setOperatorContext(array $context): void
    {
        $this->operatorContext = $context + [
            'operator_id' => null,
            'dealer_id' => null,
            'roles' => [],
            'permissions' => [],
            'scoped_dealer_ids' => null,
        ];
    }

    public function getOperatorContext(): ?array
    {
        return $this->operatorContext;
    }

    public function hasRole(string $role): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        return in_array($role, (array)($this->operatorContext['roles'] ?? []), true);
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        if ($this->hasRole(self::ROLE_SUPER_ADMIN)) {
            return true;
        }
        return in_array($permission, (array)($this->operatorContext['permissions'] ?? []), true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN)
            || $this->hasRole(self::ROLE_WALLET_ADMIN);
    }

    public function isAuditor(): bool
    {
        return $this->hasRole(self::ROLE_SUPER_ADMIN)
            || $this->hasRole(self::ROLE_AUDITOR);
    }

    public function canViewWallet(int $targetDealerId): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        if ($this->hasPermission(self::PERM_WALLET_VIEW_ALL)) {
            return true;
        }
        if ($this->hasPermission(self::PERM_WALLET_VIEW_OWN)) {
            $ownDealerId = (int)($this->operatorContext['dealer_id'] ?? 0);
            return $targetDealerId === $ownDealerId && $this->isInScope($targetDealerId);
        }
        return false;
    }

    public function canViewAllWallets(): bool
    {
        return $this->hasPermission(self::PERM_WALLET_VIEW_ALL);
    }

    public function canViewTransactions(int $targetDealerId): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        if ($this->hasPermission(self::PERM_WALLET_TRANSACTIONS_ALL)) {
            return true;
        }
        if ($this->hasPermission(self::PERM_WALLET_TRANSACTIONS_OWN)) {
            $ownDealerId = (int)($this->operatorContext['dealer_id'] ?? 0);
            return $targetDealerId === $ownDealerId && $this->isInScope($targetDealerId);
        }
        return false;
    }

    public function canViewFreezeRecords(int $targetDealerId): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        if ($this->hasPermission(self::PERM_WALLET_FREEZE_RECORDS_ALL)) {
            return true;
        }
        if ($this->hasPermission(self::PERM_WALLET_FREEZE_RECORDS_OWN)) {
            $ownDealerId = (int)($this->operatorContext['dealer_id'] ?? 0);
            return $targetDealerId === $ownDealerId && $this->isInScope($targetDealerId);
        }
        return false;
    }

    public function canReconcile(int $targetDealerId): bool
    {
        if ($this->hasPermission(self::PERM_WALLET_RECONCILE)) {
            return $this->canViewWallet($targetDealerId);
        }
        return false;
    }

    public function canFixWallet(int $targetDealerId): bool
    {
        if ($this->hasPermission(self::PERM_WALLET_FIX)) {
            return $this->canViewWallet($targetDealerId);
        }
        return false;
    }

    public function canExport(int $targetDealerId): bool
    {
        if ($this->hasPermission(self::PERM_WALLET_EXPORT)) {
            return $this->canViewWallet($targetDealerId);
        }
        return false;
    }

    public function getCurrentDealerId(): ?int
    {
        if ($this->operatorContext === null) {
            return null;
        }
        $id = $this->operatorContext['dealer_id'] ?? null;
        return $id === null ? null : (int)$id;
    }

    private function isInScope(int $targetDealerId): bool
    {
        if ($this->operatorContext === null) {
            return false;
        }
        $scoped = $this->operatorContext['scoped_dealer_ids'] ?? null;
        if ($scoped === null || $scoped === '*') {
            return true;
        }
        if (!is_array($scoped)) {
            return false;
        }
        return in_array($targetDealerId, array_map('intval', $scoped), true);
    }

    public function validateCallbackToken($token)
    {
        if (empty($token)) {
            return [
                "success" => false,
                "message" => "回调Token不能为空",
                "error_type" => "MISSING_TOKEN",
            ];
        }

        $expectedToken = $this->config["callback"]["token"] ?? "";
        if ($token !== $expectedToken) {
            return [
                "success" => false,
                "message" => "无效的回调Token",
                "error_type" => "INVALID_TOKEN",
            ];
        }

        return [
            "success" => true,
            "message" => "Token验证通过",
        ];
    }

    public function validateIpWhitelist($ip)
    {
        if (empty($ip)) {
            return [
                "success" => false,
                "message" => "IP地址不能为空",
                "error_type" => "MISSING_IP",
            ];
        }

        $whitelist = $this->config["callback"]["ip_whitelist"] ?? [];

        foreach ($whitelist as $allowedIp) {
            if (strpos($allowedIp, "/") !== false) {
                if ($this->ipInCidr($ip, $allowedIp)) {
                    return [
                        "success" => true,
                        "message" => "IP白名单验证通过",
                    ];
                }
            } else {
                if ($ip === $allowedIp) {
                    return [
                        "success" => true,
                        "message" => "IP白名单验证通过",
                    ];
                }
            }
        }

        return [
            "success" => false,
            "message" => "IP地址不在白名单中",
            "error_type" => "IP_NOT_ALLOWED",
        ];
    }

    private function ipInCidr($ip, $cidr)
    {
        list($subnet, $mask) = explode("/", $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        $maskLong = -1 << (32 - $mask);

        return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
    }

    public function verifyWarehouseOrderMatch($orderNo, $warehouseCode)
    {
        if (empty($orderNo) || empty($warehouseCode)) {
            return [
                "success" => false,
                "message" => "订单号和仓库编码不能为空",
                "error_type" => "MISSING_PARAMS",
            ];
        }

        $db = Database::getInstance();
        $sql = "SELECT id, warehouse_code FROM orders WHERE order_no = ?";
        $order = $db->fetchOne($sql, [$orderNo]);

        if (!$order) {
            return [
                "success" => false,
                "message" => "订单不存在",
                "error_type" => "ORDER_NOT_FOUND",
            ];
        }

        if ($order["warehouse_code"] !== $warehouseCode) {
            return [
                "success" => false,
                "message" => "订单与仓库不匹配",
                "error_type" => "WAREHOUSE_MISMATCH",
            ];
        }

        return [
            "success" => true,
            "message" => "仓库与订单匹配验证通过",
            "order" => $order,
        ];
    }

    public function checkWarehousePermission($warehouseCode, $scopeWarehouses = [])
    {
        if (empty($scopeWarehouses)) {
            return [
                "success" => true,
                "message" => "无仓库范围限制",
            ];
        }

        if (in_array($warehouseCode, $scopeWarehouses)) {
            return [
                "success" => true,
                "message" => "仓库权限验证通过",
            ];
        }

        return [
            "success" => false,
            "message" => "没有该仓库的操作权限",
            "error_type" => "PERMISSION_DENIED",
        ];
    }
}
