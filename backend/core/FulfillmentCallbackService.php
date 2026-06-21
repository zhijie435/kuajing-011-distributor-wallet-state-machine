<?php

class FulfillmentCallbackService
{
    const TYPE_ORDER_ACCEPT = 'ORDER_ACCEPT';
    const TYPE_PICKING_START = 'PICKING_START';
    const TYPE_PACKING_START = 'PACKING_START';
    const TYPE_ORDER_SHIP = 'ORDER_SHIP';
    const TYPE_ORDER_DELIVER = 'ORDER_DELIVER';
    const TYPE_ORDER_EXCEPTION = 'ORDER_EXCEPTION';

    private $db;
    private $permissionService;
    private $auditService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->permissionService = new PermissionService();
        $this->auditService = new AuditService();
    }

    public function handle($callbackData, $clientIp = null, $token = null)
    {
        $clientIp = $clientIp ?: ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $token = $token ?: ($_SERVER['HTTP_X_CALLBACK_TOKEN'] ?? '');

        $validation = $this->validateCallbackPermission($callbackData, $clientIp, $token);
        if (!$validation['success']) {
            $this->logCallback($callbackData, $validation, false, $clientIp);
            return $validation;
        }

        $this->db->beginTransaction();

        try {
            $orderNo = $callbackData['order_no'];
            $warehouseCode = $callbackData['warehouse_code'];
            $callbackType = $callbackData['callback_type'];

            $sql = "SELECT * FROM orders WHERE order_no = ?";
            $order = $this->db->fetchOne($sql, [$orderNo]);

            if (!$order) {
                $this->db->rollBack();
                $result = [
                    'success' => false,
                    'message' => '订单不存在',
                    'error_type' => 'ORDER_NOT_FOUND',
                    'order_no' => $orderNo,
                ];
                $this->logCallback($callbackData, $result, false, $clientIp);
                return $result;
            }

            $beforeOrderStatus = $order['order_status'];
            $beforeFulfillmentStatus = $order['fulfillment_status'];

            $handlerMethod = 'handle' . str_replace('_', '', ucwords(strtolower($callbackType), '_'));
            if (!method_exists($this, $handlerMethod)) {
                $this->db->rollBack();
                $result = [
                    'success' => false,
                    'message' => '未知的回调类型：' . $callbackType,
                    'error_type' => 'UNKNOWN_CALLBACK_TYPE',
                    'order_no' => $orderNo,
                ];
                $this->logCallback($callbackData, $result, false, $clientIp);
                return $result;
            }

            $result = $this->$handlerMethod($order, $callbackData, $warehouseCode);
            if (!$result['success']) {
                $this->db->rollBack();
                $this->logCallback($callbackData, $result, false, $clientIp);
                return $result;
            }

            $this->addStatusTrack(
                $order['id'],
                $orderNo,
                $result['order_status'] ?? null,
                $result['fulfillment_status'] ?? null,
                strtolower(str_replace('_', '', $callbackType)),
                $result['message'] ?? '',
                $warehouseCode
            );

            $this->auditService->logCallback(
                $callbackType,
                $orderNo,
                $warehouseCode,
                $callbackData,
                $result,
                true
            );

            $this->auditService->logStatusChange(
                $orderNo,
                ['order_status' => $beforeOrderStatus, 'fulfillment_status' => $beforeFulfillmentStatus],
                ['order_status' => $result['order_status'] ?? $beforeOrderStatus, 'fulfillment_status' => $result['fulfillment_status'] ?? $beforeFulfillmentStatus],
                strtolower(str_replace('_', '', $callbackType)),
                $warehouseCode
            );

            $this->logCallback($callbackData, $result, true, $clientIp);

            $this->db->commit();

            return $result;
        } catch (Exception $e) {
            $this->db->rollBack();
            $result = [
                'success' => false,
                'message' => '回调处理异常：' . $e->getMessage(),
                'error_type' => 'SYSTEM_ERROR',
            ];
            $this->logCallback($callbackData, $result, false, $clientIp);
            return $result;
        }
    }

    public function validateCallbackPermission($callbackData, $clientIp, $token)
    {
        if (empty($callbackData['order_no'])) {
            return [
                'success' => false,
                'message' => '缺少必填字段：order_no',
                'error_type' => 'MISSING_ORDER_NO',
            ];
        }

        if (empty($callbackData['warehouse_code'])) {
            return [
                'success' => false,
                'message' => '缺少必填字段：warehouse_code',
                'error_type' => 'MISSING_WAREHOUSE_CODE',
            ];
        }

        if (empty($callbackData['callback_type'])) {
            return [
                'success' => false,
                'message' => '缺少必填字段：callback_type',
                'error_type' => 'MISSING_CALLBACK_TYPE',
            ];
        }

        $tokenValidation = $this->permissionService->validateCallbackToken($token);
        if (!$tokenValidation['success']) {
            return $tokenValidation;
        }

        $ipValidation = $this->permissionService->validateIpWhitelist($clientIp);
        if (!$ipValidation['success']) {
            return $ipValidation;
        }

        $matchValidation = $this->permissionService->verifyWarehouseOrderMatch(
            $callbackData['order_no'],
            $callbackData['warehouse_code']
        );
        if (!$matchValidation['success']) {
            return $matchValidation;
        }

        return [
            'success' => true,
            'message' => '回调权限验证通过',
        ];
    }

    private function handleOrderAccept($order, $callbackData, $warehouseCode)
    {
        if ($order['order_status'] >= 3) {
            return [
                'success' => false,
                'message' => '订单已接单，不能重复接单',
                'error_type' => 'DUPLICATE_CALLBACK',
                'order_no' => $order['order_no'],
                'order_status' => (int)$order['order_status'],
            ];
        }

        $updateSql = "UPDATE orders SET order_status = 3, fulfilled_at = ? WHERE id = ?";
        $this->db->query($updateSql, [date('Y-m-d H:i:s'), $order['id']]);

        return [
            'success' => true,
            'message' => '仓库接单成功',
            'order_no' => $order['order_no'],
            'order_status' => 3,
            'fulfillment_status' => (int)$order['fulfillment_status'],
            'warehouse_code' => $warehouseCode,
            'fulfilled_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function handlePickingStart($order, $callbackData, $warehouseCode)
    {
        if ($order['order_status'] < 3) {
            return [
                'success' => false,
                'message' => '订单尚未接单，不能开始拣货',
                'error_type' => 'ORDER_NOT_ACCEPTED',
                'order_no' => $order['order_no'],
                'order_status' => (int)$order['order_status'],
            ];
        }

        if ($order['fulfillment_status'] >= 1) {
            return [
                'success' => false,
                'message' => '已开始拣货，不能重复开始',
                'error_type' => 'DUPLICATE_CALLBACK',
                'order_no' => $order['order_no'],
                'fulfillment_status' => (int)$order['fulfillment_status'],
            ];
        }

        $updateSql = "UPDATE orders SET fulfillment_status = 1, picked_at = ? WHERE id = ?";
        $this->db->query($updateSql, [date('Y-m-d H:i:s'), $order['id']]);

        return [
            'success' => true,
            'message' => '开始拣货成功',
            'order_no' => $order['order_no'],
            'order_status' => 3,
            'fulfillment_status' => 1,
            'warehouse_code' => $warehouseCode,
            'picked_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function handlePackingStart($order, $callbackData, $warehouseCode)
    {
        if ($order['fulfillment_status'] < 1) {
            return [
                'success' => false,
                'message' => '尚未开始拣货，不能开始打包',
                'error_type' => 'NOT_PICKED',
                'order_no' => $order['order_no'],
                'fulfillment_status' => (int)$order['fulfillment_status'],
            ];
        }

        if ($order['fulfillment_status'] >= 2) {
            return [
                'success' => false,
                'message' => '已开始打包，不能重复开始',
                'error_type' => 'DUPLICATE_CALLBACK',
                'order_no' => $order['order_no'],
                'fulfillment_status' => (int)$order['fulfillment_status'],
            ];
        }

        $updateSql = "UPDATE orders SET fulfillment_status = 2, packed_at = ? WHERE id = ?";
        $this->db->query($updateSql, [date('Y-m-d H:i:s'), $order['id']]);

        return [
            'success' => true,
            'message' => '开始打包成功',
            'order_no' => $order['order_no'],
            'order_status' => 3,
            'fulfillment_status' => 2,
            'warehouse_code' => $warehouseCode,
            'packed_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function handleOrderShip($order, $callbackData, $warehouseCode)
    {
        if (empty($callbackData['tracking_number'])) {
            return [
                'success' => false,
                'message' => '缺少运单号',
                'error_type' => 'MISSING_TRACKING_NUMBER',
                'order_no' => $order['order_no'],
            ];
        }

        if ($order['fulfillment_status'] < 2) {
            return [
                'success' => false,
                'message' => '尚未完成打包，不能发货',
                'error_type' => 'NOT_PACKED',
                'order_no' => $order['order_no'],
                'fulfillment_status' => (int)$order['fulfillment_status'],
            ];
        }

        if ($order['order_status'] >= 5) {
            return [
                'success' => false,
                'message' => '订单已发货，不能重复发货',
                'error_type' => 'DUPLICATE_CALLBACK',
                'order_no' => $order['order_no'],
                'order_status' => (int)$order['order_status'],
            ];
        }

        $itemsSql = "SELECT * FROM order_items WHERE order_id = ?";
        $orderItems = $this->db->fetchAll($itemsSql, [$order['id']]);

        foreach ($orderItems as $item) {
            $releaseReservedSql = "UPDATE warehouse_inventories 
                                   SET reserved_quantity = reserved_quantity - ?
                                   WHERE warehouse_id = ? AND sku = ?";
            $this->db->query($releaseReservedSql, [$item['quantity'], $order['warehouse_id'], $item['sku']]);
        }

        $carrier = $callbackData['carrier'] ?? null;
        $shippingMethod = $callbackData['shipping_method'] ?? null;

        $updateSql = "UPDATE orders 
                      SET order_status = 5, fulfillment_status = 3, 
                          tracking_number = ?, carrier = ?, shipping_method = ?,
                          shipped_at = ?
                      WHERE id = ?";
        $this->db->query($updateSql, [
            $callbackData['tracking_number'],
            $carrier,
            $shippingMethod,
            date('Y-m-d H:i:s'),
            $order['id'],
        ]);

        return [
            'success' => true,
            'message' => '发货成功',
            'order_no' => $order['order_no'],
            'order_status' => 5,
            'fulfillment_status' => 3,
            'warehouse_code' => $warehouseCode,
            'tracking_number' => $callbackData['tracking_number'],
            'carrier' => $carrier,
            'shipping_method' => $shippingMethod,
            'shipped_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function handleOrderDeliver($order, $callbackData, $warehouseCode)
    {
        if ($order['order_status'] < 5) {
            return [
                'success' => false,
                'message' => '订单尚未发货，不能签收',
                'error_type' => 'NOT_SHIPPED',
                'order_no' => $order['order_no'],
                'order_status' => (int)$order['order_status'],
            ];
        }

        if ($order['order_status'] >= 6) {
            return [
                'success' => false,
                'message' => '订单已签收，不能重复签收',
                'error_type' => 'DUPLICATE_CALLBACK',
                'order_no' => $order['order_no'],
                'order_status' => (int)$order['order_status'],
            ];
        }

        $updateSql = "UPDATE orders SET order_status = 6, fulfillment_status = 4, delivered_at = ? WHERE id = ?";
        $this->db->query($updateSql, [date('Y-m-d H:i:s'), $order['id']]);

        return [
            'success' => true,
            'message' => '签收成功',
            'order_no' => $order['order_no'],
            'order_status' => 6,
            'fulfillment_status' => 4,
            'warehouse_code' => $warehouseCode,
            'delivered_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function handleOrderException($order, $callbackData, $warehouseCode)
    {
        $reason = $callbackData['reason'] ?? '';
        $exceptionType = $callbackData['exception_type'] ?? 'GENERAL';

        $updateSql = "UPDATE orders SET fulfillment_status = 9, exception_reason = ?, exception_type = ? WHERE id = ?";
        $this->db->query($updateSql, [$reason, $exceptionType, $order['id']]);

        return [
            'success' => true,
            'message' => '异常回调处理成功',
            'order_no' => $order['order_no'],
            'order_status' => (int)$order['order_status'],
            'fulfillment_status' => 9,
            'warehouse_code' => $warehouseCode,
            'exception_type' => $exceptionType,
            'reason' => $reason,
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

    private function logCallback($callbackData, $result, $success, $clientIp)
    {
        $data = [
            'callback_type' => $callbackData['callback_type'] ?? '',
            'order_no' => $callbackData['order_no'] ?? '',
            'warehouse_code' => $callbackData['warehouse_code'] ?? '',
            'request_data' => json_encode($callbackData),
            'response_data' => json_encode($result),
            'is_success' => $success ? 1 : 0,
            'client_ip' => $clientIp,
            'error_type' => $result['error_type'] ?? null,
            'error_message' => $result['message'] ?? null,
        ];

        return $this->db->insert('callback_logs', $data);
    }

    public function getCallbackLogs($orderNo = null, $page = 1, $pageSize = 20)
    {
        $where = ['1=1'];
        $params = [];

        if ($orderNo !== null && $orderNo !== '') {
            $where[] = 'order_no = ?';
            $params[] = $orderNo;
        }

        $whereSql = implode(' AND ', $where);
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT * FROM callback_logs WHERE $whereSql ORDER BY id DESC LIMIT $offset, $pageSize";
        $list = $this->db->fetchAll($sql, $params);

        $countSql = "SELECT COUNT(*) as total FROM callback_logs WHERE $whereSql";
        $countResult = $this->db->fetchOne($countSql, $params);

        return [
            'list' => $list,
            'total' => (int)($countResult['total'] ?? 0),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }
}
