<?php

class OrderService
{
    private $db;
    private $warehouseRouter;
    private $auditService;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->warehouseRouter = new WarehouseRouter();
        $this->auditService = new AuditService();
        $this->config = require __DIR__ . '/../config/config.php';
    }

    public function createOrder($orderData)
    {
        $validation = $this->validateOrderData($orderData);
        if (!$validation['success']) {
            return $validation;
        }

        $this->db->beginTransaction();

        try {
            $items = $orderData['items'];
            $country = $orderData['receiver_country'];
            $state = $orderData['receiver_state'] ?? null;
            $scopeWarehouses = $orderData['scope_warehouses'] ?? [];

            $routingResult = $this->warehouseRouter->route($items, $country, $state, $scopeWarehouses);
            if (!$routingResult['success']) {
                $this->db->rollBack();
                return $routingResult;
            }

            $warehouse = $routingResult['warehouse'];
            $shippingFee = $routingResult['shipping_fee'];
            $estimatedDeliveryDate = $routingResult['estimated_delivery_date'];

            $totalAmount = 0;
            $totalWeight = 0;
            $orderItems = [];

            foreach ($items as $item) {
                $sku = $item['sku'];
                $quantity = $item['quantity'];

                $productSql = "SELECT * FROM products WHERE sku = ? AND status = 1";
                $product = $this->db->fetchOne($productSql, [$sku]);

                if (!$product) {
                    $this->db->rollBack();
                    return [
                        'success' => false,
                        'message' => "商品{$sku}不存在",
                        'error_type' => 'PRODUCT_NOT_FOUND',
                    ];
                }

                $subtotal = $product['price'] * $quantity;
                $totalAmount += $subtotal;
                $totalWeight += $product['weight'] * $quantity;

                $orderItems[] = [
                    'product_id' => $product['id'],
                    'sku' => $sku,
                    'product_name' => $product['name'],
                    'quantity' => $quantity,
                    'unit_price' => $product['price'],
                    'subtotal' => $subtotal,
                    'weight' => $product['weight'],
                ];
            }

            $orderNo = OrderNoGenerator::generate($this->config['order']['no_prefix'] ?? 'WH');

            $orderDataInsert = [
                'order_no' => $orderNo,
                'warehouse_id' => $warehouse['id'],
                'warehouse_code' => $warehouse['code'],
                'order_status' => 1,
                'fulfillment_status' => 0,
                'receiver_name' => $orderData['receiver_name'],
                'receiver_phone' => $orderData['receiver_phone'],
                'receiver_email' => $orderData['receiver_email'] ?? null,
                'receiver_country' => $orderData['receiver_country'],
                'receiver_state' => $orderData['receiver_state'] ?? null,
                'receiver_city' => $orderData['receiver_city'] ?? null,
                'receiver_address' => $orderData['receiver_address'],
                'receiver_zipcode' => $orderData['receiver_zipcode'] ?? null,
                'total_amount' => $totalAmount,
                'shipping_fee' => $shippingFee,
                'total_weight' => $totalWeight,
                'estimated_delivery_date' => $estimatedDeliveryDate,
                'remark' => $orderData['remark'] ?? null,
                'created_by' => $orderData['created_by'] ?? null,
            ];

            $orderId = $this->db->insert('orders', $orderDataInsert);

            foreach ($orderItems as $item) {
                $item['order_id'] = $orderId;
                $item['order_no'] = $orderNo;
                $this->db->insert('order_items', $item);
            }

            foreach ($items as $item) {
                $sku = $item['sku'];
                $quantity = $item['quantity'];

                $stockSql = "SELECT * FROM warehouse_inventories 
                            WHERE warehouse_id = ? AND sku = ?";
                $stock = $this->db->fetchOne($stockSql, [$warehouse['id'], $sku]);

                if (!$stock || $stock['available_quantity'] < $quantity) {
                    $this->db->rollBack();
                    return [
                        'success' => false,
                        'message' => "商品{$sku}库存不足",
                        'error_type' => 'INSUFFICIENT_STOCK',
                    ];
                }

                $updateStockSql = "UPDATE warehouse_inventories 
                                  SET available_quantity = available_quantity - ?,
                                      reserved_quantity = reserved_quantity + ?
                                  WHERE warehouse_id = ? AND sku = ?";
                $this->db->query($updateStockSql, [$quantity, $quantity, $warehouse['id'], $sku]);
            }

            $this->addStatusTrack($orderId, $orderNo, 1, null, 'order_routed', '系统自动路由', 'system');

            $this->auditService->logRouting($orderNo, $warehouse['code'], $routingResult, $orderData['created_by'] ?? null);

            $this->db->commit();

            return [
                'success' => true,
                'order_no' => $orderNo,
                'order_id' => $orderId,
                'warehouse' => $warehouse,
                'order_status' => 1,
                'fulfillment_status' => 0,
                'total_amount' => $totalAmount,
                'shipping_fee' => $shippingFee,
                'total_weight' => $totalWeight,
                'estimated_delivery_date' => $estimatedDeliveryDate,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => '创建订单失败：' . $e->getMessage(),
                'error_type' => 'SYSTEM_ERROR',
            ];
        }
    }

    public function validateOrderData($orderData)
    {
        if (empty($orderData['items']) || !is_array($orderData['items'])) {
            return [
                'success' => false,
                'message' => '商品列表不能为空',
                'error_type' => 'EMPTY_ITEMS',
            ];
        }

        if (empty($orderData['receiver_name'])) {
            return [
                'success' => false,
                'message' => '收货人姓名不能为空',
                'error_type' => 'EMPTY_RECEIVER_NAME',
            ];
        }

        if (mb_strlen($orderData['receiver_name']) < 2) {
            return [
                'success' => false,
                'message' => '收货人姓名长度不能少于2个字符',
                'error_type' => 'RECEIVER_NAME_TOO_SHORT',
            ];
        }

        if (empty($orderData['receiver_phone'])) {
            return [
                'success' => false,
                'message' => '收货人电话不能为空',
                'error_type' => 'EMPTY_RECEIVER_PHONE',
            ];
        }

        if (!preg_match('/^[\d\-\s\+\(\)]{5,20}$/', $orderData['receiver_phone'])) {
            return [
                'success' => false,
                'message' => '收货人电话格式不正确',
                'error_type' => 'INVALID_PHONE',
            ];
        }

        if (!empty($orderData['receiver_email']) && !filter_var($orderData['receiver_email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => '收货人邮箱格式不正确',
                'error_type' => 'INVALID_EMAIL',
            ];
        }

        if (empty($orderData['receiver_country'])) {
            return [
                'success' => false,
                'message' => '收货国家不能为空',
                'error_type' => 'EMPTY_COUNTRY',
            ];
        }

        if (strlen($orderData['receiver_country']) != 2) {
            return [
                'success' => false,
                'message' => '收货国家必须是2位国家代码',
                'error_type' => 'INVALID_COUNTRY',
            ];
        }

        if (empty($orderData['receiver_address'])) {
            return [
                'success' => false,
                'message' => '收货地址不能为空',
                'error_type' => 'EMPTY_ADDRESS',
            ];
        }

        if (mb_strlen($orderData['receiver_address']) < 5) {
            return [
                'success' => false,
                'message' => '收货地址长度不能少于5个字符',
                'error_type' => 'ADDRESS_TOO_SHORT',
            ];
        }

        $maxQuantity = $this->config['order']['max_quantity_per_item'] ?? 999;
        foreach ($orderData['items'] as $item) {
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

            if ($item['quantity'] > $maxQuantity) {
                return [
                    'success' => false,
                    'message' => "单个商品数量不能超过{$maxQuantity}",
                    'error_type' => 'QUANTITY_TOO_LARGE',
                ];
            }
        }

        return [
            'success' => true,
            'message' => '订单数据验证通过',
        ];
    }

    public function cancelOrder($orderNo, $operator = null, $reason = '')
    {
        $this->db->beginTransaction();

        try {
            $sql = "SELECT * FROM orders WHERE order_no = ?";
            $order = $this->db->fetchOne($sql, [$orderNo]);

            if (!$order) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '订单不存在',
                    'error_type' => 'ORDER_NOT_FOUND',
                ];
            }

            if ($order['order_status'] >= 5) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '订单已发货，无法取消',
                    'error_type' => 'ORDER_CANNOT_CANCEL',
                ];
            }

            if ($order['order_status'] == 9) {
                $this->db->rollBack();
                return [
                    'success' => false,
                    'message' => '订单已取消，不能重复取消',
                    'error_type' => 'ORDER_ALREADY_CANCELLED',
                ];
            }

            $beforeStatus = $order['order_status'];

            $itemsSql = "SELECT * FROM order_items WHERE order_id = ?";
            $orderItems = $this->db->fetchAll($itemsSql, [$order['id']]);

            if (!empty($order['warehouse_id'])) {
                foreach ($orderItems as $item) {
                    $releaseStockSql = "UPDATE warehouse_inventories 
                                       SET available_quantity = available_quantity + ?,
                                           reserved_quantity = reserved_quantity - ?
                                       WHERE warehouse_id = ? AND sku = ?";
                    $this->db->query($releaseStockSql, [
                        $item['quantity'],
                        $item['quantity'],
                        $order['warehouse_id'],
                        $item['sku'],
                    ]);
                }
            }

            $updateSql = "UPDATE orders SET order_status = 9 WHERE id = ?";
            $this->db->query($updateSql, [$order['id']]);

            $this->addStatusTrack($order['id'], $orderNo, 9, null, 'order_cancelled', $reason, $operator);

            $this->auditService->logOperation($orderNo, 'cancel_order', 
                ['status' => $beforeStatus], 
                ['status' => 9, 'reason' => $reason],
                $operator, $reason
            );

            $this->db->commit();

            return [
                'success' => true,
                'message' => '订单取消成功',
                'order_no' => $orderNo,
                'order_status' => 9,
            ];
        } catch (Exception $e) {
            $this->db->rollBack();
            return [
                'success' => false,
                'message' => '取消订单失败：' . $e->getMessage(),
                'error_type' => 'SYSTEM_ERROR',
            ];
        }
    }

    public function getOrderDetail($orderNo)
    {
        $sql = "SELECT * FROM orders WHERE order_no = ?";
        $order = $this->db->fetchOne($sql, [$orderNo]);

        if (!$order) {
            return null;
        }

        $itemsSql = "SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC";
        $order['items'] = $this->db->fetchAll($itemsSql, [$order['id']]);

        $tracksSql = "SELECT * FROM order_status_tracks WHERE order_id = ? ORDER BY id ASC";
        $order['status_tracks'] = $this->db->fetchAll($tracksSql, [$order['id']]);

        return $order;
    }

    public function listOrders($params = [], $page = 1, $pageSize = 20)
    {
        $where = ['1=1'];
        $queryParams = [];

        if (!empty($params['order_no'])) {
            $where[] = 'order_no LIKE ?';
            $queryParams[] = '%' . $params['order_no'] . '%';
        }

        if (isset($params['order_status']) && $params['order_status'] !== '') {
            $where[] = 'order_status = ?';
            $queryParams[] = $params['order_status'];
        }

        if (!empty($params['warehouse_code'])) {
            $where[] = 'warehouse_code = ?';
            $queryParams[] = $params['warehouse_code'];
        }

        if (!empty($params['receiver_name'])) {
            $where[] = 'receiver_name LIKE ?';
            $queryParams[] = '%' . $params['receiver_name'] . '%';
        }

        if (!empty($params['receiver_phone'])) {
            $where[] = 'receiver_phone LIKE ?';
            $queryParams[] = '%' . $params['receiver_phone'] . '%';
        }

        if (!empty($params['scope_warehouses']) && is_array($params['scope_warehouses'])) {
            $placeholders = implode(',', array_fill(0, count($params['scope_warehouses']), '?'));
            $where[] = "warehouse_code IN ($placeholders)";
            $queryParams = array_merge($queryParams, $params['scope_warehouses']);
        }

        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT * FROM orders WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $queryParams);

        $countSql = "SELECT COUNT(*) as total FROM orders WHERE $whereSql";
        $countResult = $this->db->fetchOne($countSql, $queryParams);

        return [
            'list' => $list,
            'total' => (int)($countResult['total'] ?? 0),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    private function addStatusTrack($orderId, $orderNo, $orderStatus = null, $fulfillmentStatus = null, $action = '', $remark = '', $operator = null)
    {
        $data = [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'order_status' => $orderStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'action' => $action,
            'remark' => $remark,
            'operator' => $operator,
        ];

        return $this->db->insert('order_status_tracks', $data);
    }

    public static function getOrderStatusMap()
    {
        return [
            0 => '待处理',
            1 => '已路由',
            2 => '已推送仓库',
            3 => '仓库已接单',
            4 => '已出库',
            5 => '已发货',
            6 => '已签收',
            9 => '已取消',
        ];
    }

    public static function getFulfillmentStatusMap()
    {
        return [
            0 => '未开始',
            1 => '拣货中',
            2 => '打包中',
            3 => '已发货',
            4 => '已签收',
            9 => '异常',
        ];
    }

    public static function getOrderStatusText($status)
    {
        $map = self::getOrderStatusMap();
        return $map[$status] ?? '未知状态';
    }

    public static function getFulfillmentStatusText($status)
    {
        $map = self::getFulfillmentStatusMap();
        return $map[$status] ?? '未知状态';
    }
}
