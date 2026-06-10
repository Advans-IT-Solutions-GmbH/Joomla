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
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

// Register plugin namespace so the class can be resolved without a full
// plugin bootstrap via PluginHelper::importPlugin().
JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/' . (getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store') . '/productcompare/src',
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
    private $seededQuantityIds = [];
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

            $this->test('Product has ' . $pkCol,    isset($p->$pkCol));
            $this->test('Product has title',         isset($p->title) && !empty($p->title));
            $this->test('Product has sku',           isset($p->sku));
            $this->test('Product has price',         isset($p->price));
            $this->test('Product has availability',  array_key_exists('availability', (array) $p));
            $this->test('Product has stock',         array_key_exists('stock', (array) $p));
            $this->test('Product has options key',   isset($p->options) && is_array($p->options));

            // Product 0 has options — verify they're loaded on both J4 and J6
            $p1 = array_values(array_filter($products, fn($x) => (int)$x->$pkCol === $this->seededProductIds[0]))[0] ?? null;
            if ($p1) {
                $this->test('Product with quantity: stock is loaded from productquantities',
                    (int) $p1->stock === 7,
                    'Expected stock 7, got ' . ($p1->stock ?? 'missing'));
                $this->test('Product with options: options not empty', !empty($p1->options),
                    'Expected options for product ' . $this->seededProductIds[0]);
                $optionNames = array_column($p1->options, 'option_name');
                $this->test('Option name "Colour" present', in_array('Colour', $optionNames));
            }

            // Product 1 has no options — verify empty array, no crash
            $p2 = array_values(array_filter($products, fn($x) => (int)$x->$pkCol === $this->seededProductIds[1]))[0] ?? null;
            if ($p2) {
                $this->test('Product with zero quantity: stock is zero',
                    (int) $p2->stock === 0,
                    'Expected stock 0, got ' . ($p2->stock ?? 'missing'));
                $this->test('Product without options: options is empty array',
                    isset($p2->options) && $p2->options === []);
            }

            $this->testTableRendersStockStatus($products);
        }
    }

    private function testTableRendersStockStatus(array $products): void
    {
        echo "\n--- Table layout stock rendering ---\n";

        try {
            $group  = $this->isJ6 ? 'j2commerce' : 'j2store';
            $path   = JPATH_PLUGINS . '/' . $group . '/productcompare/tmpl/table.php';
            $view   = new class {
                public function escape($value): string
                {
                    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                }
            };
            $render = function (array $products) use ($path): string {
                ob_start();
                include $path;
                return ob_get_clean();
            };
            $html = $render->call($view, $products);

            $inStockLabel  = Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_IN_STOCK');
            $outStockLabel = Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_OUT_OF_STOCK');

            $this->test('Table renders in-stock label for positive quantity',
                str_contains($html, $inStockLabel),
                'Expected label ' . $inStockLabel);
            $this->test('Table renders out-of-stock label for zero quantity',
                str_contains($html, $outStockLabel),
                'Expected label ' . $outStockLabel);
        } catch (\Throwable $e) {
            $this->test('Table layout renders stock statuses', false, $e->getMessage());
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
        $this->test('getProductOptions: returns array', is_array($opts));
        $this->test('getProductOptions: not empty',    !empty($opts));
        $this->test('Option has option_name key',      isset($opts[0]['option_name']));
        $this->test('Option has option_value key',     isset($opts[0]['option_value']));

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
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
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
    /** @var string Product quantities table name */
    private string $productQuantitiesTable;
    /** @var string Product options mapping table name */
    private string $optionsTable;
    /** @var string Options label table (J6 only) */
    private string $optionsLabelTable;
    /** @var string Option values table (J6 only) */
    private string $optionValuesTable;
    /** @var string Product option values mapping table (J6 only) */
    private string $productOptionValuesTable;
    /** @var string Products PK column */
    private string $productsPk;
    /** @var string Variants PK column */
    private string $variantsPk;
    /** @var string Product quantities PK column */
    private string $quantitiesPk;
    /** @var string Options PK column */
    private string $optionsPk;
    /** @var int[] Seeded j2commerce_options IDs (J6 only) */
    private array $seededOptionLabelIds = [];
    /** @var int[] Seeded j2commerce_optionvalues IDs (J6 only) */
    private array $seededOptionValueIds = [];
    /** @var int[] Seeded j2commerce_product_optionvalues IDs (J6 only) */
    private array $seededProductOptionValueIds = [];

    private function detectStack(): void
    {
        // J2COMMERCE_STACK=j6 is set in the J6 docker-compose environment and
        // passed through to the test container. Use it to select the right tables
        // rather than relying on getTableList() which may not reflect a fresh install.
        $this->isJ6 = (getenv('J2COMMERCE_STACK') === 'j6');

        if ($this->isJ6) {
            $this->productsTable            = '#__j2commerce_products';
            $this->variantsTable            = '#__j2commerce_variants';
            $this->productQuantitiesTable   = '#__j2commerce_productquantities';
            $this->optionsTable             = '#__j2commerce_product_options';
            $this->optionsLabelTable        = '#__j2commerce_options';
            $this->optionValuesTable        = '#__j2commerce_optionvalues';
            $this->productOptionValuesTable = '#__j2commerce_product_optionvalues';
            $this->productsPk               = 'j2commerce_product_id';
            $this->variantsPk               = 'j2commerce_variant_id';
            $this->quantitiesPk             = 'j2commerce_productquantity_id';
            $this->optionsPk                = 'j2commerce_productoption_id';
        } else {
            $this->productsTable            = '#__j2store_products';
            $this->variantsTable            = '#__j2store_variants';
            $this->productQuantitiesTable   = '#__j2store_productquantities';
            $this->optionsTable             = '#__j2store_product_options';
            $this->optionsLabelTable        = '#__j2store_options';
            $this->optionValuesTable        = '#__j2store_optionvalues';
            $this->productOptionValuesTable = '#__j2store_product_optionvalues';
            $this->productsPk               = 'j2store_product_id';
            $this->variantsPk               = 'j2store_variant_id';
            $this->quantitiesPk             = 'j2store_productquantity_id';
            $this->optionsPk                = 'j2store_productoption_id';
        }
    }

    private function ensureProductTables(): void
    {
        // Create minimal table stubs so fixture inserts succeed when neither
        // J2Commerce 4 nor J2Commerce 6 is installed in the test container.
        $prefix = $this->db->getPrefix();

        $productCol = $this->productsPk;
        $variantCol = $this->variantsPk;
        $quantityCol = $this->quantitiesPk;
        $optionCol  = $this->optionsPk;

        // '#__j2store_products' → strip '#__' prefix, then prepend real DB prefix
        $productsBase = substr($this->productsTable, 3); // strip '#__'
        $variantsBase = substr($this->variantsTable, 3);
        $quantitiesBase = substr($this->productQuantitiesTable, 3);
        $optionsBase  = substr($this->optionsTable, 3);

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $productsBase . '` (
            `' . $productCol . '`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_source_id`    INT UNSIGNED NOT NULL DEFAULT 0,
            `product_source`       VARCHAR(100) NOT NULL DEFAULT \'\',
            `product_type`         VARCHAR(50)  NOT NULL DEFAULT \'simple\',
            `visibility`           TINYINT(1)   NOT NULL DEFAULT 1,
            `enabled`              TINYINT(1)   NOT NULL DEFAULT 1,
            `taxprofile_id`        INT UNSIGNED NOT NULL DEFAULT 0,
            `vendor_id`            INT UNSIGNED NOT NULL DEFAULT 0,
            `addtocart_text`       VARCHAR(255) NOT NULL DEFAULT \'\',
            `up_sells`             TEXT         NOT NULL,
            `cross_sells`          TEXT         NOT NULL,
            `params`               TEXT         NOT NULL,
            PRIMARY KEY (`' . $productCol . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $variantsBase . '` (
            `' . $variantCol . '`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `sku`                  VARCHAR(255) NOT NULL DEFAULT \'\',
            `price`                DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
            `availability`         INT          DEFAULT NULL,
            `pricing_calculator`   VARCHAR(64)  NOT NULL DEFAULT \'standard\',
            `shipping`             TINYINT(1)   NOT NULL DEFAULT 1,
            `quantity_restriction` TINYINT(1)   NOT NULL DEFAULT 0,
            `allow_backorder`      TINYINT(1)   NOT NULL DEFAULT 0,
            `is_master`            TINYINT(1)   NOT NULL DEFAULT 1,
            `isdefault_variant`    TINYINT(1)   NOT NULL DEFAULT 0,
            `enabled`              TINYINT(1)   NOT NULL DEFAULT 1,
            `params`               TEXT         NOT NULL,
            `isdefault`            TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY (`' . $variantCol . '`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $quantitiesBase . '` (
            `' . $quantityCol . '` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `product_attributes`   TEXT         NOT NULL,
            `variant_id`           INT UNSIGNED NOT NULL DEFAULT 0,
            `quantity`             INT          NOT NULL DEFAULT 0,
            `on_hold`              INT          NOT NULL DEFAULT 0,
            `sold`                 INT          NOT NULL DEFAULT 0,
            PRIMARY KEY (`' . $quantityCol . '`),
            KEY `variantidx` (`variant_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

        if ($this->isJ6) {
            // J6: product_options is a mapping table (product_id → option_id)
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionsBase . '` (
                `' . $optionCol . '`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_id`            INT UNSIGNED NOT NULL DEFAULT 0,
                `parent_id`            INT UNSIGNED NOT NULL DEFAULT 0,
                `product_id`           INT UNSIGNED NOT NULL DEFAULT 0,
                `ordering`             INT          NOT NULL DEFAULT 0,
                `required`             TINYINT(1)   NOT NULL DEFAULT 0,
                `is_variant`           TINYINT(1)   NOT NULL DEFAULT 0,
                PRIMARY KEY (`' . $optionCol . '`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J6: options label table
            $optionsLabelBase = substr($this->optionsLabelTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionsLabelBase . '` (
                `j2commerce_option_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_name`          VARCHAR(255) NOT NULL DEFAULT \'\',
                `option_unique_name`   VARCHAR(255) NOT NULL DEFAULT \'\',
                `type`                 VARCHAR(50)  NOT NULL DEFAULT \'\',
                `enabled`              TINYINT(1)   NOT NULL DEFAULT 1,
                `ordering`             INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`j2commerce_option_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J6: option values table
            $optionValuesBase = substr($this->optionValuesTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionValuesBase . '` (
                `j2commerce_optionvalue_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_id`                INT UNSIGNED NOT NULL DEFAULT 0,
                `optionvalue_name`         VARCHAR(255) NOT NULL DEFAULT \'\',
                `optionvalue_image`        LONGTEXT     NOT NULL,
                `ordering`                 INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`j2commerce_optionvalue_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J6: product option values mapping table
            $productOptionValuesBase = substr($this->productOptionValuesTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $productOptionValuesBase . '` (
                `j2commerce_product_optionvalue_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `productoption_id`                     INT UNSIGNED NOT NULL DEFAULT 0,
                `optionvalue_id`                       INT UNSIGNED DEFAULT NULL,
                `parent_optionvalue`                   TEXT         NOT NULL,
                `product_optionvalue_price`            DECIMAL(15,8) NOT NULL DEFAULT 0.00000000,
                `product_optionvalue_prefix`           VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_weight`           DECIMAL(15,8) NOT NULL DEFAULT 0.00000000,
                `product_optionvalue_weight_prefix`    VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_sku`              VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_default`          INT          NOT NULL DEFAULT 0,
                `ordering`                             INT          NOT NULL DEFAULT 0,
                `product_optionvalue_attribs`          TEXT         NOT NULL,
                PRIMARY KEY (`j2commerce_product_optionvalue_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        } else {
            // J2Store 4: product_options is a mapping table (product_id → option_id)
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionsBase . '` (
                `' . $optionCol . '`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_id`            INT UNSIGNED NOT NULL DEFAULT 0,
                `parent_id`            INT UNSIGNED NOT NULL DEFAULT 0,
                `product_id`           INT UNSIGNED NOT NULL DEFAULT 0,
                `ordering`             INT          NOT NULL DEFAULT 0,
                `required`             TINYINT(1)   NOT NULL DEFAULT 0,
                `is_variant`           TINYINT(1)   NOT NULL DEFAULT 0,
                PRIMARY KEY (`' . $optionCol . '`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J2Store 4: options label table
            $optionsLabelBase = substr($this->optionsLabelTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionsLabelBase . '` (
                `j2store_option_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_name`          VARCHAR(255) NOT NULL DEFAULT \'\',
                `option_unique_name`   VARCHAR(255) NOT NULL DEFAULT \'\',
                `type`                 VARCHAR(50)  NOT NULL DEFAULT \'\',
                `enabled`              TINYINT(1)   NOT NULL DEFAULT 1,
                `ordering`             INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`j2store_option_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J2Store 4: option values table
            $optionValuesBase = substr($this->optionValuesTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $optionValuesBase . '` (
                `j2store_optionvalue_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `option_id`                INT UNSIGNED NOT NULL DEFAULT 0,
                `optionvalue_name`         VARCHAR(255) NOT NULL DEFAULT \'\',
                `optionvalue_image`        LONGTEXT     NOT NULL,
                `ordering`                 INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`j2store_optionvalue_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();

            // J2Store 4: product option values mapping table
            $productOptionValuesBase = substr($this->productOptionValuesTable, 3);
            $this->db->setQuery('CREATE TABLE IF NOT EXISTS `' . $prefix . $productOptionValuesBase . '` (
                `j2store_product_optionvalue_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `productoption_id`                  INT UNSIGNED NOT NULL DEFAULT 0,
                `optionvalue_id`                    INT UNSIGNED DEFAULT NULL,
                `parent_optionvalue`                TEXT         NOT NULL,
                `product_optionvalue_price`         DECIMAL(15,8) NOT NULL DEFAULT 0.00000000,
                `product_optionvalue_prefix`        VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_weight`        DECIMAL(15,8) NOT NULL DEFAULT 0.00000000,
                `product_optionvalue_weight_prefix` VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_sku`           VARCHAR(255) NOT NULL DEFAULT \'\',
                `product_optionvalue_default`       INT          NOT NULL DEFAULT 0,
                `ordering`                          INT          NOT NULL DEFAULT 0,
                `product_optionvalue_attribs`       TEXT         NOT NULL,
                PRIMARY KEY (`j2store_product_optionvalue_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
        }
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
                'visibility'        => 1,
                'enabled'           => $p['enabled'],
                'taxprofile_id'     => 0,
                'vendor_id'         => 0,
                'addtocart_text'    => '',
                'up_sells'          => '',
                'cross_sells'       => '',
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
                'availability'         => 1,
                'pricing_calculator'   => 'standard',
                'shipping'             => 1,
                'quantity_restriction' => 0,
                'allow_backorder'      => 0,
                'is_master'            => 1,
                'isdefault_variant'    => 1,
                'enabled'              => 1,
                'params'               => '{}',
                'isdefault'            => 1,
            ];
            $this->db->insertObject($this->variantsTable, $variant, $this->variantsPk);
            $this->seededVariantIds[] = (int)$this->db->insertid();
        }

        foreach ($this->seededVariantIds as $i => $variantId) {
            $quantity = (object)[
                'product_attributes' => '',
                'variant_id'         => $variantId,
                'quantity'           => $i === 0 ? 7 : 0,
                'on_hold'            => 0,
                'sold'               => 0,
            ];
            $this->db->insertObject($this->productQuantitiesTable, $quantity, $this->quantitiesPk);
            $this->seededQuantityIds[] = (int)$this->db->insertid();
        }

        // Options for product 0 only
        if ($this->isJ6) {
            // J6: seed via the three-table join:
            //   j2commerce_options (label) → j2commerce_product_options (mapping)
            //   → j2commerce_product_optionvalues → j2commerce_optionvalues (value label)
            foreach ([
                ['option_name' => 'Colour', 'option_value' => 'Red'],
                ['option_name' => 'Size',   'option_value' => 'M'],
            ] as $opt) {
                // 1. Option label
                $optLabel = (object)[
                    'option_name'        => $opt['option_name'],
                    'option_unique_name' => strtolower($opt['option_name']) . '_' . $ts,
                    'type'               => 'select',
                    'enabled'            => 1,
                    'ordering'           => 0,
                ];
                $this->db->insertObject($this->optionsLabelTable, $optLabel, 'j2commerce_option_id');
                $optionLabelId = (int) $this->db->insertid();
                $this->seededOptionLabelIds[] = $optionLabelId;

                // 2. Option value label
                $optValue = (object)[
                    'option_id'        => $optionLabelId,
                    'optionvalue_name' => $opt['option_value'],
                    'optionvalue_image' => '',
                    'ordering'         => 0,
                ];
                $this->db->insertObject($this->optionValuesTable, $optValue, 'j2commerce_optionvalue_id');
                $optionValueId = (int) $this->db->insertid();
                $this->seededOptionValueIds[] = $optionValueId;

                // 3. Product → option mapping
                $productOption = (object)[
                    'option_id'  => $optionLabelId,
                    'parent_id'  => 0,
                    'product_id' => $this->seededProductIds[0],
                    'ordering'   => 0,
                    'required'   => 0,
                    'is_variant' => 0,
                ];
                $this->db->insertObject($this->optionsTable, $productOption, $this->optionsPk);
                $productOptionId = (int) $this->db->insertid();
                $this->seededOptionIds[] = $productOptionId;

                // 4. Product option → option value mapping
                $productOptionValue = (object)[
                    'productoption_id'                  => $productOptionId,
                    'optionvalue_id'                    => $optionValueId,
                    'parent_optionvalue'                => '',
                    'product_optionvalue_price'         => 0,
                    'product_optionvalue_prefix'        => '+',
                    'product_optionvalue_weight'        => 0,
                    'product_optionvalue_weight_prefix' => '+',
                    'product_optionvalue_sku'           => '',
                    'product_optionvalue_default'       => 0,
                    'ordering'                          => 0,
                    'product_optionvalue_attribs'       => '',
                ];
                $this->db->insertObject($this->productOptionValuesTable, $productOptionValue, 'j2commerce_product_optionvalue_id');
                $this->seededProductOptionValueIds[] = (int) $this->db->insertid();
            }
        } else {
            // J2Store 4: same three-table join as J2Commerce 6, different table/PK names
            foreach ([
                ['option_name' => 'Colour', 'option_value' => 'Red'],
                ['option_name' => 'Size',   'option_value' => 'M'],
            ] as $opt) {
                // 1. Option label
                $optLabel = (object)[
                    'option_name'        => $opt['option_name'],
                    'option_unique_name' => strtolower($opt['option_name']) . '_' . $ts,
                    'type'               => 'select',
                    'enabled'            => 1,
                    'ordering'           => 0,
                ];
                $this->db->insertObject($this->optionsLabelTable, $optLabel, 'j2store_option_id');
                $optionLabelId = (int) $this->db->insertid();
                $this->seededOptionLabelIds[] = $optionLabelId;

                // 2. Option value label
                $optValue = (object)[
                    'option_id'         => $optionLabelId,
                    'optionvalue_name'  => $opt['option_value'],
                    'optionvalue_image' => '',
                    'ordering'          => 0,
                ];
                $this->db->insertObject($this->optionValuesTable, $optValue, 'j2store_optionvalue_id');
                $optionValueId = (int) $this->db->insertid();
                $this->seededOptionValueIds[] = $optionValueId;

                // 3. Product → option mapping
                $productOption = (object)[
                    'option_id'  => $optionLabelId,
                    'parent_id'  => 0,
                    'product_id' => $this->seededProductIds[0],
                    'ordering'   => 0,
                    'required'   => 0,
                    'is_variant' => 0,
                ];
                $this->db->insertObject($this->optionsTable, $productOption, $this->optionsPk);
                $productOptionId = (int) $this->db->insertid();
                $this->seededOptionIds[] = $productOptionId;

                // 4. Product option → option value mapping
                $productOptionValue = (object)[
                    'productoption_id'                  => $productOptionId,
                    'optionvalue_id'                    => $optionValueId,
                    'parent_optionvalue'                => '',
                    'product_optionvalue_price'         => 0,
                    'product_optionvalue_prefix'        => '+',
                    'product_optionvalue_weight'        => 0,
                    'product_optionvalue_weight_prefix' => '+',
                    'product_optionvalue_sku'           => '',
                    'product_optionvalue_default'       => 0,
                    'ordering'                          => 0,
                    'product_optionvalue_attribs'       => '',
                ];
                $this->db->insertObject($this->productOptionValuesTable, $productOptionValue, 'j2store_product_optionvalue_id');
                $this->seededProductOptionValueIds[] = (int) $this->db->insertid();
            }
        }
    }

    private function cleanupFixtures(): void
    {
        $toDelete = [];

        if ($this->isJ6) {
            $toDelete[] = [$this->productOptionValuesTable, 'j2commerce_product_optionvalue_id', $this->seededProductOptionValueIds];
            $toDelete[] = [$this->optionsTable,             $this->optionsPk,                    $this->seededOptionIds];
            $toDelete[] = [$this->optionValuesTable,        'j2commerce_optionvalue_id',          $this->seededOptionValueIds];
            $toDelete[] = [$this->optionsLabelTable,        'j2commerce_option_id',               $this->seededOptionLabelIds];
        } else {
            $toDelete[] = [$this->productOptionValuesTable, 'j2store_product_optionvalue_id', $this->seededProductOptionValueIds];
            $toDelete[] = [$this->optionsTable,             $this->optionsPk,                 $this->seededOptionIds];
            $toDelete[] = [$this->optionValuesTable,        'j2store_optionvalue_id',         $this->seededOptionValueIds];
            $toDelete[] = [$this->optionsLabelTable,        'j2store_option_id',              $this->seededOptionLabelIds];
        }

        $toDelete[] = [$this->productQuantitiesTable, $this->quantitiesPk, $this->seededQuantityIds];
        $toDelete[] = [$this->variantsTable,          $this->variantsPk,   $this->seededVariantIds];
        $toDelete[] = [$this->productsTable, $this->productsPk, $this->seededProductIds];
        $toDelete[] = ['#__content',         'id',              $this->seededContentIds];

        foreach ($toDelete as [$table, $pk, $ids]) {
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
