<?php
/**
 * Test 11 (J2C6 full-install): cart endpoints against real J2Commerce 6 tables.
 *
 * Requires:
 *   - J2Commerce 6 installed (#__j2commerce_carts, #__j2commerce_cartitems present)
 *   - Test user id=999 with cart seeded by docker-entrypoint.sh
 *   - Plugin enabled
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false, false, 'psr4'
);

$passed = 0;
$failed = 0;

function pass(string $name): void { global $passed; echo "✓ $name\n"; $passed++; }
function fail(string $name, string $d = ''): void { global $failed; echo "✗ $name" . ($d ? " — $d" : '') . "\n"; $failed++; }
function check(string $name, bool $ok, string $d = ''): void { $ok ? pass($name) : fail($name, $d); }

$db = Factory::getContainer()->get(DatabaseInterface::class);

// ── 1. Table presence ────────────────────────────────────────────────────────
echo "--- Table presence ---\n";
$db->setQuery('SHOW TABLES LIKE ' . $db->quote($db->getPrefix() . 'j2commerce_carts'));
check($db->getPrefix() . 'j2commerce_carts exists', $db->loadResult() !== null);

$db->setQuery('SHOW TABLES LIKE ' . $db->quote($db->getPrefix() . 'j2commerce_cartitems'));
check($db->getPrefix() . 'j2commerce_cartitems exists', $db->loadResult() !== null);

// ── 2. Detection methods ─────────────────────────────────────────────────────
echo "\n--- Detection methods ---\n";
$plugin = new \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms(
    new Dispatcher(), ['params' => new Registry(['enable_j2store_cart' => 1])]
);
$plugin->setDatabase($db);
$rc = new ReflectionClass($plugin);

$mInstalled = $rc->getMethod('isJ2CommerceInstalled');
$mInstalled->setAccessible(true);
$installed = $mInstalled->invoke($plugin, $db);
check('isJ2CommerceInstalled() returns true', $installed === true, var_export($installed, true));

$mIs4 = $rc->getMethod('isJ2Commerce4');
$mIs4->setAccessible(true);
$is4 = $mIs4->invoke($plugin, $db);
check('isJ2Commerce4() returns false (J2C6 tables present)', $is4 === false, var_export($is4, true));

// ── 3. getCartCountForUser() with seeded data ─────────────────────────────────
echo "\n--- getCartCountForUser() with seeded data ---\n";
$mCount = $rc->getMethod('getCartCountForUser');
$mCount->setAccessible(true);

try {
    $count = $mCount->invoke($plugin, $db, 999);
    check('getCartCountForUser(999) returns int', is_int($count), gettype($count));
    check('getCartCountForUser(999) returns 3 (2+1 seeded items)', $count === 3, "Got: $count");
} catch (\Throwable $e) {
    fail('getCartCountForUser(999) does not throw', $e->getMessage());
}

// ── 4-5. HTTP tests (IDOR, authenticated delete) ────────────────────────────
// Use a curl cookie jar so all Set-Cookie headers (including session rotation
// after login) are handled automatically across requests.
$cookieJar = tempnam(sys_get_temp_dir(), 'joomla_cookies_');
$baseUrl   = 'http://localhost/index.php?option=com_ajax&plugin=joomlaajaxforms&group=ajax&format=json';

// ── 4a. Fetch initial CSRF token ──────────────────────────────────────────────
echo "\n--- HTTP: fetch initial CSRF token ---\n";
$ch = curl_init('http://localhost/');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_TIMEOUT        => 10,
]);
$initBody = (string) curl_exec($ch);
curl_close($ch);
$token = '';
if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $initBody, $mt)) {
    $token = $mt[1];
}
check('Got CSRF token from HTTP', $token !== '', 'Could not extract token from front page');

// ── 4b. IDOR: guest cannot delete user 999's item ────────────────────────────
echo "\n--- HTTP: removeCartItem IDOR (guest) ---\n";
$ch = curl_init($baseUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query(['task' => 'removeCartItem', 'cartitem_id' => 1, $token => '1']),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$raw  = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('removeCartItem IDOR HTTP 200', $code === 200, "Got HTTP $code");
$resp = json_decode($raw, true);
if ($resp !== null) {
    $inner = $resp['data'][0] ?? null;
    if (is_string($inner)) { $inner = json_decode($inner, true); }
    check('removeCartItem IDOR returns success=false',
        isset($inner['success']) && $inner['success'] === false,
        'Got: ' . json_encode($inner));
}
$db->setQuery('SELECT COUNT(*) FROM ' . $db->getPrefix() . 'j2commerce_cartitems WHERE j2commerce_cartitem_id = 1');
check('Cart item 1 not deleted by IDOR attempt', (int)$db->loadResult() === 1);

// ── 5. Authenticated removeCartItem ───────────────────────────────────────────
echo "\n--- HTTP: removeCartItem authenticated (testbuyer / Test1234!) ---\n";

// Login — cookie jar captures the new session cookie automatically
$ch = curl_init('http://localhost/index.php?option=com_users&task=user.login');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'username' => 'testbuyer',
        'password' => 'Test1234!',
        $token     => '1',
        'return'   => base64_encode('index.php'),
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_COOKIEJAR      => $cookieJar,
    CURLOPT_COOKIEFILE     => $cookieJar,
    CURLOPT_TIMEOUT        => 15,
]);
$loginBody = (string) curl_exec($ch);
$loginCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
$loggedIn = str_contains($loginBody, 'task=user.logout') || str_contains($loginBody, 'logout');
check('User 999 login succeeded', $loggedIn, "HTTP $loginCode, body snippet: " . substr($loginBody, 0, 200));

if ($loggedIn) {
    // Fetch a fresh CSRF token for the authenticated session
    $ch = curl_init('http://localhost/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $authBody  = (string) curl_exec($ch);
    curl_close($ch);
    $authToken = $token;
    if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $authBody, $atm)) {
        $authToken = $atm[1];
    }

    $ch = curl_init($baseUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['task' => 'removeCartItem', 'cartitem_id' => 2, $authToken => '1']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    check('Authenticated removeCartItem HTTP 200', $code === 200, "Got HTTP $code");
    $resp = json_decode($raw, true);
    if ($resp !== null) {
        $inner = $resp['data'][0] ?? null;
        if (is_string($inner)) { $inner = json_decode($inner, true); }
        check('Authenticated removeCartItem success=true',
            isset($inner['success']) && $inner['success'] === true,
            'Got: ' . json_encode($inner));
        if (isset($inner['data']['cartCount'])) {
            check('cartCount decreased after remove',
                (int)$inner['data']['cartCount'] < 3,
                "Got: {$inner['data']['cartCount']}");
        }
    }
    $db->setQuery('SELECT COUNT(*) FROM ' . $db->getPrefix() . 'j2commerce_cartitems WHERE j2commerce_cartitem_id = 2');
    check('Cart item 2 deleted from DB', (int)$db->loadResult() === 0);
} else {
    fail('Authenticated removeCartItem skipped', 'Login failed');
}
@unlink($cookieJar);

// ── Summary ───────────────────────────────────────────────────────────────────
echo "\n=== J2C6 Full-Install Cart Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
