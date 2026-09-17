<?php
/**
 * Page Render Tests — real HTTP requests through Apache.
 *
 * Joomla 6 + J2Commerce 6: a visible product (published article in a public
 * category, enabled product, master variant) is seeded and its detail page
 * (index.php?option=com_j2commerce&view=product&id=…) is requested. J2Commerce
 * imports the j2commerce plugin group while rendering the page, which registers
 * the plugin's onBeforeCompileHead/onAfterRender listeners. The response must contain
 * the plugin's CSS and JS, its script options (with form token and translated
 * texts), the compare button for the product and the compare bar and modal
 * before </body>. Then the comparison is requested exactly as
 * media/js/productcompare.js does it: POST to the ajaxUrl from the script
 * options, form-encoded products[] and the token from the page, same session.
 *
 * Both stacks: pages without a compare button (home page) and com_ajax
 * responses must not contain any of it.
 *
 * Joomla 5 + J2Store 4: the product page itself is not requested here. It needs
 * the complete J2Store catalogue setup (app layouts, prices, currency, tax and
 * store profile) that the test environment does not provide; the J2Store hooks
 * that render the button are exercised in 10-event-dispatch.php with the event
 * names and arguments J2Store 4.1.4 uses.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class PageRenderTest
{
    private const BASE_URL = 'http://localhost';

    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private bool $isJ6;
    private string $group;

    /** @var array<int, array{0:string,1:string,2:int}> rows to delete: table, pk, id */
    private array $seeded = [];

    /** @var array<int, string> product id => title */
    private array $titles = [];

    private string $cookieJar = '';

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
            echo "✗ $name" . ($message !== '' ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Page Render Tests (group: {$this->group}) ===\n";

        $enabled = $this->pluginEnabled();
        $this->test('Product Compare plugin is enabled in #__extensions', $enabled);

        if ($enabled) {
            try {
                if ($this->isJ6) {
                    $this->testProductPage();
                }

                $this->testHomePage();
                $this->testAjaxResponse();
            } finally {
                $this->cleanup();
            }
        }

        echo "\n=== Page Render Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function query()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function pluginEnabled(): bool
    {
        $query = $this->query()
            ->select('MAX(' . $this->db->quoteName('enabled') . ')')
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'));

        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }

    /**
     * GET or POST with a cookie jar, so page and AJAX request share the session.
     *
     * @return array{0:int,1:string} HTTP status and body
     */
    private function request(string $url, ?string $postBody = null, array $headers = []): array
    {
        if ($this->cookieJar === '') {
            $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'pc-page-cookies-');
        }

        if (!preg_match('#^https?://#', $url)) {
            $url = self::BASE_URL . $url;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($postBody !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postBody);
        }

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $body];
    }

    private function get(string $path): array
    {
        return $this->request($path, null, ['Accept: text/html']);
    }

    /**
     * All Joomla script options of a rendered page.
     */
    private function scriptOptions(string $body): array
    {
        $options = [];

        if (preg_match_all('#<script[^>]*class="joomla-script-options[^"]*"[^>]*>(.*?)</script>#is', $body, $m)) {
            foreach ($m[1] as $json) {
                $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES), true);

                if (\is_array($decoded)) {
                    $options = array_replace_recursive($options, $decoded);
                }
            }
        }

        return $options;
    }

    private function diagnose(string $body): string
    {
        $title = preg_match('#<title>(.*?)</title>#is', $body, $m) ? trim(strip_tags($m[1])) : '';
        $error = preg_match('#<(?:h1|div)[^>]*class="[^"]*(?:error|alert)[^"]*"[^>]*>(.*?)</#is', $body, $e) ? trim(strip_tags($e[1])) : '';

        return 'title "' . $title . '"' . ($error !== '' ? ', message "' . mb_substr($error, 0, 200) . '"' : '') . ', ' . strlen($body) . ' bytes';
    }

    private function assertUntouched(string $label, string $body): void
    {
        $this->test("$label: no compare bar", strpos($body, 'j2store-compare-bar') === false);
        $this->test("$label: no compare modal", strpos($body, 'j2store-compare-modal') === false);
        $this->test("$label: no plugin assets", strpos($body, 'media/plg_j2commerce_productcompare/') === false);
        $this->test("$label: no plugin script options", strpos($body, '"plg_j2commerce_productcompare"') === false);
    }

    // -------------------------------------------------------------------------

    private function testProductPage(): void
    {
        echo "\n--- J2Commerce 6 product page ---\n";

        $productId = $this->seedVisibleProduct('Compare Page Product A');
        $secondId  = $this->seedVisibleProduct('Compare Page Product B');
        $this->test('Two visible J2Commerce 6 products seeded', $productId > 0 && $secondId > 0);

        if ($productId <= 0 || $secondId <= 0) {
            return;
        }

        [$status, $body] = $this->get('/index.php?option=com_j2commerce&view=product&id=' . $productId);
        $this->test('Product page returns HTTP 200', $status === 200, "HTTP $status, " . $this->diagnose($body));

        $this->test('Compare button for the product is rendered',
            (bool) preg_match('#data-product-id="' . $productId . '"#', $body), $this->diagnose($body));
        if (!preg_match('#media/plg_j2commerce_productcompare/#i', $body)) {
            preg_match_all('#<(?:link|script)[^>]+(?:href|src)="([^"]+)"#i', $body, $assets);
            echo '  DIAG assets on the page: ' . implode(', ', array_slice($assets[1], 0, 25)) . "\n";
        }

        $this->test('Plugin stylesheet is included',
            (bool) preg_match('#<link[^>]+media/plg_j2commerce_productcompare/[^"]*\.css#i', $body));
        $this->test('Plugin script is included',
            (bool) preg_match('#<script[^>]+media/plg_j2commerce_productcompare/[^"]*\.js#i', $body));
        $this->test('Plugin script options are present',
            strpos($body, '"plg_j2commerce_productcompare"') !== false);
        $this->test('Script options point to com_ajax with the installed group',
            strpos($body, 'plugin=productcompare') !== false && strpos($body, 'group=j2commerce') !== false);

        $barPos  = strpos($body, 'id="j2store-compare-bar"');
        $modal   = strpos($body, 'id="j2store-compare-modal"');
        $bodyEnd = strripos($body, '</body>');
        $this->test('Compare bar is injected', $barPos !== false);
        $this->test('Compare modal is injected', $modal !== false);
        $this->test('Bar and modal sit before </body>',
            $barPos !== false && $modal !== false && $bodyEnd !== false && $barPos < $bodyEnd && $modal < $bodyEnd);
        $this->test('Bar and modal are injected once',
            substr_count($body, 'id="j2store-compare-bar"') === 1 && substr_count($body, 'id="j2store-compare-modal"') === 1);

        $options = $this->scriptOptions($body);
        $plugin  = $options['plg_j2commerce_productcompare'] ?? [];
        $texts   = $options['joomla.jtext'] ?? [];
        $token   = (string) ($plugin['token'] ?? '');
        $ajaxUrl = (string) ($plugin['ajaxUrl'] ?? '');

        $this->test('Script options carry a form token', preg_match('/^[a-f0-9]{32}$/', $token) === 1,
            'options: ' . json_encode($plugin));
        $this->test('Script options carry the com_ajax URL', str_contains($ajaxUrl, 'option=com_ajax'), $ajaxUrl);
        $this->test('JS texts are translated on the page',
            isset($texts['PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE'])
            && $texts['PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE'] !== 'PLG_J2COMMERCE_PRODUCTCOMPARE_JS_REMOVE',
            'joomla.jtext: ' . json_encode($texts));

        if ($token === '' || $ajaxUrl === '') {
            return;
        }

        echo "\n--- Comparison request as sent by productcompare.js ---\n";

        // productcompare.js: URLSearchParams with products[] and <token>=1, POST,
        // same session (credentials: same-origin).
        $jsBody = http_build_query(['products' => [$productId, $secondId], $token => 1], '', '&', PHP_QUERY_RFC1738);
        [$status, $response] = $this->request($ajaxUrl, $jsBody, [
            'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
            'Accept: application/json',
            'X-Requested-With: XMLHttpRequest',
        ]);
        $json = json_decode(trim($response), true);

        $this->test('Comparison request returns HTTP 200', $status === 200, "HTTP $status");
        $this->test('Comparison request succeeds', \is_array($json) && ($json['success'] ?? false) === true,
            'body: ' . mb_substr($response, 0, 300));

        $html = \is_array($json) ? (string) ($json['data']['html'] ?? '') : '';

        foreach ([$productId, $secondId] as $id) {
            $this->test("Comparison table contains product $id", str_contains($html, $this->titles[$id]),
                mb_substr(strip_tags($html), 0, 300));
        }

        // Without the token the same request is rejected.
        $noToken = http_build_query(['products' => [$productId, $secondId]], '', '&', PHP_QUERY_RFC1738);
        [, $rejected] = $this->request($ajaxUrl, $noToken, ['Content-Type: application/x-www-form-urlencoded;charset=UTF-8']);
        $rejectedJson = json_decode(trim($rejected), true);
        $this->test('Request without token is rejected',
            \is_array($rejectedJson) && ($rejectedJson['success'] ?? true) === false, mb_substr($rejected, 0, 200));
    }

    private function testHomePage(): void
    {
        echo "\n--- Page without compare buttons (home page) ---\n";

        [$status, $body] = $this->get('/');
        $this->test('Home page returns HTTP 200', $status === 200, "HTTP $status, " . $this->diagnose($body));
        $this->assertUntouched('Home page', $body);
    }

    private function testAjaxResponse(): void
    {
        echo "\n--- com_ajax response ---\n";

        // Without a token the endpoint answers with a JSON error; the response
        // must stay plain JSON without injected markup.
        [$status, $body] = $this->get('/index.php?option=com_ajax&plugin=productcompare&group=' . $this->group
            . '&format=json&products[]=1&products[]=2');
        $decoded = json_decode($body, true);

        $this->test('com_ajax answers with JSON', \is_array($decoded), "HTTP $status, body: " . mb_substr($body, 0, 200));
        $this->assertUntouched('com_ajax response', $body);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    private function publicCategoryId(): int
    {
        $query = $this->query()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__categories'))
            ->where($this->db->quoteName('extension') . ' = ' . $this->db->quote('com_content'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->where($this->db->quoteName('access') . ' = 1')
            ->order($this->db->quoteName('id') . ' ASC')
            ->setLimit(1);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    private function seedVisibleProduct(string $title): int
    {
        $catid = $this->publicCategoryId();
        $this->test('Public com_content category available', $catid > 0);

        if ($catid <= 0) {
            return 0;
        }

        $now     = Factory::getDate()->toSql();
        $title   = $title . ' ' . bin2hex(random_bytes(3));
        $article = (object) [
            'title' => $title, 'alias' => strtolower(str_replace(' ', '-', $title)),
            'introtext' => '<p>' . $title . '</p>', 'fulltext' => '', 'state' => 1, 'catid' => $catid,
            'created' => $now, 'created_by' => 42, 'modified' => $now, 'publish_up' => $now,
            'access' => 1, 'language' => '*', 'metadata' => '{}', 'attribs' => '{}',
            'images' => '{}', 'urls' => '{}', 'metadesc' => '', 'metakey' => '', 'note' => '',
            'featured' => 0, 'version' => 1, 'ordering' => 0, 'hits' => 0,
        ];
        $this->db->insertObject('#__content', $article, 'id');
        $articleId = (int) $this->db->insertid();
        $this->seeded[] = ['#__content', 'id', $articleId];

        $product = $this->buildRow('#__j2commerce_products', [
            'product_source_id' => $articleId,
            'product_source'    => 'com_content',
            'product_type'      => 'simple',
            'visibility'        => 1,
            'enabled'           => 1,
            'taxprofile_id'     => 0,
            'params'            => '{}',
            'created_on'        => $now,
            'modified_on'       => $now,
        ]);
        $this->db->insertObject('#__j2commerce_products', $product, 'j2commerce_product_id');
        $productId = (int) $this->db->insertid();
        $this->seeded[] = ['#__j2commerce_products', 'j2commerce_product_id', $productId];
        $this->titles[$productId] = $title;

        $variant = $this->buildRow('#__j2commerce_variants', [
            'product_id'     => $productId,
            'is_master'      => 1,
            'sku'            => 'CMP-PAGE-' . $productId,
            'price'          => 10,
            'manage_stock'   => 0,
            'availability'   => 1,
            'allow_backorder' => 0,
            'quantity_restriction' => 0,
            'shipping'       => 0,
            'params'         => '{}',
            'created_on'     => $now,
            'modified_on'    => $now,
        ]);
        $this->db->insertObject('#__j2commerce_variants', $variant, 'j2commerce_variant_id');
        $variantId = (int) $this->db->insertid();
        $this->seeded[] = ['#__j2commerce_variants', 'j2commerce_variant_id', $variantId];

        if (\in_array($this->db->getPrefix() . 'j2commerce_productquantities', $this->db->getTableList(), true)) {
            $quantity = $this->buildRow('#__j2commerce_productquantities', [
                'variant_id' => $variantId,
                'quantity'   => 10,
            ]);
            $this->db->insertObject('#__j2commerce_productquantities', $quantity, 'j2commerce_productquantity_id');
            $this->seeded[] = ['#__j2commerce_productquantities', 'j2commerce_productquantity_id', (int) $this->db->insertid()];
        }

        return $productId;
    }

    /**
     * Row with a safe value for every NOT NULL column without default, plus the
     * given values for the columns that exist on this table.
     */
    private function buildRow(string $table, array $values): object
    {
        $columns = $this->db->getTableColumns($table, false);
        $row     = [];

        foreach ($columns as $name => $info) {
            $extra = strtolower((string) ($info->Extra ?? ''));

            if (str_contains($extra, 'auto_increment') || strtoupper((string) ($info->Null ?? 'YES')) === 'YES' || ($info->Default ?? null) !== null) {
                continue;
            }

            $type = strtolower((string) ($info->Type ?? 'varchar'));

            if (preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|bit|year)/', $type)) {
                $row[$name] = 0;
            } elseif (str_starts_with($type, 'datetime') || str_starts_with($type, 'timestamp')) {
                $row[$name] = Factory::getDate()->toSql();
            } elseif (str_starts_with($type, 'date')) {
                $row[$name] = Factory::getDate()->format('Y-m-d');
            } elseif (str_starts_with($type, 'enum') && preg_match("/^enum\\('([^']*)'/i", (string) $info->Type, $m)) {
                $row[$name] = $m[1];
            } else {
                $row[$name] = '';
            }
        }

        foreach ($values as $name => $value) {
            if (isset($columns[$name])) {
                $row[$name] = $value;
            }
        }

        return (object) $row;
    }

    private function cleanup(): void
    {
        foreach (array_reverse($this->seeded) as [$table, $pk, $id]) {
            try {
                $query = $this->query()
                    ->delete($this->db->quoteName($table))
                    ->where($this->db->quoteName($pk) . ' = ' . (int) $id);
                $this->db->setQuery($query)->execute();
            } catch (\Throwable $e) {
                echo "  WARN could not delete $table #$id: {$e->getMessage()}\n";
            }
        }

        $this->seeded = [];

        if ($this->cookieJar !== '' && is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }
}

$test = new PageRenderTest();
exit($test->run() ? 0 : 1);
