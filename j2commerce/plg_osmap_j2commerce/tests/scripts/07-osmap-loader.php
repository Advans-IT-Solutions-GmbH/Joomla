<?php
/**
 * Real OSMap Loader Tests for the OSMap J2Commerce Plugin
 *
 * Exercises the plugin the way OSMap actually does at runtime:
 *
 *   1. OSMap calls General::getPluginsForComponent($option) which queries
 *      #__extensions, builds the class name (PlgOsmapJ2commerce), requires the
 *      installed entry file, instantiates it and matches it against the menu
 *      item's component via getComponentElement() === $option.
 *   2. OSMap then dispatches getTree($collector, $parent, $params) for each
 *      matching plugin (General::callPluginsGetItemTree()).
 *
 * This test reproduces that exact loader/dispatch flow against the REAL OSMap
 * library installed in the test image (OSMap 5.1.6) — not stubs — for both:
 *   - J5 + J2Store/J2Commerce 4   (#__j2store_products, com_j2store)
 *   - J6 + J2Commerce 6           (#__j2commerce_products, com_j2commerce)
 *
 * It asserts that the real dispatch produces the correct product URLs and that
 * component matching distinguishes com_j2store from com_j2commerce.
 *
 * Covers issue #99: getTree dispatch + single-product coverage exercised
 * against the real OSMap runtime.
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
use Joomla\Registry\Registry;

osmap_boot_application();
$realOsmap = osmap_ensure_classes();

/**
 * Collector that records every node the plugin emits via printNode().
 * It is a real OSMap Collector subtype, so it satisfies getTree()'s type hint;
 * printNode() is overridden to capture nodes instead of routing them.
 */
class LoaderRecordingCollector extends \Alledia\OSMap\Sitemap\Collector
{
    /** @var object[] */
    public array $nodes = [];

    public function printNode($node): bool
    {
        $this->nodes[] = (object) $node;
        return true;
    }
}

class OsmapLoaderTest
{
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private bool $realOsmap;
    private string $option;
    private string $otherOption;
    private $db;

