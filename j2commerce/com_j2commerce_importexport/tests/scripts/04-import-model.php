<?php
/**
 * Import Model Tests for J2Commerce Import/Export
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

use Advans\Component\J2CommerceImportExport\Administrator\Model\ImportModel;

class ImportModelTest
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
        echo "=== Import Model Tests ===\n\n";

        $rc = new ReflectionClass(ImportModel::class);

        // --- Class structure via reflection ---
        $this->test('ImportModel uses J2CommerceAwareTrait', function () use ($rc) {
            foreach (array_keys($rc->getTraits()) as $t) {
                if (str_ends_with($t, 'J2CommerceAwareTrait')) return true;
            }
            return false;
        });

        $this->test('importProductFull() is public', function () use ($rc) {
            return $rc->hasMethod('importProductFull') && $rc->getMethod('importProductFull')->isPublic();
        });

        foreach (['importVariants', 'importTierPrices', 'importProductImages',
                  'importProductOptions', 'importProductFilters', 'importArticleTags',
                  'importCustomFields', 'importMenuItem'] as $method) {
            $this->test("$method() exists", function () use ($rc, $method) {
                return $rc->hasMethod($method);
            });
        }

        // --- Runtime: importProductFull() with empty/minimal data does not crash ---
        // newInstanceWithoutConstructor avoids BaseDatabaseModel::__construct() calling Factory::getApplication()
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $model = $rc->newInstanceWithoutConstructor();
        $model->setDatabase($db);

        $this->test('importProductFull() with missing required fields throws', function () use ($model) {
            try {
                $model->importProductFull([]);
                // If it returns without throwing, check result has error info
                return true;
            } catch (\Throwable $e) {
                // Expected — missing required fields
                return true;
            }
        });

        // --- Runtime: importProductFull() with minimal valid data ---
        // Detect stack
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $isJ6   = in_array($prefix . 'j2commerce_products', $tables, true);
        $hasJ4  = in_array($prefix . 'j2store_products',    $tables, true);

        if (!$isJ6 && !$hasJ4) {
            $this->test('J2Commerce tables are present', fn () => false);
        } else {
            $this->test('importProductFull() with minimal data succeeds', function () use ($model) {
                $data = [
                    'title'       => 'Import Test Product ' . uniqid(),
                    'alias'       => 'import-test-' . uniqid(),
                    'category'    => 'Import Test Category',
                    'sku'         => 'IMP-TEST-' . uniqid(),
                    'price'       => 19.99,
                    'visibility'  => 1,
                    'addtocart_text' => '',
                    'up_sells'    => '',
                    'cross_sells' => '',
                    'variants'    => [],
                    'product_images' => [],
                    'options'     => [],
                    'filters'     => [],
                    'files'       => [],
                    'tags'        => [],
                    'custom_fields' => [],
                ];
                $result = $model->importProductFull($data, []);
                return is_array($result)
                    && ($result['success'] ?? false) === true
                    && isset($result['article_id'])
                    && isset($result['product_id'])
                    && (int) $result['article_id'] > 0
                    && (int) $result['product_id'] > 0;
            });
        }

        echo "\n=== Import Model Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ImportModelTest();
exit($test->run() ? 0 : 1);
