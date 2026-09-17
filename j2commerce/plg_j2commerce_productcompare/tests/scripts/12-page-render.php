<?php
/**
 * Page Render Tests — real HTTP requests through Apache.
 *
 * Joomla 6 + J2Commerce 6: a visible product (published article in a public
 * category, enabled product, master variant) is seeded and its detail page
 * (index.php?option=com_j2commerce&view=product&id=…) is requested. J2Commerce
 * imports the j2commerce plugin group while rendering the page, which registers
 * the plugin's onAfterDispatch/onAfterRender listeners. The response must contain
 * the plugin's CSS and JS, its script options, the compare button for the
 * product and the compare bar and modal before </body>.
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
     * @return array{0:int,1:string} HTTP status and body
     */
    private function get(string $path): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 30, 'header' => "Accept: text/html\r\n"],
        ]);
        $body   = @file_get_contents(self::BASE_URL . $path, false, $context);
        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, $body === false ? '' : $body];
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

        $productId = $this->seedVisibleProduct();
        $this->test('Visible J2Commerce 6 product seeded', $productId > 0);

        if ($productId <= 0) {
            return;
        }

        [$status, $body] = $this->get('/index.php?option=com_j2commerce&view=product&id=' . $productId);
        $this->test('Product page returns HTTP 200', $status === 200, "HTTP $status, " . $this->diagnose($body));

        $this->test('Compare button for the product is rendered',
            (bool) preg_match('#data-product-id="' . $productId . '"#', $body), $this->diagnose($body));
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

    private function seedVisibleProduct(): int
    {
        $catid = $this->publicCategoryId();
        $this->test('Public com_content category available', $catid > 0);

        if ($catid <= 0) {
            return 0;
        }

        $now     = Factory::getDate()->toSql();
        $article = (object) [
            'title' => 'Compare Page Product', 'alias' => 'compare-page-product-' . time(),
            'introtext' => '<p>Compare page product</p>', 'fulltext' => '', 'state' => 1, 'catid' => $catid,
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
    }
}

$test = new PageRenderTest();
exit($test->run() ? 0 : 1);
