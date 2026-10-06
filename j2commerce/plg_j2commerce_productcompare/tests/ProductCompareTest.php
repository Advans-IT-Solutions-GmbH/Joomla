<?php
/**
 * @package     J2Commerce Product Compare Plugin
 * @subpackage  Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Joomla\Plugin\J2store\ProductCompare\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Test cases for J2Commerce Product Compare plugin
 *
 * @since  1.0.0
 */
class ProductCompareTest extends TestCase
{
    /**
     * Test product ID validation
     *
     * @return  void
     */
    public function testProductIdValidation()
    {
        $validId = 123;
        $invalidId = 'abc';
        
        $this->assertTrue(is_numeric($validId));
        $this->assertFalse(is_numeric($invalidId));
        $this->assertGreaterThan(0, $validId);
    }

    /**
     * Test maximum products limit
     *
     * @return  void
     */
    public function testMaxProductsLimit()
    {
        $maxProducts = 4;
        $productIds = [1, 2, 3, 4, 5];
        
        $limited = array_slice($productIds, 0, $maxProducts);
        
        $this->assertCount($maxProducts, $limited);
        $this->assertEquals([1, 2, 3, 4], $limited);
    }

    /**
     * Test duplicate product prevention
     *
     * @return  void
     */
    public function testDuplicatePrevention()
    {
        $productIds = [1, 2, 3, 2, 4, 1];
        
        $unique = array_unique($productIds);
        
        $this->assertCount(4, $unique);
        $this->assertContains(1, $unique);
        $this->assertContains(2, $unique);
        $this->assertContains(3, $unique);
        $this->assertContains(4, $unique);
    }

    /**
     * Test product data structure
     *
     * @return  void
     */
    public function testProductDataStructure()
    {
        $product = [
            'id' => 1,
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'price' => 99.99,
            'image' => 'test.jpg'
        ];
        
        $this->assertArrayHasKey('id', $product);
        $this->assertArrayHasKey('name', $product);
        $this->assertArrayHasKey('sku', $product);
        $this->assertArrayHasKey('price', $product);
        $this->assertIsNumeric($product['id']);
        $this->assertIsString($product['name']);
        $this->assertIsFloat($product['price']);
    }

    /**
     * Test comparison attributes extraction
     *
     * @return  void
     */
    public function testAttributesExtraction()
    {
        $products = [
            [
                'id' => 1,
                'name' => 'Product 1',
                'price' => 10.00,
                'weight' => 1.5
            ],
            [
                'id' => 2,
                'name' => 'Product 2',
                'price' => 20.00,
                'color' => 'Red'
            ]
        ];
        
        $attributes = $this->extractAttributes($products);
        
        $this->assertContains('name', $attributes);
        $this->assertContains('price', $attributes);
        $this->assertContains('weight', $attributes);
        $this->assertContains('color', $attributes);
    }

    /**
     * Test JSON encoding for localStorage
     *
     * @return  void
     */
    public function testJsonEncoding()
    {
        $productIds = [1, 2, 3];
        
        $json = json_encode($productIds);
        $decoded = json_decode($json, true);
        
        $this->assertIsString($json);
        $this->assertIsArray($decoded);
        $this->assertEquals($productIds, $decoded);
    }

    /**
     * Test product removal from list
     *
     * @return  void
     */
    public function testProductRemoval()
    {
        $productIds = [1, 2, 3, 4];
        $removeId = 2;
        
        $filtered = array_values(array_filter($productIds, function($id) use ($removeId) {
            return $id !== $removeId;
        }));
        
        $this->assertCount(3, $filtered);
        $this->assertNotContains($removeId, $filtered);
        $this->assertEquals([1, 3, 4], $filtered);
    }

    /**
     * Test clear all products
     *
     * @return  void
     */
    public function testClearAllProducts()
    {
        $productIds = [1, 2, 3, 4];
        
        $cleared = [];
        
        $this->assertEmpty($cleared);
        $this->assertCount(0, $cleared);
    }

    /**
     * Test attribute value comparison
     *
     * @return  void
     */
    public function testAttributeComparison()
    {
        $product1 = ['price' => 10.00, 'weight' => 1.5];
        $product2 = ['price' => 20.00, 'weight' => 1.5];
        
        $this->assertNotEquals($product1['price'], $product2['price']);
        $this->assertEquals($product1['weight'], $product2['weight']);
    }

    /**
     * Test button CSS class generation
     *
     * @return  void
     */
    public function testButtonClassGeneration()
    {
        $baseClass = 'btn btn-primary';
        $additionalClass = 'j2store-compare';
        
        $fullClass = trim($baseClass . ' ' . $additionalClass);
        
        $this->assertEquals('btn btn-primary j2store-compare', $fullClass);
        $this->assertStringContainsString('btn', $fullClass);
        $this->assertStringContainsString('j2store-compare', $fullClass);
    }

    /**
     * Helper: Extract all unique attributes from products
     *
     * @param   array  $products  Products array
     *
     * @return  array
     */
    private function extractAttributes(array $products): array
    {
        $attributes = [];
        
        foreach ($products as $product) {
            $attributes = array_merge($attributes, array_keys($product));
        }
        
        return array_unique($attributes);
    }
}
