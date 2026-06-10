<?php
/**
 * Export→Import Round-Trip Tests for J2Commerce Import/Export
 *
 * Imports a product via importProductFull(), exports it via
 * exportData('products_full'), and verifies the exported record
 * matches the imported data.
 *
 * Covers issue #100.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

// Register component PSR-4 namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'Advans\\Component\\J2CommerceImportExport\\Administrator\\';
    $base   = '/var/www/html/administrator/components/com_j2commerce_importexport/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $base . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Advans\Component\J2CommerceImportExport\Administrator\Model\ExportModel;
use Advans\Component\J2CommerceImportExport\Administrator\Model\ImportModel;

class RoundTripTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $tp;

    public function __construct()
    {
        $this->db   = Factory::getContainer()->get(DatabaseInterface::class);
        $tables     = $this->db->getTableList();
        $prefix     = $this->db->getPrefix();
        $this->isJ6 = in_array($prefix . 'j2commerce_products', $tables, true);
        $this->tp   = $this->isJ6 ? 'j2commerce' : 'j2store';
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "PASS $name\n";
                $this->passed++;
            } else {
                echo "FAIL $name\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "FAIL $name — " . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    private function makeModel(string $class): object
    {
        $rc     = new ReflectionClass($class);
        $model  = $rc->newInstanceWithoutConstructor();
        $model->setDatabase($this->db);
        return $model;
    }

    public function run(): bool
    {
        echo "=== Export→Import Round-Trip Tests ===\n";
        echo "Stack: " . strtoupper($this->tp) . "\n\n";

        $tables = $this->db->getTableList();
        $prefix = $this->db->getPrefix();
        $hasJ4  = in_array($prefix . 'j2store_products', $tables, true);

        if (!$this->isJ6 && !$hasJ4) {
            $this->test('J2Commerce tables are present', fn () => false);
            echo "\n=== Round-Trip Test Summary ===\n";
            echo "Passed: {$this->passed}\nFailed: {$this->failed}\n";
            return false;
        }

        $sku   = 'RT-TEST-' . uniqid();
        $title = 'Round-Trip Test Product ' . $sku;
        $alias = 'round-trip-test-' . strtolower($sku);

        // ---- Step 1: Import ----
        echo "--- Step 1: importProductFull() ---\n";

        $importModel = $this->makeModel(ImportModel::class);

        $importData = [
            'title'          => $title,
            'alias'          => $alias,
            'category'       => 'Round-Trip Test Category',
            'sku'            => $sku,
            'price'          => 29.99,
            'visibility'     => 1,
            'addtocart_text' => '',
            'up_sells'       => '',
            'cross_sells'    => '',
            'variants'       => [
                [
                    'sku' => $sku . '-V1',
                    'price' => 29.99,
                    'is_master' => 1,
                    'isdefault_variant' => 1,
                    'pricing_calculator' => 'standard',
                    'shipping' => 1,
                    'quantity_restriction' => 0,
                    'allow_backorder' => 0,
                ],
            ],
            'product_images' => [],
            'options'        => [],
            'filters'        => [],
            'files'          => [],
            'tags'           => [],
            'custom_fields'  => [],
        ];

        $importResult = null;
        $this->test('importProductFull() succeeds', function () use ($importModel, $importData, &$importResult) {
            $importResult = $importModel->importProductFull($importData, []);
            return is_array($importResult)
                && ($importResult['success'] ?? false) === true
                && (int) ($importResult['article_id'] ?? 0) > 0
                && (int) ($importResult['product_id'] ?? 0) > 0;
        });

        if (!$importResult || !($importResult['success'] ?? false)) {
            echo "SKIP export round-trip — import failed\n";
            echo "\n=== Round-Trip Test Summary ===\n";
            echo "Passed: {$this->passed}\nFailed: {$this->failed}\n";
            return $this->failed === 0;
        }

        $articleId = (int) $importResult['article_id'];
        $productId = (int) $importResult['product_id'];

        // ---- Step 2: Export ----
        echo "\n--- Step 2: exportData('products_full') ---\n";

        $exportModel = $this->makeModel(ExportModel::class);

        $exported = null;
        $this->test("exportData('products_full') returns array", function () use ($exportModel, &$exported) {
            $exported = $exportModel->exportData('products_full');
            return is_array($exported);
        });

        // Find the exported record for our product
        $exportedProduct = null;
        if (is_array($exported)) {
            foreach ($exported as $row) {
                $rowProductId = (int) ($row[$this->tp . '_product_id'] ?? $row['product_id'] ?? 0);
                if ($rowProductId === $productId) {
                    $exportedProduct = $row;
                    break;
                }
            }
        }

        $this->test('Imported product appears in export', function () use ($exportedProduct) {
            return $exportedProduct !== null;
        });

        if ($exportedProduct !== null) {
            $this->test('Exported title matches imported title', function () use ($exportedProduct, $title) {
                return ($exportedProduct['title'] ?? '') === $title;
            });

            $this->test('Exported alias matches imported alias', function () use ($exportedProduct, $alias) {
                return ($exportedProduct['alias'] ?? '') === $alias;
            });

            $this->test('Exported product has variants array', function () use ($exportedProduct) {
                return isset($exportedProduct['variants']) && is_array($exportedProduct['variants']);
            });

            $this->test('Exported variant has correct SKU', function () use ($exportedProduct, $sku) {
                $variants = $exportedProduct['variants'] ?? [];
                foreach ($variants as $v) {
                    if (($v['sku'] ?? '') === $sku . '-V1') {
                        return true;
                    }
                }
                return false;
            });

            $this->test('Exported variant price matches', function () use ($exportedProduct) {
                $variants = $exportedProduct['variants'] ?? [];
                foreach ($variants as $v) {
                    if (abs((float)($v['price'] ?? 0) - 29.99) < 0.001) {
                        return true;
                    }
                }
                return false;
            });
        }

        // ---- Step 3: Re-import (idempotency) ----
        echo "\n--- Step 3: Re-import (idempotency) ---\n";

        if ($exportedProduct !== null) {
            $this->test('Re-importing exported data does not crash', function () use ($importModel, $exportedProduct) {
                // Re-import with update_existing option — must not throw
                $result = $importModel->importProductFull($exportedProduct, ['update_existing' => true]);
                return is_array($result) && ($result['success'] ?? false) === true;
            });
        }

        // ---- Cleanup ----
        echo "\n--- Cleanup ---\n";
        $this->cleanup($articleId, $productId);

        echo "\n=== Round-Trip Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function cleanup(int $articleId, int $productId): void
    {
        $pkProd = $this->tp . '_product_id';
        $pkVar  = $this->tp . '_variant_id';

        try {
            // Delete variants
            $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
                ->delete($this->db->quoteName('#__' . $this->tp . '_variants'))
                ->where($this->db->quoteName('product_id') . ' = ' . $productId);
            $this->db->setQuery($q)->execute();

            // Delete product
            $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
                ->delete($this->db->quoteName('#__' . $this->tp . '_products'))
                ->where($this->db->quoteName($pkProd) . ' = ' . $productId);
            $this->db->setQuery($q)->execute();

            // Delete article
            $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
                ->delete($this->db->quoteName('#__content'))
                ->where($this->db->quoteName('id') . ' = ' . $articleId);
            $this->db->setQuery($q)->execute();

            echo "Cleanup: removed article $articleId, product $productId\n";
        } catch (\Throwable $e) {
            echo "Cleanup warning: " . $e->getMessage() . "\n";
        }
    }
}

$test = new RoundTripTest();
exit($test->run() ? 0 : 1);
