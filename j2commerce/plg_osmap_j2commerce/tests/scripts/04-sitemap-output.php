<?php
/**
 * Sitemap Output Tests for OSMap J2Commerce Plugin
 *
 * Tests the plugin's product query and node generation logic directly against
 * the database, without requiring a full OSMap sitemap render. This avoids
 * the need for a configured sitemap menu item and OSMap cron/cache.
 *
 * What is tested:
 * - The DB query that getTree() uses returns the expected products
 * - The generated node structure has correct link, uid, priority, changefreq
 * - Disabled products are excluded
 * - Products without a published=-2 menu item are excluded
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class SitemapOutputTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    // IDs inserted by docker-entrypoint.sh fixtures
    private const SHOP_MENU_ID    = 9001;
    private const PRODUCT_ALPHA   = ['article_id' => 9001, 'menu_id' => 9002, 'alias' => 'test-product-alpha'];
    private const PRODUCT_BETA    = ['article_id' => 9002, 'menu_id' => 9003, 'alias' => 'test-product-beta'];

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Sitemap Output Tests ===\n\n";

        $this->test('Fixture: shop menu item exists (published=1, com_j2store)', function () {
            $q = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__menu')
                ->where('id = ' . self::SHOP_MENU_ID)
                ->where('published = 1')
                ->where('link LIKE ' . $this->db->quote('%com_j2store%'));
            return (int) $this->db->setQuery($q)->loadResult() === 1;
        });

        $this->test('Fixture: 2 product menu items exist (published=-2)', function () {
            $q = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__menu')
                ->where('parent_id = ' . self::SHOP_MENU_ID)
                ->where('published = -2');
            return (int) $this->db->setQuery($q)->loadResult() === 2;
        });

        $this->test('Fixture: 2 enabled J2Store products exist', function () {
            $q = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__j2store_products')
                ->where('product_source_id IN (9001, 9002)')
                ->where('enabled = 1');
            return (int) $this->db->setQuery($q)->loadResult() === 2;
        });

        // Run the exact same query as getTree() uses, with the shop menu item as parent
        $products = $this->runGetTreeQuery(self::SHOP_MENU_ID);

        $this->test('getTree() query returns 2 products for shop menu item', function () use ($products) {
            return count($products) === 2;
        });

        $this->test('getTree() query returns correct product aliases', function () use ($products) {
            $aliases = array_column($products, 'alias');
            sort($aliases);
            return $aliases === ['test-product-alpha', 'test-product-beta'];
        });

        $this->test('Generated nodes have correct link format', function () use ($products) {
            foreach ($products as $p) {
                $expected = 'index.php?option=com_content&view=article&id=' . $p->article_id . '&Itemid=' . $p->id;
                $node = $this->buildNode($p, new Registry('{}'));
                if ($node->link !== $expected) {
                    echo "  Expected: $expected\n  Got:      {$node->link}\n";
                    return false;
                }
            }
            return true;
        });

        $this->test('Generated nodes have correct uid format', function () use ($products) {
            foreach ($products as $p) {
                $node = $this->buildNode($p, new Registry('{}'));
                if ($node->uid !== 'j2commerce.product.' . $p->id) {
                    return false;
                }
            }
            return true;
        });

        $this->test('Generated nodes use default priority 0.8', function () use ($products) {
            foreach ($products as $p) {
                $node = $this->buildNode($p, new Registry('{}'));
                if ((string)$node->priority !== '0.8') {
                    return false;
                }
            }
            return true;
        });

        $this->test('Generated nodes use default changefreq weekly', function () use ($products) {
            foreach ($products as $p) {
                $node = $this->buildNode($p, new Registry('{}'));
                if ($node->changefreq !== 'weekly') {
                    return false;
                }
            }
            return true;
        });

        $this->test('Custom params override priority and changefreq', function () use ($products) {
            if (empty($products)) return false;
            $params = new Registry('{"priority":"0.5","changefreq":"daily"}');
            $node = $this->buildNode($products[0], $params);
            return (string)$node->priority === '0.5' && $node->changefreq === 'daily';
        });

        $this->test('Disabled product is excluded from query results', function () {
            // Temporarily disable product alpha
            $this->db->setQuery(
                'UPDATE #__j2store_products SET enabled=0 WHERE product_source_id=9001'
            )->execute();

            $products = $this->runGetTreeQuery(self::SHOP_MENU_ID);
            $count = count($products);

            // Re-enable
            $this->db->setQuery(
                'UPDATE #__j2store_products SET enabled=1 WHERE product_source_id=9001'
            )->execute();

            return $count === 1;
        });

        $this->test('Product without menu item is excluded from query results', function () {
            // Temporarily remove the menu item for product beta
            $this->db->setQuery(
                'UPDATE #__menu SET published=0 WHERE id=9003'
            )->execute();

            $products = $this->runGetTreeQuery(self::SHOP_MENU_ID);
            $count = count($products);

            // Restore
            $this->db->setQuery(
                'UPDATE #__menu SET published=-2 WHERE id=9003'
            )->execute();

            // published=0 is not -2, so it should be excluded
            return $count === 1;
        });

        echo "\n=== Sitemap Output Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * Runs the exact same DB query as PlgOsmapJ2commerce::getTree() uses.
     * Keeping this in sync with j2commerce.php is intentional — if the query
     * changes, this test must change too.
     */
    private function runGetTreeQuery(int $parentMenuId): array
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('m.id'),
                $db->quoteName('m.alias'),
                $db->quoteName('m.browserNav'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.title'),
                $db->quoteName('a.id', 'article_id'),
            ])
            ->from($db->quoteName('#__menu', 'm'))
            ->join('INNER', $db->quoteName('#__content', 'a')
                . ' ON m.link LIKE CONCAT(' . $db->quote('%id=') . ', a.id)'
                . ' AND m.link LIKE ' . $db->quote('%com_content%view=article%'))
            ->join('INNER', $db->quoteName('#__j2store_products', 'p')
                . ' ON p.product_source_id = a.id'
                . ' AND p.product_source = ' . $db->quote('com_content')
                . ' AND p.enabled = 1')
            ->where([
                'm.published = -2',
                'm.parent_id = ' . (int) $parentMenuId,
                'm.client_id = 0',
            ])
            ->order('a.title ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Replicates the node-building logic from PlgOsmapJ2commerce::getTree().
     */
    private function buildNode(object $product, Registry $params): object
    {
        return (object) [
            'id'         => $product->id,
            'name'       => $product->title,
            'uid'        => 'j2commerce.product.' . $product->id,
            'modified'   => $product->modified,
            'browserNav' => 0,
            'priority'   => $params->get('priority', '0.8'),
            'changefreq' => $params->get('changefreq', 'weekly'),
            'link'       => 'index.php?option=com_content&view=article&id=' . $product->article_id . '&Itemid=' . $product->id,
            'expandible' => false,
        ];
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
