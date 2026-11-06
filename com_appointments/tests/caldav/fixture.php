<?php
/**
 * Server-side fixture of the CalDAV client job (tests/caldav/run-clients.sh).
 * Runs inside the Joomla container, through the component's own services, so
 * that the clients meet exactly what a site would hold after the same steps in
 * the administrator and in «My calendars».
 *
 *   php fixture.php setup            switch the feature on, create users, calendars, grants, devices
 *   php fixture.php book             a guest books the event type whose target is CAL-T
 *   php fixture.php cancel           the guest cancels that booking through the signed link
 *   php fixture.php mfa-disable      WRITER removes its second factor
 *   php fixture.php mfa-enable       WRITER sets up a second factor again
 *   php fixture.php revoke           OWNER revokes WRITER's grant on CAL-R
 *   php fixture.php cleanup-start    start the independent clean-up devices
 *   php fixture.php cleanup-advance  advance the idle device through a Scheduler timeout
 *   php fixture.php teardown         remove everything this fixture created
 *
 * Every command prints one JSON object. `setup` prints the state the client
 * container needs (addresses, user names, app passwords, calendar URIs); it
 * contains test credentials of a throw-away container only. The fixture's own
 * state, including the test passwords, stays in /tmp inside the container.
 */

// Model the site's root HTTPS request before Joomla derives its CLI URI.
const DEFAULT_DAV_URL = 'https://joomla/dav/';
$davUrl = getenv('APPOINTMENTS_TEST_DAV_URL') ?: DEFAULT_DAV_URL;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
$_SERVER['HTTP_HOST'] = (string) parse_url($davUrl, PHP_URL_HOST)
    . (parse_url($davUrl, PHP_URL_PORT) ? ':' . parse_url($davUrl, PHP_URL_PORT) : '');
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REQUEST_URI'] = '/';

if (getenv('APPOINTMENTS_TEST_ROOT')) {
    define('JPATH_BASE', getenv('APPOINTMENTS_TEST_ROOT'));
}

require __DIR__ . '/../scripts/appointments-joomla.php';

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Component\ComponentHelper;

define('STATE_FILE', getenv('APPOINTMENTS_FIXTURE_STATE') ?: '/tmp/appointments-caldav-fixture.json');

/**
 * @var  array<string, CalendarRole|null>  Users of the job and their role on CAL-T; null: no grant there.
 */
const USERS = [
    'owner'  => CalendarRole::Manage,
    'writer' => CalendarRole::Write,
    'reader' => CalendarRole::Read,
    'busy'   => CalendarRole::FreeBusy,
    'vdir'   => CalendarRole::Write,
    'cleanup-sync' => CalendarRole::Write,
    'cleanup-list' => CalendarRole::Write,
    'cleanup-guard' => CalendarRole::Write,
    'cleanup-timeout' => CalendarRole::Write,
];

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function out(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function db(): mysqli
{
    static $db = null;

    if ($db === null) {
        $config = new JConfig();
        $db = new mysqli($config->host, $config->user, $config->password, $config->db);
        $db->set_charset('utf8mb4');
    }

    return $db;
}

function prefix(): string
{
    return (new JConfig())->dbprefix;
}

function state(): array
{
    $state = is_file(STATE_FILE) ? json_decode((string) file_get_contents(STATE_FILE), true) : null;

    return is_array($state) ? $state : fail('No fixture state; run setup first.');
}

function saveState(array $state): void
{
    if (file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false
        || !chmod(STATE_FILE, 0600)) {
        fail('Could not persist the fixture clean-up checkpoint.');
    }
}

/**
 * Fresh services after a change of the component options: TeamServices reads
 * its Registry once.
 */
function services(): TeamServices
{
    $params = ComponentHelper::getComponent('com_appointments', true)->getParams();

    return new TeamServices(
        $GLOBALS['joomlaDb'],
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret),
        $params,
        static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
    );
}

function unlock(TeamServices $team, array $state, string $user): KeyContext
{
    $keys = $team->keys()->unlockWithPassword($state['users'][$user]['id'], $state['users'][$user]['password']);

    return $keys ?? fail("The password does not unlock the key of {$user}.");
}

