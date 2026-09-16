<?php
/**
 * Test 13 (full-install lanes): shop detection follows the enabled component.
 *
 * Rule: com_j2commerce enabled -> J2Commerce 6 (#__j2commerce_* tables);
 * otherwise com_j2store enabled -> J2Store / J2Commerce 4 (#__j2store_* tables);
 * otherwise no shop. Tables alone never decide: after a migration from J2Store
 * to J2Commerce 6 the #__j2store_* tables remain in the database.
 *
 * Everything is checked through the public com_ajax endpoint (AJAX login,
 * getCartCount, removeCartItem). A dedicated test user gets a cart in BOTH
 * table sets with different quantities, so the returned cartCount shows which
 * tables the plugin used: 5 = #__j2commerce_*, 7 = #__j2store_*, 0 = no shop.
 *
 * J6 + J2Commerce 6 lane (tests-j2c6), "migration" scenario:
 *   - adds stale #__j2store_carts / #__j2store_cartitems (only if missing) and a
 *     com_j2store component row with enabled=0 (only if missing)
 *   - AJAX login redirect points to com_j2commerce, cartCount = 5,
 *     removeCartItem deletes from #__j2commerce_cartitems only
 *   - counter-check: com_j2commerce disabled, com_j2store enabled -> cartCount = 7.
 *     The login redirect is not checked here: com_j2store is only a database row
 *     in this lane (no component files, no router); the real J2Store redirect is
 *     checked in the J5 + J2Store lane.
 *   - both disabled -> cartCount = 0 and removeCartItem reports "not installed"
 *
 * J5 + J2Store 4 lane (tests-j2c4), counter-check with the real J2Store:
 *   - adds #__j2commerce_carts / #__j2commerce_cartitems (only if missing) and a
 *     com_j2commerce component row with enabled=0 (only if missing)
 *   - AJAX login redirect points to com_j2store, cartCount = 7,
 *     removeCartItem deletes from #__j2store_cartitems only
 *   - com_j2commerce enabled -> cartCount = 5 (J2Commerce 6 has priority)
 *   - both disabled -> cartCount = 0 and removeCartItem reports "not installed"
 *
 * All changes (tables, component rows and states, user, carts, sessions) are
 * reverted at the end, also when an assertion or the script fails, because the
 * production-like lane runs all suites one after another in the same container.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

const SD_BASE_URL  = 'http://localhost';
const SD_AJAX_PATH = '/index.php?option=com_ajax&plugin=joomlaajaxforms&group=ajax&format=json';
const SD_QTY       = ['j2commerce' => 5, 'j2store' => 7];
const SD_PASSWORD  = 'ShopDetect1234!';

$passed = 0;
$failed = 0;

function sd_check(string $name, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        echo "✓ $name\n";
        $passed++;
    } else {
        echo "✗ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failed++;
    }
}

function sd_db(): DatabaseInterface
{
    return Factory::getContainer()->get(DatabaseInterface::class);
}

function sd_query(DatabaseInterface $db)
{
    return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
}

/**
 * Insert a row with only the columns the table has (core tables differ slightly
 * between Joomla 5 and 6) and return the new primary key value.
 */
function sd_insert(DatabaseInterface $db, string $table, array $data, ?string $key = null): int
{
    $columns = $db->getTableColumns($table, true);
    $row     = (object) array_intersect_key($data, $columns);
    $db->insertObject($table, $row, $key);

    return $key !== null ? (int) ($row->$key ?? 0) : 0;
}

function sd_table_exists(DatabaseInterface $db, string $table): bool
{
    $db->setQuery('SHOW TABLES LIKE ' . $db->quote($db->getPrefix() . $table));

    return $db->loadResult() !== null;
}

/**
 * @return object|null  extension_id, enabled of the component row
 */
function sd_component(DatabaseInterface $db, string $element): ?object
{
    $query = sd_query($db)
        ->select([$db->quoteName('extension_id'), $db->quoteName('enabled')])
        ->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        ->where($db->quoteName('element') . ' = :element')
        ->bind(':element', $element);

    return $db->setQuery($query)->loadObject() ?: null;
}

