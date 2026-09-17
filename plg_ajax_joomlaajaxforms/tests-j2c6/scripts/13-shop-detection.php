<?php
/**
 * Test 13 (full-install lanes): shop detection follows the enabled component.
 *
 * Rule: com_j2commerce enabled AND #__j2commerce_carts present -> J2Commerce 6;
 * otherwise com_j2store enabled AND #__j2store_carts present -> J2Store /
 * J2Commerce 4; otherwise no shop. Tables alone never decide (after a migration
 * from J2Store to J2Commerce 6 the #__j2store_* tables remain), and an enabled
 * component without its cart table is skipped in favour of the next candidate.
 *
 * Everything is checked through the public com_ajax endpoint (AJAX login,
 * getCartCount, removeCartItem). A dedicated test user gets a cart in BOTH
 * table sets with different quantities, so the returned cartCount shows which
 * tables the plugin used: 5 = #__j2commerce_*, 7 = #__j2store_*, 0 = no shop.
 *
 * J6 + J2Commerce 6 lane (tests-j2c6), "migration" scenario:
 *   1 adds stale #__j2store_carts / #__j2store_cartitems (only if missing) and a
 *     com_j2store component row with enabled=0 (only if missing);
 *     AJAX login redirect points to com_j2commerce, cartCount = 5,
 *     removeCartItem deletes from #__j2commerce_cartitems only
 *   2 counter-check: com_j2commerce disabled, com_j2store enabled -> cartCount = 7.
 *     The login redirect is not checked here: com_j2store is only a database row
 *     in this lane (no component files, no router); the real J2Store redirect is
 *     checked in the J5 + J2Store lane.
 *   3 both disabled -> cartCount = 0, removeCartItem reports "not installed",
 *     login redirect (fresh session) leads to the Joomla user profile
 *   4 #__j2store_* tables hidden, both components enabled -> cartCount = 5
 *   5 #__j2store_* tables hidden, only com_j2store enabled -> cartCount = 0,
 *     login redirect leads to the Joomla user profile
 *
 * J5 + J2Store 4 lane (tests-j2c4), counter-check with the real J2Store:
 *   1 adds #__j2commerce_carts / #__j2commerce_cartitems (only if missing) and a
 *     com_j2commerce component row with enabled=0 (only if missing);
 *     AJAX login redirect points to com_j2store, cartCount = 7,
 *     removeCartItem deletes from #__j2store_cartitems only
 *   2 com_j2commerce enabled -> cartCount = 5 (J2Commerce 6 has priority)
 *   3 both disabled -> as in the J2Commerce 6 lane
 *   4 #__j2commerce_* tables hidden, both components enabled -> cartCount = 7
 *     (the enabled com_j2commerce without tables no longer blocks J2Store)
 *   5 #__j2commerce_* tables hidden, only com_j2commerce enabled -> cartCount = 0,
 *     login redirect leads to the Joomla user profile
 *
 * "Hidden" means the test renames the cart tables of the other shop and renames
 * them back in the cleanup (the lanes have no real tables of the other shop;
 * renaming also keeps real tables intact if a lane ever has them).
 *
 * MFA: in scenario 3 the test user additionally gets a #__user_mfa record. The
 * plugin then answers the login with the captive page URL whose "return"
 * parameter carries the profile target; it must be the same target as without
 * MFA (as an absolute URL). The MFA code itself is not entered: the captive
 * page is Joomla core and not part of this plugin.
 *
 * All changes (tables, component rows and states, user, carts, sessions, MFA
 * record, remember-me keys, action log entries) are reverted at the end, also
 * when an assertion or the script fails, because the production-like lane runs
 * all suites one after another in the same container.
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
const SD_HIDDEN_SUFFIX = '_sdhidden';

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
 * Rename the cart tables of the shop so the plugin does not find them
 * (sd_restore_cart_tables() renames them back).
 */
