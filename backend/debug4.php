<?php
require_once __DIR__ . '/tests/bootstrap.php';

$db = MockDatabase::getInstance();
TestDataSeeder::seedDefaultData($db);

echo "=== warehouse_inventories 中 SKU001 的记录 ===\n";
$rows = $db->fetchAll("SELECT * FROM warehouse_inventories WHERE sku = ?", ['SKU001']);
echo "count: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  id={$row['id']}, warehouse_id={$row['warehouse_id']}, sku={$row['sku']}, qty={$row['available_quantity']}\n";
}

echo "\n=== INNER JOIN 查询（调试 ===\n";
$sql = "SELECT wi.warehouse_id, wi.id as inv_id, w.id as wh_id, w.code
        FROM warehouse_inventories wi
        INNER JOIN warehouses w ON w.id = wi.warehouse_id
        WHERE wi.sku = ?";
$results = $db->fetchAll($sql, ['SKU001']);
echo "count: " . count($results) . "\n";
print_r($results);
