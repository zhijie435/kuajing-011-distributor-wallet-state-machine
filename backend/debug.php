<?php

require_once __DIR__ . '/tests/bootstrap.php';

$db = MockDatabase::getInstance();
$db->seedDefaultData();

echo "=== products 表 ===\n";
$products = $db->fetchAll("SELECT * FROM products WHERE sku = ?", ["SKU001"]);
print_r($products);

echo "\n=== JOIN 查询 ===\n";
$sql = "SELECT p.id, p.sku, p.name, wi.available_quantity
        FROM products p
        LEFT JOIN warehouse_inventories wi ON wi.product_id = p.id
        WHERE p.sku = ?";
$result = $db->fetchAll($sql, ["SKU001"]);
print_r($result);

echo "\n=== GROUP BY + SUM 查询 ===\n";
$sql2 = "SELECT p.id, p.sku, 
        SUM(wi.available_quantity) as total_available
        FROM products p
        LEFT JOIN warehouse_inventories wi ON wi.product_id = p.id
        WHERE p.sku = ? AND p.status = 1
        GROUP BY p.id";
$result2 = $db->fetchAll($sql2, ["SKU001"]);
print_r($result2);

echo "\n=== COALESCE + SUM 查询 ===\n";
$sql3 = "SELECT p.id, p.sku,
        COALESCE(SUM(wi.available_quantity), 0) as total_available
        FROM products p
        LEFT JOIN warehouse_inventories wi ON wi.product_id = p.id
        WHERE p.sku = ? AND p.status = 1
        GROUP BY p.id";
$result3 = $db->fetchAll($sql3, ["SKU001"]);
print_r($result3);

echo "\n=== warehouse_inventories 表 ===\n";
$inv = $db->fetchAll("SELECT * FROM warehouse_inventories WHERE sku = ?", ["SKU001"]);
print_r($inv);