function componentParams(): array
{
    $row = db()->query("SELECT params FROM " . prefix() . "extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc();

    return json_decode((string) ($row['params'] ?? ''), true) ?: [];
}

function setComponentParams(array $params): void
{
    $json = db()->real_escape_string(json_encode($params));
    db()->query("UPDATE " . prefix() . "extensions SET params='{$json}' WHERE type='component' AND element='com_appointments'");
    // ComponentHelper caches the component row for the request.
    $reflection = new ReflectionProperty(ComponentHelper::class, 'components');
    $reflection->setValue(null, []);
}

function setPlugins(int $enabled): void
{
    db()->query("UPDATE " . prefix() . "extensions SET enabled={$enabled} WHERE type='plugin'"
        . " AND ((folder='system' AND element='appointmentsdav') OR (folder='user' AND element='appointments'))");
    resetPluginCache();
}

/**
 * The client container reaches the site as https://joomla (the compose service
 * name, which the test certificate names), so the CalDAV base address is set to
 * it; TeamServices::davBaseUrl() is then what every part and the clients use.
 * An empty value restores the default (site root plus /dav/).
 */
function setDavBaseUrl(string $url): void
{
    $prefix = prefix();
    $row = db()->query("SELECT params FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav'")->fetch_assoc();
    $params = json_decode((string) ($row['params'] ?? ''), true) ?: [];
    $params['dav_base_url'] = $url;
    $json = db()->real_escape_string(json_encode($params));
    db()->query("UPDATE {$prefix}extensions SET params='{$json}' WHERE type='plugin' AND folder='system' AND element='appointmentsdav'");
    resetPluginCache();
}

function resetPluginCache(): void
{
    // PluginHelper keeps the plugin list of the request.
    $reflection = new ReflectionProperty(\Joomla\CMS\Plugin\PluginHelper::class, 'plugins');
    $reflection->setValue(null, null);
}

/**
 * A synthetic account in the team group with a second factor on record. The
 * MFA row is what GrantServiceInterface::hasSecondFactor() reads; no TOTP code
 * is ever needed, because the job signs in with app passwords only.
 */
function createUser(string $name, int $group): array
{
    $prefix = prefix();
    $username = 'ci-caldav-' . $name;
    $password = bin2hex(random_bytes(16));
    $hash = db()->real_escape_string(password_hash($password, PASSWORD_DEFAULT));
    $now = gmdate('Y-m-d H:i:s');
    db()->begin_transaction();

    try {
        db()->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,lastvisitDate,activation,params,requireReset)"
            . " VALUES ('CI {$name}','{$username}','{$username}@example.invalid','{$hash}',0,0,'{$now}',NULL,'','{}',0)");
        $id = (int) db()->insert_id;
        db()->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$id},{$group})");
        db()->query("INSERT INTO {$prefix}user_mfa (user_id,title,method,`default`,options,created_on,last_used)"
            . " VALUES ({$id},'CI authenticator','totp',1,'{}','{$now}',NULL)");
        db()->commit();
    } catch (\Throwable $error) {
        db()->rollback();

        throw $error;
    }

    return ['id' => $id, 'username' => $username, 'password' => $password];
}

