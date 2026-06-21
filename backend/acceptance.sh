#!/bin/bash

echo "========================================"
echo "  海外仓一件代发系统 - 部署验收"
echo "========================================"

PASS=0
FAIL=0

# 测试1: 数据库连接
echo -n "[1/5] 检查数据库连接... "
php -r "
\$config = require 'config/config.php';
try {
    \$pdo = new PDO('mysql:host='.\$config['db']['host'].';port='.\$config['db']['port'].';dbname='.\$config['db']['database'].';charset='.\$config['db']['charset'], \$config['db']['username'], \$config['db']['password']);
    echo '✓ PASS';
    exit(0);
} catch (Exception \$e) {
    echo '✗ FAIL: '.\$e->getMessage();
    exit(1);
}
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试2: 单元测试
echo -n "[2/5] 运行单元测试... "
php tests/run.php > /tmp/test_result.txt 2>&1
if [ $? -eq 0 ]; then
    echo "✓ PASS"
    PASS=$((PASS+1))
else
    echo "✗ FAIL"
    cat /tmp/test_result.txt
    FAIL=$((FAIL+1))
fi

# 测试3: 仓库路由功能
echo -n "[3/5] 测试仓库路由... "
php -r "
require_once 'core/WarehouseRouter.php';
\$router = new WarehouseRouter();
\$result = \$router->route([['sku'=>'SKU001','quantity'=>1]], 'US');
echo \$result['success'] ? '✓ PASS' : '✗ FAIL: '.\$result['message'];
exit(\$result['success'] ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试4: 履约回调Token校验
echo -n "[4/5] 测试履约回调Token... "
php -r "
require_once 'core/FulfillmentCallbackService.php';
\$service = new FulfillmentCallbackService();
\$config = require 'config/config.php';
echo \$service->validateToken(\$config['callback']['token']) ? '✓ PASS' : '✗ FAIL';
exit(\$service->validateToken(\$config['callback']['token']) ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

# 测试5: 数据表初始化
echo -n "[5/5] 检查测试数据... "
php -r "
\$config = require 'config/config.php';
\$pdo = new PDO('mysql:host='.\$config['db']['host'].';port='.\$config['db']['port'].';dbname='.\$config['db']['database'].';charset='.\$config['db']['charset'], \$config['db']['username'], \$config['db']['password']);
\$count = \$pdo->query('SELECT COUNT(*) FROM warehouses')->fetchColumn();
echo \$count >= 4 ? '✓ PASS ('.$count.' warehouses)' : '✗ FAIL';
exit(\$count >= 4 ? 0 : 1);
" && PASS=$((PASS+1)) || FAIL=$((FAIL+1))
echo ""

echo "========================================"
echo "  验收结果: $PASS 通过, $FAIL 失败"
echo "========================================"
exit $FAIL
