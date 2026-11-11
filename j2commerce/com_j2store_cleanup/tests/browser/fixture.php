<?php
/**
 * Disposable test sites only. Creates a synthetic Super User with a random
 * password and an eligible "no files" probe record, and prints both as JSON
 * for the rendered layout test. Nothing is written outside the test database.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || getenv('CLEANUP_LAYOUT_TEST') !== '1') {
    throw new RuntimeException('Explicit disposable-site CLI opt-in required.');
}

$baseUrl = rtrim((string) getenv('CLEANUP_LAYOUT_BASE_URL'), '/');
if (!in_array(parse_url($baseUrl, PHP_URL_HOST), ['localhost', '127.0.0.1', '[::1]'], true)) {
    throw new RuntimeException('A loopback base URL is required.');
}

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

$db       = Factory::getContainer()->get(DatabaseInterface::class);
$suffix   = bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(24)) . '!aA1';
$user     = (object) [
    'name'         => 'Synthetic Layout Administrator',
    'username'     => 'layout-' . $suffix,
    'email'        => 'layout-' . $suffix . '@example.invalid',
    'password'     => password_hash($password, PASSWORD_BCRYPT),
    'block'        => 0,
    'sendEmail'    => 0,
    'registerDate' => gmdate('Y-m-d H:i:s'),
    'params'       => '{}',
];
$db->insertObject('#__users', $user, 'id');
$membership = (object) ['user_id' => (int) $user->id, 'group_id' => 8];
$db->insertObject('#__user_usergroup_map', $membership);

// A long element name stresses the table width on narrow screens.
$probe = (object) [
    'name'           => 'plg_j2store_layoutprobe',
    'type'           => 'plugin',
    'element'        => 'layoutprobe_with_a_deliberately_long_element_name_' . $suffix,
    'folder'         => 'j2store',
    'client_id'      => 0,
    'enabled'        => 0,
    'access'         => 1,
    'protected'      => 0,
    'locked'         => 0,
    'manifest_cache' => '{}',
    'params'         => '{}',
    'custom_data'    => '',
    'state'          => 0,
];
$db->insertObject('#__extensions', $probe, 'extension_id');

echo json_encode([
    'baseURL'      => $baseUrl,
    'username'     => $user->username,
    'password'     => $password,
    'probeElement' => $probe->element,
], JSON_THROW_ON_ERROR);
