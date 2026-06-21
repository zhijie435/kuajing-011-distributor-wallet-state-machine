<?php

class PermissionService
{
    private $config;

    public function __construct()
    {
        $this->config = require __DIR__ . "/../config/config.php";
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
