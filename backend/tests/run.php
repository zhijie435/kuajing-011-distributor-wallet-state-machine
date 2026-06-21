<?php

echo "\033[1;36m============================================\033[0m\n";
echo "\033[1;36m  海外仓一件代发系统 - 单元测试\033[0m\n";
echo "\033[1;36m============================================\033[0m\n\n";

require_once __DIR__ . '/bootstrap.php';

$testDir = __DIR__;
$testFiles = glob($testDir . '/*Test.php');

if (empty($testFiles)) {
    echo "\033[1;31m没有找到测试文件\033[0m\n";
    exit(1);
}

$specifiedClass = $argv[1] ?? null;

$totalTests = 0;
$totalPassed = 0;
$totalFailed = 0;
$failedTests = [];

$startTime = microtime(true);

foreach ($testFiles as $testFile) {
    $className = basename($testFile, '.php');
    
    if ($specifiedClass && $className !== $specifiedClass) {
        continue;
    }
    
    if (!class_exists($className)) {
        require_once $testFile;
    }
    
    if (!class_exists($className)) {
        continue;
    }
    
    $reflection = new ReflectionClass($className);
    if (!$reflection->isSubclassOf('TestCase') || $reflection->isAbstract()) {
        continue;
    }
    
    echo "\033[1;33m【{$className}】\033[0m\n";
    
    $instance = $reflection->newInstance();
    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);
    
    $classTests = 0;
    $classPassed = 0;
    $classFailed = 0;
    
    foreach ($methods as $method) {
        $methodName = $method->getName();
        if (strpos($methodName, 'test') !== 0) {
            continue;
        }
        
        $classTests++;
        $totalTests++;
        
        try {
            if (method_exists($instance, 'setUp')) {
                $instance->setUp();
            }
            
            $instance->$methodName();
            
            if (method_exists($instance, 'tearDown')) {
                $instance->tearDown();
            }
            
            $classPassed++;
            $totalPassed++;
            echo "  \033[0;32m✓\033[0m {$methodName}\n";
        } catch (Exception $e) {
            $classFailed++;
            $totalFailed++;
            $failedTests[] = [
                'class' => $className,
                'method' => $methodName,
                'error' => $e->getMessage(),
            ];
            echo "  \033[0;31m✗\033[0m {$methodName}\n";
            echo "    \033[0;31m" . $e->getMessage() . "\033[0m\n";
        }
    }
    
    echo "  共 {$classTests} 个测试，通过 \033[0;32m{$classPassed}\033[0m，失败 \033[0;31m{$classFailed}\033[0m\n\n";
}

$endTime = microtime(true);
$duration = round($endTime - $startTime, 4);

echo "\033[1;36m============================================\033[0m\n";
echo "测试完成！\n";
echo "总测试数：{$totalTests}\n";
echo "通过：\033[0;32m{$totalPassed}\033[0m\n";
echo "失败：\033[0;31m{$totalFailed}\033[0m\n";
echo "耗时：{$duration} 秒\n";
echo "\033[1;36m============================================\033[0m\n";

if (!empty($failedTests)) {
    echo "\n\033[1;31m失败的测试：\033[0m\n";
    foreach ($failedTests as $failed) {
        echo "  - {$failed['class']}::{$failed['method']}\n";
        echo "    {$failed['error']}\n";
    }
    echo "\n";
}

exit($totalFailed > 0 ? 1 : 0);
