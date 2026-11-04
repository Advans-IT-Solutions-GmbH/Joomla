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
 *   php fixture.php revoke           OWNER revokes WRITER's grant on CAL-R
 *   php fixture.php cleanup-start    VDIR removes its device: the clean-up starts
 *   php fixture.php cleanup-advance  the Scheduler advances every delivered clean-up phase
 *   php fixture.php teardown         remove everything this fixture created
 *
 * Every command prints one JSON object. `setup` prints the state the client
 * container needs (addresses, user names, app passwords, calendar URIs); it
 * contains test credentials of a throw-away container only. The fixture's own
 * state, including the test passwords, stays in /tmp inside the container.
 */

require __DIR__ . '/../scripts/appointments-joomla.php';
require_once __DIR__ . '/../scripts/appointments-fixtures.php';

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Component\ComponentHelper;

const STATE_FILE = '/tmp/appointments-caldav-fixture.json';

/**
 * @var  array<string, CalendarRole|null>  Users of the job and their role on CAL-T; null: no grant there.
 */
const USERS = [
    'owner'  => CalendarRole::Manage,
    'writer' => CalendarRole::Write,
    'reader' => CalendarRole::Read,
    'busy'   => CalendarRole::FreeBusy,
    'vdir'   => CalendarRole::Write,
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
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
    chmod(STATE_FILE, 0600);
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
    db()->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,lastvisitDate,activation,params,requireReset)"
        . " VALUES ('CI {$name}','{$username}','{$username}@example.invalid','{$hash}',0,0,'{$now}',NULL,'','{}',0)");
    $id = (int) db()->insert_id;
    db()->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$id},{$group})");
    db()->query("INSERT INTO {$prefix}user_mfa (user_id,title,method,`default`,options,created_on,last_used)"
        . " VALUES ({$id},'CI authenticator','totp',1,'{}','{$now}',NULL)");

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
    $team = services();

    if (!$team->enabled()) {
        fail('The internet calendars do not switch on (option or libsodium).');
    }

    $admin = (int) db()->query("SELECT id FROM " . prefix() . "users WHERE username='"
        . db()->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin') . "'")->fetch_assoc()['id'];
    $state = ['admin' => $admin, 'users' => [], 'calendars' => [], 'devices' => []];

    if ($team->keys()->recoveryFingerprint() === null) {
        // The secret is shown once on a real site; the job never needs it.
        $team->keys()->createRecoveryKey($admin);
        $state['recovery_created'] = true;
    }

    $keys = [];

    foreach (array_keys(USERS) as $name) {
        $state['users'][$name] = createUser($name, $group);
        $keys[$name] = $team->keys()->createUserKey($state['users'][$name]['id'], $state['users'][$name]['password']);
    }

    // A team member with a second factor and no grant at all.
    $state['users']['nogrant'] = createUser('nogrant', $group);
    saveState($state);

    $owner = $state['users']['owner']['id'];

    foreach (['T' => 'CI team calendar', 'R' => 'CI revocation calendar', 'F' => 'CI owner only'] as $key => $title) {
        $id = $team->calendars()->create($admin, ['title' => $title, 'owner_user_id' => $owner, 'colour' => '#2a6f97']);
        $state['calendars'][$key] = ['id' => $id, 'uri' => (string) $team->calendars()->get($id)->uri, 'title' => $title];
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
    }

    saveState($state);

    $public = ['base' => 'https://joomla', 'dav' => '/dav/', 'users' => [], 'calendars' => $state['calendars']];

    foreach ($state['users'] as $name => $user) {
        $public['users'][$name] = [
            'id'           => $user['id'],
            'username'     => $user['username'],
            'password'     => $user['password'],
            'app_password' => $user['app_password'] ?? null,
        ];
    }

    out($public);
}

function book(): void
{
    $state = state();
    $fixture = new AppointmentsFixture('caldav');
    $event = $fixture->event();
    $calendar = (int) $state['calendars']['T']['id'];
    db()->query("UPDATE " . prefix() . "appointments_eventtypes SET calendar_id={$calendar} WHERE id={$event}");
    $start = gmdate('Y-m-d', strtotime('+3 days')) . ' 10:00:00';
    $booking = $fixture->booking($event, $start, ['name' => 'CI Guest', 'email' => 'ci-guest@example.invalid'])['booking'];
    $state['event'] = $event;
    $state['booking'] = (int) $booking['id'];
    saveState($state);

    out(['booking_id' => (int) $booking['id'], 'uid' => (string) $booking['uid'], 'start_utc' => $start]);
}

function cancel(): void
{
    $state = state();
    $fixture = new AppointmentsFixture('caldav');
    $booking = $fixture->row((int) $state['booking']) ?? fail('The booking is gone.');
    $client = new AppointmentsHttp('http://localhost', 'caldav-guest');
    $fixture->apply($client, $fixture->link($booking, 'cancel'));
    $row = $fixture->row((int) $state['booking']);

    out(['booking_id' => (int) $state['booking'], 'state' => (int) ($row['state'] ?? -1)]);
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
    services()->cleanup()->startForDevice($state['devices']['vdir'], 'user_removed', $state['users']['vdir']['id']);

    out(['device' => 'vdir', 'state' => deviceState($state['devices']['vdir'])]);
}

function cleanupAdvance(): void
{
    $state = state();
    $advanced = services()->cleanup()->advance(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

    out(['advanced' => $advanced, 'state' => deviceState($state['devices']['vdir'])]);
}

function deviceState(int $device): int
{
    $row = db()->query("SELECT state FROM " . prefix() . "appointments_devices WHERE id={$device}")->fetch_assoc();

    return $row ? (int) $row['state'] : -1;
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

    foreach ($state['calendars'] as $calendar) {
        try {
            $team->calendars()->delete($state['admin'], (int) $calendar['id']);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Calendar delete: ' . $e->getMessage() . "\n");
        }
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

    foreach ($state['users'] as $user) {
        $id = (int) $user['id'];

        try {
            $team->cleanup()->onUserDeleted($id);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'User clean-up: ' . $e->getMessage() . "\n");
        }

        db()->query("DELETE FROM {$prefix}user_mfa WHERE user_id={$id}");
        db()->query("DELETE FROM {$prefix}user_usergroup_map WHERE user_id={$id}");
        db()->query("DELETE FROM {$prefix}users WHERE id={$id}");
    }

    if (!empty($state['recovery_created'])) {
        db()->query("DELETE FROM {$prefix}appointments_keyring WHERE purpose='recovery'");
    }

    $params = componentParams();
    $params['teamcal_enabled'] = 0;
    setComponentParams($params);
    setPlugins(0);
    unlink(STATE_FILE);

    out(['teardown' => 'done']);
}

$commands = [
    'setup'           => 'setup',
    'book'            => 'book',
    'cancel'          => 'cancel',
    'revoke'          => 'revoke',
    'cleanup-start'   => 'cleanupStart',
    'cleanup-advance' => 'cleanupAdvance',
    'teardown'        => 'teardown',
];
$command = $argv[1] ?? '';

if (!isset($commands[$command])) {
    fail('Usage: php fixture.php ' . implode('|', array_keys($commands)));
}

$commands[$command]();