    public function __construct(bool $realOsmap)
    {
        $this->realOsmap   = $realOsmap;
        $this->isJ6        = (getenv('J2COMMERCE_STACK') === 'j6');
        $this->option      = $this->isJ6 ? 'com_j2commerce' : 'com_j2store';
        $this->otherOption = $this->isJ6 ? 'com_j2store' : 'com_j2commerce';
        $this->db          = Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function newCollector(): LoaderRecordingCollector
    {
        // Skip the heavy Collector constructor (needs a SitemapInterface);
        // printNode() is all the plugin uses.
        return (new \ReflectionClass(LoaderRecordingCollector::class))
            ->newInstanceWithoutConstructor();
    }

    /**
     * Reproduce OSMap's plugin matching. Prefer the REAL
     * General::getPluginsForComponent(); fall back to a faithful inline
     * replication (same DB query, class name, getComponentElement() check)
     * using the real plugin class if OSMap's full container cannot bootstrap
     * in CLI. Returns matched plugin descriptors (->instance, ->className).
     *
     * @return object[]
     */
    private function loadPluginsForComponent(string $option): array
    {
        if (class_exists('\\Alledia\\OSMap\\Helper\\General')) {
            try {
                $plugins = \Alledia\OSMap\Helper\General::getPluginsForComponent($option);
                if (is_array($plugins)) {
                    echo "  (matched via real General::getPluginsForComponent)\n";
                    return $plugins;
                }
            } catch (\Throwable $e) {
                echo "  (real General loader unavailable: {$e->getMessage()} — replicating)\n";
            }
        }

        return $this->replicatePluginMatching($option);
    }

    /**
     * Faithful replication of General::checkPluginCompatibilityWithOption()
     * for the osmap/j2commerce plugin, using the real installed entry file.
     *
     * @return object[]
     */
    private function replicatePluginMatching(string $option): array
    {
        $db = $this->db;
        $q  = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select(['folder', 'element', 'params'])
            ->from('#__extensions')
            ->where('type = ' . $db->quote('plugin'))
            ->where('folder IN (' . $db->quote('osmap') . ',' . $db->quote('xmap') . ')')
            ->where('enabled = 1')
            ->order('folder DESC, ordering');
        $rows = $db->setQuery($q)->loadObjectList() ?: [];

        $matched = [];
        foreach ($rows as $row) {
            $path = JPATH_PLUGINS . '/' . $row->folder . '/' . $row->element . '/' . $row->element . '.php';
            if (!is_file($path)) {
                continue;
            }
            $className = 'Plg' . ucfirst($row->folder) . ucfirst($row->element);
            if (!class_exists($className)) {
                require_once $path;
            }
            if (!class_exists($className)) {
                continue;
            }
            $instance = new $className(new \Joomla\Event\Dispatcher());
            if (
                method_exists($instance, 'getComponentElement')
                && $instance->getComponentElement() === $option
            ) {
                $row->instance  = $instance;
                $row->className = $className;
                $row->isLegacy  = false;
                $row->params    = new Registry($row->params);
                $matched[]      = $row;
            }
        }
        return $matched;
    }

    /**
     * Dispatch getTree() exactly the way OSMap's callPluginsGetItemTree() does.
     */
    private function dispatchGetTree(object $plugin, object $collector, object $parent, Registry $params): void
    {
        $className = '\\' . ltrim($plugin->className, '\\');
        $args      = [&$collector, &$parent, &$params];

        if (class_exists('\\Alledia\\OSMap\\Helper\\General')) {
            \Alledia\OSMap\Helper\General::callUserFunc($className, $plugin->instance, 'getTree', $args);
            return;
        }

        $plugin->instance->getTree($collector, $parent, $params);
    }

    private function findOurPlugin(array $plugins): ?object
    {
        foreach ($plugins as $plugin) {
            if (isset($plugin->className) && ltrim($plugin->className, '\\') === 'PlgOsmapJ2commerce') {
                return $plugin;
            }
        }
        return null;
    }

    public function run(): bool
    {
        echo "=== Real OSMap Loader Tests ===\n";
        echo 'Stack: ' . ($this->isJ6 ? 'J6 (com_j2commerce / #__j2commerce_products)' : 'J5 (com_j2store / #__j2store_products)') . "\n";
        echo 'Real OSMap library loaded: ' . ($this->realOsmap ? 'yes' : 'NO (stubs)') . "\n";
        echo "Component under test: {$this->option}\n\n";

        $this->test('Real OSMap Collector class is available (not a stub)', function () {
            $rc = new \ReflectionClass('\\Alledia\\OSMap\\Sitemap\\Collector');
            // Real OSMap Collector lives under the installed com_osmap library.
            return $this->realOsmap
                && str_contains((string) $rc->getFileName(), 'com_osmap');
        });

        // --- 1. Component matching via the real OSMap loader ---
        $plugins = $this->loadPluginsForComponent($this->option);
        $ourPlugin = $this->findOurPlugin($plugins);

        $this->test("OSMap loader matches PlgOsmapJ2commerce for {$this->option}", function () use ($ourPlugin) {
            return $ourPlugin !== null;
        });

        $this->test("Matched plugin instance reports getComponentElement() === {$this->option}", function () use ($ourPlugin) {
            return $ourPlugin
                && method_exists($ourPlugin->instance, 'getComponentElement')
                && $ourPlugin->instance->getComponentElement() === $this->option;
        });

        // The single-element plugin only matches one component (known OSMap
        // limitation documented in j2commerce.php). It must NOT match the other.
        $otherPlugins = $this->loadPluginsForComponent($this->otherOption);
        $this->test("OSMap loader does NOT match plugin for {$this->otherOption} (single-element)", function () use ($otherPlugins) {
            return $this->findOurPlugin($otherPlugins) === null;
        });

        if ($ourPlugin === null) {
            echo "\nFATAL: plugin not matched by loader — cannot continue dispatch tests\n";
            echo "\n=== Real OSMap Loader Test Summary ===\n";
            echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
            return false;
        }

        // --- 2. getTree() dispatch: product list view ---
        $root      = rtrim(Uri::root(), '/');
        $listLink  = 'index.php?option=' . $this->option . '&view=products';
        $parentList = osmap_make_item([
            'id'         => 9001,
            'link'       => $listLink,
            'component'  => $this->option,
            'path'       => 'shop',
            'browserNav' => 0,
        ]);

        $collector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $collector, $parentList, new Registry([]));

        echo "  list-view emitted " . count($collector->nodes) . " node(s):\n";
        foreach ($collector->nodes as $n) {
            echo "    - {$n->link}\n";
        }

        $this->test('getTree(products) emits both enabled product URLs', function () use ($collector, $root) {
            $links = array_map(static fn($n) => $n->link, $collector->nodes);
            return in_array($root . '/shop/test-product-alpha', $links, true)
                && in_array($root . '/shop/test-product-beta', $links, true);
        });

        $this->test('getTree(products) excludes disabled and menu-less products', function () use ($collector) {
            foreach ($collector->nodes as $n) {
                if (str_contains($n->link, 'test-product-disabled') || str_contains($n->link, 'test-product-nomenu')) {
                    return false;
                }
            }
            return true;
        });

        $this->test('getTree(products) nodes carry j2commerce.product. uid prefix', function () use ($collector) {
            foreach ($collector->nodes as $n) {
                if (!str_starts_with($n->uid, 'j2commerce.product.')) {
                    return false;
                }
            }
            return count($collector->nodes) > 0;
        });

        // --- 3. getTree() dispatch: single product view (real emitSingleProduct) ---
        // Article 9001 (Test Product Alpha) has an enabled product row in both
        // the J2Store and J2Commerce products tables, so the single-product
        // path emits a real node on both stacks.
        $singleParent = osmap_make_item([
            'id'         => 9001,
            'link'       => 'index.php?option=' . $this->option . '&view=product&id=9001',
            'component'  => $this->option,
            'path'       => 'shop',
            'browserNav' => 0,
        ]);
        $singleCollector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $singleCollector, $singleParent, new Registry([]));

        echo "  single-view emitted " . count($singleCollector->nodes) . " node(s)\n";

        $this->test('getTree(product,id=9001) emits the single product node', function () use ($singleCollector) {
            return count($singleCollector->nodes) === 1;
        });

        $this->test('Single product node URL is shop/test-product-alpha', function () use ($singleCollector, $root) {
            return isset($singleCollector->nodes[0])
                && $singleCollector->nodes[0]->link === $root . '/shop/test-product-alpha';
        });

        // --- 4. Single product view for a non-emitting product id ---
        // Id 9003 is a disabled product on J5 and absent on J6 — either way the
        // single-product path must emit nothing (no crash).
        $disabledParent = osmap_make_item([
            'id'         => 9001,
            'link'       => 'index.php?option=' . $this->option . '&view=product&id=9003',
            'component'  => $this->option,
            'path'       => 'shop',
            'browserNav' => 0,
        ]);
        $disabledCollector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $disabledCollector, $disabledParent, new Registry([]));

        $this->test('getTree(product,id=9003) emits no node (disabled/absent)', function () use ($disabledCollector) {
            return count($disabledCollector->nodes) === 0;
        });

        echo "\n=== Real OSMap Loader Test Summary ===\n";
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

$test = new OsmapLoaderTest($realOsmap);
exit($test->run() ? 0 : 1);
