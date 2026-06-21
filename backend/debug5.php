<?php
require_once __DIR__ . '/tests/bootstrap.php';

$db = MockDatabase::getInstance();
TestDataSeeder::seedDefaultData($db);

echo "=== warehouse_inventories 总记录数 ===\n";
$rows = $db->fetchAll("SELECT * FROM warehouse_inventories");
echo "count: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  id={$row['id']}, warehouse_id={$row['warehouse_id']}, sku={$row['sku']}, qty={$row['available_quantity']}\n";
}

echo "\n=== 用 ID 查第一条 ===\n";
$row = $db->fetchOne("SELECT * FROM warehouse_inventories WHERE id = ?", [4]);
print_r($row);

echo "\n=== 用 warehouse_id 查 ===\n";
$rows = $db->fetchAll("SELECT * FROM warehouse_inventories WHERE warehouse_id = ?", [2]);
echo "count: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  id={$row['id']}, sku={$row['sku']}\n";
}
