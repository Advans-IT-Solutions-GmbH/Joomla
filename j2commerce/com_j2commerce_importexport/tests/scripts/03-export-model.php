<?php
/**
 * Export Model Tests for J2Commerce Import/Export
 *
 * Calls exportData() with real DB fixtures instead of strpos() checks.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactory;

class ExportModelTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $seededProductIds = [];
    private $seededVariantIds = [];
    private $seededContentIds = [];

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ $name\n";
            $this->passed++;
        } else {
            echo "✗ $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Export Model Tests ===\n\n";

        try {
            $this->seedFixtures();
            $this->testExportProducts();
            $this->testExportCategories();
            $this->testExportVariants();
            $this->testExportFormats();
        } finally {
            $this->cleanupFixtures();
        }

        echo "\n=== Export Model Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function getModel(): \Advans\Component\J2CommerceImportExport\Administrator\Model\ExportModel
    {
        $app = Factory::getApplication('administrator');
        /** @var MVCFactory $mvcFactory */
        $mvcFactory = $app->bootComponent('com_j2commerce_importexport')->getMVCFactory();
        return $mvcFactory->createModel('Export', 'Administrator');
    }

    private function testExportProducts(): void
    {
        echo "--- exportData('products') ---\n";

        $model = $this->getModel();
        $data  = $model->exportData('products');

        $this->test('Returns array',       is_array($data));
        $this->test('Not empty',           !empty($data), 'No products returned — fixtures may not have been seeded');

        if (!empty($data)) {
            $row = $data[0];
            foreach (['title', 'sku', 'price', 'enabled'] as $col) {
                $this->test("Row has '$col'", array_key_exists($col, $row));
            }

            // Seeded product must appear
            $skus = array_column($data, 'sku');
            $this->test('Seeded SKU present', in_array('EXPORT-TEST-SKU-0', $skus),
                'SKU EXPORT-TEST-SKU-0 not found in export');
        }
    }

    private function testExportCategories(): void
    {
        echo "\n--- exportData('categories') ---\n";

        $model = $this->getModel();
        $data  = $model->exportData('categories');

        $this->test('Returns array', is_array($data));
        if (!empty($data)) {
            $this->test('Row has title', array_key_exists('title', $data[0]));
        }
    }

    private function testExportVariants(): void
    {
        echo "\n--- exportData('variants') ---\n";

        $model = $this->getModel();
        $data  = $model->exportData('variants');

        $this->test('Returns array', is_array($data));
        if (!empty($data)) {
            $this->test('Row has sku',   array_key_exists('sku',   $data[0]));
            $this->test('Row has price', array_key_exists('price', $data[0]));
        }
    }

    private function testExportFormats(): void
    {
        echo "\n--- Invalid type → empty array ---\n";

        $model = $this->getModel();
        try {
            $data = $model->exportData('nonexistent_type');
            $this->test('Unknown type returns empty array or throws', is_array($data) && empty($data));
        } catch (\InvalidArgumentException $e) {
            $this->test('Unknown type throws InvalidArgumentException', true);
        } catch (\Exception $e) {
            $this->test('Unknown type does not crash fatally', true);
        }
    }

    private function seedFixtures(): void
    {
        $ts = time();
        foreach (['Export Test Alpha', 'Export Test Beta'] as $i => $title) {
            $article = (object)[
                'title'      => $title,
                'alias'      => 'export-test-' . $i . '-' . $ts,
                'introtext'  => 'Export test product',
                'fulltext'   => '',
                'state'      => 1,
                'catid'      => 2,
                'created'    => date('Y-m-d H:i:s'),
                'created_by' => 42,
                'access'     => 1,
                'language'   => '*',
                'params'     => '{}',
                'metadata'   => '{}',
                'attribs'    => '{}',
            ];
            $this->db->insertObject('#__content', $article, 'id');
            $this->seededContentIds[] = (int) $this->db->insertid();
        }

        foreach ($this->seededContentIds as $i => $contentId) {
            $product = (object)[
                'product_source_id' => $contentId,
                'product_source'    => 'com_content',
                'product_type'      => 'simple',
                'enabled'           => 1,
                'taxprofile_id'     => 0,
                'params'            => '{}',
            ];
            $this->db->insertObject('#__j2store_products', $product, 'j2store_product_id');
            $this->seededProductIds[] = (int) $this->db->insertid();
        }

        foreach ($this->seededProductIds as $i => $productId) {
            $variant = (object)[
                'product_id'   => $productId,
                'sku'          => 'EXPORT-TEST-SKU-' . $i,
                'price'        => 19.99 + $i,
                'stock'        => 10,
                'availability' => '',
                'params'       => '{}',
                'isdefault'    => 1,
            ];
            $this->db->insertObject('#__j2store_variants', $variant, 'j2store_variant_id');
            $this->seededVariantIds[] = (int) $this->db->insertid();
        }
    }

    private function cleanupFixtures(): void
    {
        foreach ([
            ['#__j2store_variants', 'j2store_variant_id', $this->seededVariantIds],
            ['#__j2store_products', 'j2store_product_id', $this->seededProductIds],
            ['#__content',          'id',                 $this->seededContentIds],
        ] as [$table, $pk, $ids]) {
            if (empty($ids)) continue;
            try {
                $this->db->setQuery(
                    $this->db->getQuery(true)->delete($table)->whereIn($pk, $ids)
                )->execute();
            } catch (\Exception $e) {}
        }
    }
}

$test = new ExportModelTest();
exit($test->run() ? 0 : 1);
