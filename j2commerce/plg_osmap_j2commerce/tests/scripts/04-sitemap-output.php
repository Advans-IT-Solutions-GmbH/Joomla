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
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

osmap_ensure_classes();
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
        $plugin = new \PlgOsmapJ2commerce(new Dispatcher(), ['params' => new Registry([])]);
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
            $expected = ['test-product-alpha', 'test-product-beta'];
            if (!$this->isJ6) {
                $expected[] = 'test-product-nomenu';
            }
            sort($expected);

            return $this->aliases($nodes) === $expected;
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

        $this->test('Emitted nodes have the j2commerce.product.* uid format', function () use ($nodes) {
            foreach ($nodes as $node) {
                if (!preg_match('/^j2commerce\.product\.\d+$/', (string) $node->uid)) {
                    return false;
                }
            }
            return count($nodes) > 0;
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

        $this->test('Products stay in getTree(view=products) when a hidden child menu item is removed', function () {
            $this->db->setQuery('UPDATE #__menu SET published = 0 WHERE id = 9003')->execute();

            try {
                return in_array('test-product-beta', $this->aliases($this->collect()), true);
            } finally {
                $this->db->setQuery('UPDATE #__menu SET published = -2 WHERE id = 9003')->execute();
            }
        });

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
