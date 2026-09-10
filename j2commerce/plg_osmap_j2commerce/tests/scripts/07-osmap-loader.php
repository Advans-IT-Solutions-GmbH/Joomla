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
            // Construct the plugin the way Joomla's plugin loader (PluginHelper /
            // CMSPlugin) does: pass the dispatcher/subject plus a config array
            // carrying the DB-provided params, name and type from #__extensions,
            // so params/name/type are available during construction rather than
            // being attached only afterwards.
            $pluginParams = new Registry($row->params);
            $config = [
                'name'   => $row->element,
                'type'   => $row->folder,
                'params' => $pluginParams,
            ];
            $instance = new $className(new \Joomla\Event\Dispatcher(), $config);
            if (
                method_exists($instance, 'getComponentElement')
                && $instance->getComponentElement() === $option
            ) {
                $row->instance  = $instance;
                $row->className = $className;
                $row->isLegacy  = false;
                $row->params    = $pluginParams;
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

        $this->test('getTree(products) excludes disabled products', function () use ($collector) {
            foreach ($collector->nodes as $n) {
                if (str_contains($n->link, 'test-product-disabled')) {
                    return false;
                }
            }
            return true;
        });

        // Mechanism 2 completeness (#180): a product that is enabled but has no
        // published=-2 hidden menu child must still appear. Both mechanisms run
        // now, so a leftover hidden child no longer suppresses the direct query.
        // Only the J2Store fixture defines such a product (article 9004).
        if ($this->option === 'com_j2store') {
            $this->test('getTree(products) includes enabled products without a hidden menu item', function () use ($collector, $root) {
                $links = array_map(static fn($n) => $n->link, $collector->nodes);
                return in_array($root . '/shop/test-product-nomenu', $links, true);
            });

            $this->test('getTree(products) emits each product once (de-duplicated)', function () use ($collector) {
                $links = array_map(static fn($n) => $n->link, $collector->nodes);
                return count($links) === count(array_unique($links));
            });
        }

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

        // --- 5. getTree() dispatch: category list views (#181, #99) ---
        // view=categories carries the root category in `id`; view=categoryalias
        // carries it too. Both must emit the products of that category subtree.
        foreach (['categories' => 'id', 'categoryalias' => 'id'] as $catView => $param) {
            $catParent = osmap_make_item([
                'id'         => 9001,
                'link'       => 'index.php?option=' . $this->option . '&view=' . $catView . '&' . $param . '=2',
                'component'  => $this->option,
                'path'       => 'shop',
                'browserNav' => 0,
            ]);
            $catCollector = $this->newCollector();
            $this->dispatchGetTree($ourPlugin, $catCollector, $catParent, new Registry([]));

            echo "  {$catView}-view emitted " . count($catCollector->nodes) . " node(s)\n";

            $this->test("getTree({$catView},id=2) emits the category's products", function () use ($catCollector, $root) {
                $links = array_map(static fn($n) => $n->link, $catCollector->nodes);
                return in_array($root . '/shop/test-product-alpha', $links, true)
                    && in_array($root . '/shop/test-product-beta', $links, true);
            });

            $this->test("getTree({$catView},id=2) emits each product once", function () use ($catCollector) {
                $links = array_map(static fn($n) => $n->link, $catCollector->nodes);
                return count($links) === count(array_unique($links));
            });
        }

        // --- 6. Gate coverage (#178): unpublished / invisible / access-restricted
        // products must never reach the sitemap. Uses throwaway rows so the
        // shared fixtures (and their node counts) stay untouched. J5 only —
        // the insert uses the #__j2store_products schema.
        if ($this->option === 'com_j2store') {
            $this->testGateExclusions($ourPlugin, $root);
        }

        // --- 7. Descendant subtree (#181): a product in a descendant category
        // with no hidden menu item must still be emitted for a parent-category
        // menu item. Dispatching view=categories&id=1 (root) and asserting the
        // catid=2 menu-less product appears proves real subtree traversal — a
        // non-subtree `a.catid = :catid` implementation would emit nothing.
        $this->testDescendantSubtree($ourPlugin, $root);

        // --- 8. Language SEF prefix (#176 regression guard) ---
        // A product emitted for a menu item with a specific (non-'*') language
        // must carry that language's SEF prefix, so the sitemap URL resolves
        // directly instead of via a 301 to the prefixed path.
        $this->testLanguagePrefix($ourPlugin, $root);

        echo "\n=== Real OSMap Loader Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * Inserts a throwaway published language (sef 'zz'), dispatches a
     * single-product menu item whose language points at it, and asserts the
     * emitted URL is prefixed with '/zz/'. Cleans the row up afterwards.
     */
    private function testLanguagePrefix($ourPlugin, string $root): void
    {
        $db = $this->db;

        $langCode = 'zz-ZZ';

        try {
            // Clean any leftover row, then insert through the database API so the
            // test follows the project's portable query conventions (no manually
            // concatenated SQL / unquoted identifiers).
            $del = $db->createQuery()
                ->delete($db->quoteName('#__languages'))
                ->where($db->quoteName('lang_code') . ' = ' . $db->quote($langCode));
            $db->setQuery($del)->execute();

            $row = (object) [
                'lang_code'    => $langCode,
                'title'        => 'Test ZZ',
                'title_native' => 'Test ZZ',
                'sef'          => 'zz',
                'image'        => '',
                'description'  => '',
                'metakey'      => '',
                'metadesc'     => '',
                'sitename'     => '',
                'published'    => 1,
                'access'       => 1,
                'ordering'     => 99,
            ];
            $db->insertObject('#__languages', $row);
        } catch (\Throwable $e) {
            $this->test('Language SEF prefix (#176): fixture language row inserted', function () {
                return false;
            });
            return;
        }

        $langParent = osmap_make_item([
            'id'         => 9001,
            'link'       => 'index.php?option=' . $this->option . '&view=product&id=9001',
            'component'  => $this->option,
            'path'       => 'shop',
            'language'   => 'zz-ZZ',
            'browserNav' => 0,
        ]);
        $langCollector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $langCollector, $langParent, new Registry([]));

        $this->test('getTree(product) prefixes the menu language SEF (#176 → /zz/shop/...)', function () use ($langCollector, $root) {
            return isset($langCollector->nodes[0])
                && $langCollector->nodes[0]->link === $root . '/zz/shop/test-product-alpha';
        });

        try {
            $cleanup = $db->createQuery()
                ->delete($db->quoteName('#__languages'))
                ->where($db->quoteName('lang_code') . ' = ' . $db->quote($langCode));
            $db->setQuery($cleanup)->execute();
        } catch (\Throwable $e) {
            // best-effort cleanup
        }
    }

    /**
     * Dispatches a root-category (id=1) list view and asserts a product living
     * in a descendant category (catid=2) is emitted — proving the loader walks
     * the category subtree rather than matching the id directly.
     */
    private function testDescendantSubtree($ourPlugin, string $root): void
    {
        $catParent = osmap_make_item([
            'id'         => 9001,
            'link'       => 'index.php?option=' . $this->option . '&view=categories&id=1',
            'component'  => $this->option,
            'path'       => 'shop',
            'browserNav' => 0,
        ]);
        $collector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $collector, $catParent, new Registry([]));
        $links = array_map(static fn($n) => $n->link, $collector->nodes);

        if ($this->option === 'com_j2store') {
            // nomenu (catid=2) has no hidden menu item, so only mechanism 2's
            // subtree query can surface it under the root category.
            $this->test('getTree(categories,id=1) emits a menu-less product from a descendant category (#181 subtree)', function () use ($links, $root) {
                return in_array($root . '/shop/test-product-nomenu', $links, true);
            });
        } else {
            $this->test('getTree(categories,id=1) emits descendant-category products (#181 subtree)', function () use ($links, $root) {
                return in_array($root . '/shop/test-product-alpha', $links, true)
                    && in_array($root . '/shop/test-product-beta', $links, true);
            });
        }
    }

    /**
     * Seeds three throwaway products in category 2, each violating exactly one
     * visibility gate, dispatches the full product run and asserts none reach
     * the sitemap. Cleans the rows up afterwards. J5 (#__j2store_products) only.
     */
    private function testGateExclusions($ourPlugin, string $root): void
    {
        $db  = $this->db;
        $ids = [9111, 9112, 9113];

        $articles = [
            // id,   alias,             state, access  (catid 2)
            [9111, 'zz-unpublished', 0, 1],
            [9112, 'zz-invisible',   1, 1],
            [9113, 'zz-restricted',  1, 2], // access 2 = Registered (not a guest level)
        ];
        $products = [
            // product_source_id, visibility, enabled
            [9111, 1, 1],
            [9112, 0, 1], // invisible
            [9113, 1, 1],
        ];

        try {
            $this->cleanupGateRows($ids);

            foreach ($articles as [$id, $alias, $state, $access]) {
                $article = (object) [
                    'id'         => $id,
                    'title'      => 'ZZ ' . $alias,
                    'alias'      => $alias,
                    'introtext'  => '',
                    'fulltext'   => '',
                    'state'      => $state,
                    'catid'      => 2,
                    'created'    => Factory::getDate()->toSql(),
                    'modified'   => Factory::getDate()->toSql(),
                    'publish_up' => Factory::getDate()->toSql(),
                    'language'   => '*',
                    'access'     => $access,
                ];
                $db->insertObject('#__content', $article);
            }

            foreach ($products as [$sourceId, $visibility, $enabled]) {
                $product = (object) [
                    'product_source_id' => $sourceId,
                    'product_source'    => 'com_content',
                    'product_type'      => 'simple',
                    'visibility'        => $visibility,
                    'enabled'           => $enabled,
                    'addtocart_text'    => '',
                    'up_sells'          => '',
                    'cross_sells'       => '',
                    'params'            => '{}',
                ];
                $db->insertObject('#__j2store_products', $product);
            }
        } catch (\Throwable $e) {
            $this->cleanupGateRows($ids);
            $this->test('Gate exclusions (#178): fixture rows seeded', function () use ($e) {
                echo '  Error: ' . $e->getMessage() . "\n";
                return false;
            });
            return;
        }

        $parent = osmap_make_item([
            'id'         => 9001,
            'link'       => 'index.php?option=' . $this->option . '&view=products',
            'component'  => $this->option,
            'path'       => 'shop',
            'browserNav' => 0,
        ]);
        $collector = $this->newCollector();
        $this->dispatchGetTree($ourPlugin, $collector, $parent, new Registry([]));
        $links = array_map(static fn($n) => $n->link, $collector->nodes);

        foreach (['zz-unpublished' => 'unpublished article (a.state=0)',
                  'zz-invisible'   => 'invisible product (p.visibility=0)',
                  'zz-restricted'  => 'guest-inaccessible article (a.access)'] as $alias => $why) {
            $this->test("Gate exclusions (#178): excludes {$why}", function () use ($links, $root, $alias) {
                return !in_array($root . '/shop/' . $alias, $links, true);
            });
        }

        $this->cleanupGateRows($ids);
    }

    private function cleanupGateRows(array $ids): void
    {
        $db  = $this->db;
        $csv = implode(',', array_map('intval', $ids));
        foreach (['#__content' => 'id', '#__j2store_products' => 'product_source_id'] as $table => $col) {
            try {
                $q = $db->createQuery()
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName($col) . ' IN (' . $csv . ')');
                $db->setQuery($q)->execute();
            } catch (\Throwable $e) {
                // best-effort cleanup
            }
        }
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
