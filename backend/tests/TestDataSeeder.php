<?php

class TestDataSeeder
{
    public static function seedDefaultData($db)
    {
        self::seedWarehouses($db);
        self::seedProducts($db);
        self::seedShippingZones($db);
        self::seedInventories($db);
    }

    public static function seedWarehouses($db)
    {
        $warehouses = [
            ['code' => 'USCA', 'name' => '美国加州仓', 'country' => 'US', 'state' => 'CA', 'city' => 'Los Angeles', 'address' => '123 Main St, Los Angeles, CA', 'priority' => 100, 'status' => 1],
            ['code' => 'USNJ', 'name' => '美国新泽西仓', 'country' => 'US', 'state' => 'NJ', 'city' => 'Newark', 'address' => '456 Oak Ave, Newark, NJ', 'priority' => 90, 'status' => 1],
            ['code' => 'GBLN', 'name' => '英国伦敦仓', 'country' => 'GB', 'state' => null, 'city' => 'London', 'address' => '789 King St, London', 'priority' => 80, 'status' => 1],
            ['code' => 'DEBE', 'name' => '德国柏林仓', 'country' => 'DE', 'state' => null, 'city' => 'Berlin', 'address' => '321 Berlin Strasse, Berlin', 'priority' => 70, 'status' => 1],
        ];

        $db->seed('warehouses', $warehouses);
    }

    public static function seedProducts($db)
    {
        $products = [
            ['id' => 1, 'sku' => 'SKU001', 'name' => '无线蓝牙耳机', 'price' => 49.99, 'cost' => 25.00, 'weight' => 0.2, 'status' => 1],
            ['id' => 2, 'sku' => 'SKU002', 'name' => '智能手表', 'price' => 199.99, 'cost' => 120.00, 'weight' => 0.15, 'status' => 1],
            ['id' => 3, 'sku' => 'SKU003', 'name' => '便携充电宝', 'price' => 29.99, 'cost' => 15.00, 'weight' => 0.3, 'status' => 1],
            ['id' => 4, 'sku' => 'SKU004', 'name' => '手机壳', 'price' => 19.99, 'cost' => 5.00, 'weight' => 0.05, 'status' => 1],
            ['id' => 5, 'sku' => 'SKU005', 'name' => '数据线', 'price' => 9.99, 'cost' => 2.00, 'weight' => 0.02, 'status' => 1],
        ];

        $db->seed('products', $products);
    }

    public static function seedShippingZones($db)
    {
        $zones = [
            ['warehouse_id' => 1, 'country' => 'US', 'state' => 'CA', 'shipping_fee' => 5.99, 'delivery_days_min' => 2, 'delivery_days_max' => 4, 'status' => 1],
            ['warehouse_id' => 1, 'country' => 'US', 'state' => 'NV', 'shipping_fee' => 6.99, 'delivery_days_min' => 3, 'delivery_days_max' => 5, 'status' => 1],
            ['warehouse_id' => 1, 'country' => 'US', 'state' => null, 'shipping_fee' => 9.99, 'delivery_days_min' => 5, 'delivery_days_max' => 7, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => 'NJ', 'shipping_fee' => 5.99, 'delivery_days_min' => 2, 'delivery_days_max' => 4, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => 'NY', 'shipping_fee' => 6.99, 'delivery_days_min' => 3, 'delivery_days_max' => 5, 'status' => 1],
            ['warehouse_id' => 2, 'country' => 'US', 'state' => null, 'shipping_fee' => 9.99, 'delivery_days_min' => 5, 'delivery_days_max' => 7, 'status' => 1],
            ['warehouse_id' => 3, 'country' => 'GB', 'state' => null, 'shipping_fee' => 4.99, 'delivery_days_min' => 2, 'delivery_days_max' => 5, 'status' => 1],
            ['warehouse_id' => 4, 'country' => 'DE', 'state' => null, 'shipping_fee' => 5.99, 'delivery_days_min' => 2, 'delivery_days_max' => 5, 'status' => 1],
        ];

        $db->seed('warehouse_shipping_zones', $zones);
    }

    public static function seedInventories($db)
    {
        $inventories = [
            ['warehouse_id' => 1, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 500, 'reserved_quantity' => 0, 'available_quantity' => 500],
            ['warehouse_id' => 1, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 200, 'reserved_quantity' => 0, 'available_quantity' => 200],
            ['warehouse_id' => 1, 'product_id' => 3, 'sku' => 'SKU003', 'quantity' => 800, 'reserved_quantity' => 0, 'available_quantity' => 800],
            ['warehouse_id' => 1, 'product_id' => 4, 'sku' => 'SKU004', 'quantity' => 1000, 'reserved_quantity' => 0, 'available_quantity' => 1000],
            ['warehouse_id' => 2, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 300, 'reserved_quantity' => 0, 'available_quantity' => 300],
            ['warehouse_id' => 2, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 150, 'reserved_quantity' => 0, 'available_quantity' => 150],
            ['warehouse_id' => 2, 'product_id' => 5, 'sku' => 'SKU005', 'quantity' => 2000, 'reserved_quantity' => 0, 'available_quantity' => 2000],
            ['warehouse_id' => 3, 'product_id' => 1, 'sku' => 'SKU001', 'quantity' => 100, 'reserved_quantity' => 0, 'available_quantity' => 100],
            ['warehouse_id' => 3, 'product_id' => 3, 'sku' => 'SKU003', 'quantity' => 200, 'reserved_quantity' => 0, 'available_quantity' => 200],
            ['warehouse_id' => 3, 'product_id' => 4, 'sku' => 'SKU004', 'quantity' => 500, 'reserved_quantity' => 0, 'available_quantity' => 500],
            ['warehouse_id' => 4, 'product_id' => 2, 'sku' => 'SKU002', 'quantity' => 80, 'reserved_quantity' => 0, 'available_quantity' => 80],
            ['warehouse_id' => 4, 'product_id' => 5, 'sku' => 'SKU005', 'quantity' => 1000, 'reserved_quantity' => 0, 'available_quantity' => 1000],
        ];

        $db->seed('warehouse_inventories', $inventories);
    }

    public static function createTestOrder($db, $orderData = [])
    {
        $defaultData = [
            'order_no' => 'WH' . date('YmdHis') . str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT),
            'warehouse_id' => 1,
            'warehouse_code' => 'USCA',
            'order_status' => 1,
            'fulfillment_status' => 0,
            'receiver_name' => '张三',
            'receiver_phone' => '13800138000',
            'receiver_email' => 'test@example.com',
            'receiver_country' => 'US',
            'receiver_state' => 'CA',
            'receiver_city' => 'Los Angeles',
            'receiver_address' => '123 Test Street',
            'receiver_zipcode' => '90001',
            'total_amount' => 49.99,
            'shipping_fee' => 5.99,
            'total_weight' => 0.2,
            'created_by' => 'test_user',
        ];

        $data = array_merge($defaultData, $orderData);
        return $db->insert('orders', $data);
    }

    public static function createTestOrderItem($db, $orderId, $orderNo, $itemData = [])
    {
        $defaultData = [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'product_id' => 1,
            'sku' => 'SKU001',
            'product_name' => '无线蓝牙耳机',
            'quantity' => 1,
            'unit_price' => 49.99,
            'subtotal' => 49.99,
            'weight' => 0.2,
        ];

        $data = array_merge($defaultData, $itemData);
        return $db->insert('order_items', $data);
    }
}
