<?php
/**
 * Event Dispatch Tests — real proof that the storefront events reach the plugin
 * handlers and produce compare-button output.
 *
 * Two real product rows are seeded (#__j2store_* on J5/J2C4, #__j2commerce_* on
 * J6/J2C6) and read back from the database, then the actual storefront events are
 * driven and the rendered button markup is asserted:
 *
 *   J6 / J2Commerce 6  (SubscriberInterface, real Joomla\Event\Dispatcher):
 *     onJ2CommerceAfterProductListItemDisplay
 *     onJ2CommerceAfterProductDisplay
 *   These are dispatched through a real dispatcher after registering the plugin as
 *   a subscriber (exactly how Joomla wires SubscriberInterface plugins), and the
 *   result is read back from the event — the same path J2Commerce 6's
 *   eventWithHtml() uses.
 *
 *   J4 / J2Store 4  (legacy method-name convention):
 *     onJ2StoreAfterDisplayProductList
 *     onJ2StoreAfterDisplayProduct
 *   J2Store 4 dispatches these via its legacy JEventDispatcher, which invokes the
 *   matching public method on each registered plugin with positional arguments
 *   (call_user_func_array). We invoke the registered listener the same way and
 *   assert the returned button HTML.
 *
 * Stack-correct behaviour is also asserted: on J6 the legacy J2Store events must
 * be suppressed (the plugin detects J2Commerce 6 and returns empty), and on both
 * stacks the SubscriberInterface registration must wire the J2Commerce 6 events.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once __DIR__ . '/bootstrap-app.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event as BaseEvent;
use Joomla\Registry\Registry;

$group = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';

JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/' . $group . '/productcompare/src',
    false,
    false,
    'psr4'
);

/**
 * Minimal result-collecting event that mirrors the result-aware event J2Commerce 6
 * dispatches via eventWithHtml(): positional arguments + addResult()/getResults().
 * The plugin's J2Commerce 6 handlers call $event->addResult($html).
 */
class CompareRenderTestEvent extends BaseEvent
{
    public function addResult($value): self
    {
        $results   = $this->getArgument('result', []);
        $results[] = $value;
        $this->setArgument('result', $results);

        return $this;
    }

    public function getResults(): array
    {
        return (array) $this->getArgument('result', []);
    }
}

class EventDispatchTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private bool $isJ6;
    private string $group;
    private string $productsTable;
    private string $productsPk;

    private array $seededProductIds = [];
    private array $seededContentIds = [];

    public function __construct()
    {
        $this->db    = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6  = getenv('J2COMMERCE_STACK') === 'j6';
        $this->group = $this->isJ6 ? 'j2commerce' : 'j2store';

        $this->productsTable = $this->isJ6 ? '#__j2commerce_products' : '#__j2store_products';
        $this->productsPk    = $this->isJ6 ? 'j2commerce_product_id'  : 'j2store_product_id';
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ $name\n";
            $this->passed++;
        } else {
            echo "✗ $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Event Dispatch Tests (group: {$this->group}) ===\n\n";

        try {
            $this->seedFixtures();

            if ($this->isJ6) {
                $this->testJ2Commerce6Events();
                $this->testLegacyEventsSuppressedOnJ6();
            } else {
                $this->testJ2Store4Events();
                $this->testSubscriberWiring();
            }
        } finally {
            $this->cleanupFixtures();
        }

        echo "\n=== Event Dispatch Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function makePlugin(): \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare
    {
        $params = new Registry([
            'max_products'   => 4,
            'show_in_list'   => 1,
            'show_in_detail' => 1,
            'button_class'   => 'btn btn-secondary',
        ]);

        $plugin = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            new Dispatcher(),
            ['params' => $params, 'type' => $this->group, 'name' => 'productcompare']
        );

        $plugin->setDatabase($this->db);

        // renderCompareButton() -> renderLayout() needs the application for the
        // template override include path.
        try {
            $app = bootstrapSiteApplication();
            if (method_exists($plugin, 'setApplication')) {
                $plugin->setApplication($app);
            }
        } catch (\Throwable $e) {
            // continue — render still works off the base tmpl path
        }

        return $plugin;
    }

    private function loadSeededProduct(int $id): object
    {
        $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
            ->select('*')
            ->from($this->db->quoteName($this->productsTable))
            ->where($this->db->quoteName($this->productsPk) . ' = ' . (int) $id);
        $this->db->setQuery($q);
        $row = $this->db->loadObject();

        if (!$row) {
            throw new \RuntimeException('Seeded product row ' . $id . ' could not be reloaded');
        }

        return $row;
    }

    // -------------------------------------------------------------------------
    // J2Commerce 6 — real SubscriberInterface dispatch
    // -------------------------------------------------------------------------

    private function testJ2Commerce6Events(): void
    {
        echo "--- J2Commerce 6 events via real dispatcher ---\n";

        $productId = $this->seededProductIds[0];
        $product   = $this->loadSeededProduct($productId);
        $this->test('Seeded J2Commerce 6 product reloaded from DB',
            isset($product->{$this->productsPk}) && (int) $product->{$this->productsPk} === $productId);

        $plugin     = $this->makePlugin();
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($plugin);

        // List event — args: [$product, $context, &$displayData]
        $listEvent = new CompareRenderTestEvent(
            'onJ2CommerceAfterProductListItemDisplay',
            [$product, 'com_j2commerce.product.list', []]
        );
        $dispatcher->dispatch($listEvent->getName(), $listEvent);
        $listHtml = implode('', $listEvent->getResults());

        $this->test('List event produced a result', $listHtml !== '');
        $this->test('List event rendered compare button',
            strpos($listHtml, 'j2store-compare-btn') !== false, $listHtml);
        $this->test('List button carries seeded product id',
            strpos($listHtml, 'data-product-id="' . $productId . '"') !== false, $listHtml);

        // Detail event — args scanned for the product object carrying the J6 PK.
        $detailEvent = new CompareRenderTestEvent(
            'onJ2CommerceAfterProductDisplay',
            [$product, (object) ['name' => 'view']]
        );
        $dispatcher->dispatch($detailEvent->getName(), $detailEvent);
        $detailHtml = implode('', $detailEvent->getResults());

        $this->test('Detail event produced a result', $detailHtml !== '');
        $this->test('Detail event rendered compare button',
            strpos($detailHtml, 'j2store-compare-btn') !== false, $detailHtml);
        $this->test('Detail button carries seeded product id',
            strpos($detailHtml, 'data-product-id="' . $productId . '"') !== false, $detailHtml);

        // show_in_list = 0 must suppress the list button.
        $offPlugin = $this->makePluginWith(['show_in_list' => 0, 'show_in_detail' => 1]);
        $offDispatcher = new Dispatcher();
        $offDispatcher->addSubscriber($offPlugin);
        $offEvent = new CompareRenderTestEvent(
            'onJ2CommerceAfterProductListItemDisplay',
            [$product, 'com_j2commerce.product.list', []]
        );
        $offDispatcher->dispatch($offEvent->getName(), $offEvent);
        $this->test('show_in_list=0 suppresses list button',
            implode('', $offEvent->getResults()) === '');
    }

    private function testLegacyEventsSuppressedOnJ6(): void
    {
        echo "\n--- Legacy J2Store 4 events suppressed on J2Commerce 6 ---\n";

        $product = $this->loadSeededProduct($this->seededProductIds[0]);
        // The legacy handler expects a J2Store-style PK; provide it. The plugin must
        // still return '' because isJ2Commerce6() detects the J2Commerce 6 schema.
        $product->j2store_product_id = (int) $product->{$this->productsPk};

        $plugin = $this->makePlugin();

        $listOut   = call_user_func_array([$plugin, 'onJ2StoreAfterDisplayProductList'], [$product]);
        $detailOut = call_user_func_array([$plugin, 'onJ2StoreAfterDisplayProduct'], [$product, 'product']);

        $this->test('onJ2StoreAfterDisplayProductList returns empty on J6', $listOut === '',
            'Expected empty string, got: ' . $listOut);
        $this->test('onJ2StoreAfterDisplayProduct returns empty on J6', $detailOut === '',
            'Expected empty string, got: ' . $detailOut);
    }

    // -------------------------------------------------------------------------
    // J2Store 4 — legacy method-name dispatch
    // -------------------------------------------------------------------------

    private function testJ2Store4Events(): void
    {
        echo "--- J2Store 4 legacy events ---\n";

        $productId = $this->seededProductIds[0];
        $product   = $this->loadSeededProduct($productId);
        $this->test('Seeded J2Store 4 product reloaded from DB',
            isset($product->{$this->productsPk}) && (int) $product->{$this->productsPk} === $productId);

        $plugin = $this->makePlugin();

        // J2Store 4's legacy dispatcher calls the matching method with positional
        // args (call_user_func_array). Invoke the listener exactly the same way.
        $listOut   = call_user_func_array([$plugin, 'onJ2StoreAfterDisplayProductList'], [$product]);
        $detailOut = call_user_func_array([$plugin, 'onJ2StoreAfterDisplayProduct'], [$product, 'product']);

        $this->test('List event rendered compare button',
            strpos($listOut, 'j2store-compare-btn') !== false, $listOut);
        $this->test('List button carries seeded product id',
            strpos($listOut, 'data-product-id="' . $productId . '"') !== false, $listOut);

        $this->test('Detail event rendered compare button',
            strpos($detailOut, 'j2store-compare-btn') !== false, $detailOut);
        $this->test('Detail button carries seeded product id',
            strpos($detailOut, 'data-product-id="' . $productId . '"') !== false, $detailOut);

        // Params must gate output.
        $offPlugin = $this->makePluginWith(['show_in_list' => 0, 'show_in_detail' => 0]);
        $this->test('show_in_list=0 suppresses list button',
            call_user_func_array([$offPlugin, 'onJ2StoreAfterDisplayProductList'], [$product]) === '');
        $this->test('show_in_detail=0 suppresses detail button',
            call_user_func_array([$offPlugin, 'onJ2StoreAfterDisplayProduct'], [$product, 'product']) === '');
    }

    /**
     * Prove the SubscriberInterface wiring also works on the J5/J2C4 stack: the two
     * J2Commerce 6 events are registered through a real dispatcher and reach the
     * plugin. (On J5 they are simply not fired by the storefront, but the wiring
     * itself must be correct.)
     */
    private function testSubscriberWiring(): void
    {
        echo "\n--- SubscriberInterface wiring (J2Commerce 6 events) ---\n";

        $events = \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare::getSubscribedEvents();
        $this->test('Subscribes to onJ2CommerceAfterProductListItemDisplay',
            array_key_exists('onJ2CommerceAfterProductListItemDisplay', $events));
        $this->test('Subscribes to onJ2CommerceAfterProductDisplay',
            array_key_exists('onJ2CommerceAfterProductDisplay', $events));

        $productId = $this->seededProductIds[0];
        $product   = (object) ['j2commerce_product_id' => $productId];

        $plugin     = $this->makePlugin();
        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($plugin);

        $event = new CompareRenderTestEvent(
            'onJ2CommerceAfterProductListItemDisplay',
            [$product, 'com_j2commerce.product.list', []]
        );
        $dispatcher->dispatch($event->getName(), $event);

        $this->test('Registered J2Commerce 6 listener reached and rendered button',
            strpos(implode('', $event->getResults()), 'data-product-id="' . $productId . '"') !== false);
    }

    private function makePluginWith(array $paramOverrides): \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare
    {
        $params = new Registry(array_merge([
            'max_products'   => 4,
            'show_in_list'   => 1,
            'show_in_detail' => 1,
            'button_class'   => 'btn btn-secondary',
        ], $paramOverrides));

        $plugin = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            new Dispatcher(),
            ['params' => $params, 'type' => $this->group, 'name' => 'productcompare']
        );
        $plugin->setDatabase($this->db);
        try {
            $app = bootstrapSiteApplication();
            if (method_exists($plugin, 'setApplication')) {
                $plugin->setApplication($app);
            }
        } catch (\Throwable $e) {
        }

        return $plugin;
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function ensureProductsTable(): void
    {
        $prefix = $this->db->getPrefix();
        $base   = substr($this->productsTable, 3); // strip '#__'
        $pk     = $this->productsPk;

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $base . '` (
            `' . $pk . '`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_source_id`  INT UNSIGNED NOT NULL DEFAULT 0,
            `product_source`     VARCHAR(100) NOT NULL DEFAULT \'\',
            `product_type`       VARCHAR(50)  NOT NULL DEFAULT \'simple\',
            `visibility`         TINYINT(1)   NOT NULL DEFAULT 1,
            `enabled`            TINYINT(1)   NOT NULL DEFAULT 1,
            `params`             TEXT         NOT NULL,
            PRIMARY KEY (`' . $pk . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
    }

    private function ensureCatid(): int
    {
        $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->setLimit(1);
        $this->db->setQuery($q);
        $catid = (int) $this->db->loadResult();
        if ($catid) {
            return $catid;
        }

        $cat = (object) [
            'title' => 'Test Category', 'alias' => 'test-cat-evt-' . time(),
            'extension' => 'com_content', 'published' => 1, 'access' => 1,
            'params' => '{}', 'metadata' => '{}', 'language' => '*',
            'path' => 'test-cat-evt', 'parent_id' => 1, 'level' => 1, 'lft' => 0, 'rgt' => 0,
        ];
        $this->db->insertObject('#__categories', $cat, 'id');
        return (int) $this->db->insertid();
    }

    private function seedFixtures(): void
    {
        $ts    = time();
        $catid = $this->ensureCatid();
        $this->ensureProductsTable();

        foreach (['Event Product One', 'Event Product Two'] as $i => $title) {
            $article = (object) [
                'title' => $title, 'alias' => 'event-product-' . $i . '-' . $ts,
                'introtext' => 'Intro ' . $title, 'fulltext' => '', 'state' => 1, 'catid' => $catid,
                'created' => date('Y-m-d H:i:s'), 'created_by' => 42, 'modified' => date('Y-m-d H:i:s'),
                'access' => 1, 'language' => '*', 'metadata' => '{}', 'attribs' => '{}',
                'images' => '{}', 'urls' => '{}', 'metadesc' => '', 'metakey' => '', 'note' => '',
                'featured' => 0, 'version' => 1, 'ordering' => 0, 'hits' => 0,
            ];
            $this->db->insertObject('#__content', $article, 'id');
            $this->seededContentIds[] = (int) $this->db->insertid();
        }

        foreach ($this->seededContentIds as $contentId) {
            $product = $this->buildProductRow($contentId);
            $this->db->insertObject($this->productsTable, $product, $this->productsPk);
            $this->seededProductIds[] = (int) $this->db->insertid();
        }
    }

    /**
     * Build a complete product row for the REAL products table.
     *
     * On the live stacks the products table (#__j2store_products on J2Store 4,
     * #__j2commerce_products on J2Commerce 6) carries many NOT NULL columns with
     * no default (e.g. up_sells, addtocart_text). A fixed column list therefore
     * fails with "Field '…' doesn't have a default value". We introspect the real
     * table and supply a safe value for every required column, then override the
     * fields the plugin and the reload actually care about.
     */
    private function buildProductRow(int $contentId): object
    {
        $columns = $this->db->getTableColumns($this->productsTable, false);

        $row = [];

        foreach ($columns as $name => $info) {
            $extra   = strtolower((string) ($info->Extra ?? ''));
            $null    = strtoupper((string) ($info->Null ?? 'YES'));
            $default = $info->Default ?? null;

            // Skip the auto-increment PK, nullable columns and columns that already
            // carry a default — MySQL will fill those in for us.
            if (strpos($extra, 'auto_increment') !== false || $null === 'YES' || $default !== null) {
                continue;
            }

            $row[$name] = $this->defaultForColumnType((string) ($info->Type ?? 'varchar'));
        }

        // Meaningful overrides (only when the column exists on this stack).
        $overrides = [
            'product_source_id' => $contentId,
            'product_source'    => 'com_content',
            'product_type'      => 'simple',
            'visibility'        => 1,
            'enabled'           => 1,
            'params'            => '{}',
        ];

        foreach ($overrides as $col => $value) {
            if (isset($columns[$col])) {
                $row[$col] = $value;
            }
        }

        return (object) $row;
    }

    /**
     * Pick a safe, strict-mode-compatible default for a MySQL column type.
     */
    private function defaultForColumnType(string $type)
    {
        $t = strtolower($type);

        if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit|year)/', $t)) {
            return 0;
        }

        if (strpos($t, 'datetime') === 0 || strpos($t, 'timestamp') === 0) {
            return '2000-01-01 00:00:00';
        }

        if (strpos($t, 'date') === 0) {
            return '2000-01-01';
        }

        if (strpos($t, 'time') === 0) {
            return '00:00:00';
        }

        // enum('a','b',…) — use the first allowed value.
        if (strpos($t, 'enum') === 0 && preg_match("/^enum\\('([^']*)'/i", $type, $m)) {
            return $m[1];
        }

        // varchar / char / text / blob / json / set / …
        return '';
    }

    private function cleanupFixtures(): void
    {
        foreach ([
            [$this->productsTable, $this->productsPk, $this->seededProductIds],
            ['#__content', 'id', $this->seededContentIds],
        ] as [$table, $pk, $ids]) {
            if (empty($ids)) {
                continue;
            }
            try {
                $q = (method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true))
                    ->delete($table)->whereIn($pk, $ids);
                $this->db->setQuery($q)->execute();
            } catch (\Exception $e) {
                // non-fatal
            }
        }
    }
}

$test = new EventDispatchTest();
exit($test->run() ? 0 : 1);
