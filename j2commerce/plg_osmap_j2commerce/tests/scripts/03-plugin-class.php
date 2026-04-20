<?php
/**
 * Plugin Class Tests for OSMap J2Commerce Plugin
 *
 * Tests class structure without instantiation — Base::__construct() calls
 * admin/include.php which triggers PluginHelper::importPlugin() ->
 * Factory::getApplication(), which is not available in CLI context.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
Factory::getDbo();

// Load allediaframework (installed by OSMap at libraries/allediaframework/)
$allediaFramework = JPATH_LIBRARIES . '/allediaframework/include.php';
if (!is_file($allediaFramework)) {
    echo "FATAL: allediaframework not found at {$allediaFramework}\n";
    exit(1);
}
require_once $allediaFramework;

// Register Alledia\OSMap namespace directly — avoids admin/include.php
// which calls PluginHelper::importPlugin() -> Factory::getApplication() (CLI-unsafe)
use Alledia\Framework\AutoLoader;
$osmapLibrary = JPATH_ADMINISTRATOR . '/components/com_osmap/library';
if (!is_dir($osmapLibrary)) {
    echo "FATAL: OSMap library not found at {$osmapLibrary}\n";
    exit(1);
}
AutoLoader::register('Alledia', $osmapLibrary . '/Alledia');

// Register plugin's PSR-4 namespace (composer not run after JOOMLA_EXTENSIONS_PATHS)
spl_autoload_register(function (string $class): void {
    $prefix = 'Advans\\Plugin\\Osmap\\J2Commerce\\';
    $base   = JPATH_PLUGINS . '/osmap/j2commerce/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

class PluginClassTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool
    {
        echo "=== Plugin Class Tests ===\n\n";

        $this->test('Plugin file is loadable', function () {
            require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';
            return class_exists('PlgOsmapJ2commerce');
        });

        $this->test('Plugin extends Alledia\\OSMap\\Plugin\\Base', function () {
            return is_subclass_of('PlgOsmapJ2commerce', 'Alledia\\OSMap\\Plugin\\Base');
        });

        $this->test('getTree() method exists', function () {
            return method_exists('PlgOsmapJ2commerce', 'getTree');
        });

        $this->test('getComponentElement() method exists', function () {
            return method_exists('PlgOsmapJ2commerce', 'getComponentElement');
        });

        $this->test('getComponentElement() returns com_j2store (source check)', function () {
            // Cannot instantiate: Base::__construct() calls admin/include.php ->
            // PluginHelper::importPlugin() -> Factory::getApplication() (CLI-unsafe).
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return str_contains($src, "return 'com_j2store'");
        });

        echo "\n=== Plugin Class Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new PluginClassTest();
exit($test->run() ? 0 : 1);
