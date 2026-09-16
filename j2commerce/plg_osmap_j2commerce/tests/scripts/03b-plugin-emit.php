<?php
/**
 * @package     J2Commerce.OSMap
 * Plugin Emit Tests for OSMap J2Commerce Plugin
 *
 * Exercises the product emit paths (category list, full list, product URL
 * construction) through the plugin's public OSMap entry point getTree(), the
 * method OSMap itself calls for every matching menu item, against real fixture
 * data.
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
    private const MAIN_CLASS = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2Commerce';
    private const NEW_CLASS  = 'Advans\\Plugin\\Osmap\\J2Commerce\\Extension\\J2CommerceNew';

    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $productsTable;

    public function __construct()
    {
        $this->db            = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6          = (getenv('J2COMMERCE_STACK') === 'j6');
        $this->productsTable = $this->isJ6 ? '#__j2commerce_products' : '#__j2store_products';
    }

    /**
     * The plugin class that handles the shop component of this stack: the
     * J2Store class reads #__j2store_products, the J2Commerce 6 subclass reads
     * #__j2commerce_products. No property is changed from outside.
     */
    private function stackClass(): string
    {
        return $this->isJ6 ? self::NEW_CLASS : self::MAIN_CLASS;
    }

    private function makePlugin(?string $class = null): object
    {
        $class  = $class ?? $this->stackClass();
        $plugin = new $class(new Dispatcher(), ['params' => new Registry([])]);
        $plugin->setDatabase($this->db);

        return $plugin;
    }

    /**
     * A plugin whose product loading returns the given rows. Only the data
     * source is replaced (protected loadProducts()); getTree() and the URL
     * construction in printProductNode() run unchanged. This is the only way
     * to feed aliases that a real article cannot have (e.g. a leading slash)
     * without reaching into private members.
     *
     * @param object[] $products
     */
    private function makePluginWithProducts(array $products): object
    {
        $plugin = new class (new Dispatcher(), ['params' => new Registry([])]) extends \Advans\Plugin\Osmap\J2Commerce\Extension\J2Commerce {
            /** @var object[] */
            public array $fixtureProducts = [];

            protected function loadProducts(?int $catid): array
            {
                return $this->fixtureProducts;
            }
        };
        $plugin->setDatabase($this->db);
        $plugin->fixtureProducts = $products;

        return $plugin;
    }

    /**
     * The OSMap parent menu item for a J2Store/J2Commerce list view. id 0 keeps
     * the hidden-menu-children mechanism out of these tests (covered in
     * 04-sitemap-output.php / 06-sitemap-http.php).
     */
    private function makeParent(object $plugin, string $query, string $path): object
    {
        return osmap_make_item([
            'id'         => 0,
            'link'       => 'index.php?option=' . $plugin->getComponentElement() . ($query !== '' ? '&' . $query : ''),
            'path'       => $path,
            'language'   => '*',
            'browserNav' => 0,
        ]);
    }

    private function collect(object $plugin, object $parent): RecordingCollector
    {
        $collector = new RecordingCollector();
        $plugin->getTree($collector, $parent, new Registry([]));

        return $collector;
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

    private function testCategoryList(): void
    {
        echo "\n--- getTree(view=products&catid=…) → products of one category ---\n";

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
            if (getenv('TEST_STRICT_SKIP') === '1') {
                $this->test('fixture products available for the category list', fn () => false);
            } else {
                echo "SKIP category list — no enabled fixture products found\n";
            }
            return;
        }

        echo "  Using fixture catid=$fixtureCatid\n";

        $plugin    = $this->makePlugin();
        $collector = $this->collect($plugin, $this->makeParent($plugin, 'view=products&catid=' . $fixtureCatid, 'shop'));

        $this->test('category list emits nodes for fixture category', function () use ($collector) {
            return count($collector->nodes) >= 1;
        });

        $this->test('category list nodes have absolute URLs', function () use ($collector) {
            $root = rtrim(Uri::root(), '/');
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->link, $root . '/')) {
                    echo "  Bad link: {$node->link}\n";
                    return false;
                }
            }
            return true;
        });

        $this->test('category list nodes have uid prefix j2commerce.product.', function () use ($collector) {
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->uid, 'j2commerce.product.')) {
                    return false;
                }
            }
            return true;
        });

        // Non-existent category → 0 nodes, no crash
        $collector2 = $this->collect($plugin, $this->makeParent($plugin, 'view=products&catid=999999999', 'shop'));
        $this->test('category list with non-existent catid emits 0 nodes', function () use ($collector2) {
            return count($collector2->nodes) === 0;
        });
    }

    private function testFullList(): void
    {
        echo "\n--- getTree(view=products) → all products ---\n";

        $plugin    = $this->makePlugin();
        $collector = $this->collect($plugin, $this->makeParent($plugin, 'view=products', 'shop'));

        $this->test('full list emits at least 2 nodes (fixture products)', function () use ($collector) {
            return count($collector->nodes) >= 2;
        });

        $this->test('full list nodes have absolute URLs', function () use ($collector) {
            $root = rtrim(Uri::root(), '/');
            foreach ($collector->nodes as $node) {
                if (!str_starts_with($node->link, $root . '/')) {
                    echo "  Bad link: {$node->link}\n";
                    return false;
                }
            }
            return true;
        });

        $this->test('full list nodes have expandible=false', function () use ($collector) {
            foreach ($collector->nodes as $node) {
                if ($node->expandible !== false) {
                    return false;
                }
            }
            return true;
        });
    }

    private function testProductUrlEdgeCases(): void
    {
        echo "\n--- Product URL edge cases (getTree → printProductNode) ---\n";

        $root = rtrim(Uri::root(), '/');

        $linkFor = function (string $parentPath, string $alias, int $id): array {
            $plugin    = $this->makePluginWithProducts([
                (object) ['id' => $id, 'title' => 'Test', 'alias' => $alias, 'modified' => null, 'catid' => 2, 'language' => '*'],
            ]);
            $collector = $this->collect($plugin, $this->makeParent($plugin, 'view=products', $parentPath));

            return array_map(static fn (object $node): string => (string) $node->link, $collector->nodes);
        };

        // Case 1: normal alias, parent path "shop"
        $this->test('Normal alias: shop/my-product', function () use ($linkFor, $root) {
            return $linkFor('shop', 'my-product', 1) === [$root . '/shop/my-product'];
        });

        // Case 2: alias with leading slash (must be stripped)
        $this->test('Alias with leading slash stripped', function () use ($linkFor, $root) {
            return $linkFor('shop', '/my-product', 2) === [$root . '/shop/my-product'];
        });

        // Case 3: empty parent path (root-level shop)
        $this->test('Empty parent path: /my-product', function () use ($linkFor, $root) {
            return $linkFor('', 'my-product', 3) === [$root . '/my-product'];
        });

        // Case 4: parent path with trailing slash (must not double-slash)
        $this->test('Parent path with trailing slash: no double slash', function () use ($linkFor, $root) {
            return $linkFor('shop/', 'my-product', 4) === [$root . '/shop/my-product'];
        });

        // Case 5: alias must NOT be percent-encoded (rawurlencode removed)
        $this->test('Alias not percent-encoded (no rawurlencode)', function () use ($linkFor) {
            $links = $linkFor('shop', 'my-great-product', 5);
            return count($links) === 1
                && !preg_match('/%[0-9A-F]{2}/i', $links[0])
                && str_ends_with($links[0], '/my-great-product');
        });

        // Case 6: the same product twice in one run is emitted once
        $this->test('Duplicate product emitted once per getTree() run', function () {
            $product   = (object) ['id' => 6, 'title' => 'Test', 'alias' => 'dup', 'modified' => null, 'catid' => 2, 'language' => '*'];
            $plugin    = $this->makePluginWithProducts([$product, clone $product]);
            $collector = $this->collect($plugin, $this->makeParent($plugin, 'view=products', 'shop'));
            return count($collector->nodes) === 1;
        });
    }

    private function testJ2CommerceNewFullList(): void
    {
        echo "\n--- J2CommerceNew getTree(view=products) (J6 branch) ---\n";

        if (!class_exists(self::NEW_CLASS)) {
            $this->test('J2CommerceNew class available', fn () => false);
            return;
        }

        $plugin = $this->makePlugin(self::NEW_CLASS);

        $this->test('J2CommerceNew handles com_j2commerce', function () use ($plugin) {
            return $plugin->getComponentElement() === 'com_j2commerce';
        });

        // On the J5 stack #__j2commerce_products does not exist: the plugin logs
        // the query error and emits nothing. On J6 it emits the fixture products.
        $collector = null;
        $this->test('J2CommerceNew getTree() does not throw', function () use ($plugin, &$collector) {
            $collector = $this->collect($plugin, $this->makeParent($plugin, 'view=products', 'shop'));
            return true;
        });

        if ($collector === null) {
            return;
        }

        if ($this->isJ6) {
            $this->test('J2CommerceNew emits nodes on J6 stack', function () use ($collector) {
                return count($collector->nodes) >= 1;
            });
        } else {
            $this->test('J2CommerceNew emits 0 nodes on J5 stack', function () use ($collector) {
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
        echo "Plugin class: " . $this->stackClass() . "\n";

        $this->testCategoryList();
        $this->testFullList();
        $this->testProductUrlEdgeCases();
        $this->testJ2CommerceNewFullList();

        echo "\n=== Plugin Emit Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new PluginEmitTest();
exit($test->run() ? 0 : 1);
