<?php
/**
 * Active Shop Detection Tests
 *
 * The plugin must read product data from the shop whose component is enabled,
 * not from whichever tables happen to exist. This suite seeds BOTH table sets
 * (#__j2commerce_* and #__j2store_*) with the same product IDs but different
 * titles and SKUs, switches the component states in #__extensions and calls the
 * public AJAX endpoint over HTTP (com_ajax, real guest session + CSRF token).
 * The returned comparison table shows which table set the plugin read.
 *
 * Every HTTP request boots a fresh plugin instance, so the cached detection
 * never leaks from one constellation into the next.
 *
 * J6 stack (J2Commerce 6 installed):
 *   - com_j2commerce on, com_j2store off (row ensured)    → J2Commerce 6 data
 *   - both on (migration in progress)                     → J2Commerce 6 data
 *   - com_j2commerce off, com_j2store on                  → J2Store data
 *   - both off (fallback: j2commerce tables present)      → J2Commerce 6 data
 * J5 stack (J2Store 4 installed):
 *   - com_j2store on, com_j2commerce off (row ensured)    → J2Store data
 *   - both on                                             → J2Commerce 6 data
 *
 * Cleanup: only rows and tables created here are removed; component states are
 * restored, because production lanes run suites one after another in the same
 * container.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class ActiveShopDetectionTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $group;
    private string $baseUrl = 'http://localhost';
    private string $cookieJar = '';
    private string $token = '';

    /** Shop definitions: table names and primary keys per shop. */
    private const SHOPS = [
        'j2commerce' => [
            'component'  => 'com_j2commerce',
            'products'   => 'j2commerce_products',
            'variants'   => 'j2commerce_variants',
            'quantities' => 'j2commerce_productquantities',
            'productPk'  => 'j2commerce_product_id',
            'variantPk'  => 'j2commerce_variant_id',
            'quantityPk' => 'j2commerce_productquantity_id',
            'label'      => 'J2Commerce',
        ],
        'j2store' => [
            'component'  => 'com_j2store',
            'products'   => 'j2store_products',
            'variants'   => 'j2store_variants',
            'quantities' => 'j2store_productquantities',
            'productPk'  => 'j2store_product_id',
            'variantPk'  => 'j2store_variant_id',
            'quantityPk' => 'j2store_productquantity_id',
            'label'      => 'J2Store',
        ],
    ];

    /** @var int[] Product IDs used in both table sets */
    private array $productIds = [];
    /** @var array<string, string[]> Expected titles per shop */
    private array $titles = [];
    /** @var array<string, string[]> Expected SKUs per shop */
    private array $skus = [];

    /** @var string[] Tables created here (with prefix) */
    private array $createdTables = [];
    /** @var array<int, array{0:string,1:string,2:int[]}> [table, pk, ids] rows seeded here */
    private array $seededRows = [];
    /** @var int[] #__content ids seeded here */
    private array $seededContentIds = [];
    private int $createdCategoryId = 0;
    /** @var int[] #__extensions ids inserted here */
    private array $insertedExtensionIds = [];
    /** @var array<int, int> extension_id => original enabled state */
    private array $originalStates = [];

    public function __construct()
    {
        $this->db    = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6  = getenv('J2COMMERCE_STACK') === 'j6';
        $this->group = $this->isJ6 ? 'j2commerce' : 'j2store';
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
        echo '=== Active Shop Detection Tests (stack: ' . ($this->isJ6 ? 'J6/J2Commerce 6' : 'J5/J2Store 4')
            . ", group: {$this->group}) ===\n\n";

        try {
            $this->prepare();

            if ($this->isJ6) {
                $this->runJ6Scenarios();
            } else {
                $this->runJ5Scenarios();
            }
        } catch (\Throwable $e) {
            $this->test('Suite ran without exception', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            $this->cleanup();
        }

        echo "\n=== Active Shop Detection Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------
    // Scenarios
    // -------------------------------------------------------------------------

    private function runJ6Scenarios(): void
    {
        $this->test('Precondition: com_j2commerce is installed and enabled',
            $this->componentEnabled('com_j2commerce'));

        echo "\n--- com_j2commerce on, com_j2store off ---\n";
        $this->setComponents(['com_j2commerce' => 1, 'com_j2store' => 0]);
        $this->assertShopDelivered('j2commerce', 'J2Commerce 6 active');

        echo "\n--- both components on (migration in progress) ---\n";
        $this->setComponents(['com_j2commerce' => 1, 'com_j2store' => 1]);
        $this->assertShopDelivered('j2commerce', 'both active');

        echo "\n--- com_j2commerce off, com_j2store on (counter-check) ---\n";
        $this->setComponents(['com_j2commerce' => 0, 'com_j2store' => 1]);
        $this->assertShopDelivered('j2store', 'J2Store active');

        echo "\n--- both components off (fallback to table check) ---\n";
        $this->setComponents(['com_j2commerce' => 0, 'com_j2store' => 0]);
        $this->assertShopDelivered('j2commerce', 'no active shop');
    }

    private function runJ5Scenarios(): void
    {
        $this->test('Precondition: com_j2store is installed and enabled',
            $this->componentEnabled('com_j2store'));

        echo "\n--- com_j2store on, com_j2commerce off (stale j2commerce tables) ---\n";
        $this->setComponents(['com_j2store' => 1, 'com_j2commerce' => 0]);
        $this->assertShopDelivered('j2store', 'J2Store active');

        echo "\n--- both components on (counter-check) ---\n";
        $this->setComponents(['com_j2store' => 1, 'com_j2commerce' => 1]);
        $this->assertShopDelivered('j2commerce', 'J2Commerce 6 active');
    }

    /**
     * Call the AJAX endpoint and assert that exactly $shop's data came back.
     */
    private function assertShopDelivered(string $shop, string $context): void
    {
        $other = $shop === 'j2commerce' ? 'j2store' : 'j2commerce';
        $html  = $this->requestComparison($context);

        if ($html === null) {
            return;
        }

        foreach ($this->titles[$shop] as $i => $title) {
            $this->test("[$context] " . self::SHOPS[$shop]['label'] . " title #$i delivered",
                str_contains($html, $title), 'Response: ' . substr(strip_tags($html), 0, 300));
        }

        foreach ($this->skus[$shop] as $i => $sku) {
            $this->test("[$context] " . self::SHOPS[$shop]['label'] . " SKU #$i delivered",
                str_contains($html, $sku));
        }

        foreach (array_merge($this->titles[$other], $this->skus[$other]) as $value) {
            $this->test("[$context] no " . self::SHOPS[$other]['label'] . " data ($value)",
                !str_contains($html, $value));
        }
    }

    // -------------------------------------------------------------------------
    // HTTP
    // -------------------------------------------------------------------------

    private function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body];
    }

    /**
     * Start a guest session and read its CSRF token (form field or script option).
     */
    private function startSession(): void
    {
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'pc-detect-cookies-');

        foreach (['/index.php?option=com_users&view=remind', '/index.php?option=com_users&view=login', '/'] as $page) {
            [$code, $body] = $this->curl($this->baseUrl . $page);

            if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $body, $m)
                || preg_match('/"csrf\.token"\s*:\s*"([a-f0-9]{32})"/i', $body, $m)) {
                $this->token = $m[1];

                return;
            }

            echo "  DIAG no token on $page: HTTP $code, " . strlen($body) . " bytes\n";
        }
    }

    /**
     * Request the comparison table for the seeded products.
     *
     * @return string|null  table HTML, or null after a recorded failure
     */
    private function requestComparison(string $context): ?string
    {
        $query = http_build_query([
            'option'   => 'com_ajax',
            'plugin'   => 'productcompare',
            'group'    => $this->group,
            'format'   => 'json',
            'products' => $this->productIds,
            $this->token => 1,
        ]);

        [$code, $body] = $this->curl($this->baseUrl . '/index.php?' . $query);

        $json = json_decode(trim($body), true);

        if (!is_array($json) && ($pos = strpos($body, '{"success"')) !== false) {
            $json = json_decode(substr($body, $pos), true);
        }

        $ok = $code === 200 && is_array($json) && ($json['success'] ?? false) === true
            && isset($json['data']['html']) && is_string($json['data']['html']);

        $this->test("[$context] AJAX endpoint returned comparison HTML", $ok,
            "HTTP $code, body: " . substr($body, 0, 300));

        return $ok ? $json['data']['html'] : null;
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function q()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function prepare(): void
    {
        $this->startSession();

        if ($this->token === '') {
            throw new \RuntimeException('No CSRF token could be obtained from the site');
        }

        $this->ensureComponentRow('com_j2commerce', 'J2Commerce');
        $this->ensureComponentRow('com_j2store', 'J2Store');

        foreach (array_keys(self::SHOPS) as $shop) {
            $this->ensureShopTables($shop);
        }

        $this->seedProducts();
    }

    private function tableExists(string $table): bool
    {
        $like = $this->db->quote($this->db->escape($this->db->getPrefix() . $table, true), false);

        return !empty($this->db->setQuery('SHOW TABLES LIKE ' . $like)->loadResult());
    }

    /**
     * Create minimal product/variant/quantity tables for a shop that is not
     * installed on this stack. Existing (real) tables are left untouched.
     */
    private function ensureShopTables(string $shop): void
    {
        $s      = self::SHOPS[$shop];
        $prefix = $this->db->getPrefix();

        $definitions = [
            $s['products'] => '`' . $s['productPk'] . '` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_source_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `product_source`    VARCHAR(100) NOT NULL DEFAULT \'\',
                `product_type`      VARCHAR(50)  NOT NULL DEFAULT \'simple\',
                `visibility`        TINYINT(1)   NOT NULL DEFAULT 1,
                `enabled`           TINYINT(1)   NOT NULL DEFAULT 1,
                PRIMARY KEY (`' . $s['productPk'] . '`)',
            $s['variants'] => '`' . $s['variantPk'] . '` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `product_id`   INT UNSIGNED  NOT NULL DEFAULT 0,
                `sku`          VARCHAR(255)  NOT NULL DEFAULT \'\',
                `price`        DECIMAL(15,5) NOT NULL DEFAULT 0.00000,
                `availability` INT           DEFAULT NULL,
                `is_master`    TINYINT(1)    NOT NULL DEFAULT 1,
                PRIMARY KEY (`' . $s['variantPk'] . '`)',
            $s['quantities'] => '`' . $s['quantityPk'] . '` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `quantity`   INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (`' . $s['quantityPk'] . '`)',
        ];

        foreach ($definitions as $table => $columns) {
            if ($this->tableExists($table)) {
                echo "  Table {$prefix}{$table} exists, using it\n";
                continue;
            }

            $this->db->setQuery('CREATE TABLE `' . $prefix . $table . '` (' . $columns
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
            $this->createdTables[] = $prefix . $table;
            echo "  Created table {$prefix}{$table}\n";
        }
    }

    private function maxId(string $table, string $pk): int
    {
        $query = $this->q()
            ->select('MAX(' . $this->db->quoteName($pk) . ')')
            ->from($this->db->quoteName('#__' . $table));

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function seedProducts(): void
    {
        $ts    = time();
        $catid = $this->ensureCatid();

        // IDs that are free in both product tables, so the same request IDs hit
        // different rows depending on which table set the plugin reads.
        $base = max(
            $this->maxId(self::SHOPS['j2commerce']['products'], self::SHOPS['j2commerce']['productPk']),
            $this->maxId(self::SHOPS['j2store']['products'], self::SHOPS['j2store']['productPk'])
        ) + 1000;
        $this->productIds = [$base, $base + 1];

        foreach (self::SHOPS as $shop => $s) {
            $this->titles[$shop] = [];
            $this->skus[$shop]   = [];
            $productRows         = [];
            $variantRows         = [];
            $quantityRows        = [];

            foreach ($this->productIds as $i => $productId) {
                $title = 'PcDetect ' . $s['label'] . ' Product ' . $i . ' ' . $ts;
                $sku   = 'PCDETECT-' . strtoupper($shop) . '-' . $i . '-' . $ts;

                $this->titles[$shop][] = $title;
                $this->skus[$shop][]   = $sku;

                $contentId = $this->insertArticle($title, $catid, $shop . '-' . $i . '-' . $ts);

                $product = $this->buildRow('#__' . $s['products'], [
                    $s['productPk']     => $productId,
                    'product_source_id' => $contentId,
                    'product_source'    => 'com_content',
                    'product_type'      => 'simple',
                    'visibility'        => 1,
                    'enabled'           => 1,
                    'params'            => '{}',
                ]);
                $this->db->insertObject('#__' . $s['products'], $product);
                $productRows[] = $productId;

                $variant = $this->buildRow('#__' . $s['variants'], [
                    'product_id'   => $productId,
                    'sku'          => $sku,
                    'price'        => 10 + $i,
                    'availability' => 1,
                    'is_master'    => 1,
                    'enabled'      => 1,
                    'params'       => '{}',
                ]);
                $this->db->insertObject('#__' . $s['variants'], $variant);
                $variantId     = (int) $this->db->insertid();
                $variantRows[] = $variantId;

                $quantity = $this->buildRow('#__' . $s['quantities'], [
                    'variant_id' => $variantId,
                    'quantity'   => 5,
                ]);
                $this->db->insertObject('#__' . $s['quantities'], $quantity);
                $quantityRows[] = (int) $this->db->insertid();
            }

            // Deleted in reverse order during cleanup.
            $this->seededRows[] = ['#__' . $s['products'], $s['productPk'], $productRows];
            $this->seededRows[] = ['#__' . $s['variants'], $s['variantPk'], $variantRows];
            $this->seededRows[] = ['#__' . $s['quantities'], $s['quantityPk'], $quantityRows];
        }

        echo '  Seeded product IDs ' . implode(', ', $this->productIds) . " in both table sets\n";
    }

    private function insertArticle(string $title, int $catid, string $aliasSuffix): int
    {
        $now     = date('Y-m-d H:i:s');
        $article = (object) [
            'title'      => $title,
            'alias'      => 'pcdetect-' . $aliasSuffix,
            'introtext'  => 'Description for ' . $title,
            'fulltext'   => '',
            'state'      => 1,
            'catid'      => $catid,
            'created'    => $now,
            'created_by' => 42,
            'modified'   => $now,
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
        $id = (int) $this->db->insertid();
        $this->seededContentIds[] = $id;

        return $id;
    }

    private function ensureCatid(): int
    {
        $query = $this->q()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->setLimit(1);
        $catid = (int) $this->db->setQuery($query)->loadResult();

        if ($catid) {
            return $catid;
        }

        $cat = (object) [
            'title' => 'PcDetect Category', 'alias' => 'pcdetect-category-' . time(),
            'extension' => 'com_content', 'published' => 1, 'access' => 1,
            'params' => '{}', 'metadata' => '{}', 'language' => '*',
            'path' => 'pcdetect-category', 'parent_id' => 1, 'level' => 1, 'lft' => 0, 'rgt' => 0,
        ];
        $this->db->insertObject('#__categories', $cat, 'id');
        $this->createdCategoryId = (int) $this->db->insertid();

        return $this->createdCategoryId;
    }

    /**
     * Build a row for a real or minimal table: every NOT NULL column without a
     * default gets a strict-mode-safe value, then the given overrides are applied
     * for the columns that exist.
     */
    private function buildRow(string $table, array $overrides): object
    {
        $columns = $this->db->getTableColumns($table, false);
        $row     = [];

        foreach ($columns as $name => $info) {
            $extra = strtolower((string) ($info->Extra ?? ''));
            $null  = strtoupper((string) ($info->Null ?? 'YES'));

            if (strpos($extra, 'auto_increment') !== false || $null === 'YES' || ($info->Default ?? null) !== null) {
                continue;
            }

            $row[$name] = $this->defaultForColumnType((string) ($info->Type ?? 'varchar'));
        }

        foreach ($overrides as $col => $value) {
            if (isset($columns[$col])) {
                $row[$col] = $value;
            }
        }

        return (object) $row;
    }

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

        if (strpos($t, 'enum') === 0 && preg_match("/^enum\\('([^']*)'/i", $type, $m)) {
            return $m[1];
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Component states
    // -------------------------------------------------------------------------

    /**
     * @return array<int, int>  extension_id => enabled for the component rows
     */
    private function componentRows(string $element): array
    {
        $query = $this->q()
            ->select([$this->db->quoteName('extension_id'), $this->db->quoteName('enabled')])
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
            ->where($this->db->quoteName('element') . ' = :element')
            ->bind(':element', $element, ParameterType::STRING);

        $rows = [];

        foreach ($this->db->setQuery($query)->loadObjectList() ?: [] as $row) {
            $rows[(int) $row->extension_id] = (int) $row->enabled;
        }

        return $rows;
    }

    private function componentEnabled(string $element): bool
    {
        return in_array(1, $this->componentRows($element), true);
    }

    /**
     * Make sure a component row exists (disabled when inserted here) and record
     * the original state of every row for restoration.
     */
    private function ensureComponentRow(string $element, string $name): void
    {
        $rows = $this->componentRows($element);

        if ($rows === []) {
            $row = $this->buildRow('#__extensions', [
                'package_id'     => 0,
                'name'           => $name,
                'type'           => 'component',
                'element'        => $element,
                'folder'         => '',
                'client_id'      => 1,
                'enabled'        => 0,
                'access'         => 1,
                'protected'      => 0,
                'locked'         => 0,
                'manifest_cache' => '{}',
                'params'         => '{}',
                'custom_data'    => '',
                'ordering'       => 0,
                'state'          => 0,
            ]);
            $this->db->insertObject('#__extensions', $row);
            $id = (int) $this->db->insertid();
            $this->insertedExtensionIds[] = $id;
            echo "  Inserted disabled component row $element (id $id)\n";

            return;
        }

        foreach ($rows as $id => $enabled) {
            $this->originalStates[$id] = $enabled;
        }
    }

    /**
     * @param array<string, int> $states element => enabled
     */
    private function setComponents(array $states): void
    {
        foreach ($states as $element => $enabled) {
            $ids = array_keys($this->componentRows($element));

            if ($ids === []) {
                throw new \RuntimeException("No #__extensions row for $element");
            }

            $query = $this->q()
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('enabled') . ' = ' . (int) $enabled)
                ->whereIn($this->db->quoteName('extension_id'), $ids);
            $this->db->setQuery($query)->execute();
        }
    }

    private function cleanup(): void
    {
        foreach ($this->originalStates as $id => $enabled) {
            $this->safeExecute(
                $this->q()
                    ->update($this->db->quoteName('#__extensions'))
                    ->set($this->db->quoteName('enabled') . ' = ' . (int) $enabled)
                    ->where($this->db->quoteName('extension_id') . ' = ' . (int) $id)
            );
        }

        if ($this->insertedExtensionIds !== []) {
            $this->safeExecute(
                $this->q()
                    ->delete($this->db->quoteName('#__extensions'))
                    ->whereIn($this->db->quoteName('extension_id'), $this->insertedExtensionIds)
            );
        }

        foreach (array_reverse($this->seededRows) as [$table, $pk, $ids]) {
            if ($ids !== []) {
                $this->safeExecute(
                    $this->q()->delete($this->db->quoteName($table))->whereIn($this->db->quoteName($pk), $ids)
                );
            }
        }

        if ($this->seededContentIds !== []) {
            $this->safeExecute(
                $this->q()->delete($this->db->quoteName('#__content'))
                    ->whereIn($this->db->quoteName('id'), $this->seededContentIds)
            );
        }

        if ($this->createdCategoryId) {
            $this->safeExecute(
                $this->q()->delete($this->db->quoteName('#__categories'))
                    ->where($this->db->quoteName('id') . ' = ' . $this->createdCategoryId)
            );
        }

        foreach ($this->createdTables as $table) {
            try {
                $this->db->setQuery('DROP TABLE IF EXISTS ' . $this->db->quoteName($table))->execute();
            } catch (\Throwable $e) {
                echo "  WARN could not drop $table: " . $e->getMessage() . "\n";
            }
        }

        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }

        // Restoration is part of the contract with the suites that follow.
        foreach ($this->originalStates as $id => $enabled) {
            $query = $this->q()
                ->select($this->db->quoteName('enabled'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('extension_id') . ' = ' . (int) $id);
            $this->test("Cleanup: extension $id enabled state restored",
                (int) $this->db->setQuery($query)->loadResult() === $enabled);
        }

        foreach ($this->createdTables as $table) {
            $this->test("Cleanup: table $table dropped",
                !$this->tableExists(substr($table, strlen($this->db->getPrefix()))));
        }
    }

    private function safeExecute($query): void
    {
        try {
            $this->db->setQuery($query)->execute();
        } catch (\Throwable $e) {
            echo '  WARN cleanup step failed: ' . $e->getMessage() . "\n";
        }
    }
}

$test = new ActiveShopDetectionTest();
exit($test->run() ? 0 : 1);