function sd_hide_cart_tables(DatabaseInterface $db, string $shop): void
{
    foreach (['_cartitems', '_carts'] as $suffix) {
        $db->setQuery(
            'RENAME TABLE ' . $db->quoteName('#__' . $shop . $suffix)
            . ' TO ' . $db->quoteName('#__' . $shop . $suffix . SD_HIDDEN_SUFFIX)
        )->execute();
    }
}

function sd_restore_cart_tables(DatabaseInterface $db, string $shop): void
{
    foreach (['_cartitems', '_carts'] as $suffix) {
        if (sd_table_exists($db, $shop . $suffix . SD_HIDDEN_SUFFIX) && !sd_table_exists($db, $shop . $suffix)) {
            $db->setQuery(
                'RENAME TABLE ' . $db->quoteName('#__' . $shop . $suffix . SD_HIDDEN_SUFFIX)
                . ' TO ' . $db->quoteName('#__' . $shop . $suffix)
            )->execute();
        }
    }
}

/**
 * Number of rows of the user in a core table (0 if the table does not exist).
 */
function sd_user_rows(DatabaseInterface $db, string $table, string $column, $value): int
{
    if (!sd_table_exists($db, $table)) {
        return 0;
    }

    $query = sd_query($db)
        ->select('COUNT(*)')
        ->from($db->quoteName('#__' . $table))
        ->where($db->quoteName($column) . ' = :value')
        ->bind(':value', $value, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);

    return (int) $db->setQuery($query)->loadResult();
}

/**
 * Published site menu item for the myprofile view of the shop, if any.
 */
function sd_profile_menu_item(DatabaseInterface $db, string $shop): ?object
{
    return sd_menu_item($db, 'index.php?option=com_' . $shop . '&view=myprofile');
}

/**
 * Published site menu item with exactly this link, if any.
 */
