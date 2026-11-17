<?php
/**
 * Disposable, mail-disabled test sites only. Creates a synthetic administrator with a
 * CLI-created session (no browser password), two event types with booking fields and a
 * schedule with past and future date-specific times, for list-filters.spec.cjs.
 */
declare(strict_types=1);

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli' || getenv('LIST_FILTERS_TEST') !== '1') {
    throw new RuntimeException('Explicit disposable-site CLI opt-in required.');
}
umask(0077);
$root = realpath((string) getenv('LIST_FILTERS_SITE'));
$baseUrl = rtrim((string) getenv('LIST_FILTERS_BASE_URL'), '/');
$host = parse_url($baseUrl, PHP_URL_HOST);
if (!$root || !in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
    throw new RuntimeException('A disposable site path and loopback URL are required.');
}
require $root . DIRECTORY_SEPARATOR . 'configuration.php';
$config = new JConfig();
if ($config->mailonline || !in_array(explode(':', $config->host)[0], ['mysql', 'localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Local database and disabled mail are required.');
}
define('_JEXEC', 1);
define('JPATH_BASE', $root . DIRECTORY_SEPARATOR . 'administrator');
$_SERVER['HTTP_HOST'] = (string) parse_url($baseUrl, PHP_URL_HOST) . ':' . (parse_url($baseUrl, PHP_URL_PORT) ?: 80);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/administrator/index.php';
$_SERVER['SCRIPT_NAME'] = '/administrator/index.php';
require JPATH_BASE . '/includes/defines.php';
require JPATH_BASE . '/includes/framework.php';
$container = Factory::getContainer();
$container->alias('session.web', 'session.web.administrator')
    ->alias('session', 'session.web.administrator')
    ->alias(Session::class, 'session.web.administrator')
    ->alias(Joomla\Session\Session::class, 'session.web.administrator')
    ->alias(Joomla\Session\SessionInterface::class, 'session.web.administrator');
$app = $container->get(AdministratorApplication::class);
Factory::$application = $app;
$app->loadLanguage($container->get(LanguageFactoryInterface::class)->createLanguage('en-GB'));
$app->createExtensionNamespaceMap();
$db = $container->get(DatabaseInterface::class);
$now = gmdate('Y-m-d H:i:s');
$tag = 'browser-filter-' . bin2hex(random_bytes(5));

$userData = (object) [
    'name' => 'Synthetic List Administrator',
    'username' => 'lists-' . bin2hex(random_bytes(8)),
    'email' => 'lists-' . bin2hex(random_bytes(8)) . '@example.invalid',
    'password' => password_hash(bin2hex(random_bytes(24)) . '!aA1', PASSWORD_BCRYPT),
    'block' => 0,
    'sendEmail' => 0,
    'registerDate' => $now,
    'params' => '{}',
];
$db->insertObject('#__users', $userData, 'id');
$membership = (object) ['user_id' => $userData->id, 'group_id' => 8];
$db->insertObject('#__user_usergroup_map', $membership);
$user = $container->get(UserFactoryInterface::class)->loadUserById((int) $userData->id);
$app->loadIdentity($user);

$schedule = (object) [
    'title' => $tag . ' schedule', 'timezone' => 'UTC', 'owner_user_id' => $userData->id,
    'published' => 1, 'created_utc' => $now, 'modified_utc' => $now,
];
$db->insertObject('#__appointments_schedules', $schedule, 'id');
$weekly = (object) ['schedule_id' => $schedule->id, 'weekday' => 1, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'is_unavailable' => 0];
$db->insertObject('#__appointments_schedule_rules', $weekly);
$dates = [];
foreach (['-30 days', '-10 days', '+10 days', '+30 days'] as $offset) {
    $date = gmdate('Y-m-d', strtotime($offset));
    $dates[] = $date;
    $rule = (object) ['schedule_id' => $schedule->id, 'specific_date' => $date, 'start_time' => '00:00:00', 'end_time' => '00:00:00', 'is_unavailable' => 1];
    $db->insertObject('#__appointments_schedule_rules', $rule);
}

$events = [];
$fields = [];
foreach (['a', 'b'] as $letter) {
    $event = (object) [
        'title' => $tag . ' event ' . $letter, 'alias' => $tag . '-event-' . $letter, 'schedule_id' => $schedule->id,
        'host_user_id' => $userData->id, 'feed_token' => bin2hex(random_bytes(32)), 'created_utc' => $now, 'modified_utc' => $now,
    ];
    $db->insertObject('#__appointments_eventtypes', $event, 'id');
    $events[$letter] = (int) $event->id;
    foreach ([1, 2, 3] as $position) {
        $field = (object) [
            'eventtype_id' => $event->id, 'label' => $tag . ' ' . $letter . $position, 'type' => 'text',
            'options' => '[]', 'required' => 0, 'published' => 1, 'ordering' => $position,
        ];
        $db->insertObject('#__appointments_fields', $field, 'id');
        $fields[$letter][] = (int) $field->id;
    }
}

$session = $app->getSession();
if ($session->isActive()) {
    $session->close();
}
$session->setId(bin2hex(random_bytes(24)));
$session->start();
$session->set('user', $user);
$app->setUserState('application.lang', 'en-GB');
$cookies = [[
    'name' => $session->getName(), 'value' => $session->getId(), 'domain' => $host, 'path' => '/',
    'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax',
]];
$session->close();

echo json_encode([
    'baseURL' => $baseUrl,
    'tag' => $tag,
    'events' => $events,
    'fields' => $fields,
    'scheduleId' => (int) $schedule->id,
    'dates' => $dates,
    'today' => gmdate('Y-m-d'),
    'cookies' => $cookies,
], JSON_THROW_ON_ERROR);
