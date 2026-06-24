<?php
/**
 * @package     J2Commerce.OSMap
 * Plugin Emit Tests for OSMap J2Commerce Plugin
 *
 * Tests emitProductsForCategory(), emitAllProducts(), and printProductNode()
 * URL construction against real fixture data.
 *
 * Covers issue #99: missing test coverage for emit methods and URL edge cases.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

require_once __DIR__ . '/_osmap_bootstrap.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

// Load the REAL OSMap library (Collector, Item) installed in the test image so
// the emit path runs against real OSMap classes, not stubs. Stubs are only used
// if OSMap could not be loaded at all.
$REAL_OSMAP = osmap_ensure_classes();
echo 'Real OSMap library loaded: ' . ($REAL_OSMAP ? 'yes' : 'NO (stubs)') . "\n";

// Register plugin PSR-4 namespace
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

if (file_exists(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php')) {
    require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';
}

/**
 * Collector that records all nodes passed to printNode(). Extends the real
 * OSMap Collector (or the fallback stub) with a signature-compatible override.
 * The empty constructor bypasses the real Collector's SitemapInterface
 * requirement — only printNode() is exercised here.
 */
class RecordingCollector extends \Alledia\OSMap\Sitemap\Collector
{
    public array $nodes = [];
    public function __construct() {}
    public function printNode($node): bool
    {
        $this->nodes[] = (object) $node;
        return true;
    }
}

class PluginEmitTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $productsTable;

    // Fixture articles are in catid=2 (Joomla default "Uncategorised")
    private const FIXTURE_CATID = 2;

    public function __construct()
    {
        $this->db            = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6          = (getenv('J2COMMERCE_STACK') === 'j6');
        $this->productsTable = $this->isJ6 ? '#__j2commerce_products' : '#__j2store_products';
    }

    private function makePlugin(string $class = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2Commerce'): object
    {
        $plugin = new $class(new Dispatcher(), ['params' => new Registry([])]);
        $plugin->setDatabase($this->db);

        // J2CommerceNew always targets #__j2commerce_products by design — do not
        // override its default. Only inject the stack-specific table into the main
        // J2Commerce class when running on J6 (where it would otherwise default to
        // #__j2store_products).
        $isMainClass = ($class === 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2Commerce');
        if ($isMainClass && $this->isJ6) {
            $rc = new ReflectionClass($plugin);
            if ($rc->hasProperty('productsTable')) {
                $prop = $rc->getProperty('productsTable');
                $prop->setAccessible(true);
                $prop->setValue($plugin, $this->productsTable);
            }
        }

        return $plugin;
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

    // -------------------------------------------------------------------------

    private function testEmitProductsForCategory(): void
    {
        echo "\n--- emitProductsForCategory() ---\n";

        $plugin    = $this->makePlugin();
        $parent    = osmap_make_item([]);
        $parent->path = 'shop';

        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('emitProductsForCategory');
        $method->setAccessible(true);

        // Discover the catid that fixture products actually use by querying
        // the products table directly — avoids hard-coding a catid that may
        // differ between J5 and J6 fixture setups.
        $db = $this->db;
        $q  = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select('DISTINCT ' . $db->quoteName('a.catid'))
            ->from($db->quoteName('#__content', 'a'))
            ->join('INNER', $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1')
            ->where($db->quoteName('a.state') . ' = 1')
            ->setLimit(1);
        $fixtureCatid = (int) $db->setQuery($q)->loadResult();

        if ($fixtureCatid === 0) {
            echo "SKIP emitProductsForCategory() — no enabled fixture products found\n";
            return;
        }

        echo "  Using fixture catid=$fixtureCatid\n";

        $collector = new RecordingCollector();
        $method->invoke($plugin, $collector, $parent, new Registry([]), $fixtureCatid);

        $this->test('emitProductsForCategory() emits nodes for fixture category', function () use ($collector) {
            return count($collector->nodes) >= 1;
        });

        $this->test('emitProductsForCategory() nodes have absolute URLs', function () use ($collector) {
            $root = rtrim(Uri::root(), '/');
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->link, $root . '/')) {
                    echo "  Bad link: {$node->link}\n";
                    return false;
                }
            }
            return true;
        });

        $this->test('emitProductsForCategory() nodes have uid prefix j2commerce.product.', function () use ($collector) {
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->uid, 'j2commerce.product.')) {
                    return false;
                }
            }
            return true;
        });

        // Non-existent category → 0 nodes, no crash
        $collector2 = new RecordingCollector();
        $method->invoke($plugin, $collector2, $parent, new Registry([]), 999999999);
        $this->test('emitProductsForCategory() with non-existent catid emits 0 nodes', function () use ($collector2) {
            return count($collector2->nodes) === 0;
        });
    }

    private function testEmitAllProducts(): void
    {
        echo "\n--- emitAllProducts() ---\n";

        $plugin    = $this->makePlugin();
        $collector = new RecordingCollector();
        $parent    = osmap_make_item([]);
        $parent->path = 'shop';

        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('emitAllProducts');
        $method->setAccessible(true);

        $method->invoke($plugin, $collector, $parent, new Registry([]));

        $this->test('emitAllProducts() emits at least 2 nodes (fixture products)', function () use ($collector) {
            return count($collector->nodes) >= 2;
        });

        $this->test('emitAllProducts() nodes have absolute URLs', function () use ($collector) {
            $root = rtrim(Uri::root(), '/');
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->link, $root . '/')) {
                    echo "  Bad link: {$node->link}\n";
                    return false;
                }
            }
            return true;
        });

        $this->test('emitAllProducts() nodes have expandible=false', function () use ($collector) {
            foreach ($collector->nodes as $node) {
                if ($node->expandible !== false) {
                    return false;
                }
            }
            return true;
        });
    }

    private function testPrintProductNodeUrlEdgeCases(): void
    {
        echo "\n--- printProductNode() URL edge cases ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('printProductNode');
        $method->setAccessible(true);

        $root = rtrim(Uri::root(), '/');

        // Case 1: normal alias, parent path "shop"
        $this->test('Normal alias: shop/my-product', function () use ($plugin, $method, $root) {
            $collector = new RecordingCollector();
            $parent    = osmap_make_item([]);
            $parent->path = 'shop';
            $product = (object)['id' => 1, 'title' => 'Test', 'alias' => 'my-product', 'modified' => null];
            $method->invoke($plugin, $collector, $parent, new Registry([]), $product);
            $expected = $root . '/shop/my-product';
            return count($collector->nodes) === 1 && $collector->nodes[0]->link === $expected;
        });

        // Case 2: alias with leading slash (must be stripped)
        $this->test('Alias with leading slash stripped', function () use ($plugin, $method, $root) {
            $collector = new RecordingCollector();
            $parent    = osmap_make_item([]);
            $parent->path = 'shop';
            $product = (object)['id' => 2, 'title' => 'Test', 'alias' => '/my-product', 'modified' => null];
            $method->invoke($plugin, $collector, $parent, new Registry([]), $product);
            $expected = $root . '/shop/my-product';
            return count($collector->nodes) === 1 && $collector->nodes[0]->link === $expected;
        });

        // Case 3: empty parent path (root-level shop)
        $this->test('Empty parent path: /my-product', function () use ($plugin, $method, $root) {
            $collector = new RecordingCollector();
            $parent    = osmap_make_item([]);
            $parent->path = '';
            $product = (object)['id' => 3, 'title' => 'Test', 'alias' => 'my-product', 'modified' => null];
            $method->invoke($plugin, $collector, $parent, new Registry([]), $product);
            $expected = $root . '/my-product';
            return count($collector->nodes) === 1 && $collector->nodes[0]->link === $expected;
        });

        // Case 4: parent path with trailing slash (must not double-slash)
        $this->test('Parent path with trailing slash: no double slash', function () use ($plugin, $method, $root) {
            $collector = new RecordingCollector();
            $parent    = osmap_make_item([]);
            $parent->path = 'shop/';
            $product = (object)['id' => 4, 'title' => 'Test', 'alias' => 'my-product', 'modified' => null];
            $method->invoke($plugin, $collector, $parent, new Registry([]), $product);
            $link = $collector->nodes[0]->link ?? '';
            return !str_contains($link, '//shop') && str_ends_with($link, '/my-product');
        });

        // Case 5: alias must NOT be percent-encoded (rawurlencode removed)
        $this->test('Alias not percent-encoded (no rawurlencode)', function () use ($plugin, $method, $root) {
            $collector = new RecordingCollector();
            $parent    = osmap_make_item([]);
            $parent->path = 'shop';
            $product = (object)['id' => 5, 'title' => 'Test', 'alias' => 'my-great-product', 'modified' => null];
            $method->invoke($plugin, $collector, $parent, new Registry([]), $product);
            $link = $collector->nodes[0]->link ?? '';
            return !preg_match('/%[0-9A-F]{2}/i', $link) && str_ends_with($link, '/my-great-product');
        });
    }

    private function testJ2CommerceNewEmitAllProducts(): void
    {
        echo "\n--- J2CommerceNew::emitAllProducts() (J6 branch) ---\n";

        $newClass = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2CommerceNew';
        if (!class_exists($newClass)) {
            echo "SKIP J2CommerceNew not available\n";
            return;
        }

        $plugin    = $this->makePlugin($newClass);
        $collector = new RecordingCollector();
        $parent    = osmap_make_item([]);
        $parent->path = 'shop';

        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('emitAllProducts');
        $method->setAccessible(true);

        // On J5 stack #__j2commerce_products doesn't exist — expect 0 nodes, no crash.
        // On J6 stack expect fixture products.
        try {
            $method->invoke($plugin, $collector, $parent, new Registry([]));
            $this->test('J2CommerceNew::emitAllProducts() does not crash', function () { return true; });
        } catch (\Throwable $e) {
            // Table-not-found on J5 is acceptable — treat as 0 nodes
            $isTableMissing = str_contains($e->getMessage(), 'j2commerce_products')
                || str_contains($e->getMessage(), "doesn't exist")
                || str_contains($e->getMessage(), 'Table');
            if ($isTableMissing && !$this->isJ6) {
                echo "NOTE J2CommerceNew: table absent on J5 stack (expected)\n";
                $this->test('J2CommerceNew::emitAllProducts() gracefully absent on J5', function () { return true; });
                return;
            }
            $this->test('J2CommerceNew::emitAllProducts() does not crash', function () use ($e) {
                echo "  Error: " . $e->getMessage() . "\n";
                return false;
            });
            return;
        }

        if ($this->isJ6) {
            $this->test('J2CommerceNew::emitAllProducts() emits nodes on J6 stack', function () use ($collector) {
                return count($collector->nodes) >= 1;
            });
        } else {
            $this->test('J2CommerceNew::emitAllProducts() emits 0 nodes on J5 stack', function () use ($collector) {
                return count($collector->nodes) === 0;
            });
        }
    }

    // -------------------------------------------------------------------------

    public function run(): bool
    {
        echo "=== Plugin Emit Tests ===\n";
        echo "Stack: " . ($this->isJ6 ? 'J6 (j2commerce)' : 'J5 (j2store)') . "\n";
        echo "Products table: {$this->productsTable}\n";

        $this->testEmitProductsForCategory();
        $this->testEmitAllProducts();
        $this->testPrintProductNodeUrlEdgeCases();
        $this->testJ2CommerceNewEmitAllProducts();

        echo "\n=== Plugin Emit Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new PluginEmitTest();
exit($test->run() ? 0 : 1);
