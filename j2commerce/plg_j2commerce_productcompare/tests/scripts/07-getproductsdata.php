<?php
/**
 * getProductsData() Round-Trip Tests
 *
 * Seeds product fixtures → calls getProductsData() via reflection →
 * verifies returned data contains title, SKU, price, options.
 * Also tests: disabled product excluded, getProductOptions() with/without options.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

// Register plugin namespace so the class can be resolved without a full
// plugin bootstrap via PluginHelper::importPlugin().
JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/j2store/productcompare/src',
    false,
    false,
    'psr4'
);

class GetProductsDataTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $seededProductIds  = [];
    private $seededVariantIds  = [];
    private $seededContentIds  = [];
    private $seededOptionIds   = [];

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->detectStack();
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
        echo "=== getProductsData() Round-Trip Tests ===\n\n";

        try {
            $this->seedFixtures();
            $this->testGetProductsData();
            $this->testDisabledProductExcluded();
            $this->testGetProductOptions();
        } finally {
            $this->cleanupFixtures();
        }

        echo "\n=== getProductsData() Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function testGetProductsData(): void
    {
        echo "--- getProductsData() round-trip ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('getProductsData');
        $method->setAccessible(true);

        $products = $method->invoke($plugin, $this->seededProductIds);

        $this->test('Returns array', is_array($products));
        $this->test('Returns 2 products (enabled only)', count($products) === 2,
            'Got ' . count($products));

        if (!empty($products)) {
            $p   = $products[0];
            $pkCol = $this->productsPk;

            $this->test('Product has ' . $pkCol,  isset($p->$pkCol));
            $this->test('Product has title',       isset($p->title) && !empty($p->title));
            $this->test('Product has sku',         isset($p->sku));
            $this->test('Product has price',       isset($p->price));
            $this->test('Product has options key', isset($p->options) && is_array($p->options));

            // Product 0 has options — verify they're loaded
            $p1 = array_values(array_filter($products, fn($x) => (int)$x->$pkCol === $this->seededProductIds[0]))[0] ?? null;
            if ($p1) {
                $this->test('Product with options: options not empty', !empty($p1->options),
                    'Expected options for product ' . $this->seededProductIds[0]);
                $optionNames = array_column($p1->options, 'option_name');
                $this->test('Option name "Colour" present', in_array('Colour', $optionNames));
            }

            // Product 1 has no options — verify empty array, no crash
            $p2 = array_values(array_filter($products, fn($x) => (int)$x->$pkCol === $this->seededProductIds[1]))[0] ?? null;
            if ($p2) {
                $this->test('Product without options: options is empty array',
                    isset($p2->options) && $p2->options === []);
            }
        }
    }

    private function testDisabledProductExcluded(): void
    {
        echo "\n--- Disabled product excluded ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('getProductsData');
        $method->setAccessible(true);

        // seededProductIds[2] is the disabled product
        $allThree  = $method->invoke($plugin, $this->seededProductIds);
        $pkCol     = $this->productsPk;
        $returnedIds = array_map(fn($p) => (int)$p->$pkCol, $allThree);

        $this->test('Disabled product not in results',
            !in_array($this->seededProductIds[2], $returnedIds),
            'Disabled product ID ' . $this->seededProductIds[2] . ' should be excluded');
    }

    private function testGetProductOptions(): void
    {
        echo "\n--- getProductOptions() ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        $method = $rc->getMethod('getProductOptions');
        $method->setAccessible(true);

        // Product with options
        $opts = $method->invoke($plugin, $this->seededProductIds[0]);
        $this->test('Product with options: returns array',    is_array($opts));
        $this->test('Product with options: not empty',        !empty($opts));
        $this->test('Option has option_name key',             isset($opts[0]['option_name']));
        $this->test('Option has option_value key',            isset($opts[0]['option_value']));

        // Product without options
        $opts2 = $method->invoke($plugin, $this->seededProductIds[1]);
        $this->test('Product without options: returns empty array', $opts2 === []);

        // Non-existent product ID — must not crash
        try {
            $opts3 = $method->invoke($plugin, 999999999);
            $this->test('Non-existent product ID: no crash', true);
            $this->test('Non-existent product ID: returns empty array', $opts3 === []);
        } catch (\Throwable $e) {
            $this->test('Non-existent product ID: no crash', false, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function ensureCatid(): int
    {
        // catid=2 may not exist; find or create a valid com_content category
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->where($db->quoteName('published') . ' = 1')
            ->setLimit(1);
        $db->setQuery($query);
        $catid = (int) $db->loadResult();
        if ($catid) {
            return $catid;
        }
        // Create a minimal category
        $cat = (object)[
            'title'     => 'Test Category',
            'alias'     => 'test-category-' . time(),
            'extension' => 'com_content',
            'published' => 1,
            'access'    => 1,
            'params'    => '{}',
            'metadata'  => '{}',
            'language'  => '*',
            'path'      => 'test-category',
            'parent_id' => 1,
            'level'     => 1,
            'lft'       => 0,
            'rgt'       => 0,
        ];
        $db->insertObject('#__categories', $cat, 'id');
        return (int) $db->insertid();
    }

    /** @var bool Whether we're running against J2Commerce 6 tables */
    private bool $isJ6;

    /** @var string Products table name */
    private string $productsTable;
    /** @var string Variants table name */
    private string $variantsTable;
    /** @var string Product options table name */
    private string $optionsTable;
    /** @var string Products PK column */
    private string $productsPk;
    /** @var string Variants PK column */
    private string $variantsPk;
    /** @var string Options PK column */
    private string $optionsPk;

    private function detectStack(): void
    {
        // J2COMMERCE_STACK=j6 is set in the J6 docker-compose environment and
        // passed through to the test container. Use it to select the right tables
        // rather than relying on getTableList() which may not reflect a fresh install.
        $this->isJ6 = (getenv('J2COMMERCE_STACK') === 'j6');

        if ($this->isJ6) {
            $this->productsTable = '#__j2commerce_products';
            $this->variantsTable = '#__j2commerce_variants';
            $this->optionsTable  = '#__j2commerce_product_options';
            $this->productsPk    = 'j2commerce_product_id';
            $this->variantsPk    = 'j2commerce_variant_id';
            $this->optionsPk     = 'j2commerce_product_option_id';
        } else {
            $this->productsTable = '#__j2store_products';
            $this->variantsTable = '#__j2store_variants';
            $this->optionsTable  = '#__j2store_product_options';
            $this->productsPk    = 'j2store_product_id';
            $this->variantsPk    = 'j2store_variant_id';
            $this->optionsPk     = 'j2store_product_option_id';
        }
    }

    private function ensureProductTables(): void
    {
        // Create minimal table stubs so fixture inserts succeed when neither
        // J2Commerce 4 nor J2Commerce 6 is installed in the test container.
        $prefix = $this->db->getPrefix();

        $productCol = $this->productsPk;
        $variantCol = $this->variantsPk;
        $optionCol  = $this->optionsPk;

        // '#__j2store_products' → strip '#__' prefix, then prepend real DB prefix
        $productsBase = substr($this->productsTable, 3); // strip '#__'
        $variantsBase = substr($this->variantsTable, 3);
        $optionsBase  = substr($this->optionsTable, 3);

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $productsBase . '` (
            `' . $productCol . '`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_source_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `product_source`       VARCHAR(100) NOT NULL DEFAULT \'\',
            `product_type`         VARCHAR(50)  NOT NULL DEFAULT \'simple\',
            `enabled`              TINYINT(1)   NOT NULL DEFAULT 1,
            `taxprofile_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `params`               TEXT         NOT NULL,
            PRIMARY KEY (`' . $productCol . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $variantsBase . '` (
            `' . $variantCol . '`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `sku`                  VARCHAR(255) NOT NULL DEFAULT \'\',
            `price`                DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
            `stock`                DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
            `availability`         VARCHAR(255) NOT NULL DEFAULT \'\',
            `params`               TEXT         NOT NULL,
            `isdefault`            TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (`' . $variantCol . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionsBase . '` (
            `' . $optionCol . '`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `option_name`          VARCHAR(255) NOT NULL DEFAULT \'\',
            `option_value`         VARCHAR(255) NOT NULL DEFAULT \'\',
            PRIMARY KEY (`' . $optionCol . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
    }

    private function seedFixtures(): void
    {
        $ts    = time();
        $catid = $this->ensureCatid();
        $this->ensureProductTables();

        // Content articles (product source)
        foreach (['Test Product Alpha', 'Test Product Beta', 'Test Product Disabled'] as $i => $title) {
            $article = (object)[
                'title'      => $title,
                'alias'      => 'test-product-' . $i . '-' . $ts,
                'introtext'  => 'Description for ' . $title,
                'fulltext'   => '',
                'state'      => 1,
                'catid'      => $catid,
                'created'    => date('Y-m-d H:i:s'),
                'created_by' => 42,
                'modified'   => date('Y-m-d H:i:s'),
                'access'     => 1,
                'language'   => '*',
                'metadata'   => '{}',
                'attribs'    => '{}',
                'images'     => '{}',
                'urls'       => '{}',
                'metadesc'   => '',
                'metakey'    => '',
                'note'       => '',
                'featured'   => 0,
                'version'    => 1,
                'ordering'   => 0,
                'hits'       => 0,
            ];
            $this->db->insertObject('#__content', $article, 'id');
            $this->seededContentIds[] = (int)$this->db->insertid();
        }

        // Products (J4: #__j2store_products, J6: #__j2commerce_products)
        foreach ([
            ['enabled' => 1, 'idx' => 0],
            ['enabled' => 1, 'idx' => 1],
            ['enabled' => 0, 'idx' => 2],  // disabled
        ] as $p) {
            $product = (object)[
                'product_source_id' => $this->seededContentIds[$p['idx']],
                'product_source'    => 'com_content',
                'product_type'      => 'simple',
                'enabled'           => $p['enabled'],
                'taxprofile_id'     => 0,
                'params'            => '{}',
            ];
            $this->db->insertObject($this->productsTable, $product, $this->productsPk);
            $this->seededProductIds[] = (int)$this->db->insertid();
        }

        // Variants
        foreach ($this->seededProductIds as $i => $productId) {
            $variant = (object)[
                'product_id'   => $productId,
                'sku'          => 'TEST-SKU-' . $i . '-' . $ts,
                'price'        => 10.00 + ($i * 5),
                'stock'        => 100,
                'availability' => '',
                'params'       => '{}',
                'isdefault'    => 1,
            ];
            $this->db->insertObject($this->variantsTable, $variant, $this->variantsPk);
            $this->seededVariantIds[] = (int)$this->db->insertid();
        }

        // Options for product 0 only
        foreach ([
            ['option_name' => 'Colour', 'option_value' => 'Red'],
            ['option_name' => 'Size',   'option_value' => 'M'],
        ] as $opt) {
            $option = (object)[
                'product_id'   => $this->seededProductIds[0],
                'option_name'  => $opt['option_name'],
                'option_value' => $opt['option_value'],
            ];
            $this->db->insertObject($this->optionsTable, $option, $this->optionsPk);
            $this->seededOptionIds[] = (int)$this->db->insertid();
        }
    }

    private function cleanupFixtures(): void
    {
        foreach ([
            [$this->optionsTable,  $this->optionsPk,   $this->seededOptionIds],
            [$this->variantsTable, $this->variantsPk,  $this->seededVariantIds],
            [$this->productsTable, $this->productsPk,  $this->seededProductIds],
            ['#__content',         'id',               $this->seededContentIds],
        ] as [$table, $pk, $ids]) {
            if (empty($ids)) continue;
            try {
                $this->db->setQuery(
                    $this->db->getQuery(true)->delete($table)->whereIn($pk, $ids)
                )->execute();
            } catch (\Exception $e) {
                // non-fatal
            }
        }
    }

    private function makePlugin(): \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare
    {
        $dispatcher = new Dispatcher();
        $params     = new Registry(['max_products' => 4, 'show_in_list' => 1, 'show_in_detail' => 1]);
        $plugin = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            $dispatcher,
            ['params' => $params]
        );
        // Inject the database so getDatabase() / getProductsData() work without DI container.
        $plugin->setDatabase($this->db);
        return $plugin;
    }
}

$test = new GetProductsDataTest();
exit($test->run() ? 0 : 1);
