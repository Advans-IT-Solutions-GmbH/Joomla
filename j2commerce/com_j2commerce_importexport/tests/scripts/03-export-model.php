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

        // --- Runtime: real DB calls ---
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $model = new ExportModel([], null);
        $model->setDatabase($db);

        foreach (['products', 'categories', 'variants', 'prices'] as $type) {
            $this->test("exportData('$type') returns array", function () use ($model, $type) {
                return is_array($model->exportData($type));
            });
        }

        $this->test('exportData() throws on unknown type', function () use ($model) {
            try {
                $model->exportData('__invalid__');
                return false;
            } catch (\Exception $e) {
                return true;
            }
        });

        echo "\n=== Export Model Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ExportModelTest();
exit($test->run() ? 0 : 1);
