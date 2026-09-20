<?php
/**
 * SEF Sitemap HTTP Test for the OSMap J2Commerce Plugin
 *
 * Runs only in the dedicated SEF-enabled stacks (J2COMMERCE_SEF=1): the J5 stack
 * (docker-entrypoint.sh + docker-compose.sef.yml) and the J6 stack
 * (docker-entrypoint-j6.sh + docker-compose.joomla6-sef.yml). It makes a real
 * HTTP request to the live OSMap XML sitemap and asserts that the emitted product
 * URLs are correctly-formed SEF paths carrying the /de/ language prefix (no
 * index.php, no option=com_... query string).
 *
 * The multilingual fixture is what makes the language-prefix assertion
 * meaningful: a single-language fixture has no prefix that could go missing,
 * which is how the #176 regression slipped through (issue #99/#183).
 *
 * On J6 the fixture additionally installs the de-DE language pack and seeds
 * dedicated published de-DE product routes, so this suite also requires every
 * product URL to resolve directly with HTTP 200 and without a 301 redirect
 * (issue #185). The J5 SEF stack installs no language pack, so it asserts URL
 * generation only and logs the live status for diagnostics.
 */
define('_JEXEC', 1);

// This test only applies to the dedicated SEF-enabled stack. It is part of
// TEST_SCRIPTS, so `./run-tests.sh all` would otherwise run it on the standard
// non-SEF J5/J6 stacks where SEF is intentionally disabled and it would fail.
// Skip cleanly (exit 0) unless the SEF environment flag (J2COMMERCE_SEF=1) is set.
if (getenv('J2COMMERCE_SEF') !== '1') {
    fwrite(STDOUT, "skipped: SEF env not set (J2COMMERCE_SEF=1 required)\n");
    exit(0);
}