function setup(): void
{
    if (is_file(STATE_FILE)) {
        fail('The fixture is set up already; run teardown first.');
    }

    $params = componentParams();
    $group = (int) (($params['team_groups'] ?? [])[0] ?? 0) ?: fail('The installer created no team group.');
    $params['teamcal_enabled'] = 1;
    setComponentParams($params);
    setPlugins(1);
    setDavBaseUrl($GLOBALS['davUrl']);
    $team = services();

    if ($team->davBaseUrl() !== $GLOBALS['davUrl']) {
        fail('The configured HTTPS CalDAV base URL was not accepted by the site.');
    }

    if (!$team->enabled()) {
        fail('The internet calendars do not switch on (option or libsodium).');
    }

    $admin = (int) db()->query("SELECT id FROM " . prefix() . "users WHERE username='"
        . db()->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin') . "'")->fetch_assoc()['id'];
    $state = ['admin' => $admin, 'users' => [], 'calendars' => [], 'devices' => []];
    saveState($state);

    if ($team->keys()->recoveryFingerprint() === null) {
        // The secret is shown once on a real site; the job never needs it.
        $team->keys()->createRecoveryKey($admin);
        $state['recovery_created'] = true;
        saveState($state);
    }

    $keys = [];

    foreach (array_keys(USERS) as $name) {
        $state['users'][$name] = createUser($name, $group);
        saveState($state);
        $keys[$name] = $team->keys()->createUserKey($state['users'][$name]['id'], $state['users'][$name]['password']);
    }

    // A team member with a second factor and no grant at all.
    $state['users']['nogrant'] = createUser('nogrant', $group);
    saveState($state);

    // Unlike the protocol fixtures, this account starts without a key or device.
    // Joomla encrypts its real TOTP options; the client must complete password/MFA.
    $state['users']['authenticated'] = createUser('authenticated', $group);
    saveState($state);
    $authenticated = $state['users']['authenticated']['id'];
    db()->query("DELETE FROM " . prefix() . "user_mfa WHERE user_id={$authenticated}");
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $totp = '';

    for ($i = 0; $i < 32; $i++) {
        $totp .= $alphabet[random_int(0, 31)];
    }

    $mfa = \Joomla\CMS\Factory::getApplication()->bootComponent('com_users')->getMVCFactory()->createTable('Mfa', 'Administrator');

    if (!$mfa->save(['user_id' => $authenticated, 'title' => 'Synthetic TOTP', 'method' => 'totp', 'default' => 1, 'options' => ['key' => $totp]])) {
        fail('Could not provision the synthetic TOTP method.');
    }

    $state['totp_enabled'] = (int) db()->query("SELECT enabled FROM " . prefix() . "extensions WHERE type='plugin' AND folder='multifactorauth' AND element='totp'")->fetch_assoc()['enabled'];
    db()->query("UPDATE " . prefix() . "extensions SET enabled=1 WHERE type='plugin' AND folder='multifactorauth' AND element='totp'");
    $state['users']['authenticated']['totp'] = $totp;
    saveState($state);

    if ($team->keys()->userKeyStatus($authenticated)['status'] !== 'none') {
        fail('The synthetic sign-in account must start without a calendar key.');
    }

    $owner = $state['users']['owner']['id'];
    $superGroup = (int) (db()->query("SELECT id FROM " . prefix() . "usergroups WHERE title='Super Users'")->fetch_assoc()['id'] ?? 0);

    if ($superGroup < 1) {
        fail('The Joomla Super Users group is missing.');
    }

    // Calendar deletion requires both core.delete and an effective manager grant.
    db()->query("INSERT INTO " . prefix() . "user_usergroup_map (user_id,group_id) VALUES ({$owner},{$superGroup})");
    $state['cleanup_actor'] = $owner;
    saveState($state);

    foreach (['T' => 'CI team calendar', 'R' => 'CI revocation calendar', 'F' => 'CI owner only'] as $key => $title) {
        $id = $team->calendars()->create($admin, ['title' => $title, 'owner_user_id' => $owner, 'colour' => '#2a6f97']);
        $state['calendars'][$key] = ['id' => $id, 'title' => $title];
        saveState($state);
        $calendar = $team->calendars()->get($id) ?? fail('The newly created calendar is missing.');
        $state['calendars'][$key]['uri'] = (string) $calendar->uri;
        saveState($state);
    }

    saveState($state);

    foreach (USERS as $name => $role) {
        if ($name !== 'owner') {
            $team->grants()->grant($owner, $state['calendars']['T']['id'], $state['users'][$name]['id'], $role, $keys['owner']);
        }
    }

    $team->grants()->grant($owner, $state['calendars']['R']['id'], $state['users']['writer']['id'], CalendarRole::Write, $keys['owner']);

    foreach (array_keys(USERS) as $name) {
        $device = $team->devices()->create($keys[$name], 'CI ' . $name, 'other', true);
        $state['devices'][$name] = (int) $device['device_id'];
        $state['users'][$name]['app_password'] = $device['app_password'];
        saveState($state);
    }

    saveState($state);

    $public = ['dav_url' => $team->davBaseUrl(), 'users' => [], 'calendars' => $state['calendars']];

    foreach ($state['users'] as $name => $user) {
        $public['users'][$name] = [
            'id'           => $user['id'],
            'username'     => $user['username'],
            'password'     => $user['password'],
            'totp'         => $user['totp'] ?? null,
            'app_password' => $user['app_password'] ?? null,
        ];
    }

    out($public);
}

function book(): void
{
    require_once __DIR__ . '/../scripts/appointments-fixtures.php';
    $state = state();
    $fixture = new AppointmentsFixture('caldav');
    $event = $fixture->event();
    $calendar = (int) $state['calendars']['T']['id'];
    $host = (int) $state['users']['owner']['id'];
    db()->query("UPDATE " . prefix() . "appointments_eventtypes SET calendar_id={$calendar},host_user_id={$host} WHERE id={$event}");
    $state['event'] = $event;
    saveState($state);
    $start = gmdate('Y-m-d', strtotime('+3 days')) . ' 10:00:00';
    $booking = $fixture->booking($event, $start, ['first_name' => 'CI', 'last_name' => 'Guest', 'email' => 'ci-guest@example.invalid'])['booking'];
    $state['booking'] = (int) $booking['id'];
    saveState($state);

    out(['booking_id' => (int) $booking['id'], 'uid' => (string) $booking['uid'], 'start_utc' => $start]);
}

function cancel(): void
{
    require_once __DIR__ . '/../scripts/appointments-fixtures.php';
    $state = state();
    $fixture = new AppointmentsFixture('caldav');
    $booking = $fixture->row((int) $state['booking']) ?? fail('The booking is gone.');
    $client = new AppointmentsHttp('http://localhost', 'caldav-guest');
    $fixture->apply($client, $fixture->link($booking, 'cancel'));
    $row = $fixture->row((int) $state['booking']);

    if (!$row || (int) $row['state'] !== 2) {
        fail('The signed cancellation did not cancel the fixture booking.');
    }

    out(['booking_id' => (int) $state['booking'], 'state' => (int) $row['state']]);
}

function mfaDisable(): void
{
    $state = state();
    $id = (int) $state['users']['writer']['id'];
    db()->query("DELETE FROM " . prefix() . "user_mfa WHERE user_id={$id}");
    out(['mfa' => 'disabled']);
}

function mfaEnable(): void
{
    $state = state();
    $id = (int) $state['users']['writer']['id'];
    $now = gmdate('Y-m-d H:i:s');
    db()->query("INSERT INTO " . prefix() . "user_mfa (user_id,title,method,`default`,options,created_on,last_used)"
        . " VALUES ({$id},'CI authenticator','totp',1,'{}','{$now}',NULL)");
    out(['mfa' => 'enabled']);
}

function revoke(): void
{
    $state = state();
    $team = services();
    $team->grants()->revoke($state['users']['owner']['id'], $state['calendars']['R']['id'], $state['users']['writer']['id'], unlock($team, $state, 'owner'));

    out(['revoked' => 'writer', 'calendar' => $state['calendars']['R']['uri']]);
}

function cleanupStart(): void
{
    $state = state();
    $cleanup = services()->cleanup();
    $states = [];

    foreach (['vdir', 'cleanup-sync', 'cleanup-list', 'cleanup-guard', 'cleanup-timeout'] as $name) {
        $cleanup->startForDevice($state['devices'][$name], 'user_removed', $state['users'][$name]['id']);
        $states[$name] = deviceState($state['devices'][$name]);

        if ($states[$name] !== TeamStates::DEVICE_CLEANUP_EMPTY) {
            fail("Removing {$name}'s device did not start the empty clean-up phase.");
        }
    }

    out(['states' => $states]);
}

function cleanupAdvance(): void
{
    $state = state();
    $device = (int) $state['devices']['cleanup-timeout'];
    $before = deviceState($device);
    $expected = match ($before) {
        TeamStates::DEVICE_CLEANUP_EMPTY => TeamStates::DEVICE_CLEANUP_HIDDEN,
        TeamStates::DEVICE_CLEANUP_HIDDEN => TeamStates::DEVICE_REVOKED,
        default => fail('The idle device is not in a clean-up phase.'),
    };
    $started = db()->query("SELECT cleanup_started_utc FROM " . prefix() . "appointments_devices WHERE id={$device}")->fetch_assoc()['cleanup_started_utc'] ?? null;

    if ($started === null) {
        fail('The idle device has no clean-up phase start.');
    }

    $days = filter_var(componentParams()['team_cleanup_days'] ?? 14, FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 90]]);
    $days = $days === false ? 14 : $days;
    $afterTimeout = (new \DateTimeImmutable($started, new \DateTimeZone('UTC')))->modify("+{$days} days +1 second");
    $advanced = services()->cleanup()->advance($afterTimeout);

    if ($advanced < 1 || deviceState($device) !== $expected) {
        fail('The Scheduler did not advance the timed-out clean-up phase.');
    }

    out(['advanced' => $advanced, 'device' => 'cleanup-timeout', 'state' => deviceState($device)]);
}

