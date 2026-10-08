<?php
/**
 * Sitemap Output Tests for OSMap J2Commerce Plugin
 *
 * Exercises the public getTree() entry point against the real fixture data and
 * asserts the emitted sitemap nodes. This keeps the suite coupled to plugin
 * behaviour instead of re-implementing its SQL in the test itself.
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

echo 'Real OSMap library loaded: ' . (osmap_ensure_classes() ? 'yes' : 'NO (stubs)') . PHP_EOL;
require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';

class SitemapOutputCollector extends \Alledia\OSMap\Sitemap\Collector
{
    /** @var object[] */
    public array $nodes = [];
    public function __construct() {}
    public function printNode($node): bool
    {
        $this->nodes[] = (object) $node;
        return true;
    }
}

class SitemapOutputTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $option;
    private string $productsTable;

    private const SHOP_MENU_ID = 9001;

    public function __construct()
    {
        $this->db            = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6          = (getenv('J2COMMERCE_STACK') === 'j6');
        $this->option        = $this->isJ6 ? 'com_j2commerce' : 'com_j2store';
        $this->productsTable = $this->isJ6 ? '#__j2commerce_products' : '#__j2store_products';
    }

    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery')
            ? $this->db->createQuery()
            : $this->db->getQuery(true);
    }

    private function makePlugin(): \PlgOsmapJ2commerce
    {
        $plugin = new \PlgOsmapJ2commerce(['params' => new Registry([])]);
        $plugin->setDatabase($this->db);

        return $plugin;
    }

    private function collect(string $query = 'view=products', ?Registry $params = null): array
    {
        $collector = new SitemapOutputCollector();
        $parent = osmap_make_item([
            'id'         => self::SHOP_MENU_ID,
            'link'       => 'index.php?option=' . $this->option . '&' . $query,
            'component'  => $this->option,
            'path'       => 'shop',
            'language'   => '*',
            'browserNav' => 0,
        ]);

        $this->makePlugin()->getTree($collector, $parent, $params ?? new Registry([]));

        return $collector->nodes;
    }

    /**
     * @param object[] $nodes
     * @return string[]
     */
    private function aliases(array $nodes): array
    {
        $aliases = [];
        foreach ($nodes as $node) {
            $aliases[] = basename(parse_url((string) $node->link, PHP_URL_PATH) ?: '');
        }
        sort($aliases);

        return $aliases;
    }

    /**
     * @return string[]
     */
    private function expectedAliases(): array
    {
        $expected = ['test-product-alpha', 'test-product-beta'];
        if (!$this->isJ6) {
            $expected[] = 'test-product-nomenu';
        }
        sort($expected);

        return $expected;
    }

    /**
     * The article id behind each expected alias, read from the fixture rows rather than written in
     * here, so the expectation follows the seed of the entrypoint. A missing article yields 0, which
     * the caller reports as a fixture error instead of comparing against it.
     *
     * @return array<string, int>  alias => article id, sorted by alias
     */
    private function expectedUidsByAlias(): array
    {
        $map = [];

        foreach ($this->expectedAliases() as $alias) {
            $q = $this->createDbQuery()
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__content'))
                ->where($this->db->quoteName('alias') . ' = ' . $this->db->quote($alias));
            $map[$alias] = (int) $this->db->setQuery($q)->loadResult();
        }

        ksort($map);

        return $map;
    }

    public function run(): bool
    {
        echo "=== Sitemap Output Tests ===\n\n";

        $this->test('Fixture: shop menu item exists (published=1)', function () {
            $q = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from('#__menu')
                ->where('id = ' . self::SHOP_MENU_ID)
                ->where('published = 1')
                ->where('link LIKE ' . $this->db->quote('%' . $this->option . '%'));
            return (int) $this->db->setQuery($q)->loadResult() === 1;
        });

        $this->test('Fixture: 2 hidden product menu items exist on the standard stack', function () {
            $q = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from('#__menu')
                ->where('parent_id = ' . self::SHOP_MENU_ID)
                ->where('published = -2');
            return (int) $this->db->setQuery($q)->loadResult() === 2;
        });

        $this->test('Fixture: 2 enabled products exist in the stack products table', function () {
            $q = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from($this->productsTable)
                ->where('product_source_id IN (9001, 9002)')
                ->where('enabled = 1');
            return (int) $this->db->setQuery($q)->loadResult() === 2;
        });

        $nodes = $this->collect();

        $this->test('getTree(view=products) emits the fixture product aliases', function () use ($nodes) {
            return $this->aliases($nodes) === $this->expectedAliases();
        });

        $this->test('getTree(view=categories&id=2) emits the fixture product aliases', function () {
            return $this->aliases($this->collect('view=categories&id=2')) === $this->expectedAliases();
        });

        $this->test('getTree(view=categoryalias&id=2) emits the fixture product aliases', function () {
            return $this->aliases($this->collect('view=categoryalias&id=2')) === $this->expectedAliases();
        });

        // The two tests above would also pass if the plugin ignored the category
        // id and simply emitted every product (all fixtures live in category 2).
        // Prove the id is actually honoured by pointing the same menu views at a
        // non-existent category: its nested-set subtree is empty, so a filter that
        // is applied emits nothing, whereas an ignored id would still return all.
        $noSuchCategory = 90000002;

        $this->test('getTree(view=categories) honours the category id (no match → no products)', function () use ($noSuchCategory) {
            return $this->aliases($this->collect('view=categories&id=' . $noSuchCategory)) === [];
        });

        $this->test('getTree(view=categoryalias) honours the category id (no match → no products)', function () use ($noSuchCategory) {
            return $this->aliases($this->collect('view=categoryalias&id=' . $noSuchCategory)) === [];
        });

        $this->test('Emitted nodes use absolute URLs', function () use ($nodes) {
            $root = rtrim(Uri::root(), '/');
            foreach ($nodes as $node) {
                if (!str_starts_with((string) $node->link, $root . '/')) {
                    return false;
                }
            }
            return count($nodes) > 0;
        });

        // OSMap identifies and de-duplicates nodes by their uid. A node carrying the uid of another
        // product, or the same uid on every node, would vanish from the rendered sitemap although
        // its link is right, and a format check alone passes either way. So the exact pairing of
        // each alias with the article id behind it is asserted, one node per product, including
        // the menu-less product on J5.
        $this->test('Emitted nodes carry the uid of exactly their own product (alias → article id)', function () use ($nodes) {
            $erwartet = [];
            foreach ($this->expectedUidsByAlias() as $alias => $id) {
                if ($id <= 0) {
                    echo "  fixture article for {$alias} not found\n";

                    return false;
                }
                $erwartet[$alias] = 'j2commerce.product.' . $id;
            }

            $ist = [];
            foreach ($nodes as $node) {
                $alias = basename(parse_url((string) $node->link, PHP_URL_PATH) ?: '');
                if (isset($ist[$alias])) {
                    echo "  {$alias} emitted twice\n";

                    return false;
                }
                $ist[$alias] = (string) $node->uid;
            }
            ksort($ist);

            if ($ist !== $erwartet) {
                echo '  expected: ' . json_encode($erwartet) . "\n  got:      " . json_encode($ist) . "\n";

                return false;
            }

            return true;
        });

        $this->test('Emitted nodes use default priority 0.8', function () use ($nodes) {
            foreach ($nodes as $node) {
                if ((string) $node->priority !== '0.8') {
                    return false;
                }
            }
            return true;
        });

        $this->test('Emitted nodes use default changefreq weekly', function () use ($nodes) {
            foreach ($nodes as $node) {
                if ($node->changefreq !== 'weekly') {
                    return false;
                }
            }
            return true;
        });

        $this->test('Custom params override priority and changefreq', function () {
            $nodes = $this->collect('view=products', new Registry('{"priority":"0.5","changefreq":"daily"}'));
            return isset($nodes[0])
                && (string) $nodes[0]->priority === '0.5'
                && $nodes[0]->changefreq === 'daily';
        });

        $this->test('Disabled product is excluded from getTree(view=products)', function () {
            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName($this->productsTable) . ' SET enabled = 0 WHERE product_source_id = 9001'
            )->execute();

            try {
                return !in_array('test-product-alpha', $this->aliases($this->collect()), true);
            } finally {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName($this->productsTable) . ' SET enabled = 1 WHERE product_source_id = 9001'
                )->execute();
            }
        });

        $this->test('Unpublished articles are excluded from getTree(view=categories)', function () {
            $this->db->setQuery('UPDATE #__content SET state = 0 WHERE id = 9001')->execute();

            try {
                $aliases = $this->aliases($this->collect('view=categories&id=2'));

                return !in_array('test-product-alpha', $aliases, true)
                    && in_array('test-product-beta', $aliases, true);
            } finally {
                $this->db->setQuery('UPDATE #__content SET state = 1 WHERE id = 9001')->execute();
            }
        });

        $this->test('Invisible products are excluded from getTree(view=categoryalias)', function () {
            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName($this->productsTable) . ' SET visibility = 0 WHERE product_source_id = 9001'
            )->execute();

            try {
                $aliases = $this->aliases($this->collect('view=categoryalias&id=2'));

                return !in_array('test-product-alpha', $aliases, true)
                    && in_array('test-product-beta', $aliases, true);
            } finally {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName($this->productsTable) . ' SET visibility = 1 WHERE product_source_id = 9001'
                )->execute();
            }
        });

        $this->test('Guest-inaccessible articles are excluded from getTree(view=products)', function () {
            $this->db->setQuery('UPDATE #__content SET access = 2 WHERE id = 9001')->execute();

            try {
                $aliases = $this->aliases($this->collect());

                return !in_array('test-product-alpha', $aliases, true)
                    && in_array('test-product-beta', $aliases, true);
            } finally {
                $this->db->setQuery('UPDATE #__content SET access = 1 WHERE id = 9001')->execute();
            }
        });

        $this->test('Products stay in getTree(view=products) when a hidden child menu item is removed', function () {
            $this->db->setQuery('UPDATE #__menu SET published = 0 WHERE id = 9003')->execute();

            try {
                return in_array('test-product-beta', $this->aliases($this->collect()), true);
            } finally {
                $this->db->setQuery('UPDATE #__menu SET published = -2 WHERE id = 9003')->execute();
            }
        });

        if (!$this->isJ6) {
            $this->test('J5 still emits every fixture product when all hidden child menu items are removed', function () {
                $this->db->setQuery('UPDATE #__menu SET published = 0 WHERE id IN (9002, 9003)')->execute();

                try {
                    return $this->aliases($this->collect()) === $this->expectedAliases();
                } finally {
                    $this->db->setQuery('UPDATE #__menu SET published = -2 WHERE id IN (9002, 9003)')->execute();
                }
            });
        }

        echo "\n=== Sitemap Output Test Summary ===\n";
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

$test = new SitemapOutputTest();
exit($test->run() ? 0 : 1);
