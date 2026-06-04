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
use Joomla\Database\DatabaseInterface;

class SitemapHttpTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $sitemapUrl = 'http://localhost/index.php?option=com_osmap&view=xml&id=1';
    private $db;
    private bool $isJ6;

    // Menu item IDs inserted by docker-entrypoint.sh
    private const PRODUCT_ALPHA_MENU_ID = 9002;
    private const PRODUCT_BETA_MENU_ID  = 9003;

    public function __construct()
    {
        $this->db  = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6 = (getenv('J2COMMERCE_STACK') === 'j6');
    }

    public function run(): bool
    {
        echo "=== Sitemap HTTP Integration Tests ===\n\n";
        echo "Stack: " . ($this->isJ6 ? 'J6' : 'J5') . "\n\n";

        $xml = $this->fetchSitemap();
        if ($xml === null) {
            echo "FATAL: Could not fetch sitemap\n";
            return false;
        }

        echo "Sitemap fetched (" . strlen($xml) . " bytes)\n";
        echo "Full sitemap response:\n" . $xml . "\n\n";

        $urls = $this->extractUrls($xml);
        echo "URLs found in sitemap: " . count($urls) . "\n";
        foreach ($urls as $url) {
            echo "  - {$url}\n";
        }
        echo "\n";

        $this->test('Sitemap returns valid XML', function () use ($xml) {
            return str_contains($xml, '<urlset') && str_contains($xml, 'sitemaps.org');
        });

        // The plugin builds absolute URLs from the menu item's path field
        // (e.g. http://localhost/shop/test-product-alpha). OSMap excludes
        // published=-2 items from its routing cache, so Itemid-based links
        // produce empty fullLink for these items.
        $alphaId = self::PRODUCT_ALPHA_MENU_ID;
        $betaId  = self::PRODUCT_BETA_MENU_ID;

        $this->test("Sitemap contains product Alpha URL (SEF path)", function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-alpha')) {
                    return true;
                }
            }
            return false;
        });

        $this->test("Sitemap contains product Beta URL (SEF path)", function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-beta')) {
                    return true;
                }
            }
            return false;
        });

        $this->test('Disabled product not in sitemap', function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-disabled')) return false;
            }
            return true;
        });

        $this->test('Product without menu item not in sitemap', function () use ($urls) {
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-nomenu')) return false;
            }
            return true;
        });

        $this->test('At least 2 product URLs in sitemap', function () use ($urls) {
            $count = 0;
            foreach ($urls as $u) {
                if (str_contains($u, 'test-product-alpha')) $count++;
                if (str_contains($u, 'test-product-beta'))  $count++;
            }
            return $count >= 2;
        });

        echo "\n=== Sitemap HTTP Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
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

$test = new SitemapHttpTest();
exit($test->run() ? 0 : 1);
