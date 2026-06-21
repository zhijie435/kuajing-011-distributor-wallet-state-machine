<?php

$baseDir = __DIR__;

$files = [
    'config/config.php' => '<?php

return [
    "db" => [
        "host" => "127.0.0.1",
        "port" => 3306,
        "database" => "overseas_warehouse",
        "username" => "root",
        "password" => "",
        "charset" => "utf8mb4",
    ],
    "callback" => [
        "token" => "wh_callback_token_2024",
        "ip_whitelist" => [
            "127.0.0.1",
            "10.0.0.0/8",
            "192.168.0.0/16",
        ],
    ],
    "order" => [
        "no_prefix" => "WH",
        "max_quantity_per_item" => 999,
    ],
    "warehouse" => [
        "default_priority" => 0,
    ],
];
',

    'sql/database.sql' => '-- 数据库schema文件',

    'core/Database.php' => '<?php

if (!class_exists("Database")) {

class Database
{
    private static $instance = null;
    private $pdo;

    private function __construct()
    {
        $config = require __DIR__ . "/../config/config.php";
        $dbConfig = $config["db"];
        
        $dsn = sprintf(
            "mysql:host=%s;port=%d;dbname=%s;charset=%s",
            $dbConfig["host"],
            $dbConfig["port"],
            $dbConfig["database"],
            $dbConfig["charset"]
        );
        
        $this->pdo = new PDO($dsn, $dbConfig["username"], $dbConfig["password"], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function fetchOne($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    public function insert($table, $data)
    {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), "?");
        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s)",
            $table,
            implode(", ", $fields),
            implode(", ", $placeholders)
        );
        $this->query($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = [])
    {
        $setParts = [];
        $params = [];
        foreach ($data as $field => $value) {
            $setParts[] = "$field = ?";
            $params[] = $value;
        }
        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(", ", $setParts),
            $where
        );
        $stmt = $this->query($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }
}

}
',

    'core/OrderNoGenerator.php' => '<?php

class OrderNoGenerator
{
    public static function generate($prefix = "WH")
    {
        $datetime = date("YmdHis");
        $random = str_pad(mt_rand(0, 999999), 6, "0", STR_PAD_LEFT);
        return $prefix . $datetime . $random;
    }
}
',

    'core/PermissionService.php' => '<?php

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
',

    'core/AuditService.php' => '<?php

class AuditService
{
    public function logRouting($orderNo, $warehouseCode, $routingData, $operator = null)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "routing",
            "business_no" => $orderNo,
            "operator" => $operator,
            "action" => "warehouse_routing",
            "before_data" => json_encode(["warehouse_code" => null]),
            "after_data" => json_encode(["warehouse_code" => $warehouseCode, "routing_data" => $routingData]),
            "remark" => "仓库路由分配",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logCallback($callbackType, $orderNo, $warehouseCode, $requestData, $responseData, $success)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "callback",
            "business_no" => $orderNo,
            "operator" => $warehouseCode,
            "action" => "callback_" . strtolower($callbackType),
            "before_data" => is_string($requestData) ? $requestData : json_encode($requestData),
            "after_data" => is_string($responseData) ? $responseData : json_encode($responseData),
            "remark" => $success ? "回调处理成功" : "回调处理失败",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logOperation($businessNo, $action, $beforeData = null, $afterData = null, $operator = null, $remark = "")
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "operation",
            "business_no" => $businessNo,
            "operator" => $operator,
            "action" => $action,
            "before_data" => is_string($beforeData) ? $beforeData : json_encode($beforeData),
            "after_data" => is_string($afterData) ? $afterData : json_encode($afterData),
            "remark" => $remark,
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    public function logStatusChange($orderNo, $beforeStatus, $afterStatus, $action, $operator = null)
    {
        $db = Database::getInstance();
        
        $data = [
            "audit_type" => "status_change",
            "business_no" => $orderNo,
            "operator" => $operator,
            "action" => $action,
            "before_data" => json_encode(["status" => $beforeStatus]),
            "after_data" => json_encode(["status" => $afterStatus]),
            "remark" => "状态变更",
            "ip_address" => $this->getClientIp(),
        ];
        
        return $db->insert("audit_logs", $data);
    }

    private function getClientIp()
    {
        if (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            return $_SERVER["HTTP_CLIENT_IP"];
        }
        if (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            return $_SERVER["HTTP_X_FORWARDED_FOR"];
        }
        return $_SERVER["REMOTE_ADDR"] ?? "127.0.0.1";
    }
}
',

    'core/WarehouseRouter.php' => '<?php

class WarehouseRouter
{
    private $db;
    private $permissionService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->permissionService = new PermissionService();
    }

    public function route($items, $country, $state = null, $scopeWarehouses = [])
    {
        if (empty($items) || !is_array($items)) {
            return [
                "success" => false,
                "message" => "商品列表不能为空",
                "error_type" => "EMPTY_ITEMS",
            ];
        }

        if (empty($country)) {
            return [
                "success" => false,
                "message" => "收货国家不能为空",
                "error_type" => "EMPTY_COUNTRY",
            ];
        }

        foreach ($items as $item) {
            if (empty($item["sku"])) {
                return [
                    "success" => false,
                    "message" => "商品SKU不能为空",
                    "error_type" => "EMPTY_SKU",
                ];
            }
            if (!isset($item["quantity"]) || $item["quantity"] <= 0) {
                return [
                    "success" => false,
                    "message" => "商品数量必须大于0",
                    "error_type" => "INVALID_QUANTITY",
                ];
            }
        }

        $stockCheck = $this->checkAllWarehousesStock($items);
        if (!$stockCheck["success"]) {
            return $stockCheck;
        }

        $candidateWarehouses = $this->findWarehousesWithAllStock($items);
        if (empty($candidateWarehouses)) {
            return [
                "success" => false,
                "message" => "没有仓库同时有所有商品的库存",
                "error_type" => "INSUFFICIENT_STOCK",
                "candidate_warehouses" => [],
                "shipping_country" => $country,
                "shipping_state" => $state,
                "permission_scoped" => !empty($scopeWarehouses),
                "scope_warehouse_code" => implode(",", $scopeWarehouses),
            ];
        }

        $warehouseIds = array_keys($candidateWarehouses);
        $shippingZones = $this->getMatchingShippingZones($warehouseIds, $country, $state);
        if (empty($shippingZones)) {
            $warehouseNames = [];
            foreach ($candidateWarehouses as $wh) {
                $warehouseNames[] = $wh["name"] . "(" . $wh["code"] . ")";
            }
            return [
                "success" => false,
                "message" => "候选仓库（" . implode("、", $warehouseNames) . "）均不支持配送到 " . $country,
                "error_type" => "NO_SHIPPING_ZONE",
                "shipping_country" => $country,
                "shipping_state" => $state,
                "candidate_warehouses" => $warehouseNames,
                "permission_scoped" => !empty($scopeWarehouses),
                "scope_warehouse_code" => implode(",", $scopeWarehouses),
            ];
        }

        $scoredWarehouses = [];
        foreach ($shippingZones as $zone) {
            $warehouseId = $zone["warehouse_id"];
            $warehouse = $candidateWarehouses[$warehouseId] ?? null;
            if (!$warehouse) {
                continue;
            }

            if (!empty($scopeWarehouses) && !in_array($warehouse["code"], $scopeWarehouses)) {
                continue;
            }

            $score = $this->calculateScore($warehouse, $zone, $items);
            $scoredWarehouses[] = [
                "warehouse" => $warehouse,
                "shipping_zone" => $zone,
                "score" => $score,
                "shipping_fee" => (float)$zone["shipping_fee"],
                "delivery_days_min" => (int)$zone["delivery_days_min"],
                "delivery_days_max" => (int)$zone["delivery_days_max"],
                "estimated_delivery_date" => date("Y-m-d", strtotime("+" . $zone["delivery_days_max"] . " days")),
            ];
        }

        if (empty($scoredWarehouses)) {
            return [
                "success" => false,
                "message" => "权限范围内没有可用仓库",
                "error_type" => "NO_PERMITTED_WAREHOUSE",
                "shipping_country" => $country,
                "shipping_state" => $state,
                "permission_scoped" => !empty($scopeWarehouses),
                "scope_warehouse_code" => implode(",", $scopeWarehouses),
            ];
        }

        usort($scoredWarehouses, function ($a, $b) {
            return $b["score"] <=> $a["score"];
        });

        $bestMatch = $scoredWarehouses[0];

        return [
            "success" => true,
            "warehouse" => $bestMatch["warehouse"],
            "shipping_fee" => $bestMatch["shipping_fee"],
            "delivery_days_min" => $bestMatch["delivery_days_min"],
            "delivery_days_max" => $bestMatch["delivery_days_max"],
            "estimated_delivery_date" => $bestMatch["estimated_delivery_date"],
            "score" => $bestMatch["score"],
            "alternatives" => array_slice($scoredWarehouses, 1),
            "shipping_country" => $country,
            "shipping_state" => $state,
            "permission_scoped" => !empty($scopeWarehouses),
        ];
    }
',
];

foreach ($files as $relativePath => $content) {
    $fullPath = $baseDir . '/' . $relativePath;
    $dir = dirname($fullPath);
    
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    file_put_contents($fullPath, $content);
    echo "Created: $relativePath (" . filesize($fullPath) . " bytes)\n";
}

echo "\n安装完成！\n";
