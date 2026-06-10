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
     * Like http() but also returns response Set-Cookie headers as a name→value map.
     * Returns [$httpCode, $body, $responseCookies].
     */
    private function httpWithCookies(string $method, string $url, array $fields = [], array $cookies = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }

        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(
                fn($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies)
            )));
        }

        $response = (string) curl_exec($ch);
        $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headers = substr($response, 0, $headerSize);
        $body    = substr($response, $headerSize);

        $respCookies = [];
        if (preg_match_all('/Set-Cookie:\s*([^=]+)=([^;\r\n]*)/i', $headers, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $respCookies[trim($match[1])] = trim($match[2]);
            }
        }

        return [$code, $body ?: '', $respCookies];
    }

    /**
     * Fetch the front page with the given cookies and extract the CSRF token name.
     */
    private function getCsrfToken(array $cookies): string
    {
        $ch = curl_init($this->baseUrl . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(
                fn($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies)
            )));
        }
        $body = (string) curl_exec($ch);
        curl_close($ch);

        if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $body, $m)) {
            return $m[1];
        }
        return '';
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

        // Joomla must reject unauthenticated/invalid-token AJAX requests with either:
        //   - HTTP 3xx redirect to login page
        //   - HTTP 200 with JSON {"success":false,...}
        // HTTP 200 with non-JSON body is NOT accepted as rejection.

        // 1. GET with no token
        [$code, $body] = $this->http('GET', $url, [], [], false);
        $data    = json_decode($body, true);
        $isJson  = $data !== null;
        $rejected = ($code >= 300 && $code < 400)
            || ($isJson && isset($data['success']) && $data['success'] === false);

        $this->test(
            'No-token GET is rejected (3xx or JSON success=false)',
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
        $rejected2 = ($code2 >= 300 && $code2 < 400)
            || ($isJson2 && isset($data2['success']) && $data2['success'] === false);

        $this->test(
            'Fake-token POST is rejected (3xx or JSON success=false)',
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
            echo "  SKIP: J2Commerce not installed — cart tables absent, IDOR test skipped\n";
            return;
        }
        $cartPkCol     = ($tp === 'j2commerce') ? 'j2commerce_cart_id'     : 'j2store_cart_id';
        $cartitemPkCol = ($tp === 'j2commerce') ? 'j2commerce_cartitem_id' : 'j2store_cartitem_id';
        $victimUserId  = 999;

        // Seed: cart + cart item owned by victim user 999
        $now  = date('Y-m-d H:i:s');
        $cart = (object) [
            'user_id'        => $victimUserId,
            'session_id'     => 'idor-test-' . uniqid(),
            'cart_type'      => 'cart',
            'created_on'     => $now,
            // J2Commerce 6 NOT NULL columns without defaults
            'modified_on'    => $now,
            'customer_ip'    => '127.0.0.1',
            'cart_params'    => '{}',
            'cart_browser'   => '',
            'cart_analytics' => '',
        ];
        $this->db->insertObject('#__' . $tp . '_carts', $cart, $cartPkCol);
        $cartId = (int) $this->db->insertid();

        $item = (object) [
            'cart_id'         => $cartId,
            'product_id'      => 1,
            'variant_id'      => 1,
            'product_qty'     => 1.0,
            // J2Commerce 6 NOT NULL columns without defaults
            'vendor_id'       => 0,
            'product_type'    => 'simple',
            'cartitem_params' => '{}',
            'product_options' => '{}',
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

        // com_ajax always wraps the plugin response:
        //   {"success":true,"data":["<json-encoded-plugin-response>"]}
        // The outer success:true means com_ajax dispatched the request; the
        // plugin's actual success/failure is in data[0] (JSON-encoded string).
        // Unwrap to get the plugin's response for the rejection check.
        $inner = null;
        if ($isJson && isset($data['data'][0])) {
            $inner = is_string($data['data'][0])
                ? json_decode($data['data'][0], true)
                : $data['data'][0];
        }

        // Unauthenticated request must be rejected:
        //   - any 3xx redirect (Joomla login redirect)
        //   - plugin returns success=false in the unwrapped inner response
        // HTTP 200 with non-JSON body is NOT accepted as rejection.
        $this->test(
            'Unauthenticated removeCartItem is rejected',
            ($code >= 300 && $code < 400)
                || ($inner !== null && isset($inner['success']) && $inner['success'] === false)
                || ($isJson && isset($data['success']) && $data['success'] === false),
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

        // --- Authenticated wrong-owner IDOR ---
        echo "\n--- Authenticated wrong-owner IDOR ---\n";
        $this->testIdorAuthenticatedWrongOwner($tp, $cartPkCol, $cartitemPkCol);
    }

    private function testIdorAuthenticatedWrongOwner(string $tp, string $cartPkCol, string $cartitemPkCol): void
    {
        // Seed a cart item owned by victim user 999 (not the admin)
        $victimUserId = 999;
        $now = date('Y-m-d H:i:s');

        if ($tp === 'j2commerce') {
            // J2Commerce 6 schema: several NOT NULL columns required
            $cart = (object) [
                'user_id'          => $victimUserId,
                'session_id'       => 'idor-auth-' . uniqid(),
                'cart_type'        => 'cart',
                'created_on'       => $now,
                'modified_on'      => $now,
                'customer_ip'      => '127.0.0.1',
                'cart_params'      => '{}',
                'cart_browser'     => '',
                'cart_voucher'     => '',
                'cart_coupon'      => '',
                'cart_analytics'   => '',
            ];
        } else {
            $cart = (object) [
                'user_id'    => $victimUserId,
                'session_id' => 'idor-auth-' . uniqid(),
                'cart_type'  => 'cart',
                'created_on' => $now,
            ];
        }
        $this->db->insertObject('#__' . $tp . '_carts', $cart, $cartPkCol);
        $cartId = (int) $this->db->insertid();

        if ($tp === 'j2commerce') {
            // J2Commerce 6 schema: variant_id, vendor_id, cartitem_params are NOT NULL
            $item = (object) [
                'cart_id'         => $cartId,
                'product_id'      => 1,
                'variant_id'      => 0,
                'vendor_id'       => 0,
                'product_type'    => 'simple',
                'cartitem_params' => '{}',
                'product_qty'     => 1.0,
                'product_options' => '{}',
            ];
        } else {
            $item = (object) [
                'cart_id'     => $cartId,
                'product_id'  => 1,
                'variant_id'  => 1,
                'product_qty' => 1.0,
            ];
        }
        $this->db->insertObject('#__' . $tp . '_cartitems', $item, $cartitemPkCol);
        $cartitemId = (int) $this->db->insertid();

        $this->test('Victim cart item seeded for auth test', $cartitemId > 0);

        // Log in as admin via the plugin's own task=login endpoint.
        // This is the same path used by the front-end login form and gives a
        // properly authenticated Joomla session without relying on com_users routing.
        [$sessionCookie, $tokenName] = $this->getSessionAndToken();
        $cookies = [];
        if ($sessionCookie && str_contains($sessionCookie, '=')) {
            [$cName, $cVal] = explode('=', $sessionCookie, 2);
            $cookies[$cName] = $cVal;
        }

        // Read admin password from the container environment — each docker-compose
        // sets JOOMLA_ADMIN_PASSWORD differently across J5/J2C4/J2C6 stacks.
        $adminPassword = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#';
        $loginFields = ['task' => 'login', 'username' => 'admin', 'password' => $adminPassword];
        if ($tokenName) {
            $loginFields[$tokenName] = '1';
        }
        $loginUrl = $this->baseUrl . $this->ajaxPath . '&task=login';
        [$loginCode, $loginBody, $loginRespCookies] = $this->httpWithCookies('POST', $loginUrl, $loginFields, $cookies);

        // Unwrap com_ajax envelope: outer success=true, inner plugin response in data[0]
        $loginOuter = json_decode($loginBody, true);
        $loginInner = null;
        if (isset($loginOuter['data'][0])) {
            $loginInner = is_string($loginOuter['data'][0])
                ? json_decode($loginOuter['data'][0], true)
                : $loginOuter['data'][0];
        }

        $loginOk = $loginInner !== null && ($loginInner['success'] ?? false) === true;
        $this->test(
            'Admin login via task=login succeeded',
            $loginOk,
            "HTTP $loginCode, inner: " . json_encode($loginInner)
        );

        if (!$loginOk) {
            // Cannot test authenticated wrong-owner without a valid session — cleanup and skip
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_cartitems') . ' WHERE ' . $this->db->quoteName($cartitemPkCol) . ' = ' . $cartitemId);
            $this->db->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_carts') . ' WHERE ' . $this->db->quoteName($cartPkCol) . ' = ' . $cartId);
            $this->db->execute();
            return;
        }

        // Merge session cookies from login response
        $authCookies = array_merge($cookies, $loginRespCookies);

        // Get a fresh CSRF token on the authenticated session
        $tokenName = $this->getCsrfToken($authCookies);

        // Attempt to delete the victim's cart item while authenticated as admin
        $removeFields = ['task' => 'removeCartItem', 'cartitem_id' => $cartitemId];
        if ($tokenName) {
            $removeFields[$tokenName] = '1';
        }
        $url = $this->baseUrl . $this->ajaxPath . '&task=removeCartItem';
        [$code, $body] = $this->http('POST', $url, $removeFields, $authCookies, false);

        // Unwrap com_ajax envelope to get the plugin's actual response
        $outer = json_decode($body, true);
        $inner = null;
        if (isset($outer['data'][0])) {
            $inner = is_string($outer['data'][0])
                ? json_decode($outer['data'][0], true)
                : $outer['data'][0];
        }

        // Must return success=false — admin is authenticated but does not own this item
        $this->test(
            'Authenticated wrong-owner removeCartItem returns success=false',
            $inner !== null && ($inner['success'] ?? true) === false,
            "HTTP $code, inner: " . json_encode($inner)
        );

        // Victim's row must still exist
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $tp . '_cartitems'))
            ->where($this->db->quoteName($cartitemPkCol) . ' = ' . $cartitemId);
        $this->db->setQuery($query);
        $this->test(
            'Victim cart item not deleted by wrong-owner authenticated request',
            (int) $this->db->loadResult() === 1
        );

        // Cleanup
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_cartitems') . ' WHERE ' . $this->db->quoteName($cartitemPkCol) . ' = ' . $cartitemId);
        $this->db->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $tp . '_carts') . ' WHERE ' . $this->db->quoteName($cartPkCol) . ' = ' . $cartId);
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