function sd_set_enabled(DatabaseInterface $db, string $element, int $enabled): void
{
    $query = sd_query($db)
        ->update($db->quoteName('#__extensions'))
        ->set($db->quoteName('enabled') . ' = :enabled')
        ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        ->where($db->quoteName('element') . ' = :element')
        ->bind(':enabled', $enabled, ParameterType::INTEGER)
        ->bind(':element', $element);
    $db->setQuery($query)->execute();
}

/**
 * Create a minimal cart table pair with the columns the plugin reads
 * (<shop>_cart_id, user_id / <shop>_cartitem_id, cart_id, product_qty) plus the
 * columns the real J2Store 4 / J2Commerce 6 tables require on insert, so one
 * insert statement works for both the real and the minimal tables.
 */
function sd_create_cart_tables(DatabaseInterface $db, string $shop): void
{
    $extra = $shop === 'j2commerce'
        ? ', `cart_voucher` TEXT NULL, `cart_coupon` TEXT NULL'
        : '';

    $db->setQuery(
        'CREATE TABLE ' . $db->quoteName('#__' . $shop . '_carts') . ' ('
        . '`' . $shop . '_cart_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' `user_id` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `session_id` VARCHAR(255) NOT NULL DEFAULT \'\','
        . ' `cart_type` VARCHAR(255) NOT NULL DEFAULT \'cart\','
        . ' `created_on` DATETIME NULL, `modified_on` DATETIME NULL,'
        . ' `customer_ip` VARCHAR(255) NOT NULL DEFAULT \'\','
        . ' `cart_params` TEXT NULL, `cart_browser` TEXT NULL, `cart_analytics` TEXT NULL'
        . $extra . ','
        . ' PRIMARY KEY (`' . $shop . '_cart_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )->execute();

    $db->setQuery(
        'CREATE TABLE ' . $db->quoteName('#__' . $shop . '_cartitems') . ' ('
        . '`' . $shop . '_cartitem_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . ' `cart_id` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `product_id` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `variant_id` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `vendor_id` INT UNSIGNED NOT NULL DEFAULT 0,'
        . ' `product_type` VARCHAR(255) NOT NULL DEFAULT \'\','
        . ' `cartitem_params` TEXT NULL, `product_options` TEXT NULL,'
        . ' `product_qty` DECIMAL(12,4) NOT NULL DEFAULT 0,'
        . ' PRIMARY KEY (`' . $shop . '_cartitem_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    )->execute();
}

/**
 * Insert a cart with one item for the user.
 *
 * @return int  cart item id
 */
function sd_seed_cart(DatabaseInterface $db, string $shop, int $userId, int $qty): int
{
    $now  = Factory::getDate()->toSql();
    $cart = [
        'user_id'        => $userId,
        'session_id'     => 'shopdetect' . $userId,
        'cart_type'      => 'cart',
        'created_on'     => $now,
        'modified_on'    => $now,
        'customer_ip'    => '127.0.0.1',
        'cart_params'    => '{}',
        'cart_browser'   => '',
        'cart_analytics' => '',
    ];

    if ($shop === 'j2commerce') {
        $cart['cart_voucher'] = '';
        $cart['cart_coupon']  = '';
    }

    $cartId = sd_insert($db, '#__' . $shop . '_carts', $cart, $shop . '_cart_id');

    if ($cartId <= 0) {
        throw new \RuntimeException("Cart could not be created in #__{$shop}_carts");
    }

    return sd_insert($db, '#__' . $shop . '_cartitems', [
        'cart_id'         => $cartId,
        'product_id'      => 1,
        'variant_id'      => 0,
        'vendor_id'       => 0,
        'product_type'    => 'simple',
        'cartitem_params' => '{}',
        'product_qty'     => $qty,
        'product_options' => '{}',
    ], $shop . '_cartitem_id');
}

function sd_cartitem_exists(DatabaseInterface $db, string $shop, int $itemId): bool
{
    $query = sd_query($db)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__' . $shop . '_cartitems'))
        ->where($db->quoteName($shop . '_cartitem_id') . ' = :id')
        ->bind(':id', $itemId, ParameterType::INTEGER);

    return (int) $db->setQuery($query)->loadResult() === 1;
}

/**
 * Remove all carts of the user from the shop tables (also carts a shop plugin
 * may have created during the login).
 */
function sd_delete_carts(DatabaseInterface $db, string $shop, int $userId): void
{
    $query = sd_query($db)
        ->select($db->quoteName($shop . '_cart_id'))
        ->from($db->quoteName('#__' . $shop . '_carts'))
        ->where($db->quoteName('user_id') . ' = :userId')
        ->bind(':userId', $userId, ParameterType::INTEGER);
    $cartIds = array_map('intval', $db->setQuery($query)->loadColumn());

    if ($cartIds) {
        $query = sd_query($db)
            ->delete($db->quoteName('#__' . $shop . '_cartitems'))
            ->whereIn($db->quoteName('cart_id'), $cartIds);
        $db->setQuery($query)->execute();
    }

    $query = sd_query($db)
        ->delete($db->quoteName('#__' . $shop . '_carts'))
        ->where($db->quoteName('user_id') . ' = :userId')
        ->bind(':userId', $userId, ParameterType::INTEGER);
    $db->setQuery($query)->execute();
}

/**
 * Published site menu item for the myprofile view of the shop, if any.
 */
function sd_profile_menu_item(DatabaseInterface $db, string $shop): ?object
{
    $link  = 'index.php?option=com_' . $shop . '&view=myprofile';
    $query = sd_query($db)
        ->select([$db->quoteName('id'), $db->quoteName('path')])
        ->from($db->quoteName('#__menu'))
        ->where($db->quoteName('link') . ' = :link')
        ->where($db->quoteName('client_id') . ' = 0')
        ->where($db->quoteName('published') . ' = 1')
        ->bind(':link', $link)
        ->setLimit(1);

    return $db->setQuery($query)->loadObject() ?: null;
}

/**
 * Whether the login redirect leads to the myprofile page of the shop: its menu
 * item if one exists, otherwise a URL built for com_<shop>.
 */
function sd_redirect_points_to(DatabaseInterface $db, string $redirect, string $shop): bool
{
    $item = sd_profile_menu_item($db, $shop);

    if ($item !== null) {
        return str_contains($redirect, 'Itemid=' . (int) $item->id)
            || ((string) $item->path !== '' && str_contains($redirect, (string) $item->path));
    }

    return str_contains($redirect, $shop);
}

/**
 * Messages of PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND in all installed
 * languages of the plugin.
 *
 * @return string[]
 */
function sd_not_found_messages(): array
{
    $messages = [];
    $files    = array_merge(
        glob(JPATH_BASE . '/plugins/ajax/joomlaajaxforms/language/*/plg_ajax_joomlaajaxforms.ini') ?: [],
        glob(JPATH_BASE . '/administrator/language/*/plg_ajax_joomlaajaxforms.ini') ?: [],
        glob(JPATH_BASE . '/language/*/plg_ajax_joomlaajaxforms.ini') ?: []
    );

    foreach ($files as $file) {
        $strings = @parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];

        if (isset($strings['PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND'])) {
            $messages[] = trim((string) $strings['PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND'], '"');
        }
    }

    return array_values(array_unique($messages));
}

/**
 * @return array{0:int, 1:string}  HTTP status, body
 */
function sd_http(string $url, ?array $fields, string $cookieJar): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_COOKIEJAR      => $cookieJar,
    ]);

    if ($fields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [$code, is_string($body) ? $body : ''];
}

