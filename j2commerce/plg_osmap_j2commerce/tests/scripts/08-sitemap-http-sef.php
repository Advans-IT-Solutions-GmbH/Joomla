<?php
/**
 * J6 SEF Sitemap HTTP Test for the OSMap J2Commerce Plugin
 *
 * Runs only in the dedicated SEF-enabled J6 environment (J2COMMERCE_SEF=1,
 * see docker-entrypoint-j6.sh + docker-compose.joomla6-sef.yml). It makes a
 * real HTTP request to the live OSMap XML sitemap and asserts that, with SEF
 * URLs enabled and the product menu items on a real content language (de-DE),
 * the J2Commerce 6 product URLs appear as correctly-formed SEF paths that carry
 * the /de/ language prefix (no index.php, no option=com_... query string).
 *
 * The multilingual fixture is what makes the language-prefix assertion
 * meaningful: a single-language fixture has no prefix that could go missing,
 * which is how the #176 regression slipped through (issue #99/#183).
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

class SitemapHttpSefTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $sitemapUrl = 'http://localhost/index.php?option=com_osmap&view=xml&id=1';

    public function run(): bool
    {
        echo "=== J6 SEF Sitemap HTTP Tests ===\n\n";

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

        $alpha = $this->findUrl($urls, 'test-product-alpha');
        $beta  = $this->findUrl($urls, 'test-product-beta');

        $this->test('Sitemap contains product Alpha URL', function () use ($alpha) {
            return $alpha !== null;
        });

        $this->test('Sitemap contains product Beta URL', function () use ($beta) {
            return $beta !== null;
        });

        // #176/#183: on a multilingual site every product URL must carry the
        // menu language's SEF prefix (here /de/) so it resolves directly instead
        // of 301-redirecting from a prefixless path. The SEF fixture gives the
        // hidden product menu items language=de-DE + a #__languages row with
        // sef=de, so a regressed prefixless URL fails here. (A single-language
        // fixture could not catch this — there would be no prefix to lose.)
        $this->test('Product Alpha URL carries the /de/ language SEF prefix (#176/#183)', function () use ($alpha, $root) {
            return $alpha === $root . '/de/shop/test-product-alpha';
        });

        $this->test('Product Beta URL carries the /de/ language SEF prefix (#176/#183)', function () use ($beta, $root) {
            return $beta === $root . '/de/shop/test-product-beta';
        });

        $this->test('Product URLs contain no index.php and no option=com_ query', function () use ($alpha, $beta) {
            foreach ([$alpha, $beta] as $u) {
                if ($u === null) {
                    return false;
                }
                if (str_contains($u, 'index.php') || str_contains($u, 'option=com_')) {
                    return false;
                }
            }
            return true;
        });

        // Live routability (HTTP 200 for the product-detail page) is reported for
        // diagnostics only, NOT asserted here: a live 200 additionally requires an
        // installed language pack, the language-filter plugin, and published product
        // routes, which the throwaway harness does not set up (the product's only
        // menu route is the trashed published=-2 item OSMap builds the path from).
        // That end-to-end assertion is tracked as a follow-up (issue #185). This
        // suite's deterministic guarantee is the /de/ SEF-prefix assertion above
        // (plus the language-prefix unit test in 07-osmap-loader.php): it verifies
        // correct URL *generation*, not live HTTP-200 *resolution*.
        foreach (['Alpha' => $alpha, 'Beta' => $beta] as $label => $u) {
            if ($u === null) {
                continue;
            }
            $status = $this->httpStatus($u);
            echo "  (info) Product {$label} live HTTP status: {$status} for {$u}\n";
        }

        $this->test('Disabled and menu-less products are not in sitemap', function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-disabled') || str_contains($u, 'test-product-nomenu')) {
                    return false;
                }
            }
            return true;
        });

        echo "\n=== J6 SEF Sitemap HTTP Test Summary ===\n";
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
     * Returns the final HTTP status code for $url, following redirects, so a
     * valid SEF path that the site 301-canonicalises still reports the real
     * page's status. Returns 0 when the host is unreachable.
     */
    private function httpStatus(string $url): int
    {
        $ctx = stream_context_create(['http' => [
            'method'          => 'GET',
            'timeout'         => 30,
            'follow_location' => 1,
            'max_redirects'   => 5,
            'ignore_errors'   => true,
        ]]);

        // Reset so a request that fails before receiving any response cannot
        // report the previous request's status (the wrapper only repopulates
        // $http_response_header on a completed response).
        $http_response_header = [];

        $body = @file_get_contents($url, false, $ctx);

        if ($body === false && empty($http_response_header)) {
            return 0;
        }

        // $http_response_header accumulates the status line of every hop; the
        // LAST "HTTP/x 999" line is the final response after redirects.
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
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
