<?php
/**
 * Plugin Class Tests for OSMap J2Commerce Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

// Use DI container instead of deprecated Factory::getDbo()
Factory::getContainer()->get(DatabaseInterface::class);

// Register plugin's PSR-4 namespace
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

        $this->test('Plugin entry point is loadable', function () {
            require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';
            return class_exists('PlgOsmapJ2commerce');
        });

        $this->test('PlgOsmapJ2commerce extends CMSPlugin', function () {
            return is_subclass_of('PlgOsmapJ2commerce', 'Joomla\\CMS\\Plugin\\CMSPlugin');
        });

        $this->test('getTree() method exists', function () {
            return method_exists('PlgOsmapJ2commerce', 'getTree');
        });

        $this->test('getComponentElement() method exists', function () {
            return method_exists('PlgOsmapJ2commerce', 'getComponentElement');
        });

        $this->test('J2Commerce handles com_j2store', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return str_contains($src, "com_j2store");
        });

        $this->test('J2CommerceNew class exists', function () {
            return class_exists('Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2CommerceNew');
        });

        $this->test('J2CommerceNew extends J2Commerce', function () {
            return is_subclass_of(
                'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2CommerceNew',
                'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2Commerce'
            );
        });

        $this->test('J2CommerceNew handles com_j2commerce', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2CommerceNew.php');
            return str_contains($src, "com_j2commerce");
        });

        $this->test('J2CommerceNew uses j2commerce_products table', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2CommerceNew.php');
            return str_contains($src, 'j2commerce_products');
        });

        $this->test('getTree() handles view=products', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return str_contains($src, "'products'") && str_contains($src, 'emitProductsForCategory');
        });

        $this->test('getTree() handles view=product', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return str_contains($src, "'product'") && str_contains($src, 'emitSingleProduct');
        });

        $this->test('getTree() handles view=categories', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return str_contains($src, "'categories'") && str_contains($src, 'emitAllProducts');
        });

        $this->test('No published=-2 dependency in plugin source', function () {
            $src = file_get_contents(JPATH_PLUGINS . '/osmap/j2commerce/src/Extension/J2Commerce.php');
            return !str_contains($src, 'published = -2') && !str_contains($src, "published=-2");
        });

        echo "\n=== Plugin Class Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "PASS {$name}\n";
                $this->passed++;
            } else {
                echo "FAIL {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "FAIL {$name} — {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new PluginClassTest();
exit($test->run() ? 0 : 1);
