<?php
/**
 * Export Controller Tests for J2Commerce Import/Export
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

use Advans\Component\J2CommerceImportExport\Administrator\Controller\ExportController;

class ExportControllerTest
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
        echo "=== Export Controller Tests ===\n\n";

        $rc = new ReflectionClass(ExportController::class);

        // --- Class structure via reflection ---
        $this->test('ExportController extends BaseController', function () use ($rc) {
            $parent = $rc->getParentClass();
            return $parent && str_ends_with($parent->getName(), 'BaseController');
        });

        $this->test('export() method exists and is public', function () use ($rc) {
            return $rc->hasMethod('export') && $rc->getMethod('export')->isPublic();
        });

        $this->test('getFieldDescriptions() exists', function () use ($rc) {
            return $rc->hasMethod('getFieldDescriptions');
        });

        foreach (['exportCSV', 'exportXML', 'exportJSON'] as $method) {
            $this->test("$method() exists", function () use ($rc, $method) {
                return $rc->hasMethod($method);
            });
        }

        // --- getFieldDescriptions() returns a non-empty array ---
        // Instantiate via reflection to bypass constructor DI requirements
        $controller = $rc->newInstanceWithoutConstructor();
        $method = $rc->getMethod('getFieldDescriptions');
        $method->setAccessible(true);

        $this->test('getFieldDescriptions() returns non-empty array', function () use ($controller, $method) {
            $result = $method->invoke($controller);
            return is_array($result) && count($result) > 0;
        });

        $this->test('getFieldDescriptions() entries are non-empty strings', function () use ($controller, $method) {
            $result = $method->invoke($controller);
            if (empty($result)) return false;
            foreach ($result as $key => $desc) {
                if (!is_string($key) || !is_string($desc) || $desc === '') return false;
            }
            return true;
        });

        echo "\n=== Export Controller Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ExportControllerTest();
exit($test->run() ? 0 : 1);