/**
 * Read a CSRF token for the session in the cookie jar (form field or the
 * "csrf.token" script option).
 */
function sd_token(string $cookieJar, array $pages): string
{
    foreach ($pages as $page) {
        [$code, $body] = sd_http(SD_BASE_URL . $page, null, $cookieJar);

        if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $body, $m)
            || preg_match('/"csrf\.token"\s*:\s*"([a-f0-9]{32})"/i', $body, $m)) {
            return $m[1];
        }

        echo "  DIAG no token on $page: HTTP $code, " . strlen($body) . " bytes\n";
    }

    return '';
}

/**
 * POST a task to the plugin and return the handler result (the JSON string in
 * data[0] of the com_ajax envelope).
 *
 * @return array<string,mixed>|null
 */
function sd_ajax(string $task, array $fields, string $token, string $cookieJar): ?array
{
    $fields['task'] = $task;

    if ($token !== '') {
        $fields[$token] = '1';
    }

    [$code, $body] = sd_http(SD_BASE_URL . SD_AJAX_PATH . '&task=' . $task, $fields, $cookieJar);
    $outer = json_decode($body, true);
    $inner = $outer['data'][0] ?? null;

    if (is_string($inner)) {
        $inner = json_decode($inner, true);
    }

    if (!is_array($inner)) {
        echo "  DIAG $task: HTTP $code, body: " . substr(trim($body), 0, 300) . "\n";

        return null;
    }

    return $inner;
}

