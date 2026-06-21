<?php

abstract class TestCase
{
    protected $db;

    public function setUp()
    {
        if (class_exists('MockDatabase')) {
            MockDatabase::resetInstance();
            $this->db = MockDatabase::getInstance();
            $this->db->seedDefaultData();
        }
    }

    public function tearDown()
    {
    }

    protected function assertTrue($condition, $message = '')
    {
        if ($condition !== true) {
            throw new Exception("Failed asserting that true. " . $message);
        }
    }

    protected function assertFalse($condition, $message = '')
    {
        if ($condition !== false) {
            throw new Exception("Failed asserting that false. " . $message);
        }
    }

    protected function assertEquals($expected, $actual, $message = '')
    {
        if ($expected != $actual) {
            throw new Exception("Failed asserting that " . var_export($actual, true) . " equals " . var_export($expected, true) . ". " . $message);
        }
    }

    protected function assertSame($expected, $actual, $message = '')
    {
        if ($expected !== $actual) {
            throw new Exception("Failed asserting that " . var_export($actual, true) . " is identical to " . var_export($expected, true) . ". " . $message);
        }
    }

    protected function assertNotEmpty($value, $message = '')
    {
        if (empty($value)) {
            throw new Exception("Failed asserting that value is not empty. " . $message);
        }
    }

    protected function assertEmpty($value, $message = '')
    {
        if (!empty($value)) {
            throw new Exception("Failed asserting that value is empty. " . $message);
        }
    }

    protected function assertNull($value, $message = '')
    {
        if ($value !== null) {
            throw new Exception("Failed asserting that value is null. " . $message);
        }
    }

    protected function assertNotNull($value, $message = '')
    {
        if ($value === null) {
            throw new Exception("Failed asserting that value is not null. " . $message);
        }
    }

    protected function assertCount($expectedCount, $array, $message = '')
    {
        if (!is_array($array) || count($array) !== $expectedCount) {
            $actualCount = is_array($array) ? count($array) : 'not an array';
            throw new Exception("Failed asserting that count is {$expectedCount}, got {$actualCount}. " . $message);
        }
    }

    protected function assertArrayHasKey($key, $array, $message = '')
    {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            throw new Exception("Failed asserting that array has key '{$key}'. " . $message);
        }
    }

    protected function assertStringContainsString($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) === false) {
            throw new Exception("Failed asserting that string contains '{$needle}'. " . $message);
        }
    }

    protected function assertGreaterThan($expected, $actual, $message = '')
    {
        if ($actual <= $expected) {
            throw new Exception("Failed asserting that {$actual} is greater than {$expected}. " . $message);
        }
    }

    protected function assertGreaterThanOrEqual($expected, $actual, $message = '')
    {
        if ($actual < $expected) {
            throw new Exception("Failed asserting that {$actual} is greater than or equal to {$expected}. " . $message);
        }
    }

    protected function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual >= $expected) {
            throw new Exception("Failed asserting that {$actual} is less than {$expected}. " . $message);
        }
    }

    protected function assertIsArray($value, $message = '')
    {
        if (!is_array($value)) {
            throw new Exception("Failed asserting that value is an array. " . $message);
        }
    }

    protected function assertIsString($value, $message = '')
    {
        if (!is_string($value)) {
            throw new Exception("Failed asserting that value is a string. " . $message);
        }
    }

    protected function assertIsInt($value, $message = '')
    {
        if (!is_int($value)) {
            throw new Exception("Failed asserting that value is an integer. " . $message);
        }
    }

    protected function assertMatchesRegularExpression($pattern, $value, $message = '')
    {
        if (!preg_match($pattern, $value)) {
            throw new Exception("Failed asserting that value matches pattern '{$pattern}'. " . $message);
        }
    }

    protected function assertContains($needle, $array, $message = '')
    {
        if (!is_array($array) || !in_array($needle, $array)) {
            throw new Exception("Failed asserting that array contains '{$needle}'. " . $message);
        }
    }
}
