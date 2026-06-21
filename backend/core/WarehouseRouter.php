<?php

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
                'success' => false,
                'message' => '商品列表不能为空',
                'error_type' => 'EMPTY_ITEMS',
            ];
        }

        if (empty($country)) {
            return [
                'success' => false,
                'message' => '收货国家不能为空',
                'error_type' => 'EMPTY_COUNTRY',
            ];
        }

        foreach ($items as $item) {
            if (empty($item['sku'])) {
                return [
                    'success' => false,
                    'message' => '商品SKU不能为空',
                    'error_type' => 'EMPTY_SKU',
                ];
            }
            if (!isset($item['quantity']) || $item['quantity'] <= 0) {
                return [
                    'success' => false,
                    'message' => '商品数量必须大于0',
                    'error_type' => 'INVALID_QUANTITY',
                ];
            }
        }

        $stockCheck = $this->checkAllWarehousesStock($items);
        if (!$stockCheck['success']) {
            return $stockCheck;
        }

        $candidateWarehouses = $this->findWarehousesWithAllStock($items);
        if (empty($candidateWarehouses)) {
            return [
                'success' => false,
                'message' => '没有仓库同时有所有商品的库存',
                'error_type' => 'INSUFFICIENT_STOCK',
                'candidate_warehouses' => [],
                'shipping_country' => $country,
                'shipping_state' => $state,
                'permission_scoped' => !empty($scopeWarehouses),
                'scope_warehouse_code' => implode(',', $scopeWarehouses),
            ];
        }

        $warehouseIds = array_keys($candidateWarehouses);
        $shippingZones = $this->getMatchingShippingZones($warehouseIds, $country, $state);
        if (empty($shippingZones)) {
            $warehouseNames = [];
            foreach ($candidateWarehouses as $wh) {
                $warehouseNames[] = $wh['name'] . '(' . $wh['code'] . ')';
            }
            return [
                'success' => false,
                'message' => '候选仓库（' . implode('、', $warehouseNames) . '）均不支持配送到 ' . $country,
                'error_type' => 'NO_SHIPPING_ZONE',
                'shipping_country' => $country,
                'shipping_state' => $state,
                'candidate_warehouses' => $warehouseNames,
                'permission_scoped' => !empty($scopeWarehouses),
                'scope_warehouse_code' => implode(',', $scopeWarehouses),
            ];
        }

        $scoredWarehouses = [];
        foreach ($shippingZones as $zone) {
            $warehouseId = $zone['warehouse_id'];
            $warehouse = $candidateWarehouses[$warehouseId] ?? null;
            if (!$warehouse) {
                continue;
            }

            if (!empty($scopeWarehouses) && !in_array($warehouse['code'], $scopeWarehouses)) {
                continue;
            }

            $score = $this->calculateScore($warehouse, $zone, $items);
            $scoredWarehouses[] = [
                'warehouse' => $warehouse,
                'shipping_zone' => $zone,
                'score' => $score,
                'shipping_fee' => (float)$zone['shipping_fee'],
                'delivery_days_min' => (int)$zone['delivery_days_min'],
                'delivery_days_max' => (int)$zone['delivery_days_max'],
                'estimated_delivery_date' => date('Y-m-d', strtotime('+' . $zone['delivery_days_max'] . ' days')),
            ];
        }

        if (empty($scoredWarehouses)) {
            return [
                'success' => false,
                'message' => '权限范围内没有可用仓库',
                'error_type' => 'NO_PERMITTED_WAREHOUSE',
                'shipping_country' => $country,
                'shipping_state' => $state,
                'permission_scoped' => !empty($scopeWarehouses),
                'scope_warehouse_code' => implode(',', $scopeWarehouses),
            ];
        }

        usort($scoredWarehouses, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $bestMatch = $scoredWarehouses[0];

        return [
            'success' => true,
            'warehouse' => $bestMatch['warehouse'],
            'shipping_fee' => $bestMatch['shipping_fee'],
            'delivery_days_min' => $bestMatch['delivery_days_min'],
            'delivery_days_max' => $bestMatch['delivery_days_max'],
            'estimated_delivery_date' => $bestMatch['estimated_delivery_date'],
            'score' => $bestMatch['score'],
            'alternatives' => array_slice($scoredWarehouses, 1),
            'shipping_country' => $country,
            'shipping_state' => $state,
            'permission_scoped' => !empty($scopeWarehouses),
        ];
    }

    public function checkAllWarehousesStock($items)
    {
        foreach ($items as $item) {
            $sku = $item['sku'];
            $quantity = $item['quantity'];

            $sql = "SELECT p.id, p.sku, p.name, 
                    COALESCE(SUM(wi.available_quantity), 0) as total_available
                    FROM products p
                    LEFT JOIN warehouse_inventories wi ON wi.product_id = p.id
                    WHERE p.sku = ? AND p.status = 1
                    GROUP BY p.id";
            
            $product = $this->db->fetchOne($sql, [$sku]);
            
            if (!$product) {
                return [
                    'success' => false,
                    'message' => "商品SKU {$sku}不存在",
                    'error_type' => 'PRODUCT_NOT_FOUND',
                    'sku' => $sku,
                ];
            }

            if ($product['total_available'] < $quantity) {
                return [
                    'success' => false,
                    'message' => "商品{$sku}全局库存不足，需求{$quantity}，可用{$product['total_available']}",
                    'error_type' => 'INSUFFICIENT_STOCK',
                    'sku' => $sku,
                    'required' => $quantity,
                    'available' => (int)$product['total_available'],
                ];
            }
        }

        return [
            'success' => true,
            'message' => '库存检查通过',
        ];
    }

    public function findWarehousesWithAllStock($items)
    {
        $warehouseStocks = [];
        $skuCount = count($items);

        foreach ($items as $item) {
            $sku = $item['sku'];
            $quantity = $item['quantity'];

            $sql = "SELECT wi.warehouse_id, w.code, w.name, w.country, w.priority, 
                    wi.available_quantity
                    FROM warehouse_inventories wi
                    INNER JOIN warehouses w ON w.id = wi.warehouse_id
                    WHERE wi.sku = ? AND wi.available_quantity >= ? AND w.status = 1
                    ORDER BY w.priority DESC";
            
            $stocks = $this->db->fetchAll($sql, [$sku, $quantity]);

            foreach ($stocks as $stock) {
                $warehouseId = $stock['warehouse_id'];
                if (!isset($warehouseStocks[$warehouseId])) {
                    $warehouseStocks[$warehouseId] = [
                        'id' => $warehouseId,
                        'code' => $stock['code'],
                        'name' => $stock['name'],
                        'country' => $stock['country'],
                        'priority' => (int)$stock['priority'],
                        'sku_count' => 0,
                        'items' => [],
                    ];
                }
                $warehouseStocks[$warehouseId]['sku_count']++;
                $warehouseStocks[$warehouseId]['items'][] = [
                    'sku' => $sku,
                    'quantity' => $quantity,
                    'available' => (int)$stock['available_quantity'],
                ];
            }
        }

        $result = [];
        foreach ($warehouseStocks as $warehouseId => $warehouse) {
            if ($warehouse['sku_count'] == $skuCount) {
                $result[$warehouseId] = $warehouse;
            }
        }

        return $result;
    }

    public function getMatchingShippingZones($warehouseIds, $country, $state = null)
    {
        if (empty($warehouseIds)) {
            return [];
        }

        $ids = implode(',', array_map('intval', $warehouseIds));
        $sql = "SELECT * FROM warehouse_shipping_zones
                WHERE warehouse_id IN ($ids) AND status = 1 AND country = ?";

        $params = [$country];

        if ($state !== null && $state !== '') {
            $sql .= " AND (state = ? OR state IS NULL) ";
            $params[] = $state;
        } else {
            $sql .= " AND state IS NULL ";
        }

        $sql .= " ORDER BY CASE WHEN state IS NULL THEN 1 ELSE 0 END ASC";

        $zones = $this->db->fetchAll($sql, $params);

        $result = [];
        $seenWarehouses = [];
        foreach ($zones as $zone) {
            $warehouseId = $zone['warehouse_id'];
            if (!isset($seenWarehouses[$warehouseId])) {
                $seenWarehouses[$warehouseId] = true;
                $result[] = $zone;
            }
        }

        return $result;
    }

    private function calculateScore($warehouse, $shippingZone, $items)
    {
        $score = 0;

        $priorityScore = $warehouse['priority'] * 2;
        $score += $priorityScore;

        $shippingFee = (float)$shippingZone['shipping_fee'];
        $feeScore = max(0, 100 - $shippingFee * 2);
        $score += $feeScore;

        $deliveryDaysMax = (int)$shippingZone['delivery_days_max'];
        $deliveryScore = max(0, 100 - $deliveryDaysMax * 5);
        $score += $deliveryScore;

        $sameCountryBonus = 0;
        if (isset($warehouse['country']) && $warehouse['country'] == $shippingZone['country']) {
            $sameCountryBonus = 20;
        }
        $score += $sameCountryBonus;

        return $score;
    }

    public function listWarehouses($status = null, $country = null, $page = 1, $pageSize = 20)
    {
        $where = ['1=1'];
        $params = [];

        if ($status !== null) {
            $where[] = 'w.status = ?';
            $params[] = $status;
        }

        if ($country !== null && $country !== '') {
            $where[] = 'w.country = ?';
            $params[] = $country;
        }

        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT w.*,
                (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
                FROM warehouses w
                WHERE $whereSql
                ORDER BY w.priority DESC, w.id ASC
                LIMIT $offset, $pageSize";

        $list = $this->db->fetchAll($sql, $params);

        $countSql = "SELECT COUNT(*) as total FROM warehouses w WHERE $whereSql";
        $countResult = $this->db->fetchOne($countSql, $params);

        return [
            'list' => $list,
            'total' => (int)($countResult['total'] ?? 0),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function getWarehouseByCode($code)
    {
        if (empty($code)) {
            return null;
        }

        $sql = "SELECT w.*,
                (SELECT COUNT(*) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as sku_count,
                (SELECT SUM(wi.quantity) FROM warehouse_inventories wi WHERE wi.warehouse_id = w.id) as total_stock
                FROM warehouses w
                WHERE w.code = ?";

        $warehouse = $this->db->fetchOne($sql, [$code]);

        if ($warehouse) {
            $inventorySql = "SELECT wi.*, p.name as product_name, p.sku
                            FROM warehouse_inventories wi
                            INNER JOIN products p ON p.id = wi.product_id
                            WHERE wi.warehouse_id = ?
                            ORDER BY wi.id DESC";
            $warehouse['inventories'] = $this->db->fetchAll($inventorySql, [$warehouse['id']]);
        }

        return $warehouse;
    }
}
