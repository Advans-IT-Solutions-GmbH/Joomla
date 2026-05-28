<?php
/**
 * Export Model Tests for J2Commerce Import/Export
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

class ExportModelTest
{
    private int $passed = 0;
    private int $failed = 0;

    private function test(string $name, callable $fn): void
    {
        try {
            $result = $fn();
            if ($result) {
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

    public function run(): bool
    {
        echo "=== Export Model Tests ===\n\n";

        $rc = new ReflectionClass(ExportModel::class);

        // --- Class structure via reflection ---
        $this->test('ExportModel uses J2CommerceAwareTrait', function () use ($rc) {
            foreach (array_keys($rc->getTraits()) as $t) {
                if (str_ends_with($t, 'J2CommerceAwareTrait')) return true;
            }
            return false;
        });

        $this->test('exportData() is public', function () use ($rc) {
            return $rc->hasMethod('exportData') && $rc->getMethod('exportData')->isPublic();
        });

        foreach (['exportProducts', 'exportCategories', 'exportVariants', 'exportPrices',
                  'exportProductsFull', 'getProductImages', 'getProductOptions',
                  'getProductFilters', 'getArticleCustomFields'] as $method) {
            $this->test("$method() exists", function () use ($rc, $method) {
                return $rc->hasMethod($method);
            });
        }

        // --- Runtime: round-trip with seeded fixture ---
        // newInstanceWithoutConstructor avoids BaseDatabaseModel::__construct() calling Factory::getApplication()
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $model = $rc->newInstanceWithoutConstructor();
        $model->setDatabase($db);

        // Detect stack (J4/J5 vs J6) by checking for j2commerce_products table
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $isJ6   = in_array($prefix . 'j2commerce_products', $tables, true);
        $tp     = $isJ6 ? 'j2commerce' : 'j2store';
        $pkProd = $isJ6 ? 'j2commerce_product_id' : 'j2store_product_id';
        $pkVar  = $isJ6 ? 'j2commerce_variant_id'  : 'j2store_variant_id';

        // Seed: #__content article (required FK for products)
        $article = (object)[
            'title'      => 'Export Test Product',
            'alias'      => 'export-test-product-' . uniqid(),
            'introtext'  => '',
            'fulltext'   => '',
            'state'      => 1,
            'catid'      => 2,
            'language'   => '*',
            'access'     => 1,
            'created'    => date('Y-m-d H:i:s'),
            'created_by' => 42,
            'modified'   => date('Y-m-d H:i:s'),
            'publish_up' => date('Y-m-d H:i:s'),
            'attribs'    => '{}',
            'metadata'   => '{}',
            'images'     => '{}',
            'urls'       => '{}',
        ];
        $db->insertObject('#__content', $article, 'id');
        $articleId = (int) $db->insertid();

        // Seed: product row
        $product = (object)[
            'product_source_id' => $articleId,
            'product_source'    => 'com_content',
            'enabled'           => 1,
            'taxprofile_id'     => 0,
            'vendor_id'         => 0,
            'params'            => '{}',
        ];
        $db->insertObject('#__' . $tp . '_products', $product, $pkProd);
        $productId = (int) $db->insertid();

        // Seed: variant row (master variant)
        $variant = (object)[
            'product_id' => $productId,
            'sku'        => 'EXPORT-TEST-' . $productId,
            'price'      => 9.99,
            'is_master'  => 1,
            'enabled'    => 1,
            'params'     => '{}',
        ];
        $db->insertObject('#__' . $tp . '_variants', $variant, $pkVar);
        $variantId = (int) $db->insertid();

        // --- exportData('products') round-trip ---
        $this->test("exportData('products') returns array", function () use ($model) {
            return is_array($model->exportData('products'));
        });

        $this->test('exportData(products) contains seeded product', function () use ($model, $productId, $pkProd) {
            $rows = $model->exportData('products');
            foreach ($rows as $row) {
                if ((int)($row[$pkProd] ?? 0) === $productId) return true;
            }
            return false;
        });

        // --- exportData('variants') round-trip ---
        $this->test("exportData('variants') returns array", function () use ($model) {
            return is_array($model->exportData('variants'));
        });

        $this->test('exportData(variants) contains seeded variant', function () use ($model, $variantId, $pkVar) {
            $rows = $model->exportData('variants');
            foreach ($rows as $row) {
                if ((int)($row[$pkVar] ?? 0) === $variantId) return true;
            }
            return false;
        });

        // --- exportData('categories') and exportData('prices') return arrays ---
        foreach (['categories', 'prices'] as $type) {
            $this->test("exportData('$type') returns array", function () use ($model, $type) {
                return is_array($model->exportData($type));
            });
        }

        // --- exportData() throws on unknown type ---
        $this->test('exportData() throws on unknown type', function () use ($model) {
            try {
                $model->exportData('__invalid__');
                return false;
            } catch (\Exception $e) {
                return true;
            }
        });

        // --- Cleanup fixture ---
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__' . $tp . '_variants')  . ' WHERE ' . $db->quoteName($pkVar)  . ' = ' . $variantId);
        $db->execute();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__' . $tp . '_products')  . ' WHERE ' . $db->quoteName($pkProd) . ' = ' . $productId);
        $db->execute();
        $db->setQuery('DELETE FROM ' . $db->quoteName('#__content') . ' WHERE id = ' . $articleId);
        $db->execute();

        echo "\n=== Export Model Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ExportModelTest();
exit($test->run() ? 0 : 1);
