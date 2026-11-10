<?php
// CLI-only controls/observations for the isolated installed-Core acceptance suite.
// No operation creates a privacy request or writes a confirmed/completed status.
if (PHP_SAPI !== 'cli' || !getenv('PRIVACY_TEST_ROOT')) {
    throw new RuntimeException('An explicit isolated test root is required.');
}
define('_JEXEC', 1);
define('JPATH_BASE', getenv('PRIVACY_TEST_ROOT'));
require JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
if ($app->get('sitename') !== 'Privacy Test Fixture') {
    throw new RuntimeException('Refusing to control a non-synthetic site.');
}
$app->createExtensionNamespaceMap();
$app->loadLanguage($container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB'));
$db = $container->get(\Joomla\Database\DatabaseInterface::class);
$admin = $db->quote((string) (getenv('JOOMLA_ADMIN_USERNAME') ?: 'fixture-admin'));
$op = $argv[1] ?? '';
$email = $argv[2] ?? '';
if (in_array($op, ['snapshot', 'expire'], true) && !str_ends_with($email, '@example.test')) {
    throw new RuntimeException('Only synthetic addresses are supported.');
}
$out = [];
switch ($op) {
    case 'listener-order':
        require __DIR__ . '/listener-order.php';
        break;
    case 'operator-admin-language-get':
    case 'operator-admin-language-set':
    case 'operator-notification':
        $id = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
        $user = new \Joomla\CMS\Table\User($db);
        if (!$user->load($id) || !(new \Joomla\CMS\User\User($id))->authorise('core.admin')) {
            throw new RuntimeException('Only the genuine synthetic administrator may be controlled.');
        }
        $params = new \Joomla\Registry\Registry($user->params);
        if ($op === 'operator-admin-language-set') {
            if (!in_array($email, ['', 'de-DE', 'en-GB', 'fr-FR'], true)) {
                throw new RuntimeException('Only supported fixture administrator languages are allowed.');
            }
            $params->set('admin_language', $email);
            $user->params = $params->toString();
            if (!$user->check() || !$user->store()) {
                throw new RuntimeException('Cannot set the synthetic administrator language.');
            }
        }
        $out = ['id' => $id, 'admin_language' => (string) $params->get('admin_language', '')];
        if ($op === 'operator-notification') {
            if (!ctype_digit($email)) {
                throw new RuntimeException('A real synthetic pending privacy request id is required.');
            }
            $subject = $db->setQuery('SELECT email FROM #__privacy_requests WHERE status=0 AND id=' . (int) $email)->loadResult();
            if (!$subject || !str_ends_with($subject, '@example.test')) {
                throw new RuntimeException('A real synthetic pending receipt is required.');
            }
            $out['message'] = $db->setQuery('SELECT user_id_from,user_id_to,subject,message FROM #__messages'
                . ' WHERE user_id_to=' . $id . ' ORDER BY message_id DESC')->loadAssoc();
            $class = 'Joomla\\Component\\Messages\\Administrator\\Model\\MessageModel';
            $app->bootComponent('com_messages');
            $file = (new ReflectionClass($class))->getFileName();
            $out['native'] = ['class' => $class, 'file' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        break;
    case 'operator-contact-validation':
        $manifest = simplexml_load_file(JPATH_PLUGINS . '/system/privacyrequests/privacyrequests.xml');
        if (!$manifest || !isset($manifest->config->fields)) {
            throw new RuntimeException('The actual installed contact configuration is required.');
        }
        $form = new \Joomla\CMS\Form\Form('privacy.fixture.contact');
        $form->load('<form>' . $manifest->config->fields->asXML() . '</form>');
        $out['valid'] = $form->validate(['params' => ['contact_route' => $email]]);
        break;
    case 'operator-contact-create':
        if (!preg_match('/^operator-\d+@example\.test$/D', $email)) {
            throw new RuntimeException('Only a uniquely marked synthetic operator contact is allowed.');
        }
        $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
        $user = new \Joomla\CMS\User\User($actor);
        if (!$user->authorise('core.admin', 'com_contact')) {
            throw new RuntimeException('A genuine authorized synthetic contact administrator is required.');
        }
        $category = (int) $db->setQuery('SELECT id FROM #__categories WHERE extension='
            . $db->quote('com_contact') . ' AND published=1 ORDER BY id')->loadResult();
        if (!$category) {
            throw new RuntimeException('The native installed contact category is required.');
        }
        $model = $app->bootComponent('com_contact')->getMVCFactory()
            ->createModel('Contact', 'Administrator', ['ignore_request' => true]);
        $model->setCurrentUser($user);
        if (!$model->save(['name' => 'Privacy fixture operator', 'alias' => 'privacy-fixture-' . strstr($email, '@', true),
            'catid' => $category, 'published' => 1, 'access' => 1, 'language' => '*', 'email_to' => $email,
            'misc' => 'Synthetic privacy contact only. A reply channel is not ownership evidence.',
            'metadata' => [], 'params' => ['show_email_form' => 1, 'show_email' => 1, 'show_misc' => 1]])) {
            throw new RuntimeException('Native ContactModel could not save the synthetic public contact.');
        }
        $id = (int) $model->getState('contact.id');
        if (!$id) {
            throw new RuntimeException('Native ContactModel did not return a real contact id.');
        }
        $route = 'index.php?option=com_contact&view=contact&id=' . $id;
        $pluginId = (int) $db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin'"
            . " AND folder='system' AND element='privacyrequests'")->loadResult();
        $table = new \Joomla\CMS\Table\Extension($db);
        if (!$table->load($pluginId)) {
            throw new RuntimeException('The normally installed generic adapter is required.');
        }
        $params = new \Joomla\Registry\Registry($table->params);
        $params->set('contact_route', $route);
        $table->params = $params->toString();
        if (!$table->check() || !$table->store()) {
            throw new RuntimeException('Cannot set the isolated adapter contact route.');
        }
        $out = ['id' => $id, 'route' => $route, 'recipient' => $email, 'classes' => []];
        foreach (['Joomla\\Component\\Contact\\Administrator\\Model\\ContactModel',
            'Joomla\\Component\\Contact\\Site\\Controller\\ContactController'] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $out['classes'][] = ['class' => $class, 'file' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        break;
    case 'native-renew':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic Core request id is required.');
        }
        $request = $db->setQuery('SELECT email,status FROM #__privacy_requests WHERE id=' . (int) $email)->loadObject();
        if (!$request || (int) $request->status !== 0 || !str_ends_with($request->email, '@example.test')) {
            throw new RuntimeException('Only a pending synthetic Core request may be renewed.');
        }
        $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
        $user = new \Joomla\CMS\User\User($actor);
        if (!$user->authorise('core.admin', 'com_privacy')) {
            throw new RuntimeException('A genuine authorized synthetic administrator is required.');
        }
        \Joomla\CMS\Form\Form::addFormPath(JPATH_ADMINISTRATOR . '/components/com_privacy/forms');
        if (!$app->getLanguage()->load('com_privacy', JPATH_ADMINISTRATOR)) {
            throw new RuntimeException('The actual administrator mail translations are required.');
        }
        \Joomla\CMS\Plugin\PluginHelper::importPlugin('system', null, true, $app->getDispatcher());
        $model = $app->bootComponent('com_privacy')->getMVCFactory()
            ->createModel('Request', 'Administrator', ['ignore_request' => true]);
        $model->setCurrentUser($user);
        if (!$model->notifyUserAdminCreatedRequest((int) $email)) {
            throw new RuntimeException('Native pending-request renewal failed.');
        }
        $out['renewed'] = true;
        break;
    case 'acy-create':
    case 'acy-state':
    case 'acy-restore':
    case 'acy-cleanup':
        $names = array_map(static fn ($name) => $db->replacePrefix('#__acym_' . $name),
            ['configuration', 'user', 'privacy_fixture_unavailable']);
        [$config, $active, $unavailable] = $names;
        $existing = $db->getTableList();
        if ($op === 'acy-create') {
            if (!str_ends_with($email, '@example.test') || array_intersect($names, $existing)) {
                throw new RuntimeException('Only a new explicitly synthetic newsletter fixture is allowed.');
            }
            $db->setQuery('CREATE TABLE ' . $db->quoteName($config)
                . ' (name VARCHAR(64) PRIMARY KEY, value VARCHAR(64) NOT NULL) ENGINE=InnoDB')->execute();
            $db->setQuery('INSERT INTO ' . $db->quoteName($config)
                . " VALUES ('privacy_fixture','synthetic-only')")->execute();
            $db->setQuery('CREATE TABLE ' . $db->quoteName($active)
                . ' (id INT PRIMARY KEY, email VARCHAR(320) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')->execute();
            $row = (object) ['id' => 1, 'email' => $email];
            $db->insertObject($active, $row);
            $db->setQuery('RENAME TABLE ' . $db->quoteName($active) . ' TO ' . $db->quoteName($unavailable))->execute();
        } elseif (in_array($config, $existing, true)) {
            if ($db->setQuery('SELECT value FROM ' . $db->quoteName($config)
                . " WHERE name='privacy_fixture'")->loadResult() !== 'synthetic-only') {
                throw new RuntimeException('Refusing to alter a non-synthetic newsletter installation.');
            }
            if ($op === 'acy-restore') {
                $db->setQuery('RENAME TABLE ' . $db->quoteName($unavailable) . ' TO ' . $db->quoteName($active))->execute();
            } elseif ($op === 'acy-cleanup') {
                foreach ([$active, $unavailable, $config] as $table) {
                    if (in_array($table, $existing, true)) {
                        $db->setQuery('DROP TABLE ' . $db->quoteName($table))->execute();
                    }
                }
            }
        } elseif ($op !== 'acy-state' && $op !== 'acy-cleanup') {
            throw new RuntimeException('The actual synthetic newsletter fixture is required.');
        }
        $existing = $db->getTableList();
        foreach (['active' => $active, 'unavailable' => $unavailable] as $label => $table) {
            $out[$label . '_rows'] = in_array($table, $existing, true)
                ? (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table))->loadResult() : null;
        }
        $out['fixture_present'] = in_array($config, $existing, true);
        break;
    case 'legacy-state':
    case 'legacy-enable':
    case 'legacy-bindings':
        if ($op === 'legacy-enable') {
            $model = $app->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
            $model->setCurrentUser(new \Joomla\CMS\User\User($actor));
            foreach ([['privacy', 'j2commerce', 1], ['system', 'j2commerceprivacy', 1], ['task', 'j2commerceprivacy', 0]] as [$group, $element, $enabled]) {
                $id = (int) $db->setQuery('SELECT extension_id FROM #__extensions WHERE type=' . $db->quote('plugin')
                    . ' AND folder=' . $db->quote($group) . ' AND element=' . $db->quote($element))->loadResult();
                if (!$id) {
                    throw new RuntimeException('The actual normally installed legacy bundle is required.');
                }
                if ($group === 'privacy') {
                    $table = new \Joomla\CMS\Table\Extension($db);
                    $table->load($id);
                    $params = json_decode($table->params, true, 512, JSON_THROW_ON_ERROR);
                    unset($params['guest_confirmation']);
                    $table->params = json_encode($params, JSON_THROW_ON_ERROR);
                    if (!$table->store()) {
                        throw new RuntimeException('Cannot prepare actual absent legacy default.');
                    }
                }
                $ids = [$id];
                if (!$model->publish($ids, $enabled)) {
                    throw new RuntimeException('Cannot publish the isolated legacy bundle state.');
                }
            }
        }
        $out['extensions'] = $db->setQuery("SELECT extension_id,folder,element,enabled,params FROM #__extensions"
            . " WHERE type='plugin' AND ((folder='privacy' AND element='j2commerce')"
            . " OR element='j2commerceprivacy' OR (folder='system' AND element='scheduler'))")->loadAssocList();
        $out['active_privacy_tasks'] = (int) $db->setQuery("SELECT COUNT(*) FROM #__scheduler_tasks"
            . " WHERE state=1 AND type IN ('plg_task_j2commerceprivacy.autocleanup','plg_privacy_j2commerce.autocleanup')")->loadResult();
        if ($op === 'legacy-bindings') {
            foreach ([['j2commerce', 'privacy'], ['j2commerceprivacy', 'system']] as [$element, $group]) {
                $plugin = $app->bootPlugin($element, $group);
                $file = (new ReflectionClass($plugin))->getFileName();
                $out['classes'][] = ['class' => get_class($plugin), 'file' => $file, 'sha256' => hash_file('sha256', $file)];
            }
        }
        break;
    case 'shared-module-url':
        $support = JPATH_PLUGINS . '/system/privacyrequests/advansinstaller';
        require_once $support . '/load.php';
        $regression = is_file(__DIR__ . '/installer-module-url.php') ? __DIR__ . '/installer-module-url.php'
            : dirname(__DIR__, 2) . '/shared/tests/scripts/shared-installer-module-url.php';
        $check = require $regression;
        $out = $check($db, $support . '/SystemCheck.php');
        $out['regression_file'] = $regression;
        $out['regression_sha256'] = hash_file('sha256', $regression);
        $expectedHelper = getenv('PRIVACY_EXPECTED_HELPER_SHA256');
        if ($expectedHelper && !hash_equals($expectedHelper, $out['helper_sha256'])) {
            throw new RuntimeException('The installed helper differs from the frozen source under test.');
        }
        break;
    case 'unicode-mail-core-check':
        if (!str_ends_with($email, '@example.test')) {
            throw new RuntimeException('An explicit synthetic address is required.');
        }
        $mailer = $container->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer();
        $out['mailer_class'] = get_class($mailer);
        $out['mailer_file'] = (new ReflectionClass($mailer))->getFileName();
        $out['mailer_sha256'] = hash_file('sha256', $out['mailer_file']);
        try {
            $out['accepted'] = $mailer->addRecipient($email) !== false;
        } catch (\Throwable $error) {
            $out['accepted'] = false;
            $out['exception_class'] = get_class($error);
            $out['exception_file'] = $error->getFile();
            $out['exception_line'] = $error->getLine();
            $out['exception_source_sha256'] = hash_file('sha256', $error->getFile());
        }
        break;
    case 'account-create':
        if (!str_ends_with($email, '@example.test') || !preg_match('/^[a-z0-9-]+@example\\.test$/D', $email)) {
            throw new RuntimeException('An explicit synthetic registered account is required.');
        }
        $user = new \Joomla\CMS\User\User();
        $account = ['username' => substr($email, 0, strpos($email, '@')), 'name' => 'Synthetic Registered Person',
            'email' => $email, 'password' => getenv('JOOMLA_ADMIN_PASSWORD'),
            'password2' => getenv('JOOMLA_ADMIN_PASSWORD'), 'groups' => [2], 'block' => 0, 'sendEmail' => 0];
        if (!$user->bind($account) || !$user->save()) {
            throw new RuntimeException('Cannot create a genuine synthetic registered account.');
        }
        $out['user_id'] = (int) $user->id;
        $out['username'] = $user->username;
        break;
    case 'account-snapshot':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic account id is required.');
        }
        $row = $db->setQuery('SELECT * FROM #__users WHERE id=' . (int) $email)->loadAssoc();
        if (!$row || (!str_ends_with($row['email'], '@example.test')
            && $row['email'] !== 'UserID' . (int) $email . 'removed@email.invalid')) {
            throw new RuntimeException('Only a synthetic account can be inspected.');
        }
        $out = ['user_id' => (int) $row['id'], 'blocked' => (int) $row['block'],
            'email_sha256' => hash('sha256', $row['email']), 'name_sha256' => hash('sha256', $row['name']),
            'password_sha256' => hash('sha256', $row['password'])];
        break;
    case 'operation-hold':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic Core request id is required.');
        }
        $app->bootPlugin('privacyrequests', 'system');
        $lock = new \Advans\Plugin\System\PrivacyRequests\Service\OperationLock($db, (int) $email, (string) $app->get('secret'));
        echo "LOCK_HELD\n";
        fflush(STDOUT);
        try {
            fgets(STDIN);
        } finally {
            $lock->release();
        }
        exit(0);
    case 'appointments-host-state':
        $app->bootComponent('com_appointments');
        $host = (int) $db->setQuery("SELECT id FROM #__users WHERE username='privacy-fixture-host'")->loadResult();
        $out['user_id'] = $host;
        $out['second_factor'] = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication()
            ->grants()->hasSecondFactor($host);
        $out['totp_enabled'] = (int) $db->setQuery("SELECT enabled FROM #__extensions WHERE type='plugin'"
            . " AND folder='multifactorauth' AND element='totp'")->loadResult();
        break;
    case 'generic-id':
    case 'generic-enable':
    case 'generic-disable':
        if ($op !== 'generic-id') {
            $model = $app->bootComponent('com_plugins')->getMVCFactory()
                ->createModel('Plugin', 'Administrator', ['ignore_request' => true]);
            $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
            $model->setCurrentUser(new \Joomla\CMS\User\User($actor));
            $ids = array_map('intval', $db->setQuery("SELECT extension_id FROM #__extensions WHERE type='plugin'"
                . " AND folder='system' AND element='privacyrequests'")->loadColumn());
            if (!$model->publish($ids, $op === 'generic-enable' ? 1 : 0)) {
                throw new RuntimeException('Native plugin state/cache update failed.');
            }
        }
        $out = $db->setQuery("SELECT extension_id,enabled FROM #__extensions"
            . " WHERE type='plugin' AND folder='system' AND element='privacyrequests'")->loadAssoc();
        break;
    case 'bindings':
        $factory = $app->bootComponent('com_privacy')->getMVCFactory();
        $classes = [];
        foreach ([['Request', 'Administrator'], ['Request', 'Site'], ['Confirm', 'Site'], ['Remove', 'Administrator']] as [$name, $client]) {
            $classes[] = get_class($factory->createModel($name, $client, ['ignore_request' => true]));
        }
        $classes[] = \Joomla\Component\Privacy\Administrator\Controller\RequestController::class;
        $classes[] = \Joomla\Component\Privacy\Site\Controller\RequestController::class;
        $classes[] = \Joomla\CMS\Mail\MailTemplate::class;
        $classes[] = get_class($app->bootComponent('com_actionlogs')->getMVCFactory()->createModel('Actionlog', 'Administrator'));
        $out['joomla'] = (new \Joomla\CMS\Version())->getShortVersion();
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $out['classes'][] = ['class' => $class, 'file' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        $out['product'] = [];
        $app->bootPlugin('privacyrequests', 'system');
        foreach (['Extension\\PrivacyRequests', 'Service\\Admission', 'Service\\GuestRequests',
            'Service\\OperationLock', 'Service\\RequestFactory', 'Service\\RequestTable'] as $name) {
            $class = 'Advans\\Plugin\\System\\PrivacyRequests\\' . $name;
            $file = (new ReflectionClass($class))->getFileName();
            $out['product'][] = ['class' => $class, 'file' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        break;
    case 'appointments-prepare':
        foreach (['reminders', 'retention'] as $routine) {
            $type = 'plg_task_appointments.' . $routine;
            if ((int) $db->setQuery('SELECT COUNT(*) FROM #__scheduler_tasks WHERE type=' . $db->quote($type))->loadResult()) {
                continue;
            }
            $model = $app->bootComponent('com_scheduler')->getMVCFactory()
                ->createModel('Task', 'Administrator', ['ignore_request' => true]);
            $model->setCurrentUser(new \Joomla\CMS\User\User());
            if (!$model->save(['title' => 'Synthetic disabled ' . $routine, 'type' => $type, 'state' => 0,
                'execution_rules' => ['rule-type' => 'interval-hours', 'interval-hours' => 24,
                    'exec-day' => gmdate('d'), 'exec-time' => gmdate('H:i')],
                'params' => ['batch_size' => 1], 'created_by' => 0])) {
                throw new RuntimeException('Cannot pre-create a disabled synthetic Scheduler task.');
            }
        }
        break;
    case 'appointments-bindings':
        $app->bootComponent('com_appointments');
        $classes = [
            \Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridge::class,
            \Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridgeInterface::class,
            \Advans\Component\Appointments\Administrator\Service\PrivacyCleanup::class,
            \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::class,
            get_class($app->bootPlugin('appointmentsprivacy', 'content')),
            get_class($app->bootPlugin('appointments', 'privacy')),
            get_class($app->bootPlugin('privacyrequestsprobe', 'privacy')),
        ];
        foreach ($classes as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $out['classes'][] = ['class' => $class, 'file' => $file, 'sha256' => hash_file('sha256', $file)];
        }
        $out['tasks'] = $db->setQuery("SELECT type,state FROM #__scheduler_tasks WHERE type LIKE 'plg_task_appointments.%'")->loadObjectList();
        $out['plugins'] = $db->setQuery("SELECT folder,element,enabled FROM #__extensions WHERE type='plugin'"
            . " AND (element='appointments' OR element='appointmentsdav' OR element='appointmentsprivacy')")->loadObjectList();
        break;
    case 'appointments-booking':
        if (!str_ends_with($email, '@example.test')) {
            throw new RuntimeException('Only a synthetic invitee can be created.');
        }
        $app->bootComponent('com_appointments');
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $time = $now->format('Y-m-d H:i:s');
        $host = (int) $db->setQuery("SELECT id FROM #__users WHERE username='privacy-fixture-host'")->loadResult();
        if ($host < 1) {
            $user = new \Joomla\CMS\User\User();
            $account = ['username' => 'privacy-fixture-host', 'name' => 'Synthetic Calendar Host',
                'email' => 'calendar-host@example.test', 'password' => getenv('JOOMLA_ADMIN_PASSWORD'),
                'password2' => getenv('JOOMLA_ADMIN_PASSWORD'), 'groups' => [2], 'block' => 0, 'sendEmail' => 0];
            if (!$user->bind($account) || !$user->save()) {
                throw new RuntimeException('Cannot create a genuine non-administrator synthetic staff account.');
            }
            $host = (int) $user->id;
        }
        $title = 'synthetic-privacy-' . bin2hex(random_bytes(6));
        $schedule = (object) ['title' => $title, 'timezone' => 'UTC', 'owner_user_id' => $host, 'published' => 1,
            'created_utc' => $time, 'modified_utc' => $time];
        $db->insertObject('#__appointments_schedules', $schedule, 'id');
        for ($day = 1; $day <= 7; $day++) {
            $rule = (object) ['schedule_id' => $schedule->id, 'weekday' => $day, 'start_time' => '09:00:00', 'end_time' => '17:00:00'];
            $db->insertObject('#__appointments_schedule_rules', $rule);
        }
        $event = (object) ['title' => $title, 'alias' => $title, 'schedule_id' => $schedule->id,
            'host_user_id' => $host, 'feed_token' => bin2hex(random_bytes(32)), 'created_utc' => $time, 'modified_utc' => $time];
        $db->insertObject('#__appointments_eventtypes', $event, 'id');
        $event = $db->setQuery('SELECT * FROM #__appointments_eventtypes WHERE id=' . (int) $event->id)->loadAssoc();
        $guest = ($argv[3] ?? '') === 'guest';
        $room = 'synthetic-' . bin2hex(random_bytes(12));
        $invitee = ['invitee_name' => 'Synthetic Invitee', 'invitee_email' => $guest ? 'other@example.test' : $email,
            'invitee_timezone' => 'UTC', 'invitee_language' => 'en-GB', 'guest_emails' => $guest ? $email . "\nbystander@example.test" : null,
            'conference_provider' => 'privacy-fixture', 'conference_room' => $room, 'conference_state' => 'ok'];
        $slots = (new \Advans\Component\Appointments\Administrator\Service\AvailabilityService($db))
            ->slots($event, $now, $now->modify('+2 days')->setTime(0, 0), $now->modify('+14 days')->setTime(23, 59));
        if ($slots === []) {
            throw new RuntimeException('No genuine available slot remains for the synthetic booking.');
        }
        $booking = (new \Advans\Component\Appointments\Administrator\Service\BookingStore($db))
            ->create($event, $invitee, $slots[0], $now, [1], 'en-GB');
        $db->setQuery('CREATE TABLE IF NOT EXISTS #__privacy_test_rooms (room VARCHAR(255) NOT NULL PRIMARY KEY) ENGINE=InnoDB')->execute();
        $resource = (object) ['room' => $room];
        $db->insertObject('#__privacy_test_rooms', $resource);
        $out['booking_id'] = (int) $booking['id'];
        $out['host_user_id'] = $host;
        $out['host_second_factor'] = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication()
            ->grants()->hasSecondFactor($host);
        $out['room_hash'] = hash('sha256', $room);
        break;
    case 'appointments-copy':
    case 'appointments-calendar-delete':
    case 'appointments-empty-calendars':
    case 'appointments-reset-calendars':
        if (!in_array($op, ['appointments-empty-calendars', 'appointments-reset-calendars'], true) && !ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic resource id is required.');
        }
        $app->bootComponent('com_appointments');
        $params = \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments');
        $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
        $params->set('teamcal_enabled', 1);
        $params->set('team_groups', array_values((new \Joomla\CMS\User\User($actor))->groups));
        $team = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication();
        if (in_array($op, ['appointments-empty-calendars', 'appointments-reset-calendars'], true)) {
            $ids = $db->setQuery("SELECT c.id FROM #__appointments_calendars c WHERE c.title='Synthetic privacy copy'"
                . " AND c.owner_user_id IN (SELECT id FROM #__users WHERE username IN (" . $admin . ",'privacy-fixture-host'))"
                . ($op === 'appointments-empty-calendars'
                    ? ' AND NOT EXISTS (SELECT 1 FROM #__appointments_calendar_objects o WHERE o.calendar_id=c.id)' : ''))->loadColumn();
            foreach ($ids as $id) {
                $team->calendars()->delete($actor, (int) $id);
            }
            $out['synthetic_calendars_removed'] = count($ids);
            break;
        }
        if ($op === 'appointments-calendar-delete') {
            $team->calendars()->delete($actor, (int) $email);
            break;
        }
        $booking = $db->setQuery('SELECT * FROM #__appointments_bookings WHERE id=' . (int) $email)->loadAssoc();
        $booking = \Advans\Component\Appointments\Administrator\Helper\BookingData::fromApplication()->open($booking);
        if (!str_ends_with((string) $booking['invitee_email'], '@example.test')) {
            throw new RuntimeException('Only a synthetic booking may be mirrored.');
        }
        $owner = (int) $booking['host_user_id'];
        $params->set('team_groups', array_values(array_unique(array_merge(
            (new \Joomla\CMS\User\User($actor))->groups, (new \Joomla\CMS\User\User($owner))->groups))));
        if ($team->keys()->recoveryFingerprint() === null) {
            $team->keys()->createRecoveryKey($actor);
        }
        if (!$team->keys()->hasUserKey($owner)) {
            $team->keys()->createUserKey($owner, getenv('JOOMLA_ADMIN_PASSWORD'));
        }
        $calendar = $team->calendars()->create($actor, ['title' => 'Synthetic privacy copy', 'owner_user_id' => $owner]);
        $event = $db->setQuery('SELECT * FROM #__appointments_eventtypes WHERE id=' . (int) $booking['eventtype_id'])->loadAssoc();
        $host = new \Joomla\CMS\User\User((int) $booking['host_user_id']);
        $event['host_email'] = $host->email;
        $event['host_name'] = $host->name;
        $ics = \Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridge::objectIcs($booking, $event);
        $team->objects()->putSealed($calendar, $ics, (int) $email);
        $db->setQuery('CREATE TRIGGER privacy_fixture_copy_block BEFORE DELETE ON #__appointments_calendar_objects'
            . ' FOR EACH ROW BEGIN IF OLD.booking_id = ' . (int) $email
            . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic copy deletion is unavailable'; END IF; END")->execute();
        $out['calendar_id'] = $calendar;
        $out['objects'] = count($team->objects()->all($calendar));
        break;
    case 'appointments-guest-link':
        // The signed self-service link of the guest mail, built by the component's own helper.
        if (!str_ends_with($email, '@example.test') || !ctype_digit((string) ($argv[3] ?? ''))) {
            throw new RuntimeException('A synthetic guest address and booking id are required.');
        }
        $app->bootComponent('com_appointments');
        $modified = $db->setQuery('SELECT modified_utc FROM #__appointments_bookings WHERE id=' . (int) $argv[3])->loadResult();
        $links = new \Advans\Component\Appointments\Administrator\Helper\SignedLink(
            new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) $app->get('secret'))
        );
        $out['url'] = '/index.php?' . http_build_query(['option' => 'com_appointments', 'task' => 'guest.confirm',
            'id' => (int) $argv[3], 'signature' => $links->signGuest((int) $argv[3], $email, (string) $modified)]);
        break;
    case 'appointments-outcome-block':
    case 'appointments-outcome-unblock':
        $trigger = $db->getPrefix() . 'privacy_initial_outcome_hold';
        if ($op === 'appointments-outcome-block') {
            if (!ctype_digit($email) || (int) $email < 1) {
                throw new RuntimeException('An explicit synthetic Core request id is required.');
            }
            $subject = $db->setQuery('SELECT email FROM #__privacy_requests WHERE id=' . (int) $email)->loadResult();
            if (!is_string($subject) || !str_ends_with($subject, '@example.test')) {
                throw new RuntimeException('Only a synthetic Core request may receive the failure trigger.');
            }
            $db->setQuery('CREATE TRIGGER ' . $db->quoteName($trigger)
                . ' BEFORE INSERT ON #__appointments_privacy_outcomes FOR EACH ROW BEGIN IF NEW.request_id = ' . (int) $email
                . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected initial outcome write failure'; END IF; END")->execute();
        } else {
            $db->setQuery('DROP TRIGGER IF EXISTS ' . $db->quoteName($trigger))->execute();
        }
        break;
    case 'appointments-copy-block':
    case 'appointments-copy-unblock':
        if ($op === 'appointments-copy-block') {
            if (!ctype_digit($email)) {
                throw new RuntimeException('An explicit synthetic booking id is required.');
            }
            $db->setQuery('CREATE TRIGGER privacy_fixture_copy_block BEFORE DELETE ON #__appointments_calendar_objects'
                . ' FOR EACH ROW BEGIN IF OLD.booking_id = ' . (int) $email
                . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic copy deletion is unavailable'; END IF; END")->execute();
        } else {
            $db->setQuery('DROP TRIGGER IF EXISTS privacy_fixture_copy_block')->execute();
        }
        break;
    case 'appointments-copy-retry':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic booking id is required.');
        }
        $app->bootComponent('com_appointments');
        $out['retry'] = \Advans\Component\Appointments\Administrator\Service\PrivacyCleanup::retryCopies($db,
            \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication()->bookings(), 200);
        $out['references'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__appointments_privacy_cleanup WHERE request_id=0'
            . ' AND booking_id=' . (int) $email)->loadResult();
        break;
    case 'appointments-rebuild-setup':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic booking id is required.');
        }
        $calendar = (int) $db->setQuery('SELECT calendar_id FROM #__appointments_calendar_objects WHERE booking_id=' . (int) $email)->loadResult();
        if ($calendar < 1) {
            throw new RuntimeException('The actual synthetic booking copy is required.');
        }
        $db->setQuery('CREATE TABLE IF NOT EXISTS #__privacy_test_settings (name VARCHAR(64) PRIMARY KEY, value TEXT NOT NULL) ENGINE=InnoDB')->execute();
        $original = $db->setQuery("SELECT params FROM #__extensions WHERE type='component' AND element='com_appointments'")->loadResult();
        $backup = (object) ['name' => 'appointments-rebuild', 'value' => $original];
        $db->insertObject('#__privacy_test_settings', $backup);
        $settings = json_decode($original, true, 512, JSON_THROW_ON_ERROR);
        $actor = (int) $db->setQuery("SELECT id FROM #__users WHERE username=" . $admin)->loadResult();
        $settings['teamcal_enabled'] = 1;
        $host = (int) $db->setQuery('SELECT host_user_id FROM #__appointments_bookings WHERE id=' . (int) $email)->loadResult();
        $settings['team_groups'] = array_values(array_unique(array_merge(
            (new \Joomla\CMS\User\User($actor))->groups, (new \Joomla\CMS\User\User($host))->groups)));
        $db->setQuery('UPDATE #__extensions SET params=' . $db->quote(json_encode($settings, JSON_THROW_ON_ERROR))
            . " WHERE type='component' AND element='com_appointments'")->execute();
        $db->setQuery('UPDATE #__appointments_eventtypes SET calendar_id=' . $calendar
            . ' WHERE id=(SELECT eventtype_id FROM #__appointments_bookings WHERE id=' . (int) $email . ')')->execute();
        $db->setQuery('CREATE TRIGGER privacy_fixture_copy_rebuild_block BEFORE INSERT ON #__appointments_calendar_objects'
            . ' FOR EACH ROW BEGIN IF NEW.booking_id = ' . (int) $email
            . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic copy rebuild is unavailable'; END IF; END")->execute();
        break;
    case 'appointments-rebuild-unblock':
    case 'appointments-rebuild-restore':
        $db->setQuery('DROP TRIGGER IF EXISTS privacy_fixture_copy_rebuild_block')->execute();
        if ($op === 'appointments-rebuild-restore'
            && in_array($db->replacePrefix('#__privacy_test_settings'), $db->getTableList(), true)) {
            $original = $db->setQuery("SELECT value FROM #__privacy_test_settings WHERE name='appointments-rebuild'")->loadResult();
            if ($original !== null) {
                $db->setQuery('UPDATE #__extensions SET params=' . $db->quote($original)
                    . " WHERE type='component' AND element='com_appointments'")->execute();
                $db->setQuery("DELETE FROM #__privacy_test_settings WHERE name='appointments-rebuild'")->execute();
            }
        }
        break;
    case 'appointments-copy-inspect':
        if (!ctype_digit($email) || !str_ends_with($argv[3] ?? '', '@example.test')) {
            throw new RuntimeException('Explicit synthetic calendar and subject required.');
        }
        $app->bootComponent('com_appointments');
        $team = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication();
        $actor = (int) $db->setQuery('SELECT owner_user_id FROM #__appointments_calendars WHERE id=' . (int) $email)->loadResult();
        $user = new \Joomla\CMS\User\User($actor);
        if (!\Joomla\CMS\User\UserHelper::verifyPassword(getenv('JOOMLA_ADMIN_PASSWORD'), $user->password)) {
            throw new RuntimeException('A genuine synthetic owner password is required.');
        }
        $keys = $team->keys()->unlockWithPassword($actor, getenv('JOOMLA_ADMIN_PASSWORD'));
        $keyring = $keys === null ? null : $team->keys()->openKeyring((int) $email, $keys);
        if ($keyring === null) {
            throw new RuntimeException('No genuine owner calendar key.');
        }
        $out['opened'] = 0;
        foreach ($team->objects()->all((int) $email) as $object) {
            $plain = $team->keys()->decryptObject($keyring, $object);
            if ($plain === null) {
                throw new RuntimeException('Actual sealed copy could not be opened.');
            }
            $participants = $team->timing()->emails($plain);
            $out['opened']++;
            $out['removed_guest_absent'] = !in_array($argv[3], $participants, true);
            $out['primary_present'] = in_array('other@example.test', $participants, true);
            $out['bystander_present'] = in_array('bystander@example.test', $participants, true);
        }
        break;
    case 'appointments-provenance-toggle':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic booking id is required.');
        }
        $db->setQuery('UPDATE #__appointments_privacy_cleanup SET guest=1-guest WHERE booking_id=' . (int) $email)->execute();
        break;
    case 'appointments-payload-toggle':
        if (!ctype_digit($email)) {
            throw new RuntimeException('An explicit synthetic booking id is required.');
        }
        $rows = $db->setQuery('SELECT * FROM #__appointments_privacy_cleanup WHERE booking_id=' . (int) $email)->loadAssocList();
        foreach ($rows as $row) {
            $payload = base64_decode($row['payload'], true);
            if ($payload === false || strlen($payload) <= 24) {
                throw new RuntimeException('Expected an actual encrypted cleanup reference.');
            }
            $payload[24] = chr(ord($payload[24]) ^ 1);
            $db->setQuery('UPDATE #__appointments_privacy_cleanup SET payload=' . $db->quote(base64_encode($payload))
                . ' WHERE request_id=' . (int) $row['request_id'] . ' AND booking_id=' . (int) $email
                . ' AND cleanup_key=' . $db->quote($row['cleanup_key']))->execute();
        }
        break;
    case 'appointments-snapshot':
        if (!ctype_digit($email)) {
            throw new RuntimeException('A synthetic booking id is required.');
        }
        $out['booking'] = $db->setQuery('SELECT id,state,anonymised_utc,invitee_email IS NULL AS email_cleared,'
            . 'guest_emails IS NULL AS guests_cleared,conference_room IS NULL AS room_cleared'
            . ' FROM #__appointments_bookings WHERE id=' . (int) $email)->loadObject();
        $out['copies'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__appointments_calendar_objects WHERE booking_id=' . (int) $email)->loadResult();
        $out['cleanup'] = $db->setQuery('SELECT request_id,booking_id,guest,SHA2(payload,256) AS payload_hash'
            . ' FROM #__appointments_privacy_cleanup WHERE booking_id=' . (int) $email)->loadObjectList();
        $out['rooms'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__privacy_test_rooms')->loadResult();
        $out['room_hashes'] = $db->setQuery('SELECT SHA2(room,256) FROM #__privacy_test_rooms')->loadColumn();
        $app->bootComponent('com_appointments');
        $row = $db->setQuery('SELECT * FROM #__appointments_bookings WHERE id=' . (int) $email)->loadAssoc();
        if ($row) {
            $opened = \Advans\Component\Appointments\Administrator\Helper\BookingData::fromApplication()->open($row);
            $out['primary_hash'] = hash('sha256', (string) $opened['invitee_email']);
            $out['guests_hash'] = hash('sha256', (string) $opened['guest_emails']);
        }
        break;
    case 'snapshot':
        $out['requests'] = array_values(array_filter(
            $db->setQuery('SELECT * FROM #__privacy_requests WHERE email = ' . $db->quote($email))->loadObjectList(),
            static fn ($row) => mb_strtolower(trim($row->email), 'UTF-8') === mb_strtolower(trim($email), 'UTF-8')));
        $ids = array_map(static fn ($row) => (int) $row->id, $out['requests']);
        if ($ids) {
            $where = implode(',', $ids);
            $out['audit'] = $db->setQuery('SELECT user_id,message_language_key,message,log_date FROM #__action_logs WHERE extension = '
                . $db->quote('com_privacy.request') . ' AND item_id IN (' . $where . ')')->loadObjectList();
            $out['outcomes'] = $db->setQuery('SELECT * FROM #__privacy_guest_outcomes WHERE request_id IN (' . $where . ')')->loadObjectList();
            $out['deliveries'] = $db->setQuery('SELECT request_id,delivery_failed FROM #__privacy_guest_subjects WHERE request_id IN (' . $where . ')')->loadObjectList();
            $out['events'] = $db->setQuery('SELECT * FROM #__privacy_test_events WHERE request_id IN (' . $where . ')')->loadObjectList();
        }
        $out['accounts'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__users WHERE email = ' . $db->quote($email))->loadResult();
        break;
    case 'mail-throw':
    case 'mail-unthrow':
        $enabled = $op === 'mail-throw' ? 1 : 0;
        $db->setQuery("UPDATE #__extensions SET enabled=$enabled WHERE type='plugin' AND folder='system' AND element='privacyrequestsmailprobe'")->execute();
        if ($op === 'mail-throw' && $db->getAffectedRows() !== 1) {
            throw new RuntimeException('An installed synthetic mail callback is required.');
        }
        break;
    case 'probe':
        if (!in_array($email, ['block', 'allow', 'throw', 'warning', 'conference-fail', 'conference-allow'], true)) {
            throw new RuntimeException('Invalid probe mode.');
        }
        $db->setQuery('UPDATE #__extensions SET enabled=1, params=' . $db->quote(json_encode(['mode' => $email]))
            . " WHERE type='plugin' AND folder='privacy' AND element='privacyrequestsprobe'")->execute();
        break;
    case 'mail-hide':
    case 'mail-restore':
        $from = $op === 'mail-hide' ? 'com_privacy.notification.remove' : 'privacy.fixture.hidden';
        $to = $op === 'mail-hide' ? 'privacy.fixture.hidden' : 'com_privacy.notification.remove';
        $db->setQuery('UPDATE #__mail_templates SET template_id=' . $db->quote($to)
            . ' WHERE template_id=' . $db->quote($from))->execute();
        break;
    case 'expire':
        $db->setQuery('UPDATE #__privacy_requests SET confirm_token_created_at='
            . $db->quote(gmdate('Y-m-d H:i:s', time() - 90000))
            . ' WHERE status=0 AND email=' . $db->quote($email))->execute();
        break;
    case 'admission-state':
    case 'admission-boundary':
        $operation = $argv[3] ?? 'request';
        if (!str_ends_with($email, '@example.test') || !in_array($operation, ['request', 'confirm'], true)) {
            throw new RuntimeException('An explicit synthetic admission subject and operation are required.');
        }
        $app->bootPlugin('privacyrequests', 'system');
        $admission = new \Advans\Plugin\System\PrivacyRequests\Service\Admission($db, (string) $app->get('secret'));
        if ($op === 'admission-boundary') {
            $now = time();
            $out['initial'] = [];
            for ($i = 0; $i < 6; $i++) {
                $out['initial'][] = $admission->reserve($operation, $email, 'synthetic-clock-client', $now);
            }
            $out['before_boundary'] = $admission->reserve($operation, $email, 'synthetic-clock-client', $now + 899);
            $out['at_boundary'] = $admission->reserve($operation, $email, 'synthetic-clock-client', $now + 900);
        }
        foreach (['email' => \Advans\Plugin\System\PrivacyRequests\Service\Admission::address($email),
            'ip' => $op === 'admission-boundary' ? 'synthetic-clock-client' : '127.0.0.1'] as $dimension => $value) {
            $out[$dimension] = $db->setQuery('SELECT attempts,window_start FROM #__privacy_guest_attempts WHERE subject_hash='
                . $db->quote($admission->hash($operation . ':' . $dimension, $value)))->loadAssoc();
        }
        $out['total_rows'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__privacy_guest_attempts')->loadResult();
        $out['matching_creation_audit'] = (int) $db->setQuery('SELECT COUNT(*) FROM #__action_logs WHERE extension='
            . $db->quote('com_privacy.request') . ' AND message_language_key='
            . $db->quote('COM_PRIVACY_ACTION_LOG_CREATED_REQUEST') . ' AND message LIKE '
            . $db->quote('%' . $db->escape($email, true) . '%', false))->loadResult();
        break;
    case 'admission-reset':
        $db->setQuery('DELETE FROM #__privacy_guest_attempts')->execute();
        break;
    case 'admission-exhaust':
        if (!str_ends_with($email, '@example.test')) {
            throw new RuntimeException('Only a synthetic admission bucket may be reserved.');
        }
        $app->bootPlugin('privacyrequests', 'system');
        $admission = new \Advans\Plugin\System\PrivacyRequests\Service\Admission($db, (string) $app->get('secret'));
        for ($i = 0; $i < 5; $i++) {
            if (!$admission->reserve('request', $email, '127.0.0.1')) {
                throw new RuntimeException('Unexpected fixture admission refusal.');
            }
        }
        break;
    case 'mail-off':
    case 'mail-on':
        $config = \Joomla\CMS\Factory::getConfig();
        if ($config->get('mailer') !== 'smtp' || $config->get('smtphost') !== '127.0.0.1') {
            throw new RuntimeException('Only a synthetic loopback SMTP capture can be controlled.');
        }
        $file = JPATH_CONFIGURATION . '/configuration.php';
        $bytes = file_get_contents($file);
        $pattern = '/(^[\t ]*public\s+\$mailonline\s*=\s*)(true|false|[01])(\s*;)/m';
        if ($bytes === false || preg_match($pattern, $bytes, $setting) !== 1) {
            throw new RuntimeException('Cannot identify the isolated fixture mail setting.');
        }
        $value = $op === 'mail-on' ? ($email !== '' ? $email : '1') : '0';
        if (!in_array($value, ['true', 'false', '0', '1'], true)) {
            throw new RuntimeException('Invalid fixture mail restoration value.');
        }
        $out['original_mailonline'] = $setting[2];
        $updated = preg_replace_callback($pattern,
            static fn (array $match): string => $match[1] . $value . $match[3], $bytes, 1, $count);
        if ($updated === null || $count !== 1) {
            throw new RuntimeException('Cannot replace the isolated fixture mail setting.');
        }
        if (!chmod($file, 0644)) {
            throw new RuntimeException('Cannot write the isolated fixture configuration.');
        }
        try {
            if (!\Joomla\Filesystem\File::write($file, $updated)) {
                throw new RuntimeException('Cannot save the isolated fixture configuration.');
            }
        } finally {
            chmod($file, 0444);
        }
        break;
    default:
        throw new RuntimeException('Unknown isolated fixture operation.');
}
echo json_encode($out, JSON_THROW_ON_ERROR);