define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class SitemapHttpSefTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $sitemapUrl = 'http://localhost/index.php?option=com_osmap&view=xml&id=1';
    private bool $isJ6;
    private DatabaseInterface $db;

    public function __construct()
    {
        $this->isJ6 = getenv('J2COMMERCE_STACK') === 'j6';
        $this->db   = Factory::getContainer()->get(DatabaseInterface::class);
    }

    public function run(): bool
    {
        echo "=== SEF Sitemap HTTP Tests (" . ($this->isJ6 ? 'J6' : 'J5') . ") ===\n\n";

        $this->test('SEF is enabled in this environment', function () {
            if (!class_exists('JConfig')) {
                require_once JPATH_BASE . '/configuration.php';
            }
            $cfg = new \JConfig();
            return !empty($cfg->sef);
        });

        $xml = $this->fetchSitemap();
        if ($xml === null) {
            echo "FATAL: Could not fetch sitemap\n";
            return false;
        }

        echo "Sitemap fetched (" . strlen($xml) . " bytes)\n";

        $urls = $this->extractUrls($xml);
        echo "URLs found in sitemap: " . count($urls) . "\n";
        foreach ($urls as $url) {
            echo "  - {$url}\n";
        }
        echo "\n";

        // The product <loc> values are produced by the real OSMap web request,
        // so their scheme+host is whatever the live Apache/Joomla stack emits
        // (here http://localhost). Deriving the expected base from the actual
        // response — instead of reconstructing it with Uri::root() in this CLI
        // process, which resolves the base differently outside a web request —
        // lets the test assert the real SEF response while staying host-agnostic.
        $cliRoot = rtrim(\Joomla\CMS\Uri\Uri::root(), '/');
        $root    = $this->baseFromUrls($urls) ?? $cliRoot;
        echo "Base derived from response: {$root} (CLI Uri::root(): {$cliRoot})\n\n";

        // Only dump the full sitemap response when debugging (OSMAP_SEF_DEBUG=1)
        // or when XML validation fails, to avoid bloating CI logs/artifacts.
        $xmlValid = str_contains($xml, '<urlset') && str_contains($xml, 'sitemaps.org');
        if (getenv('OSMAP_SEF_DEBUG') === '1' || !$xmlValid) {
            echo "Full sitemap response:\n" . $xml . "\n\n";
        }

        $this->test('Sitemap returns valid XML', function () use ($xmlValid) {
            return $xmlValid;
        });

        if ($this->isJ6) {
            $this->test('J6 SEF fixture omits hidden product menu children', function () {
                $query = method_exists($this->db, 'createQuery')
                    ? $this->db->createQuery()
                    : $this->db->getQuery(true);
                $query->select('COUNT(*)')
                    ->from('#__menu')
                    ->where('parent_id = 9001')
                    ->where('published = -2');

                return (int) $this->db->setQuery($query)->loadResult() === 0;
            });

            $this->test('J6 SEF fixture seeds dedicated published product routes', function () {
                $query = method_exists($this->db, 'createQuery')
                    ? $this->db->createQuery()
                    : $this->db->getQuery(true);
                $query->select('COUNT(*)')
                    ->from('#__menu')
                    ->where('id IN (9011, 9012)')
                    ->where('parent_id = 9001')
                    ->where('published = 1')
                    ->where('language = ' . $this->db->quote('de-DE'));

                return (int) $this->db->setQuery($query)->loadResult() === 2;
            });
        }

        $aliases = ['test-product-alpha', 'test-product-beta'];
        if (!$this->isJ6) {
            // The J5 fixture also emits the enabled menu-less product (9004)
            // through the direct-query path (mechanism 2). Assert its /de/ prefix
            // too, so a missing prefix on that path cannot leave this lane green.
            $aliases[] = 'test-product-nomenu';
        }
        $productUrls = [];

        foreach ($aliases as $alias) {
            $productUrls[$alias] = $this->findUrl($urls, $alias);
            $this->test("Sitemap contains {$alias}", function () use ($productUrls, $alias) {
                return $productUrls[$alias] !== null;
            });
        }

        $this->test('Every product URL carries the /de/ language SEF prefix (#176/#183)', function () use ($productUrls, $root) {
            foreach ($productUrls as $alias => $url) {
                if ($url !== $root . '/de/shop/' . $alias) {
                    return false;
                }
            }
            return true;
        });

        $this->test('Product URLs contain no index.php and no option=com_ query', function () use ($productUrls) {
            foreach ($productUrls as $url) {
                if ($url === null || str_contains($url, 'index.php') || str_contains($url, 'option=com_')) {
                    return false;
                }
            }
            return true;
        });

        // Issue #183/#185: every sitemap URL must resolve directly with HTTP 200,
        // not only after a 301 canonicalisation hop. The J6 SEF fixture builds the
        // full multilingual stack for that (de-DE language pack, plg_system_
        // languagefilter enabled, dedicated published de-DE product routes), so the
        // status is asserted there. The J5 SEF stack installs no language pack and
        // therefore proves URL *generation* only; its live status is logged for
        // diagnostics. The #176/#183 regression itself — a missing /de/ language
        // prefix — is caught by the URL-form assertions above on both stacks.
        foreach ($productUrls as $alias => $url) {
            if ($url === null) {
                continue;
            }

            $response = $this->httpResponse($url);
            echo "  (info) {$alias}: {$response['status']}"
                . ($response['location'] !== null ? " -> {$response['location']}" : '') . "\n";

            if (!$this->isJ6) {
                continue;
            }

            $this->test("Product {$alias} URL resolves directly with HTTP 200 (#183/#185)", function () use ($response) {
                return $response['status'] === 200;
            });

            $this->test("Product {$alias} URL resolves without a redirect (no 301, no Location header) (#183/#185)", function () use ($response) {
                return $response['status'] !== 301 && $response['location'] === null;
            });
        }

        $this->test('Disabled product is not in the SEF sitemap', function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-disabled')) {
                    return false;
                }
            }
            return true;
        });

        echo "\n=== SEF Sitemap HTTP Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function findUrl(array $urls, string $needle): ?string
    {
        foreach ($urls as $u) {
            if (str_contains($u, $needle)) {
                return $u;
            }
        }
        return null;
    }

    /**
     * Returns the direct HTTP response for $url without following redirects.
     * This lets the test distinguish a real 200 from a URL that only resolves
     * after a 301 canonicalisation hop. Returns status 0 when the host is
     * unreachable.
     *
     * @return array{status:int, location:?string}
     */
    private function httpResponse(string $url): array
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 0,
            'ignore_errors'   => true,
        ]]);

        // Reset so a request that fails before receiving any response cannot
        // report the previous request's headers (the wrapper only repopulates
        // $http_response_header on a completed response).
        $http_response_header = [];
        $status               = 0;
        $location             = null;

        $body = @file_get_contents($url, false, $ctx);

        if ($body === false && empty($http_response_header)) {
            return ['status' => 0, 'location' => null];
        }

        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
            if (stripos($header, 'Location:') === 0) {
                $location = trim(substr($header, strlen('Location:')));
            }
        }

        return ['status' => $status, 'location' => $location];
    }

    private function baseFromUrls(array $urls): ?string
    {
        foreach ($urls as $u) {
            if (preg_match('#^(https?://[^/]+)#i', $u, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function fetchSitemap(): ?string
    {
        for ($i = 0; $i < 6; $i++) {
            $ctx = stream_context_create(['http' => [
                'timeout'         => 30,
                'follow_location' => 1,
                'ignore_errors'   => true,
            ]]);
            $body = @file_get_contents($this->sitemapUrl, false, $ctx);
            if ($body !== false && strlen($body) > 0) {
                return $body;
            }
            echo "Waiting for Apache... ({$i})\n";
            sleep(5);
        }
        return null;
    }

    private function extractUrls(string $xml): array
    {
        $urls = [];
        if (preg_match_all('/<loc>(.*?)<\/loc>/s', $xml, $matches)) {
            foreach ($matches[1] as $raw) {
                $url = trim($raw);
                if (str_starts_with($url, '<![CDATA[') && str_ends_with($url, ']]>')) {
                    $url = substr($url, 9, -3);
                }
                $url = html_entity_decode($url, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else       { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new SitemapHttpSefTest();
exit($test->run() ? 0 : 1);
