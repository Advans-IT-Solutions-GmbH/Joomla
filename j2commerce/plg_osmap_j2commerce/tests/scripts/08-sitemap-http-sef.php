<?php
/**
 * J6 SEF Sitemap HTTP Test for the OSMap J2Commerce Plugin
 *
 * Runs only in the dedicated SEF-enabled J6 environment (J2COMMERCE_SEF=1,
 * see docker-entrypoint-j6.sh + docker-compose.joomla6-sef.yml). It makes a
 * real HTTP request to the live OSMap XML sitemap and asserts that, with SEF
 * URLs enabled, the J2Commerce 6 product URLs appear correctly formed as SEF
 * paths (no index.php, no option=com_... query string).
 *
 * Closes the gap where the J6 harness previously disabled SEF and therefore
 * never verified SEF product URL output (issue #99).
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

        $this->test('Product Alpha URL is correctly-formed SEF (/shop/test-product-alpha)', function () use ($alpha, $root) {
            return $alpha === $root . '/shop/test-product-alpha';
        });

        $this->test('Product Beta URL is correctly-formed SEF (/shop/test-product-beta)', function () use ($beta, $root) {
            return $beta === $root . '/shop/test-product-beta';
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

        // #178/#176: every product URL in the sitemap must be routable — it must
        // reach a real page (final HTTP status < 400), not a 404/500. A single-
        // language stack may 301-canonicalise a valid SEF path (trailing slash,
        // SEF suffix), so following redirects and asserting the final status is
        // not an error is the environment-independent guarantee. The #176
        // language-prefix regression itself is guarded deterministically by the
        // language-prefix unit test in 07-osmap-loader.php.
        $this->test('Product Alpha URL is routable (final HTTP status < 400)', function () use ($alpha) {
            return $alpha !== null && $this->httpStatus($alpha) < 400 && $this->httpStatus($alpha) > 0;
        });

        $this->test('Product Beta URL is routable (final HTTP status < 400)', function () use ($beta) {
            return $beta !== null && $this->httpStatus($beta) < 400 && $this->httpStatus($beta) > 0;
        });

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

        $body = @file_get_contents($url, false, $ctx);

        if ($body === false && !isset($http_response_header)) {
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
