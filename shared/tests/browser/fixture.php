<?php
/**
 * Disposable, mail-disabled test sites only. Creates a synthetic administrator
 * and authenticates a CLI-created session without submitting a browser password.
 */
declare(strict_types=1);

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;

if (PHP_SAPI !== 'cli' || getenv('BACKEND_POINTER_TEST') !== '1') {
    throw new RuntimeException('Explicit disposable-site CLI opt-in required.');
}
umask(0077);
$root = realpath((string) getenv('BACKEND_POINTER_SITE'));
$baseUrl = rtrim((string) getenv('BACKEND_POINTER_BASE_URL'), '/');
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

$password = bin2hex(random_bytes(24)) . '!aA1';
$userData = (object) [
    'name' => 'Synthetic Pointer Administrator',
    'username' => 'pointer-' . bin2hex(random_bytes(8)),
    'email' => 'pointer-' . bin2hex(random_bytes(8)) . '@example.invalid',
    'password' => password_hash($password, PASSWORD_BCRYPT),
    'block' => 0,
    'sendEmail' => 0,
    'registerDate' => gmdate('Y-m-d H:i:s'),
    'params' => '{}',
];
$db->insertObject('#__users', $userData, 'id');
$membership = (object) ['user_id' => $userData->id, 'group_id' => 8];
$db->insertObject('#__user_usergroup_map', $membership);
$user = $container->get(UserFactoryInterface::class)->loadUserById((int) $userData->id);
$app->loadIdentity($user);

$schedule = (object) [
    'title' => 'Synthetic pointer schedule',
    'timezone' => 'UTC',
    'owner_user_id' => $userData->id,
    'published' => 1,
    'created_utc' => gmdate('Y-m-d H:i:s'),
    'modified_utc' => gmdate('Y-m-d H:i:s'),
];
$db->insertObject('#__appointments_schedules', $schedule, 'id');
$model = $app->bootComponent('com_appointments')->getMVCFactory()
    ->createModel('Eventtype', 'Administrator', ['ignore_request' => true]);
if (!$model->save([
    'title' => 'Synthetic pointer event',
    'alias' => 'synthetic-pointer-' . $userData->id,
    'schedule_id' => $schedule->id,
    'host_user_id' => $userData->id,
    'duration_minutes' => 30, 'slot_step_minutes' => 30,
    'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
    'min_notice_minutes' => 0, 'horizon_days' => 30,
    'max_per_day' => 0, 'max_per_week' => 0,
    'location_type' => 'custom_text', 'location_text' => 'Synthetic local room',
    'requires_approval' => 0, 'published' => 0, 'access' => 1, 'language' => '*',
    'description' => '', 'colour' => '', 'ordering' => 0,
    'info_layout' => 'logo', 'info_title' => 'card',
    'conference_params' => ['conference_provider' => 'none'],
    'rules' => [],
])) {
    throw new RuntimeException('Cannot create the synthetic event: ' . $model->getError());
}
$eventtypeId = (int) $model->getState('eventtype.id');
$eventtypeAssetId = (int) $db->setQuery('SELECT asset_id FROM #__appointments_eventtypes WHERE id=' . $eventtypeId)->loadResult();
if ($eventtypeId <= 0 || $eventtypeAssetId <= 0) {
    throw new RuntimeException('The synthetic event must have its own ACL asset.');
}

$permissionGroupId = 0;
$db->transactionStart();
try {
    for ($i = 1; $i <= 7; $i++) {
        $right = (int) $db->setQuery('SELECT rgt FROM #__usergroups WHERE id=1')->loadResult();
        $db->setQuery("UPDATE #__usergroups SET lft=lft+2 WHERE lft>=$right")->execute();
        $db->setQuery("UPDATE #__usergroups SET rgt=rgt+2 WHERE rgt>=$right")->execute();
        $title = $db->quote('Synthetic pointer group ' . $i . ' (' . $userData->id . ')');
        $db->setQuery("INSERT INTO #__usergroups(parent_id,lft,rgt,title) VALUES(1,$right,$right+1,$title)")->execute();
        $permissionGroupId = (int) $db->insertid();
    }
    $db->transactionCommit();
} catch (Throwable $error) {
    $db->transactionRollback();
    throw $error;
}
$moduleId = (int) $db->setQuery("SELECT id FROM #__modules WHERE module='mod_dmarcreports'")->loadResult();
if ($moduleId <= 0) {
    throw new RuntimeException('Install the DMARC module test package first.');
}
$db->setQuery('UPDATE #__modules SET checked_out=NULL, checked_out_time=NULL WHERE id=' . $moduleId)->execute();
$session = $app->getSession();
if ($session->isActive()) {
    $session->close();
}
$cookiesByLocale = [];
foreach (['en-GB', 'de-DE', 'fr-FR'] as $locale) {
    if (!LanguageHelper::exists($locale, JPATH_ADMINISTRATOR)) {
        throw new RuntimeException("Install the official {$locale} language package first.");
    }
    $session->setId(bin2hex(random_bytes(24)));
    $session->start();
    $session->set('user', $user);
    $app->setUserState('application.lang', $locale);
    $cookiesByLocale[$locale] = [[
        'name' => $session->getName(),
        'value' => $session->getId(),
        'domain' => $host,
        'path' => '/',
        'httpOnly' => true,
        'secure' => false,
        'sameSite' => 'Lax',
    ]];
    $session->close();
}
echo json_encode([
    'baseURL' => $baseUrl,
    'moduleId' => $moduleId,
    'eventtypeId' => $eventtypeId,
    'eventtypeAssetId' => $eventtypeAssetId,
    'permissionGroupId' => $permissionGroupId,
    'groupCount' => (int) $db->setQuery('SELECT COUNT(*) FROM #__usergroups')->loadResult(),
    'cookiesByLocale' => $cookiesByLocale,
], JSON_THROW_ON_ERROR);
