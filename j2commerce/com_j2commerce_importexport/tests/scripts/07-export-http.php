<?php
/**
 * REAL HTTP Export Controller Tests for J2Commerce Import/Export
 *
 * Unlike 05-export-controller.php (which only proves the controller exists via
 * reflection), this test drives the component end-to-end over HTTP:
 *
 *   1. Seeds a real product + variant into the live j2store / j2commerce
 *      product tables (same fixture pattern as 03-export-model.php).
 *   2. Authenticates against the Joomla administrator and obtains a valid CSRF
 *      form token.
 *   3. Performs an authenticated POST to task=export.export for CSV and JSON,
 *      asserting: HTTP 200, correct Content-Type, correct Content-Disposition
 *      (attachment + filename), and that the downloaded file CONTENT contains
 *      the seeded product/variant data.
 *   4. Performs negative CSRF requests (missing token, invalid token) and
 *      asserts the export is rejected and never leaks seeded data.
 *
 * Runs on BOTH stacks (J5 + J2Store/J2Commerce 4 and J6 + J2Commerce 6).
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class ExportHttpTest
{
    private string $baseUrl   = 'http://localhost';
    private string $cookieJar = '/tmp/j2ie-export-cookies.txt';
    private string $adminUser;
    private string $adminPass;

    private DatabaseInterface $db;
    private bool $isJ6;
    private string $tp;
    private string $pkProd;
    private string $pkVar;

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->adminUser = getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin';
        $this->adminPass = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#';

        $this->db = Factory::getContainer()->get(DatabaseInterface::class);

        $tables       = $this->db->getTableList();
        $prefix       = $this->db->getPrefix();
        $this->isJ6   = in_array($prefix . 'j2commerce_products', $tables, true);
        $this->tp     = $this->isJ6 ? 'j2commerce' : 'j2store';
        $this->pkProd = $this->isJ6 ? 'j2commerce_product_id' : 'j2store_product_id';
        $this->pkVar  = $this->isJ6 ? 'j2commerce_variant_id'  : 'j2store_variant_id';
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "PASS $name\n";
            $this->passed++;
        } else {
            echo "FAIL $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    /**
     * Perform an HTTP request. Returns ['code','headers','body'].
     * By default does NOT follow redirects so we can inspect the real status +
     * headers; pass $follow=true to transparently follow them (used only for the
     * login/token fetches, where the intermediate headers are irrelevant).
     */
    private function request(string $url, array $post = [], bool $follow = false): array
    {
        $ch = curl_init($url);

        // Collect response headers via a callback rather than CURLOPT_HEADER so
        // the body stream never contains header data. When following redirects,
        // cURL emits one header block per hop; reset the buffer on each new
        // status line so we keep only the FINAL response's headers. This avoids
        // CURLINFO_HEADER_SIZE (which only reports the last block) leaving
        // intermediate redirect headers prefixed into the body.
        $headers = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$headers) {
                if (stripos($line, 'HTTP/') === 0) {
                    $headers = '';
                }
                $headers .= $line;
                return strlen($line);
            },
        ]);
        if (!empty($post)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = $body === false ? '' : $body;

        return ['code' => (int) $code, 'headers' => $headers, 'body' => $body];
    }

    private function headerValue(string $headers, string $name): string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/mi', $headers, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    private function extractToken(string $html): ?string
    {
        // Hidden form-token input rendered by HTMLHelper::_('form.token').
        if (preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m)) {
            return $m[1];
        }
        // Joomla injects the session token into its script options on every
        // admin page: <script class="joomla-script-options new">{"csrf.token":"<hex>",...}</script>
        if (preg_match('/"csrf\.token":"([a-f0-9]{32})"/', $html, $m)) {
            return $m[1];
        }
        // Fallback: any task URL carrying the token (e.g. the admin logout link).
        if (preg_match('/[?&;]([a-f0-9]{32})=1/', $html, $m)) {
            return $m[1];
        }
        return null;
    }

    private function login(): bool
    {
        @unlink($this->cookieJar);

        // Fetch login page + token (with retries while Joomla settles)
        $token = null;
        for ($i = 0; $i < 5; $i++) {
            $r     = $this->request($this->baseUrl . '/administrator/index.php', [], true);
            $token = $this->extractToken($r['body']);
            if ($token) {
                break;
            }
            sleep(2);
        }
        if (!$token) {
            return false;
        }

        $r = $this->request($this->baseUrl . '/administrator/index.php', [
            'username' => $this->adminUser,
            'passwd'   => $this->adminPass,
            'option'   => 'com_login',
            'task'     => 'login',
            'return'   => base64_encode('index.php'),
            $token     => '1',
        ], true);

        return strpos($r['body'], 'task=logout') !== false
            || strpos($r['body'], 'com_cpanel') !== false;
    }

    private function freshToken(): ?string
    {
        // The form token is the same session-wide; try the component dashboard
        // first, then fall back to the admin home page (which always injects the
        // csrf.token script option). Emit diagnostics if neither yields a token.
        $urls = [
            $this->baseUrl . '/administrator/index.php?option=com_j2commerce_importexport',
            $this->baseUrl . '/administrator/index.php',
        ];
        foreach ($urls as $url) {
            $r     = $this->request($url, [], true);
            $token = $this->extractToken($r['body']);
            if ($token) {
                return $token;
            }
            echo '  token lookup: HTTP ' . $r['code'] . ' from ' . $url
                . ' (' . strlen($r['body']) . " bytes, no token)\n";
        }
        return null;
    }

    private array $fixture = [];

    private function seedFixture(): bool
    {
        $sku = 'HTTPEXPORT-' . strtoupper(bin2hex(random_bytes(4)));

        $article = (object) [
            'title'      => 'HTTP Export Test Product',
            'alias'      => 'http-export-test-product-' . uniqid(),
            'introtext'  => '',
            'fulltext'   => '',
            'state'      => 1,
            'catid'      => 2,
            'language'   => '*',
            'access'     => 1,
            'created'    => date('Y-m-d H:i:s'),
            'created_by' => 42,
            'modified'   => date('Y-m-d H:i:s'),
            'publish_up' => date('Y-m-d H:i:s'),
            'attribs'    => '{}',
            'metadata'   => '{}',
            'metadesc'   => '',
            'metakey'    => '',
            'images'     => '{}',
            'urls'       => '{}',
            'note'       => '',
            'featured'   => 0,
            'version'    => 1,
            'ordering'   => 0,
            'hits'       => 0,
        ];
        $this->db->insertObject('#__content', $article, 'id');
        $articleId = (int) $this->db->insertid();

        $product = (object) [
            'product_source_id' => $articleId,
            'product_source'    => 'com_content',
            'product_type'      => 'simple',
            'visibility'        => 1,
            'enabled'           => 1,
            'taxprofile_id'     => 0,
            'vendor_id'         => 0,
            'addtocart_text'    => '',
            'up_sells'          => '',
            'cross_sells'       => '',
            'params'            => '{}',
        ];
        $this->db->insertObject('#__' . $this->tp . '_products', $product, $this->pkProd);
        $productId = (int) $this->db->insertid();

        $variant = (object) [
            'product_id'           => $productId,
            'sku'                  => $sku,
            'price'                => 9.99,
            'pricing_calculator'   => 'standard',
            'shipping'             => 1,
            'quantity_restriction' => 0,
            'allow_backorder'      => 0,
            'is_master'            => 1,
            'isdefault_variant'    => 1,
            'enabled'              => 1,
            'params'               => '{}',
        ];
        $this->db->insertObject('#__' . $this->tp . '_variants', $variant, $this->pkVar);
        $variantId = (int) $this->db->insertid();

        $this->fixture = [
            'sku'       => $sku,
            'articleId' => $articleId,
            'productId' => $productId,
            'variantId' => $variantId,
        ];

        return $articleId > 0 && $productId > 0 && $variantId > 0;
    }

    private function cleanupFixture(): void
    {
        if (empty($this->fixture)) {
            return;
        }
        try {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_variants')
                . ' WHERE ' . $this->db->quoteName($this->pkVar) . ' = ' . (int) $this->fixture['variantId'])->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_products')
                . ' WHERE ' . $this->db->quoteName($this->pkProd) . ' = ' . (int) $this->fixture['productId'])->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__content')
                . ' WHERE id = ' . (int) $this->fixture['articleId'])->execute();
            echo "Cleanup: removed fixture (article {$this->fixture['articleId']}, product {$this->fixture['productId']})\n";
        } catch (\Throwable $e) {
            echo 'Cleanup warning: ' . $e->getMessage() . "\n";
        }
    }

    private function exportUrl(): string
    {
        return $this->baseUrl . '/administrator/index.php?option=com_j2commerce_importexport&task=export.export';
    }

    public function run(): bool
    {
        echo "=== REAL HTTP Export Controller Tests ===\n";
        echo 'Stack: ' . strtoupper($this->tp) . "\n\n";

        $tables = $this->db->getTableList();
        $prefix = $this->db->getPrefix();
        $hasJ4  = in_array($prefix . 'j2store_products', $tables, true);

        if (!$this->isJ6 && !$hasJ4) {
            $this->test('J2Commerce tables are present', false, 'neither j2store nor j2commerce product tables found');
            return $this->summary();
        }

        // --- Authenticate ---
        echo "--- Admin authentication ---\n";
        $loggedIn = $this->login();
        $this->test('Admin login succeeds', $loggedIn, 'could not authenticate against /administrator');
        if (!$loggedIn) {
            return $this->summary();
        }

        $token = $this->freshToken();
        $this->test('CSRF form token obtained', $token !== null, 'no token on component dashboard');
        if ($token === null) {
            return $this->summary();
        }

        // --- Seed fixture ---
        echo "\n--- Seeding product fixture ---\n";
        $seeded = $this->seedFixture();
        $this->test('Fixture product + variant seeded', $seeded);
        if (!$seeded) {
            $this->cleanupFixture();
            return $this->summary();
        }
        $sku = $this->fixture['sku'];
        echo "Seeded SKU: $sku\n";

        // --- Positive: CSV export ---
        echo "\n--- Authenticated CSV export ---\n";
        $csv = $this->request($this->exportUrl(), [
            'type'   => 'variants',
            'format' => 'csv',
            $token   => '1',
        ]);
        $csvType = $this->headerValue($csv['headers'], 'Content-Type');
        $csvDisp = $this->headerValue($csv['headers'], 'Content-Disposition');

        if ($csv['code'] !== 200) {
            echo "  [diag] CSV export body (first 600 chars): "
                . substr(preg_replace('/\s+/', ' ', strip_tags($csv['body'])), 0, 600) . "\n";
        }
        $this->test('CSV export returns HTTP 200', $csv['code'] === 200, "got HTTP {$csv['code']}");
        $this->test('CSV Content-Type is text/csv', stripos($csvType, 'text/csv') !== false, "Content-Type: $csvType");
        $this->test('CSV Content-Disposition is attachment', stripos($csvDisp, 'attachment') !== false, "Content-Disposition: $csvDisp");
        $this->test('CSV filename is variants_*.csv',
            (bool) preg_match('/filename="variants_[\w\-]+\.csv"/', $csvDisp), "Content-Disposition: $csvDisp");
        $this->test('CSV body contains seeded SKU', strpos($csv['body'], $sku) !== false,
            'downloaded CSV did not contain the seeded product data');

        // --- Positive: JSON export ---
        echo "\n--- Authenticated JSON export ---\n";
        $json = $this->request($this->exportUrl(), [
            'type'   => 'variants',
            'format' => 'json',
            $token   => '1',
        ]);
        $jsonType = $this->headerValue($json['headers'], 'Content-Type');
        $jsonDisp = $this->headerValue($json['headers'], 'Content-Disposition');
        $decoded  = json_decode($json['body'], true);

        if ($json['code'] !== 200) {
            echo "  [diag] JSON export raw body (first 800 chars): "
                . substr(trim($json['body']), 0, 800) . "\n";
        }
        $this->test('JSON export returns HTTP 200', $json['code'] === 200, "got HTTP {$json['code']}");
        $this->test('JSON Content-Type is application/json', stripos($jsonType, 'application/json') !== false, "Content-Type: $jsonType");
        $this->test('JSON Content-Disposition is attachment', stripos($jsonDisp, 'attachment') !== false, "Content-Disposition: $jsonDisp");
        $this->test('JSON filename is variants_*.json',
            (bool) preg_match('/filename="variants_[\w\-]+\.json"/', $jsonDisp), "Content-Disposition: $jsonDisp");
        $this->test('JSON body is valid JSON', is_array($decoded), 'response body did not decode as JSON');
        $this->test('JSON body contains seeded SKU', strpos($json['body'], $sku) !== false,
            'downloaded JSON did not contain the seeded product data');

        // --- Negative: missing CSRF token ---
        echo "\n--- Negative: missing CSRF token ---\n";
        $noToken = $this->request($this->exportUrl(), [
            'type'   => 'variants',
            'format' => 'csv',
        ]);
        $noTokenLeaks = strpos($noToken['body'], $sku) !== false;
        $this->test('Missing-token export does NOT leak seeded data', !$noTokenLeaks,
            'CSRF check failed to block an export without a CSRF token');
        $this->test('Missing-token export is rejected (invalid token / not a CSV download)',
            $noTokenLeaks === false
                && (stripos($this->headerValue($noToken['headers'], 'Content-Type'), 'text/csv') === false
                    || $noToken['code'] >= 400
                    || stripos($noToken['body'], 'JINVALID_TOKEN') !== false
                    || stripos($noToken['body'], 'token') !== false),
            "HTTP {$noToken['code']}, body: " . substr(trim($noToken['body']), 0, 120));

        // --- Negative: invalid CSRF token ---
        echo "\n--- Negative: invalid CSRF token ---\n";
        $badToken = str_repeat('0', 32);
        $invalid  = $this->request($this->exportUrl(), [
            'type'   => 'variants',
            'format' => 'csv',
            $badToken => '1',
        ]);
        $this->test('Invalid-token export does NOT leak seeded data',
            strpos($invalid['body'], $sku) === false,
            'CSRF check failed to block a forged-token export');

        // --- Cleanup ---
        echo "\n--- Cleanup ---\n";
        $this->cleanupFixture();

        return $this->summary();
    }

    private function summary(): bool
    {
        echo "\n=== HTTP Export Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo 'Total:  ' . ($this->passed + $this->failed) . "\n";
        return $this->failed === 0;
    }
}

$test = new ExportHttpTest();
exit($test->run() ? 0 : 1);
