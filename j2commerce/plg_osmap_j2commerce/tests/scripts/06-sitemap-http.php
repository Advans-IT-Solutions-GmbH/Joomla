<?php
/**
 * HTTP Integration Test for OSMap J2Commerce Plugin
 *
 * Makes a real HTTP request to the Joomla sitemap endpoint and verifies
 * that J2Commerce product URLs appear in the XML output.
 * This is the only test that exercises the full stack:
 * OSMap -> plugin -> getTree() -> DB query -> XML output.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
$db = Factory::getDbo();

class SitemapHttpTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;
    private string $sitemapUrl = 'http://localhost/index.php?option=com_osmap&view=xml&id=1';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function run(): bool
    {
        echo "=== Sitemap HTTP Integration Tests ===\n\n";
        echo "URL: {$this->sitemapUrl}\n\n";

        // Fetch sitemap XML
        $xml = $this->fetchSitemap();
        if ($xml === null) {
            echo "FATAL: Could not fetch sitemap\n";
            return false;
        }

        echo "Sitemap fetched (" . strlen($xml) . " bytes)\n";
        echo "Response (first 500 chars):\n" . substr($xml, 0, 500) . "\n\n";

        $urls = $this->extractUrls($xml);
        echo "URLs found in sitemap: " . count($urls) . "\n";
        foreach ($urls as $url) {
            echo "  - {$url}\n";
        }
        echo "\n";

        $this->test('Sitemap returns valid XML', function () use ($xml) {
            return str_contains($xml, '<urlset') && str_contains($xml, 'sitemaps.org');
        });

        // With SEF disabled, product URLs are com_content article URLs with view=article.
        // URLs in the sitemap XML are CDATA-encoded so & becomes &amp;.
        // We identify product URLs by: com_content + view=article + id=NNNN.
        // Note: id=9001 also appears in Itemid=9001 (shop menu), so we require view=article.
        $isProductUrl = function (string $u, int $articleId): bool {
            return (str_contains($u, 'com_content') || str_contains($u, 'test-product-'))
                && (str_contains($u, 'view=article') || str_contains($u, 'test-product-'))
                && (str_contains($u, 'id=' . $articleId) || str_contains($u, 'test-product-'));
        };

        $this->test('Sitemap contains product Alpha URL (article id=9001)', function () use ($urls, $isProductUrl) {
            foreach ($urls as $u) {
                if ($isProductUrl($u, 9001) || str_contains($u, 'test-product-alpha')) return true;
            }
            return false;
        });

        $this->test('Sitemap contains product Beta URL (article id=9002)', function () use ($urls, $isProductUrl) {
            foreach ($urls as $u) {
                if ($isProductUrl($u, 9002) || str_contains($u, 'test-product-beta')) return true;
            }
            return false;
        });

        $this->test('Disabled product not in sitemap', function () use ($urls, $isProductUrl) {
            // product_source_id=9003 has enabled=0
            foreach ($urls as $u) {
                if ($isProductUrl($u, 9003) || str_contains($u, 'test-product-disabled')) return false;
            }
            return true;
        });

        $this->test('Product without menu item not in sitemap', function () use ($urls, $isProductUrl) {
            // product_source_id=9004 has no SEF menu item
            foreach ($urls as $u) {
                if ($isProductUrl($u, 9004) || str_contains($u, 'test-product-nomenu')) return false;
            }
            return true;
        });

        $this->test('At least 2 product URLs in sitemap', function () use ($urls, $isProductUrl) {
            $count = 0;
            foreach ($urls as $u) {
                if ($isProductUrl($u, 9001) || $isProductUrl($u, 9002)
                    || str_contains($u, 'test-product-alpha') || str_contains($u, 'test-product-beta')) {
                    $count++;
                }
            }
            return $count >= 2;
        });

        echo "\n=== Sitemap HTTP Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function fetchSitemap(): ?string
    {
        // Wait up to 30s for Apache to be ready
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
                // Strip CDATA wrapper if present: <![CDATA[...]]>
                $url = trim($raw);
                if (str_starts_with($url, '<![CDATA[') && str_ends_with($url, ']]>')) {
                    $url = substr($url, 9, -3);
                }
                // Decode HTML entities (&amp; -> &)
                $url = html_entity_decode($url, ENT_QUOTES | ENT_XML1, 'UTF-8');
                $urls[] = $url;
            }
        }
        return $urls;
    }

    private function urlsContain(array $urls, string $needle): bool
    {
        foreach ($urls as $url) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }
        return false;
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

$test = new SitemapHttpTest($db);
exit($test->run() ? 0 : 1);