function sd_menu_item(DatabaseInterface $db, string $link): ?object
{
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
 * Whether the login redirect leads to the Joomla user profile
 * (com_users, view=profile): its menu item if one exists, otherwise the
 * non-SEF URL or the SEF path /component/users/profile.
 */
function sd_redirect_is_user_profile(DatabaseInterface $db, string $redirect): bool
{
    if (str_contains($redirect, 'j2store') || str_contains($redirect, 'j2commerce')) {
        return false;
    }

    $item = sd_menu_item($db, 'index.php?option=com_users&view=profile');

    if ($item !== null
        && (str_contains($redirect, 'Itemid=' . (int) $item->id)
            || ((string) $item->path !== '' && str_contains($redirect, '/' . (string) $item->path)))) {
        return true;
    }

    // SEF: /component/users/profile, or the profile segment below another
    // com_users menu item the router picked as base (e.g. /login/profile).
    return (str_contains($redirect, 'option=com_users') && str_contains($redirect, 'view=profile'))
        || preg_match('#/component/users/\?(?:.*&)?view=profile(?:&|$)#', $redirect) === 1
        || preg_match('#/profile(?:[/?]|$)#', (string) parse_url($redirect, PHP_URL_PATH) . '?') === 1;
}

/**
 * Profile target carried in the "return" parameter of the MFA captive URL.
 */
function sd_captive_return(string $captiveUrl): string
{
    $query = (string) parse_url(html_entity_decode($captiveUrl), PHP_URL_QUERY);
    parse_str($query, $params);
    $return = (string) ($params['return'] ?? '');

    // A "+" of the base64 value may arrive as a space.
    $decoded = base64_decode(strtr($return, ' ', '+'), true);

    return is_string($decoded) ? $decoded : '';
}

/**
 * Fresh session (new cookie jar), guest token, AJAX login.
 *
 * @return array{0:?array, 1:string}  login result, cookie jar
 */
function sd_login(string $username, array &$state): array
{
    $jar                   = tempnam(sys_get_temp_dir(), 'shopdetect-');
    $state['cookieJars'][] = $jar;
    $token                 = sd_token($jar, ['/index.php?option=com_users&view=login', '/']);
    sd_check('Guest CSRF token obtained', $token !== '');

    $login = sd_ajax('login', ['username' => $username, 'password' => SD_PASSWORD], $token, $jar);
    sd_check('AJAX login succeeded', is_array($login) && ($login['success'] ?? null) === true, json_encode($login));

    return [$login, $jar];
}

/**
 * Log in with a fresh session and check that the redirect leads to the Joomla
 * user profile and that this page opens for the logged-in user.
 */
function sd_check_login_to_user_profile(DatabaseInterface $db, string $username, array &$state): string
{
    [$login, $jar] = sd_login($username, $state);
    $redirect      = (string) ($login['data']['redirect'] ?? '');
    echo "  Login redirect: $redirect\n";
    sd_check('Login redirect leads to the Joomla user profile (com_users, view=profile)',
        sd_redirect_is_user_profile($db, $redirect), $redirect);

    $url = preg_match('#^https?://#i', $redirect) ? $redirect : SD_BASE_URL . '/' . ltrim($redirect, '/');
    [$code, $body] = $redirect !== '' ? sd_http($url, null, $jar) : [0, ''];
    // The com_users profile view shows the username; for a guest it would
    // redirect to the login form instead.
    sd_check('Redirect target opens (HTTP 200) and shows the profile of the test user',
        $code === 200 && str_contains($body, $username), "HTTP $code");

    return $redirect;
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
    'username'      => '',
    'createdTables' => [],
    'hiddenTables'  => [],
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

    // First: hidden cart tables back under their names (the steps below expect them there).
    foreach ($state['hiddenTables'] as $shop) {
        $steps["rename hidden $shop cart tables back"] = static fn () => sd_restore_cart_tables($db, $shop);
    }

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

        // Login traces and the user itself. #__user_keys (remember-me) stores the username.
        $traces = [
            ['session', 'userid', $userId],
            ['user_keys', 'user_id', (string) $state['username']],
            ['action_logs', 'user_id', $userId],
            ['user_mfa', 'user_id', $userId],
            ['user_usergroup_map', 'user_id', $userId],
            ['users', 'id', $userId],
        ];

        foreach ($traces as [$table, $column, $value]) {
            if ($value === '') {
                continue;
            }

            $steps["remove user $userId from #__$table"] = static function () use ($db, $table, $column, $value): void {
                if (!sd_table_exists($db, $table)) {
                    return;
                }

                $query = sd_query($db)
                    ->delete($db->quoteName('#__' . $table))
                    ->where($db->quoteName($column) . ' = :value')
                    ->bind(':value', $value, is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);
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

    $username          = 'shopdetect_' . bin2hex(random_bytes(4));
    $state['username'] = $username;
    $now               = Factory::getDate()->toSql();
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

    [$login, $jar] = sd_login($username, $state);

    $redirect = (string) ($login['data']['redirect'] ?? '');
    echo "  Login redirect: $redirect\n";
    sd_check("Login redirect leads to the com_$lane profile", sd_redirect_points_to($db, $redirect, $lane), $redirect);
    sd_check("Login redirect does not lead to com_$other", !sd_redirect_points_to($db, $redirect, $other), $redirect);

    // The form token depends on the session user: read it again after the login.
    // Without it every cart request below would be rejected, so do not fall back
    // to the guest token but stop here (counted as failure).
    $token = sd_token($jar, ['/index.php?option=com_users&view=profile&layout=edit', '/']);
    sd_check('CSRF token read after login', $token !== '');

    if ($token === '') {
        throw new \RuntimeException('No CSRF token after the login: getCartCount/removeCartItem of all scenarios could not run');
    }

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

    echo "  Login without shop (fresh session):\n";
    $plainRedirect = sd_check_login_to_user_profile($db, $username, $state);

    // Same login with an MFA record: the captive page must return to the same target.
    echo "  Login without shop, user with MFA record (fresh session):\n";
    $mfaId = sd_insert($db, '#__user_mfa', [
        'user_id'    => $state['userId'],
        'title'      => 'Shop detection test',
        'method'     => 'totp',
        'default'    => 1,
        'options'    => '{}',
        'created_on' => Factory::getDate()->toSql(),
        'last_used'  => null,
        'tries'      => 0,
        'last_try'   => null,
    ], 'id');
    sd_check('MFA record created for the test user', $mfaId > 0);

    [$login] = sd_login($username, $state);
    $captive = (string) ($login['data']['redirect'] ?? '');
    $return  = sd_captive_return($captive);
    echo "  Captive URL: $captive\n  Return target: $return\n";
    sd_check('MFA login answers with the captive page', str_contains($captive, 'captive'), $captive);
    sd_check('MFA return target is an absolute URL of this site', str_starts_with($return, SD_BASE_URL . '/'), $return);
    sd_check('MFA return target is the same profile target as without MFA',
        $plainRedirect !== '' && str_ends_with($return, '/' . ltrim($plainRedirect, '/'))
        && sd_redirect_is_user_profile($db, $return),
        "with MFA: $return, without: $plainRedirect");

    $query = sd_query($db)
        ->delete($db->quoteName('#__user_mfa'))
        ->where($db->quoteName('user_id') . ' = :userId')
        ->bind(':userId', $state['userId'], ParameterType::INTEGER);
    $db->setQuery($query)->execute();

    // ── Scenario 4: other shop's tables missing, both components enabled ────
    echo "\n--- Enabled without tables: #__{$other}_* tables missing, com_j2commerce and com_j2store enabled ---\n";
    $state['hiddenTables'][] = $other;
    sd_hide_cart_tables($db, $other);
    sd_check("Tables {$other}_carts / {$other}_cartitems missing",
        !sd_table_exists($db, $other . '_carts') && !sd_table_exists($db, $other . '_cartitems'));

    sd_set_enabled($db, 'com_j2commerce', 1);
    sd_set_enabled($db, 'com_j2store', 1);

    $count = sd_cart_count($token, $jar);
    sd_check("getCartCount uses the #__{$lane}_* tables (" . SD_QTY[$lane] . ')',
        $count === SD_QTY[$lane], 'got ' . var_export($count, true));

    // ── Scenario 5: only the component without tables enabled ──────────────
    echo "\n--- No shop: only com_$other enabled, its tables missing ---\n";
    sd_set_enabled($db, 'com_' . $lane, 0);

    $count = sd_cart_count($token, $jar);
    sd_check('getCartCount returns 0', $count === 0, 'got ' . var_export($count, true));

    sd_check_login_to_user_profile($db, $username, $state);
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

foreach ($state['hiddenTables'] as $shop) {
    sd_check("No renamed $shop cart tables left",
        !sd_table_exists($db, $shop . '_carts' . SD_HIDDEN_SUFFIX)
        && !sd_table_exists($db, $shop . '_cartitems' . SD_HIDDEN_SUFFIX));

    if (!in_array($shop, $state['createdTables'], true)) {
        sd_check("Existing $shop cart tables back under their names",
            sd_table_exists($db, $shop . '_carts') && sd_table_exists($db, $shop . '_cartitems'));
    }
}

foreach ($state['createdTables'] as $shop) {
    sd_check("Minimal $shop cart tables dropped",
        !sd_table_exists($db, $shop . '_carts') && !sd_table_exists($db, $shop . '_cartitems'));
}

if ($state['userId'] > 0) {
    $userId = (int) $state['userId'];
    sd_check('Test user removed', sd_user_rows($db, 'users', 'id', $userId) === 0);
    sd_check('Group mapping of the test user removed', sd_user_rows($db, 'user_usergroup_map', 'user_id', $userId) === 0);
    sd_check('Sessions of the test user removed', sd_user_rows($db, 'session', 'userid', $userId) === 0);
    sd_check('MFA records of the test user removed', sd_user_rows($db, 'user_mfa', 'user_id', $userId) === 0);
    sd_check('Action log entries of the test user removed', sd_user_rows($db, 'action_logs', 'user_id', $userId) === 0);
    sd_check('Remember-me keys of the test user removed',
        $state['username'] === '' || sd_user_rows($db, 'user_keys', 'user_id', (string) $state['username']) === 0);
}

echo "\n=== Shop Detection Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
