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

// Stubs for OSMap classes that are not installed in the test container.
// emitSingleProduct() type-hints Item and Collector but only uses $parent
// when a product is found — with a non-existent article ID (999999999) the
// stubs are never accessed beyond satisfying the type check.
// class_alias() cannot alias internal classes (stdClass), so we define
// minimal user-defined stubs instead.
if (!class_exists(\Alledia\OSMap\Sitemap\Item::class)) {
    // @phpstan-ignore-next-line
    eval('namespace Alledia\OSMap\Sitemap; class Item { public $path = ""; public $browserNav = 0; }');
}
if (!class_exists(\Alledia\OSMap\Sitemap\Collector::class)) {
    // @phpstan-ignore-next-line
    eval('namespace Alledia\OSMap\Sitemap; class Collector { public function printNode(object $node): void {} }');
}

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

        // --- Reflection: method existence on J2Commerce ---
        $j2cClass = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2Commerce';
        foreach ([
            'getTree',
            'emitSingleProduct',
            'emitProductsForCategory',
            'emitAllProducts',
            'printProductNode',
            'printMenuPathNode',
            'loadProducts',
            'createDbQuery',
        ] as $method) {
            $this->test("J2Commerce::$method() exists", function () use ($j2cClass, $method) {
                return method_exists($j2cClass, $method);
            });
        }

        // --- Reflection: method existence on J2CommerceNew ---
        $newClass = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2CommerceNew';
        $this->test('J2CommerceNew inherits emitSingleProduct()', function () use ($newClass) {
            if (!class_exists($newClass)) return false;
            $rc = new ReflectionClass($newClass);
            // Method must exist (inherited or overridden) and be callable
            return $rc->hasMethod('emitSingleProduct');
        });

        $this->test('J2CommerceNew::emitSingleProduct() uses j2commerce_products table', function () use ($newClass) {
            if (!class_exists($newClass)) return false;
            $rc  = new ReflectionClass($newClass);
            $obj = $rc->newInstanceWithoutConstructor();
            // productsTable property must be set to #__j2commerce_products
            $prop = $rc->getProperty('productsTable');
            $prop->setAccessible(true);
            return $prop->getValue($obj) === '#__j2commerce_products';
        });

        // --- emitSingleProduct() round-trip: real DB query, no crash ---
        $this->test('emitSingleProduct() returns null for non-existent article (no crash)', function () use ($j2cClass) {
            $db         = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $dispatcher = new \Joomla\Event\Dispatcher();
            $params     = new \Joomla\Registry\Registry([]);

            $plugin = new $j2cClass($dispatcher, ['params' => $params]);
            $plugin->setDatabase($db);

            // Use a mock Collector that records added nodes.
            // Must extend the stub Collector so the type hint is satisfied.
            $collector = new class extends \Alledia\OSMap\Sitemap\Collector {
                public array $nodes = [];
                public function printNode(object $node): void { $this->nodes[] = $node; }
            };

            $parent = new \Alledia\OSMap\Sitemap\Item();

            $rc     = new ReflectionClass($plugin);
            $method = $rc->getMethod('emitSingleProduct');
            $method->setAccessible(true);

            // Article ID 999999999 does not exist — must return without adding nodes
            $method->invoke($plugin, $collector, $parent, new \Joomla\Registry\Registry(), 999999999);

            return count($collector->nodes) === 0;
        });

        // --- J2CommerceNew::emitSingleProduct() round-trip ---
        $this->test('J2CommerceNew::emitSingleProduct() queries #__j2commerce_products (no crash)', function () use ($newClass) {
            $db         = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $dispatcher = new \Joomla\Event\Dispatcher();
            $params     = new \Joomla\Registry\Registry([]);

            $plugin = new $newClass($dispatcher, ['params' => $params]);
            $plugin->setDatabase($db);

            // Must extend the stub Collector so the type hint is satisfied.
            $collector = new class extends \Alledia\OSMap\Sitemap\Collector {
                public array $nodes = [];
                public function printNode(object $node): void { $this->nodes[] = $node; }
            };

            $parent = new \Alledia\OSMap\Sitemap\Item();
            $rc     = new ReflectionClass($plugin);
            $method = $rc->getMethod('emitSingleProduct');
            $method->setAccessible(true);

            // Non-existent article — must not crash even against #__j2commerce_products
            $method->invoke($plugin, $collector, $parent, new \Joomla\Registry\Registry(), 999999999);

            return count($collector->nodes) === 0;
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
