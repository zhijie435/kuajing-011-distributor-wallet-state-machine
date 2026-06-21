<?php
require_once __DIR__ . '/tests/bootstrap.php';

$db = MockDatabase::getInstance();
TestDataSeeder::seedDefaultData($db);

echo "=== 测试1: SELECT w.* FROM warehouses w WHERE 1=1 ===\n";
$sql = "SELECT w.* FROM warehouses w WHERE 1=1 ORDER BY w.priority DESC";
$rows = $db->fetchAll($sql);
echo "count: " . count($rows) . "\n";
foreach ($rows as $row) {
    echo "  id={$row['id']}, code={$row['code']}, priority={$row['priority']}\n";
}

echo "\n=== 测试2: 加 LIMIT ===\n";
$sql = "SELECT w.* FROM warehouses w WHERE 1=1 ORDER BY w.priority DESC LIMIT 0, 20";
$rows = $db->fetchAll($sql);
echo "count: " . count($rows) . "\n";

echo "\n=== 测试3: WHERE w.status = ? ===\n";
$sql = "SELECT w.* FROM warehouses w WHERE w.status = ? ORDER BY w.priority DESC";
$rows = $db->fetchAll($sql, [1]);
echo "count: " . count($rows) . "\n";

echo "\n=== 测试4: WHERE 1=1 AND w.status = ? ===\n";
$sql = "SELECT w.* FROM warehouses w WHERE 1=1 AND w.status = ? ORDER BY w.priority DESC";
$rows = $db->fetchAll($sql, [1]);
echo "count: " . count($rows) . "\n";
