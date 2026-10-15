<?php
/**
 * REAL HTTP Import Controller Tests for J2Commerce Import/Export
 *
 * Exercises the import controller/HTTP layer end-to-end (the round-trip model
 * test 04b only covers the model layer):
 *
 *   1. Authenticates against the Joomla administrator + obtains a CSRF token.
 *   2. Uploads a small JSON product file via a multipart POST to
 *      task=import.upload, previews it, then runs task=import.process.
 *   3. Asserts the product is actually created in the live j2store /
 *      j2commerce product tables.
 *   4. Negative: a multipart upload without a CSRF token is rejected.
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

class ImportHttpTest
{
    private string $baseUrl   = 'http://localhost';
    private string $cookieJar = '/tmp/j2ie-import-cookies.txt';
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

        $this->db     = Factory::getContainer()->get(DatabaseInterface::class);
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

    private function request(string $url, $post = [], bool $follow = false): array
    {
        $ch = curl_init($url);

        // Collect response headers via a callback rather than CURLOPT_HEADER so
        // the body stream never contains header data. When following redirects,
        // cURL emits one header block per hop; reset the buffer on each new
        // status line so we keep only the FINAL response's headers. This avoids
        // CURLINFO_HEADER_SIZE (which only reports the last block) leaving
        // intermediate redirect headers prefixed into the body and breaking
        // token scraping / JSON decoding.
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
            // Arrays containing CURLFile must be passed as-is (multipart);
            // plain arrays are url-encoded.
            $hasFile = false;
            foreach ((array) $post as $v) {
                if ($v instanceof \CURLFile) {
                    $hasFile = true;
                    break;
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasFile ? $post : http_build_query($post));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $body = $body === false ? '' : $body;

        return ['code' => (int) $code, 'headers' => $headers, 'body' => $body, 'json' => json_decode($body, true)];
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

    private function writeImportFile(string $sku, string $title, string $alias, array $extra = []): string
    {
        $payload = [
            'products' => [
                [
                    'title'          => $title,
                    'alias'          => $alias,
                    'category'       => 'HTTP Import Test Category',
                    'sku'            => $sku,
                    'price'          => 19.95,
                    'visibility'     => 1,
                    'addtocart_text' => '',
                    'up_sells'       => '',
                    'cross_sells'    => '',
                    'variants'       => [
                        [
                            'sku'                  => $sku . '-V1',
                            'price'                => 19.95,
                            'is_master'            => 1,
                            'isdefault_variant'    => 1,
                            'pricing_calculator'   => 'standard',
                            'shipping'             => 1,
                            'quantity_restriction' => 0,
                            'allow_backorder'      => 0,
                            'quantity'             => 7,
                        ],
                    ],
                    'product_images' => [],
                    'options'        => [],
                    'filters'        => [],
                    'files'          => [],
                    'tags'           => [],
                    'custom_fields'  => [],
                ],
            ],
        ];

        $payload['products'][0] = array_replace($payload['products'][0], $extra);
        $path = '/tmp/j2ie-http-import-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $path;
    }

    private function findVariantBySku(string $sku): ?array
    {
        $this->db->setQuery(
            'SELECT * FROM ' . $this->db->quoteName('#__' . $this->tp . '_variants')
            . ' WHERE ' . $this->db->quoteName('sku') . ' = ' . $this->db->quote($sku)
        );
        $row = $this->db->loadAssoc();
        return $row ?: null;
    }

    private function cleanupBySku(string $variantSku): void
    {
        $variant = $this->findVariantBySku($variantSku);
        if (!$variant) {
            return;
        }
        $productId = (int) ($variant['product_id'] ?? 0);
        try {
            $articleId = 0;
            if ($productId > 0) {
                $this->db->setQuery(
                    'SELECT ' . $this->db->quoteName('product_source_id')
                    . ' FROM ' . $this->db->quoteName('#__' . $this->tp . '_products')
                    . ' WHERE ' . $this->db->quoteName($this->pkProd) . ' = ' . $productId
                );
                $articleId = (int) $this->db->loadResult();

                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_productquantities')
                    . ' WHERE variant_id IN (SELECT ' . $this->db->quoteName($this->pkVar)
                    . ' FROM ' . $this->db->quoteName('#__' . $this->tp . '_variants')
                    . ' WHERE product_id = ' . $productId . ')')->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_variants')
                    . ' WHERE ' . $this->db->quoteName('product_id') . ' = ' . $productId)->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_products')
                    . ' WHERE ' . $this->db->quoteName($this->pkProd) . ' = ' . $productId)->execute();
            }
            if ($articleId > 0) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__menu')
                    . ' WHERE link = ' . $this->db->quote('index.php?option=com_content&view=article&id=' . $articleId))->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__content')
                    . ' WHERE id = ' . $articleId)->execute();
            }
            echo "Cleanup: removed imported product $productId (article $articleId)\n";
        } catch (\Throwable $e) {
            echo 'Cleanup warning: ' . $e->getMessage() . "\n";
        }
    }

    public function run(): bool
    {
        echo "=== REAL HTTP Import Controller Tests ===\n";
        echo 'Stack: ' . strtoupper($this->tp) . "\n\n";

        $tables = $this->db->getTableList();
        $prefix = $this->db->getPrefix();
        $hasJ4  = in_array($prefix . 'j2store_products', $tables, true);

        if (!$this->isJ6 && !$hasJ4) {
            $this->test('J2Commerce tables are present', false, 'neither j2store nor j2commerce product tables found');
            return $this->summary();
        }

        echo "--- Admin authentication ---\n";
        $loggedIn = $this->login();
        $this->test('Admin login succeeds', $loggedIn);
        if (!$loggedIn) {
            return $this->summary();
        }

        $token = $this->freshToken();
        $this->test('CSRF form token obtained', $token !== null);
        if ($token === null) {
            return $this->summary();
        }

        $sku   = 'HTTPIMPORT-' . strtoupper(bin2hex(random_bytes(4)));
        $title = 'HTTP Import Test Product ' . $sku;
        $alias = 'http-import-test-' . strtolower($sku);
        $variantSku = $sku . '-V1';

        $uploadUrl  = $this->baseUrl . '/administrator/index.php?option=com_j2commerce_importexport&task=import.upload';
        $processUrl = $this->baseUrl . '/administrator/index.php?option=com_j2commerce_importexport&task=import.process&format=json';
        $previewUrl = $this->baseUrl . '/administrator/index.php?option=com_j2commerce_importexport&task=import.preview&format=json';

        $missing = $this->request($previewUrl, [$token => '1']);
        $this->test('Preview without upload returns a flat JSON error',
            ($missing['json']['success'] ?? null) === false && !empty($missing['json']['message'])
            && !isset($missing['json']['data']));

        // --- Negative: multipart upload without CSRF token ---
        echo "\n--- Negative: upload without CSRF token ---\n";
        $fileNoToken = $this->writeImportFile($sku, $title, $alias);
        $noTokenResp = $this->request($uploadUrl, [
            'import_file' => new \CURLFile($fileNoToken, 'application/json', 'import.json'),
            'type'        => 'products_full',
        ]);
        @unlink($fileNoToken);
        $noTokenAccepted = isset($noTokenResp['json']['success']) && $noTokenResp['json']['success'] === true;
        $this->test('Upload without a token is rejected', !$noTokenAccepted,
            "HTTP {$noTokenResp['code']}, body: " . substr(trim($noTokenResp['body']), 0, 120));
        $this->test('Upload without a token did not create the product',
            $this->findVariantBySku($variantSku) === null,
            'a product was created without a valid CSRF token');

        // --- Positive: authenticated multipart upload ---
        echo "\n--- Authenticated multipart upload ---\n";
        $file   = $this->writeImportFile($sku, $title, $alias, [
            'menu_item' => ['title' => $title, 'alias' => $alias],
        ]);
        $upload = $this->request($uploadUrl, [
            'import_file' => new \CURLFile($file, 'application/json', 'import.json'),
            'type'        => 'products_full',
            $token        => '1',
        ]);
        @unlink($file);

        $uploadOk = isset($upload['json']['success']) && $upload['json']['success'] === true;
        if (!$uploadOk) {
            echo "  [diag] upload body (first 600 chars): "
                . substr(preg_replace('/\s+/', ' ', strip_tags($upload['body'])), 0, 600) . "\n";
        }
        $this->test('Upload returns success JSON', $uploadOk,
            "HTTP {$upload['code']}, body: " . substr(trim($upload['body']), 0, 160));
        $this->test('Upload uses the flat file response contract',
            isset($upload['json']['file']) && !isset($upload['json']['data']));

        if ($uploadOk) {
            $uploadedFile = $upload['json']['file'];
            foreach ([$previewUrl, $processUrl] as $protectedUrl) {
                $withoutToken = $this->request($protectedUrl, ['type' => 'products_full']);
                $this->test('Preview/process without CSRF token is rejected',
                    ($withoutToken['json']['success'] ?? false) !== true);
            }
            foreach ([$previewUrl, $processUrl] as $identityUrl) {
                foreach (['', 'superseded-upload.json'] as $wrongFile) {
                    $rejected = $this->request($identityUrl, ['file' => $wrongFile, $token => '1']);
                    $this->test('Preview/process rejects missing or superseded upload identity',
                        ($rejected['json']['success'] ?? null) === false && !empty($rejected['json']['message']));
                }
            }
            $preview = $this->request($previewUrl, ['file' => $uploadedFile, $token => '1']);
            $this->test('Preview returns the real uploaded product in flat JSON',
                ($preview['json']['success'] ?? false) === true
                && ($preview['json']['preview']['total'] ?? 0) === 1
                && ($preview['json']['preview']['rows'][0]['title'] ?? '') === $title
                && in_array('title', $preview['json']['preview']['headers'] ?? [], true)
                && !isset($preview['json']['data']));
            $this->test('Preview does not import records', $this->findVariantBySku($variantSku) === null);

            foreach (['products', 'variants', 'prices'] as $unsupported) {
                $rejected = $this->request($processUrl, ['type' => $unsupported, 'file' => $uploadedFile, $token => '1']);
                $this->test('HTTP rejects unsupported type ' . $unsupported,
                    ($rejected['json']['success'] ?? null) === false && !empty($rejected['json']['message']));
            }
            foreach ([
                ['quantity_mode' => 'multiply'], ['update_existing' => 'false'],
                ['default_category' => -1], ['menu_access' => '1x'],
            ] as $invalidOptions) {
                $rejected = $this->request($processUrl, [
                    'type' => 'products_full', 'file' => $uploadedFile, 'options' => $invalidOptions, $token => '1',
                ]);
                $this->test('HTTP rejects invalid option ' . array_key_first($invalidOptions),
                    ($rejected['json']['success'] ?? null) === false && !empty($rejected['json']['message']));
            }
            $this->test('Rejected requests do not import records', $this->findVariantBySku($variantSku) === null);

            // --- Process the uploaded file ---
            echo "\n--- Process import ---\n";
            $this->db->setQuery('SELECT menutype FROM ' . $this->db->quoteName('#__menu_types') . ' ORDER BY id');
            $menutype = (string) $this->db->loadResult();
            $process = $this->request($processUrl, [
                'type'  => 'products_full',
                'file' => $uploadedFile,
                'options' => [
                    'update_existing' => '1', 'quantity_mode' => 'replace',
                    'create_menu' => '1', 'default_menutype' => $menutype,
                    'default_category' => '2', 'menu_access' => '1',
                    // menu_published deliberately absent: unchecked must mean false.
                ],
                $token  => '1',
            ]);
            $procJson = $process['json'];
            $procOk   = isset($procJson['success']) && $procJson['success'] === true;
            $this->test('Process returns success JSON', $procOk,
                "HTTP {$process['code']}, body: " . substr(trim($process['body']), 0, 160));
            $this->test('Process reports at least one imported product',
                (int) ($procJson['imported'] ?? 0) >= 1,
                'imported=' . ($procJson['imported'] ?? 'n/a'));

            // --- Verify the product really landed in the DB ---
            echo "\n--- Verify product created in DB ---\n";
            $variant = $this->findVariantBySku($variantSku);
            $this->test('Imported variant exists in ' . $this->tp . '_variants', $variant !== null,
                "no variant row with SKU $variantSku");

            if ($variant !== null) {
                $quantity = function () use ($variant): int {
                    $this->db->setQuery('SELECT quantity FROM ' . $this->db->quoteName('#__' . $this->tp . '_productquantities')
                        . ' WHERE variant_id = ' . (int) $variant[$this->pkVar]);
                    return (int) $this->db->loadResult();
                };
                $this->test('Initial stock matches uploaded quantity', $quantity() === 7);
                foreach (['add' => 14, 'replace' => 7] as $mode => $expected) {
                    $repeatFile = $this->writeImportFile($sku, $title, $alias);
                    try {
                        $repeatUpload = $this->request($uploadUrl, [
                            'import_file' => new \CURLFile($repeatFile, 'application/json', 'import.json'),
                            $token => '1',
                        ]);
                        $this->test('Re-upload succeeds for quantity mode ' . $mode,
                            ($repeatUpload['json']['success'] ?? false) === true);
                        $updated = $this->request($processUrl, [
                            'type' => 'products_full',
                            'file' => $repeatUpload['json']['file'] ?? '',
                            'options' => ['update_existing' => '1', 'quantity_mode' => $mode],
                            $token => '1',
                        ]);
                        $this->test('HTTP stock mode ' . $mode . ' updates the same variant',
                            ($updated['json']['imported'] ?? 0) === 1 && $quantity() === $expected);
                    } finally {
                        unlink($repeatFile);
                    }
                }
                $this->test('Imported variant price matches', abs((float) ($variant['price'] ?? 0) - 19.95) < 0.001,
                    'price=' . ($variant['price'] ?? 'n/a'));

                $productId = (int) ($variant['product_id'] ?? 0);
                $this->db->setQuery(
                    'SELECT ' . $this->db->quoteName('product_source_id')
                    . ' FROM ' . $this->db->quoteName('#__' . $this->tp . '_products')
                    . ' WHERE ' . $this->db->quoteName($this->pkProd) . ' = ' . $productId
                );
                $articleId = (int) $this->db->loadResult();
                $this->test('Imported product is linked to a Joomla article', $articleId > 0,
                    "product_source_id=$articleId");

                if ($articleId > 0) {
                    $this->db->setQuery('SELECT catid FROM ' . $this->db->quoteName('#__content') . ' WHERE id = ' . $articleId);
                    $this->test('HTTP forwards the default category', (int) $this->db->loadResult() === 2);
                    $this->db->setQuery('SELECT menutype, published, access FROM ' . $this->db->quoteName('#__menu')
                        . ' WHERE link = ' . $this->db->quote('index.php?option=com_content&view=article&id=' . $articleId));
                    $menu = $this->db->loadAssoc();
                    $this->test('HTTP forwards menu defaults and absent publication checkbox is false',
                        $menu && $menu['menutype'] === $menutype && (int) $menu['published'] === 0 && (int) $menu['access'] === 1);
                    $this->db->setQuery('SELECT title FROM ' . $this->db->quoteName('#__content')
                        . ' WHERE id = ' . $articleId);
                    $createdTitle = (string) $this->db->loadResult();
                    $this->test('Created article title matches imported title', $createdTitle === $title,
                        "got '$createdTitle'");
                }
            }
        }

        // --- Cleanup ---
        echo "\n--- Cleanup ---\n";
        $this->cleanupBySku($variantSku);

        return $this->summary();
    }

    private function summary(): bool
    {
        echo "\n=== HTTP Import Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo 'Total:  ' . ($this->passed + $this->failed) . "\n";
        return $this->failed === 0;
    }
}

$test = new ImportHttpTest();
exit($test->run() ? 0 : 1);