function sd_cart_count(string $token, string $cookieJar): ?int
{
    $result = sd_ajax('getCartCount', [], $token, $cookieJar);

    if (!is_array($result) || ($result['success'] ?? null) !== true || !isset($result['data']['cartCount'])) {
        echo '  DIAG getCartCount result: ' . json_encode($result) . "\n";

        return null;
    }

    return (int) $result['data']['cartCount'];
}

// ---------------------------------------------------------------------------

$db     = sd_db();
$state  = [
    'userId'        => 0,
    'createdTables' => [],
    'insertedRows'  => [],
    'enabled'       => [],
    'cookieJars'    => [],
    'cleaned'       => false,
];

$cleanup = static function () use ($db, &$state): void {
    if ($state['cleaned']) {
        return;
    }

    $state['cleaned'] = true;
    echo "\n--- Cleanup ---\n";

    $steps = [];

    foreach ($state['enabled'] as $element => $enabled) {
        $steps["restore $element enabled=$enabled"] = static fn () => sd_set_enabled($db, $element, $enabled);
    }

    foreach ($state['insertedRows'] as $extensionId) {
        $steps["remove component row $extensionId"] = static function () use ($db, $extensionId): void {
            $query = sd_query($db)
                ->delete($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = :id')
                ->bind(':id', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
        };
    }

    $userId = (int) $state['userId'];

    if ($userId > 0) {
        foreach (['j2commerce', 'j2store'] as $shop) {
            if (!in_array($shop, $state['createdTables'], true)) {
                $steps["remove $shop carts of user $userId"] = static function () use ($db, $shop, $userId): void {
                    if (sd_table_exists($db, $shop . '_carts') && sd_table_exists($db, $shop . '_cartitems')) {
                        sd_delete_carts($db, $shop, $userId);
                    }
                };
            }
        }

        foreach (['#__session' => 'userid', '#__user_usergroup_map' => 'user_id', '#__users' => 'id'] as $table => $column) {
            $steps["remove user $userId from $table"] = static function () use ($db, $table, $column, $userId): void {
                $query = sd_query($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName($column) . ' = :userId')
                    ->bind(':userId', $userId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            };
        }
    }

    foreach ($state['createdTables'] as $shop) {
        $steps["drop minimal $shop cart tables"] = static function () use ($db, $shop): void {
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__' . $shop . '_cartitems'))->execute();
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName('#__' . $shop . '_carts'))->execute();
        };
    }

    foreach ($steps as $label => $step) {
        try {
            $step();
            sd_check("Cleanup: $label", true);
        } catch (\Throwable $e) {
            sd_check("Cleanup: $label", false, $e->getMessage());
        }
    }

    foreach ($state['cookieJars'] as $jar) {
        @unlink($jar);
    }
};

register_shutdown_function(static function () use ($cleanup): void {
    $cleanup();
});

echo "=== Shop Detection Tests ===\n";

try {
    // ── Which stack is installed? ───────────────────────────────────────────
    echo "\n--- Installed stack ---\n";
    $components = [
        'j2commerce' => sd_component($db, 'com_j2commerce'),
        'j2store'    => sd_component($db, 'com_j2store'),
    ];
    $lane = null;

    foreach (['j2commerce', 'j2store'] as $shop) {
        $row = $components[$shop];

        if ($row !== null && (int) $row->enabled === 1
            && sd_table_exists($db, $shop . '_carts') && sd_table_exists($db, $shop . '_cartitems')) {
            $lane = $shop;
            break;
        }
    }

    sd_check('An enabled shop component with cart tables is installed', $lane !== null,
        'neither com_j2commerce nor com_j2store is installed and enabled');

    if ($lane === null) {
        throw new \RuntimeException('No shop stack; this suite belongs to the full-install lanes only');
    }

    $other = $lane === 'j2commerce' ? 'j2store' : 'j2commerce';
    echo "  Installed: com_$lane; added for the test: com_$other (disabled) with its cart tables\n";

    // ── Fixtures: component row and tables of the other shop ────────────────
    echo "\n--- Fixtures ---\n";

    foreach ($components as $shop => $row) {
        if ($row !== null) {
            $state['enabled']['com_' . $shop] = (int) $row->enabled;
        }
    }

    if ($components[$other] === null) {
        $extensionId = sd_insert($db, '#__extensions', [
            'name'           => 'com_' . $other,
            'type'           => 'component',
            'element'        => 'com_' . $other,
            'folder'         => '',
            'client_id'      => 1,
            'enabled'        => 0,
            'access'         => 1,
            'protected'      => 0,
            'locked'         => 0,
            'manifest_cache' => '{}',
            'params'         => '{}',
            'custom_data'    => '',
            'ordering'       => 0,
            'state'          => 0,
            'note'           => '',
        ], 'extension_id');

        if ($extensionId > 0) {
            $state['insertedRows'][] = $extensionId;
        }

        sd_check("Component row com_$other added (enabled=0)", $extensionId > 0);
    } else {
        sd_set_enabled($db, 'com_' . $other, 0);
        echo "  com_$other row already present (enabled={$components[$other]->enabled}); set to enabled=0\n";
    }

    if (!sd_table_exists($db, $other . '_carts') && !sd_table_exists($db, $other . '_cartitems')) {
        sd_create_cart_tables($db, $other);
        $state['createdTables'][] = $other;
    }

    sd_check("Tables {$other}_carts / {$other}_cartitems exist",
        sd_table_exists($db, $other . '_carts') && sd_table_exists($db, $other . '_cartitems'));

    $username = 'shopdetect_' . bin2hex(random_bytes(4));
    $now      = Factory::getDate()->toSql();
    $state['userId'] = sd_insert($db, '#__users', [
        'name'          => 'Shop Detection Test',
        'username'      => $username,
        'email'         => $username . '@example.com',
        'password'      => password_hash(SD_PASSWORD, PASSWORD_BCRYPT),
        'block'         => 0,
        'sendEmail'     => 0,
        'registerDate'  => $now,
        'lastvisitDate' => $now,
        'activation'    => '',
        'params'        => '{}',
        'lastResetTime' => $now,
        'resetCount'    => 0,
        'otpKey'        => '',
        'otep'          => '',
        'requireReset'  => 0,
        'authProvider'  => '',
    ], 'id');
    sd_check('Test user created', $state['userId'] > 0);

    if ($state['userId'] <= 0) {
        throw new \RuntimeException('Test user could not be created');
    }

    // Group 2 = Registered (site login permission)
    sd_insert($db, '#__user_usergroup_map', ['user_id' => $state['userId'], 'group_id' => 2]);

    $items = [];

    foreach (['j2commerce', 'j2store'] as $shop) {
        $items[$shop] = sd_seed_cart($db, $shop, $state['userId'], SD_QTY[$shop]);
        sd_check("Cart item in {$shop}_cartitems (qty " . SD_QTY[$shop] . ')', $items[$shop] > 0);
    }

    // ── Scenario 1: installed shop enabled, other shop disabled ─────────────
    $title = $lane === 'j2commerce'
        ? 'Migration: com_j2commerce enabled, stale #__j2store_* tables, com_j2store disabled'
        : 'J2Store: com_j2store enabled, #__j2commerce_* tables, com_j2commerce disabled';
    echo "\n--- $title ---\n";

    $jar                   = tempnam(sys_get_temp_dir(), 'shopdetect-');
    $state['cookieJars'][] = $jar;
    $token                 = sd_token($jar, ['/index.php?option=com_users&view=login', '/']);
    sd_check('Guest CSRF token obtained', $token !== '');

    $login = sd_ajax('login', ['username' => $username, 'password' => SD_PASSWORD], $token, $jar);
    sd_check('AJAX login succeeded', is_array($login) && ($login['success'] ?? null) === true, json_encode($login));

    $redirect = (string) ($login['data']['redirect'] ?? '');
    echo "  Login redirect: $redirect\n";
    sd_check("Login redirect leads to the com_$lane profile", sd_redirect_points_to($db, $redirect, $lane), $redirect);
    sd_check("Login redirect does not lead to com_$other", !sd_redirect_points_to($db, $redirect, $other), $redirect);

    // The form token depends on the session user: read it again after the login.
    $token = sd_token($jar, ['/index.php?option=com_users&view=profile&layout=edit', '/']) ?: $token;

    $count = sd_cart_count($token, $jar);
    sd_check("getCartCount uses the #__{$lane}_* tables (" . SD_QTY[$lane] . ')',
        $count === SD_QTY[$lane], 'got ' . var_export($count, true));

    $remove = sd_ajax('removeCartItem', ['cartitem_id' => $items[$lane]], $token, $jar);
    sd_check('removeCartItem succeeded', is_array($remove) && ($remove['success'] ?? null) === true, json_encode($remove));
    sd_check("Item deleted from #__{$lane}_cartitems", !sd_cartitem_exists($db, $lane, $items[$lane]));
    sd_check("Item in #__{$other}_cartitems untouched", sd_cartitem_exists($db, $other, $items[$other]));

    // Put the installed shop's item back for the next scenarios.
    $items[$lane] = sd_seed_cart($db, $lane, $state['userId'], SD_QTY[$lane]);

    // ── Scenario 2: the other component enabled ─────────────────────────────
    if ($lane === 'j2commerce') {
        echo "\n--- Counter-check: com_j2commerce disabled, com_j2store enabled ---\n";
        sd_set_enabled($db, 'com_j2commerce', 0);
        sd_set_enabled($db, 'com_j2store', 1);
        $expected = 'j2store';
    } else {
        echo "\n--- Priority: com_j2commerce enabled in addition to com_j2store ---\n";
        sd_set_enabled($db, 'com_j2commerce', 1);
        $expected = 'j2commerce';
    }

    $count = sd_cart_count($token, $jar);
    sd_check("getCartCount uses the #__{$expected}_* tables (" . SD_QTY[$expected] . ')',
        $count === SD_QTY[$expected], 'got ' . var_export($count, true));

    // ── Scenario 3: no shop component enabled ───────────────────────────────
    echo "\n--- No shop: com_j2commerce and com_j2store disabled (both table sets present) ---\n";
    sd_set_enabled($db, 'com_j2commerce', 0);
    sd_set_enabled($db, 'com_j2store', 0);

    $count = sd_cart_count($token, $jar);
    sd_check('getCartCount returns 0', $count === 0, 'got ' . var_export($count, true));

    $remove   = sd_ajax('removeCartItem', ['cartitem_id' => $items[$lane]], $token, $jar);
    $messages = sd_not_found_messages();
    sd_check('removeCartItem reports that no shop is installed',
        is_array($remove) && ($remove['success'] ?? null) === false && in_array($remove['message'] ?? '', $messages, true),
        json_encode($remove));
    sd_check('Both cart items untouched',
        sd_cartitem_exists($db, 'j2commerce', $items['j2commerce']) && sd_cartitem_exists($db, 'j2store', $items['j2store']));
} catch (\Throwable $e) {
    sd_check('Suite ran without exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    $cleanup();
}

// ── Cleanup verification ────────────────────────────────────────────────────
echo "\n--- Cleanup verification ---\n";

foreach ($state['enabled'] as $element => $enabled) {
    $row = sd_component($db, $element);
    sd_check("$element enabled=$enabled restored", $row !== null && (int) $row->enabled === $enabled);
}

foreach ($state['insertedRows'] as $extensionId) {
    $query = sd_query($db)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__extensions'))
        ->where($db->quoteName('extension_id') . ' = :id')
        ->bind(':id', $extensionId, ParameterType::INTEGER);
    sd_check("Component row $extensionId removed", (int) $db->setQuery($query)->loadResult() === 0);
}

foreach ($state['createdTables'] as $shop) {
    sd_check("Minimal $shop cart tables dropped",
        !sd_table_exists($db, $shop . '_carts') && !sd_table_exists($db, $shop . '_cartitems'));
}

if ($state['userId'] > 0) {
    $query = sd_query($db)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__users'))
        ->where($db->quoteName('id') . ' = :id')
        ->bind(':id', $state['userId'], ParameterType::INTEGER);
    sd_check('Test user removed', (int) $db->setQuery($query)->loadResult() === 0);
}

echo "\n=== Shop Detection Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