function deviceState(int $device): int
{
    $row = db()->query("SELECT state FROM " . prefix() . "appointments_devices WHERE id={$device}")->fetch_assoc();

    return $row ? (int) $row['state'] : -1;
}

function authenticatedReady(): void
{
    $state = state();
    $team = services();
    $id = (int) $state['users']['authenticated']['id'];

    if ($team->keys()->userKeyStatus($id)['status'] !== 'usable') {
        fail('Password and real TOTP sign-in did not create the first user key.');
    }

    $count = (int) db()->query("SELECT COUNT(*) AS n FROM " . prefix() . "appointments_devices WHERE user_id={$id}")->fetch_assoc()['n'];

    if ($count !== 0) {
        fail('A device existed before the authenticated HTTP form was submitted.');
    }

    $team->grants()->grant($state['users']['owner']['id'], $state['calendars']['T']['id'], $id, CalendarRole::Write, unlock($team, $state, 'owner'));
    out(['authenticated_key' => 'usable', 'grant' => 'write']);
}

function authenticatedBlock(): void
{
    $state = state();
    $id = (int) $state['users']['authenticated']['id'];
    $user = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($id);
    $user->block = 1;

    if (!$user->save()) {
        fail('Could not block the synthetic account through Joomla.');
    }

    out(['account' => 'blocked']);
}

