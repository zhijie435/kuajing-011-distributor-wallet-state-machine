<?php

class WarehouseRouterTest extends TestCase
{
    private $router;

    public function setUp()
    {
        parent::setUp();
        $this->router = new WarehouseRouter();
    }

    public function testRouteSuccess()
    {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 2],
        ];
        
        $result = $this->router->route($items, 'US', 'CA');
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['warehouse']);
        $this->assertEquals('USCA', $result['warehouse']['code']);
        $this->assertGreaterThan(0, $result['score']);
        $this->assertNotEmpty($result['estimated_delivery_date']);
    }

    public function testRouteEmptyItems()
    {
        $result = $this->router->route([], 'US');
        $this->assertFalse($result['success']);
        $this->assertEquals('EMPTY_ITEMS', $result['error_type']);
    }

    public function testRouteEmptyCountry()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, '');
        $this->assertFalse($result['success']);
        $this->assertEquals('EMPTY_COUNTRY', $result['error_type']);
    }

    public function testRouteEmptySku()
    {
        $items = [['sku' => '', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        $this->assertFalse($result['success']);
        $this->assertEquals('EMPTY_SKU', $result['error_type']);
    }

    public function testRouteInvalidQuantity()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 0]];
        $result = $this->router->route($items, 'US');
        $this->assertFalse($result['success']);
        $this->assertEquals('INVALID_QUANTITY', $result['error_type']);
    }

    public function testRouteProductNotFound()
    {
        $items = [['sku' => 'INVALID_SKU', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        $this->assertFalse($result['success']);
        $this->assertEquals('PRODUCT_NOT_FOUND', $result['error_type']);
    }

    public function testRouteInsufficientStock()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 9999]];
        $result = $this->router->route($items, 'US');
        $this->assertFalse($result['success']);
        $this->assertEquals('INSUFFICIENT_STOCK', $result['error_type']);
    }

    public function testRouteNoShippingZone()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'XX');
        $this->assertFalse($result['success']);
        $this->assertEquals('NO_SHIPPING_ZONE', $result['error_type']);
    }

    public function testRouteMultipleItems()
    {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 1],
            ['sku' => 'SKU002', 'quantity' => 2],
        ];
        
        $result = $this->router->route($items, 'US', 'CA');
        
        $this->assertTrue($result['success']);
        $this->assertEquals('USCA', $result['warehouse']['code']);
    }

    public function testRouteWithAlternatives()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US');
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['alternatives']);
    }

    public function testRouteShippingFeeCalculation()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US', 'CA');
        
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['shipping_fee']);
        $this->assertIsString($result['shipping_fee']);
    }

    public function testRouteEstimatedDeliveryDate()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US', 'CA');
        
        $this->assertTrue($result['success']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['estimated_delivery_date']);
    }

    public function testRouteWithScopeWarehouses()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US', null, ['USNJ']);
        
        $this->assertTrue($result['success']);
        $this->assertEquals('USNJ', $result['warehouse']['code']);
    }

    public function testRouteWithScopeWarehousesNoMatch()
    {
        $items = [['sku' => 'SKU001', 'quantity' => 1]];
        $result = $this->router->route($items, 'US', null, ['INVALID_WH']);
        
        $this->assertFalse($result['success']);
        $this->assertEquals('NO_PERMITTED_WAREHOUSE', $result['error_type']);
    }

    public function testCheckAllWarehousesStockSuccess()
    {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 10],
            ['sku' => 'SKU002', 'quantity' => 5],
        ];
        
        $result = $this->router->checkAllWarehousesStock($items);
        $this->assertTrue($result['success']);
    }

    public function testCheckAllWarehousesStockProductNotFound()
    {
        $items = [['sku' => 'INVALID', 'quantity' => 1]];
        $result = $this->router->checkAllWarehousesStock($items);
        $this->assertFalse($result['success']);
        $this->assertEquals('PRODUCT_NOT_FOUND', $result['error_type']);
    }

    public function testFindWarehousesWithAllStock()
    {
        $items = [
            ['sku' => 'SKU001', 'quantity' => 1],
            ['sku' => 'SKU002', 'quantity' => 1],
        ];
        
        $warehouses = $this->router->findWarehousesWithAllStock($items);
        $this->assertNotEmpty($warehouses);
        
        foreach ($warehouses as $warehouse) {
            $this->assertEquals(2, $warehouse['sku_count']);
        }
    }

    public function testGetMatchingShippingZones()
    {
        $zones = $this->router->getMatchingShippingZones([1, 2], 'US', 'CA');
        $this->assertNotEmpty($zones);
    }

    public function testListWarehouses()
    {
        $result = $this->router->listWarehouses();
        $this->assertIsArray($result['list']);
        $this->assertGreaterThan(0, $result['total']);
    }

    public function testListWarehousesWithCountryFilter()
    {
        $result = $this->router->listWarehouses(null, 'US');
        $this->assertGreaterThan(0, $result['total']);
        
        foreach ($result['list'] as $warehouse) {
            $this->assertEquals('US', $warehouse['country']);
        }
    }

    public function testGetWarehouseByCode()
    {
        $warehouse = $this->router->getWarehouseByCode('USCA');
        $this->assertNotNull($warehouse);
        $this->assertEquals('USCA', $warehouse['code']);
        $this->assertArrayHasKey('inventories', $warehouse);
    }

    public function testGetWarehouseByCodeNotFound()
    {
        $warehouse = $this->router->getWarehouseByCode('INVALID');
        $this->assertNull($warehouse);
    }
}
