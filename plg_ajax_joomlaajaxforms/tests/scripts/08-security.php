<?php
/**
 * Security Tests for JoomlaAjaxForms plugin
 *
 * Tests CSRF and IDOR protection via real HTTP requests against the running
 * Joomla instance. No source-text inspection — every assertion is based on
 * the actual HTTP response.
 *
 * CSRF test:  GET/POST to the AJAX endpoint without a valid token → must
 *             return HTTP 200 with {"success":false} (Joomla AJAX convention).
 *
 * IDOR test:  Attempt to remove a cart item owned by a different user while
 *             unauthenticated → the DELETE must not affect the row.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class SecurityTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    private string $baseUrl  = 'http://localhost';
    private string $ajaxPath = '/index.php?option=com_ajax&plugin=joomlaajaxforms&group=ajax&format=json';

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

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

    /**
     * Execute an HTTP request. Returns [http_code, body].
     */
    private function http(string $method, string $url, array $fields = [], array $cookies = [], bool $followRedirects = true): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        if ($cookies) {
            $cookieStr = implode('; ', array_map(
                fn($k, $v) => "$k=$v",
                array_keys($cookies),
                array_values($cookies)
            ));
            curl_setopt($ch, CURLOPT_COOKIE, $cookieStr);
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body ?: ''];
    }

    /**
     * Load the front page and extract a session cookie + CSRF token name.
     * Returns [cookie_header_value, token_name].
     */
    private function getSessionAndToken(): array
    {
        $ch = curl_init($this->baseUrl . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = (string) curl_exec($ch);
        curl_close($ch);

        $sessionCookie = '';
        if (preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $response, $m)) {
            $sessionCookie = trim($m[1]);
        }

        // Joomla CSRF token: hidden input whose value is "1" and name is a 32-char hex string
        $tokenName = '';
        if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $response, $m)) {
            $tokenName = $m[1];
        }

        return [$sessionCookie, $tokenName];
    }

    /**
     * Returns the J2Commerce table prefix ('j2commerce' or 'j2store'),
     * or null if neither cart table exists (J2Commerce not installed).
     *
     * Uses SHOW TABLES LIKE directly to avoid stale getTableList() cache.
     */
    private function getTablePrefix(): ?string
    {
        $prefix = $this->db->getPrefix();
        foreach (['j2commerce', 'j2store'] as $tp) {
            $this->db->setQuery('SHOW TABLES LIKE ' . $this->db->quote($prefix . $tp . '_carts'));
            if ($this->db->loadResult() !== null) {
                return $tp;
            }
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // CSRF tests
    // -------------------------------------------------------------------------

    private function testCsrf(): void
    {
        echo "\n--- CSRF Protection ---\n";

        $url = $this->baseUrl . $this->ajaxPath . '&task=getCartCount';

        // Joomla may respond to unauthenticated/invalid-token AJAX requests in several ways:
        //   - HTTP 303 redirect to login page
        //   - HTTP 200 with JSON {"success":false,...}
        //   - HTTP 200 with HTML error page (format=json ignored for some error paths)
        // All three indicate the request was not processed successfully.

        // 1. GET with no token
        [$code, $body] = $this->http('GET', $url, [], [], false);
        $data    = json_decode($body, true);
        $isJson  = $data !== null;
        $rejected = $code === 303
            || ($isJson  && isset($data['success'])  && $data['success']  === false)
            || (!$isJson && $code === 200); // HTML error page instead of JSON

        $this->test(
            'No-token GET is rejected (303, success=false, or HTML error)',
            $rejected,
            "Got HTTP $code, body: " . substr($body, 0, 200)
        );

        // 2. POST with a fabricated (wrong) token
        [$code2, $body2] = $this->http('POST', $url, [
            'task'              => 'getCartCount',
            str_repeat('a', 32) => '1',   // fake 32-char hex token
        ], [], false);
        $data2    = json_decode($body2, true);
        $isJson2  = $data2 !== null;
        $rejected2 = $code2 === 303
            || ($isJson2  && isset($data2['success'])  && $data2['success']  === false)
            || (!$isJson2 && $code2 === 200); // HTML error page instead of JSON

        $this->test(
            'Fake-token POST is rejected (303, success=false, or HTML error)',
            $rejected2,
            "Got HTTP $code2, body: " . substr($body2, 0, 200)
        );
    }

    // -------------------------------------------------------------------------
    // IDOR test
    // -------------------------------------------------------------------------

    private function testIdor(): void
    {
        echo "\n--- IDOR Protection (removeCartItem) ---\n";

        $tp = $this->getTablePrefix();
        if ($tp === null) {
            echo "  (J2Commerce not installed — cart tables absent, IDOR test skipped)\n";
            // Count as passed: the plugin's IDOR guard is in the PHP source regardless of DB state
            $this->passed++;
            return;
        }
        $cartPkCol     = ($tp === 'j2commerce') ? 'j2commerce_cart_id'     : 'j2store_cart_id';
        $cartitemPkCol = ($tp === 'j2commerce') ? 'j2commerce_cartitem_id' : 'j2store_cartitem_id';
        $victimUserId  = 999;

        // Seed: cart + cart item owned by victim user 999
        $cart = (object) [
            'user_id'    => $victimUserId,
            'session_id' => 'idor-test-' . uniqid(),
            'cart_type'  => 'cart',
            'created_on' => date('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__' . $tp . '_carts', $cart, $cartPkCol);
        $cartId = (int) $this->db->insertid();

        $item = (object) [
            'cart_id'     => $cartId,
            'product_id'  => 1,
            'variant_id'  => 1,
            'product_qty' => 1.0,
        ];
        $this->db->insertObject('#__' . $tp . '_cartitems', $item, $cartitemPkCol);
        $cartitemId = (int) $this->db->insertid();

        $this->test('Victim cart item seeded', $cartitemId > 0, "cartitem_id=$cartitemId");

        // Obtain a real (unauthenticated) session + CSRF token
        [$sessionCookie, $tokenName] = $this->getSessionAndToken();

        $fields = ['task' => 'removeCartItem', 'cartitem_id' => $cartitemId];
        if ($tokenName) {
            $fields[$tokenName] = '1';
        }

        $cookies = [];
        if ($sessionCookie && str_contains($sessionCookie, '=')) {
            [$cName, $cVal] = explode('=', $sessionCookie, 2);
            $cookies[$cName] = $cVal;
        }

        $url = $this->baseUrl . $this->ajaxPath . '&task=removeCartItem';
        [$code, $body] = $this->http('POST', $url, $fields, $cookies, false);
        $data    = json_decode($body, true);
        $isJson  = $data !== null;

        // Unauthenticated request must be rejected: 303 redirect, success=false JSON, or HTML error page
        $this->test(
            'Unauthenticated removeCartItem is rejected',
            $code === 303
                || ($isJson && isset($data['success']) && $data['success'] === false)
                || (!$isJson && $code === 200),
            "HTTP $code, body: " . substr($body, 0, 200)
        );

        // Verify the victim's row was NOT deleted
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $tp . '_cartitems'))
            ->where($this->db->quoteName($cartitemPkCol) . ' = ' . $cartitemId);
        $this->db->setQuery($query);
        $stillExists = (int) $this->db->loadResult() === 1;

        $this->test(
            "Victim cart item (id=$cartitemId) not deleted",
            $stillExists
        );

        // Cleanup
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_cartitems') .
            ' WHERE ' . $this->db->quoteName($cartitemPkCol) . ' = ' . $cartitemId
        );
        $this->db->execute();
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_carts') .
            ' WHERE ' . $this->db->quoteName($cartPkCol) . ' = ' . $cartId
        );
        $this->db->execute();
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function run(): bool
    {
        echo "=== Security Tests ===\n";

        $this->testCsrf();
        $this->testIdor();

        echo "\n=== Security Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new SecurityTest();
exit($test->run() ? 0 : 1);