function authenticatedRevoke(): void
{
    $state = state();
    $id = (int) $state['users']['authenticated']['id'];
    $team = services();
    $rows = db()->query("SELECT id FROM " . prefix() . "appointments_devices WHERE user_id={$id}");
    $count = 0;

    while ($row = $rows->fetch_assoc()) {
        $team->devices()->revoke($state['admin'], (int) $row['id'], 'synthetic_test');
        $count++;
    }

    if ($count !== 2) {
        fail('The real sign-in flow did not create exactly two HTTP devices.');
    }

    out(['revoked_devices' => $count]);
}

function teardown(): void
{
    if (!is_file(STATE_FILE)) {
        out(['teardown' => 'nothing to do']);

        return;
    }

    $state = state();
    $team = services();
    $prefix = prefix();
    $errors = [];

    foreach ($state['calendars'] as $key => $calendar) {
        try {
            $team->calendars()->delete($state['cleanup_actor'] ?? $state['admin'], (int) $calendar['id']);
            unset($state['calendars'][$key]);
            saveState($state);
        } catch (\Throwable $e) {
            $errors[] = 'Calendar delete: ' . $e->getMessage();
        }
    }

    if ($errors !== []) {
        fail(implode("\n", $errors));
    }

    if (!empty($state['event'])) {
        $event = (int) $state['event'];
        db()->query("DELETE FROM {$prefix}appointments_audit WHERE booking_id IN (SELECT id FROM {$prefix}appointments_bookings WHERE eventtype_id={$event})");
        db()->query("DELETE FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}");
        $schedule = (int) (db()->query("SELECT schedule_id FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc()['schedule_id'] ?? 0);
        db()->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id={$event}");
        db()->query("DELETE FROM {$prefix}appointments_schedule_rules WHERE schedule_id={$schedule}");
        db()->query("DELETE FROM {$prefix}appointments_schedules WHERE id={$schedule}");
    }

    foreach ($state['users'] as $name => $user) {
        $id = (int) $user['id'];

        try {
            $team->cleanup()->onUserDeleted($id);
        } catch (\Throwable $e) {
            $errors[] = 'User clean-up: ' . $e->getMessage();

            continue;
        }

        db()->query("DELETE FROM {$prefix}user_mfa WHERE user_id={$id}");
        db()->query("DELETE FROM {$prefix}user_usergroup_map WHERE user_id={$id}");
        db()->query("DELETE FROM {$prefix}users WHERE id={$id}");
        unset($state['users'][$name]);
        saveState($state);
    }

    if (!empty($state['recovery_created'])) {
        db()->query("DELETE FROM {$prefix}appointments_keyring WHERE purpose='recovery'");
    }

    if ($errors !== []) {
        fail(implode("\n", $errors));
    }

    $params = componentParams();
    $params['teamcal_enabled'] = 0;
    setComponentParams($params);
    setDavBaseUrl('');
    setPlugins(0);
    if (isset($state['totp_enabled'])) {
        db()->query("UPDATE {$prefix}extensions SET enabled=" . (int) $state['totp_enabled'] . " WHERE type='plugin' AND folder='multifactorauth' AND element='totp'");
    }
    unlink(STATE_FILE);

    out(['teardown' => 'done']);
}

$commands = [
    'setup'           => 'setup',
    'book'            => 'book',
    'cancel'          => 'cancel',
    'mfa-disable'     => 'mfaDisable',
    'mfa-enable'      => 'mfaEnable',
    'revoke'          => 'revoke',
    'cleanup-start'   => 'cleanupStart',
    'cleanup-advance' => 'cleanupAdvance',
    'authenticated-ready' => 'authenticatedReady',
    'authenticated-block' => 'authenticatedBlock',
    'authenticated-revoke' => 'authenticatedRevoke',
    'teardown'        => 'teardown',
];
$command = $argv[1] ?? '';

if (!isset($commands[$command])) {
    fail('Usage: php fixture.php ' . implode('|', array_keys($commands)));
}

$commands[$command]();
