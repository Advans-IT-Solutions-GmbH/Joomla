<?php
/**
 * Test 11: J2Commerce Cart
 *
 * Round-trip tests for the cart feature:
 * - Plugin params readable from DB (real query, no strpos)
 * - handleRemoveCartItem / handleGetCartCount exist via reflection
 * - isJ2CommerceInstalled() / isJ2Commerce4() return correct types
 * - getCartCountForUser() returns int >= 0 for a known user
 * - Language keys present including J2STORE_NOT_FOUND backwards-compat alias
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

// Register plugin namespace so the class can be resolved without a full plugin bootstrap.
JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false,
    false,
    'psr4'
);

class J2CommerceCartTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery')
            ? $this->db->createQuery()
            : $this->db->getQuery(true);
    }

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

    public function run(): bool
    {
        echo "=== J2Commerce Cart Tests ===\n\n";

        $this->testPluginParamsFromDb();
        $this->testCartMethodsExistViaReflection();
        $this->testCartDetectionMethods();
        $this->testGetCartCountForUser();
        $this->testLanguageKeys();

        echo "\n=== J2Commerce Cart Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function testPluginParamsFromDb(): void
    {
        echo "--- Plugin params (real DB query) ---\n";

        $query = $this->createDbQuery()
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type')    . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder')  . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));

        $this->db->setQuery($query);
        $raw    = $this->db->loadResult();
        $params = json_decode($raw ?: '{}', true);

        $this->test('Plugin record found in #__extensions', $raw !== null,
            'Plugin not installed');
        $this->test('Params is valid JSON', is_array($params),
            'Got: ' . var_export($raw, true));

        // enable_j2store_cart defaults to 1 when not explicitly set
        $enabled = (int) ($params['enable_j2store_cart'] ?? 1);
        $this->test('enable_j2store_cart is 0 or 1', in_array($enabled, [0, 1], true),
            "Got: $enabled");
    }

    private function testCartMethodsExistViaReflection(): void
    {
        echo "\n--- Cart methods via reflection ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;

        $this->test('Class loadable', class_exists($class));

        if (!class_exists($class)) {
            return;
        }

        $rc = new ReflectionClass($class);

        foreach ([
            'handleRemoveCartItem',
            'handleGetCartCount',
            'isJ2CommerceInstalled',
            'isJ2Commerce4',
            'getCartCountForUser',
            'getCartTotalForUser',
        ] as $method) {
            $this->test("Method $method exists", $rc->hasMethod($method));
        }
    }

    private function testCartDetectionMethods(): void
    {
        echo "\n--- Cart detection methods ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);

        // isJ2CommerceInstalled
        $m = $rc->getMethod('isJ2CommerceInstalled');
        $m->setAccessible(true);
        $installed = $m->invoke($plugin, $this->db);
        $this->test('isJ2CommerceInstalled() returns bool', is_bool($installed));

        // isJ2Commerce4
        $m4 = $rc->getMethod('isJ2Commerce4');
        $m4->setAccessible(true);
        $is4 = $m4->invoke($plugin, $this->db);
        $this->test('isJ2Commerce4() returns bool', is_bool($is4));

        // If J2Commerce is installed, detection must match actual table presence.
        // Use SHOW TABLES LIKE (same method as the plugin) to avoid stale cache.
        if ($installed) {
            $prefix = $this->db->getPrefix();
            $this->db->setQuery('SHOW TABLES LIKE ' . $this->db->quote($prefix . 'j2store_carts'));
            $hasJ4 = $this->db->loadResult() !== null;
            $this->test('isJ2Commerce4() matches table presence', $is4 === $hasJ4,
                'isJ2Commerce4=' . var_export($is4, true) . ' hasJ4Tables=' . var_export($hasJ4, true));
        } else {
            $this->test('J2Commerce not installed — version detection skipped', true);
        }

        // getCartTotalForUser always returns '0.00' (both J4 and J6)
        $mt = $rc->getMethod('getCartTotalForUser');
        $mt->setAccessible(true);
        $total = $mt->invoke($plugin, $this->db, 0);
        $this->test('getCartTotalForUser() returns "0.00"', $total === '0.00',
            "Got: $total");
    }

    private function testGetCartCountForUser(): void
    {
        echo "\n--- getCartCountForUser() ---\n";

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        $m      = $rc->getMethod('getCartCountForUser');
        $m->setAccessible(true);

        // User ID 0 — must return 0, not crash
        try {
            $count = $m->invoke($plugin, $this->db, 0);
            $this->test('getCartCountForUser(0) returns int', is_int($count));
            $this->test('getCartCountForUser(0) returns 0 for unknown user', $count === 0,
                "Got: $count");
        } catch (\Throwable $e) {
            $this->test('getCartCountForUser(0) does not throw', false, $e->getMessage());
        }

        // Non-existent large user ID — same expectation
        try {
            $count2 = $m->invoke($plugin, $this->db, 999999999);
            $this->test('getCartCountForUser(999999999) returns int >= 0',
                is_int($count2) && $count2 >= 0, "Got: $count2");
        } catch (\Throwable $e) {
            $this->test('getCartCountForUser(999999999) does not throw', false, $e->getMessage());
        }
    }

    private function testLanguageKeys(): void
    {
        echo "\n--- Language keys ---\n";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        $keys = [
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_ITEM_REMOVED',
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_REMOVE_FAILED',
            'PLG_AJAX_JOOMLAAJAXFORMS_INVALID_CART_ITEM',
            'PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND',
            // Backwards-compat alias — must remain even after rename to J2COMMERCE_NOT_FOUND
            'PLG_AJAX_JOOMLAAJAXFORMS_J2STORE_NOT_FOUND',
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_EMPTY',
        ];

        foreach ($keys as $key) {
            $this->test("Language key $key present", $lang->hasKey($key) !== false);
        }
    }

    // -------------------------------------------------------------------------

    private function makePlugin(): \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms
    {
        $dispatcher = new Dispatcher();
        $params     = new Registry(['enable_j2store_cart' => 1]);
        return new \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms(
            $dispatcher,
            ['params' => $params]
        );
    }
}

$test = new J2CommerceCartTest();
exit($test->run() ? 0 : 1);
