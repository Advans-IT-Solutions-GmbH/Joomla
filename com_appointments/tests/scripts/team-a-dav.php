<?php
/**
 * Work package A: CalDAV server and system plugin, over HTTP(S) against the
 * installed site. While the plugin is disabled no DAV address answers. With
 * plugin and option on: plain HTTP is refused without a body, the well-known
 * address redirects, every method needs authentication, every answer is
 * no-store/noindex without a session cookie or session row.
 *
 * The authenticated cases create temporary Joomla accounts, MFA rows, keys,
 * grants and devices, then use real HTTPS CalDAV requests. Until the required
 * services are delivered, they are reported as pending, never as passed.
 *
 * Leaves the plugin and the option as it found them.
 */

final class TeamDavFixture
{
    public const LOCKOUT_SOURCE_IP = '127.0.0.2';

    public \Advans\Component\Appointments\Administrator\Service\Team\TeamServices $services;
    public int $calendarId;
    public string $calendarUri;
    public int $adminId;
    public array $accounts = [];
    public array $cleanupErrors = [];

    private bool $createdRecoveryKey = false;
    private string $token;

    public function __construct(private readonly TeamSuite $suite)
    {
        require_once __DIR__ . '/appointments-joomla.php';
        $this->services = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication();
        $this->token = bin2hex(random_bytes(6));
    }

    public function create(): void
    {
        $db = $this->suite->db;
        $p = $this->suite->prefix;
        $adminName = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
        $admin = $db->query("SELECT id FROM {$p}users WHERE username='{$adminName}' LIMIT 1")->fetch_assoc();

        if (!$admin) {
            throw new \RuntimeException('The Joomla test administrator is missing.');
        }

        $this->adminId = (int) $admin['id'];
        $component = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc();
        $params = json_decode((string) ($component['params'] ?? '{}'), true);
        $groupIds = (array) ($params['team_groups'] ?? []);
        $groupId = (int) ($groupIds[0] ?? 0);

        if ($groupId < 1) {
            throw new \RuntimeException('No team group is configured for the CalDAV fixture.');
        }

        $superUsers = $db->query("SELECT id FROM {$p}usergroups WHERE title='Super Users' LIMIT 1")->fetch_assoc();
        $superUserGroupId = (int) ($superUsers['id'] ?? 0);

        if ($superUserGroupId < 1) {
            throw new \RuntimeException('The Joomla Super Users group is missing.');
        }

        if ($this->suite->count("SELECT COUNT(*) FROM {$p}appointments_keyring") !== 0) {
            throw new \RuntimeException('The CalDAV fixture requires an empty recovery-key table.');
        }

        $this->services->keys()->createRecoveryKey($this->adminId);
        $this->createdRecoveryKey = true;

        foreach (['owner', 'reader', 'writer', 'freebusy', 'denied', 'nomfa', 'cleanup', 'cleanupadmin', 'lockout'] as $name) {
            $username = 'dav_' . $name . '_' . $this->token;
            $password = bin2hex(random_bytes(24));
            $userId = $this->insertUser($username, $password, $groupId);
            $this->accounts[$name] = [
                'id' => $userId,
                'username' => $username,
                'password' => $password,
            ];
            $this->addMfa($userId);
            $this->accounts[$name]['keys'] = $this->services->keys()->createUserKey($userId, $password);
        }

        if ($superUserGroupId !== $groupId
            && !$db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES (" . (int) $this->accounts['cleanupadmin']['id'] . ", {$superUserGroupId})")) {
            throw new \RuntimeException('Could not grant the cleanup fixture Joomla delete permission: ' . $db->error);
        }

        $this->calendarId = $this->services->calendars()->create($this->adminId, [
            'title' => 'CalDAV protocol test ' . $this->token,
            'owner_user_id' => $this->accounts['owner']['id'],
            'allow_external' => false,
        ]);
        $calendar = $this->services->calendars()->get($this->calendarId);

        if (!$calendar || !isset($calendar->uri)) {
            throw new \RuntimeException('The CalDAV fixture calendar has no URI.');
        }

        $this->calendarUri = (string) $calendar->uri;
        $owner = $this->accounts['owner'];

        foreach ([
            'reader' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Read,
            'writer' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Write,
            'freebusy' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::FreeBusy,
            'denied' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Read,
            'nomfa' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Read,
            'cleanup' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Write,
            'cleanupadmin' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Manage,
            'lockout' => \Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole::Read,
        ] as $name => $role) {
            $this->services->grants()->grant($owner['id'], $this->calendarId, $this->accounts[$name]['id'], $role, $owner['keys']);
        }

        foreach ($this->accounts as $name => &$account) {
            $device = $this->services->devices()->create($account['keys'], 'CalDAV test ' . $name, 'other', true);
            $account['device_id'] = (int) $device['device_id'];
            $account['app_password'] = (string) $device['app_password'];
        }
        unset($account);

        $this->services->grants()->revoke(
            $owner['id'],
            $this->calendarId,
            $this->accounts['denied']['id'],
            $owner['keys']
        );
        foreach ($this->accounts as &$account) {
            unset($account['keys']);
        }
        unset($account, $owner);

        if (!$db->query("DELETE FROM {$p}user_mfa WHERE user_id=" . (int) $this->accounts['nomfa']['id'])) {
            throw new \RuntimeException('Could not disable the fixture second factor: ' . $db->error);
        }
    }

    public function home(string $account): string
    {
        return '/dav/calendars/u' . $this->accounts[$account]['id'] . '/';
    }

    public function calendar(string $account): string
    {
        return $this->home($account) . rawurlencode($this->calendarUri) . '/';
    }

    /**
     * @return array{state: int, known: bool, known_calendars: int, delivered: ?string}|null
     */
    public function cleanupStatus(string $account): ?array
    {
        $p = $this->suite->prefix;
        $deviceId = (int) $this->accounts[$account]['device_id'];
        $calendarId = (int) $this->calendarId;
        $result = $this->suite->db->query(
            "SELECT d.state, dc.device_id AS known_device_id, dc.cleanup_delivered_utc,"
            . " (SELECT COUNT(*) FROM {$p}appointments_device_calendars known WHERE known.device_id=d.id) AS known_calendars"
            . " FROM {$p}appointments_devices d LEFT JOIN {$p}appointments_device_calendars dc"
            . " ON dc.device_id=d.id AND dc.calendar_id={$calendarId} WHERE d.id={$deviceId}"
        );

        if (!$result) {
            throw new \RuntimeException('Could not read CalDAV cleanup fixture state: ' . $this->suite->db->error);
        }

        $row = $result->fetch_assoc();

        return $row === null ? null : [
            'state' => (int) $row['state'],
            'known' => $row['known_device_id'] !== null,
            'known_calendars' => (int) $row['known_calendars'],
            'delivered' => $row['cleanup_delivered_utc'] === null ? null : (string) $row['cleanup_delivered_utc'],
        ];
    }

    public function expireJournal(): bool
    {
        $p = $this->suite->prefix;

        return (bool) $this->suite->db->query("DELETE FROM {$p}appointments_calendar_changes WHERE calendar_id=" . (int) $this->calendarId);
    }

    public function cleanup(): bool
    {
        $db = $this->suite->db;
        $p = $this->suite->prefix;
        $errors = [];

        if (isset($this->calendarId)) {
            try {
                $this->services->calendars()->delete((int) $this->accounts['cleanupadmin']['id'], $this->calendarId);
            } catch (\Throwable $e) {
                $errors[] = 'CalendarService::delete: ' . $e::class . ' (' . $e->getCode() . '): ' . $e->getMessage();
            }

            if ($errors === [] && $this->suite->count("SELECT COUNT(*) FROM {$p}appointments_calendars WHERE id=" . (int) $this->calendarId) !== 0) {
                $errors[] = 'CalendarService::delete returned but the fixture calendar row still exists.';
            }
        }

        if ($errors !== []) {
            $this->cleanupErrors = $errors;

            return false;
        }

        $userIds = array_values(array_filter(array_map(static fn (array $account): int => (int) ($account['id'] ?? 0), $this->accounts)));
        $ids = implode(',', $userIds);

        if ($ids !== '') {
            if (isset($this->accounts['lockout']['username'])) {
                try {
                    $ip = self::LOCKOUT_SOURCE_IP;
                    $key = $this->services->keyProvider()->key(\Advans\Component\Appointments\Administrator\Helper\KeyProvider::PURPOSE_TEAM_AUTH_BUCKET);
                    try {
                        $identity = 'dav:' . $this->accounts['lockout']['username'] . '@' . $ip;
                        $buckets = \Advans\Component\Appointments\Administrator\Service\Team\LockoutService::buckets($key, $identity, $ip);
                    } finally {
                        \Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite::wipe($key);
                    }

                    foreach ($buckets as $bucket) {
                        if (!$db->query("DELETE FROM {$p}appointments_auth_failures WHERE bucket=UNHEX('" . bin2hex($bucket) . "')")) {
                            $errors[] = 'appointments_auth_failures delete failed: ' . $db->error;
                        }
                    }
                } catch (\Throwable $e) {
                    $errors[] = 'CalDAV lockout bucket cleanup: ' . $e::class . ' (' . $e->getCode() . '): ' . $e->getMessage();
                }
            }

            if ($errors === []) {
                foreach (['appointments_device_calendars', 'appointments_devices', 'appointments_user_keys', 'user_mfa', 'user_usergroup_map'] as $table) {
                    if (!$db->query("DELETE FROM {$p}{$table} WHERE " . ($table === 'appointments_device_calendars'
                        ? "device_id IN (SELECT id FROM {$p}appointments_devices WHERE user_id IN ({$ids}))"
                        : "user_id IN ({$ids})"))) {
                        $errors[] = "{$table} delete failed: " . $db->error;
                    }
                }
            }

            if ($errors === [] && !$db->query("DELETE FROM {$p}users WHERE id IN ({$ids})")) {
                $errors[] = 'users delete failed: ' . $db->error;
            }
        }

        if ($errors === [] && $this->createdRecoveryKey && !$db->query("DELETE FROM {$p}appointments_keyring")) {
            $errors[] = 'appointments_keyring delete failed: ' . $db->error;
        }

        $this->cleanupErrors = $errors;

        if ($errors === []) {
            $this->accounts = [];
        }

        return $errors === [];
    }

    private function insertUser(string $username, string $password, int $groupId): int
    {
        $db = $this->suite->db;
        $p = $this->suite->prefix;
        $result = $db->query("SHOW COLUMNS FROM {$p}users");
        $columns = [];

        while ($result && ($column = $result->fetch_assoc())) {
            $columns[$column['Field']] = $column;
        }

        $now = gmdate('Y-m-d H:i:s');
        $wanted = [
            'name' => 'CalDAV ' . $username,
            'username' => $username,
            'email' => $username . '@example.test',
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'block' => '0',
            'sendEmail' => '0',
            'registerDate' => $now,
            'params' => '{}',
            'requireReset' => '0',
        ];
        $sets = [];

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $wanted)) {
                $sets[] = "`{$name}`='" . $db->real_escape_string((string) $wanted[$name]) . "'";
            } elseif ($name !== 'id' && $column['Null'] !== 'YES' && $column['Default'] === null) {
                $type = strtolower((string) $column['Type']);
                $value = str_contains($type, 'char') || str_contains($type, 'text') ? ''
                    : (str_contains($type, 'datetime') || str_contains($type, 'timestamp') ? $now
                        : (str_contains($type, 'date') ? substr($now, 0, 10) : '0'));
                $sets[] = "`{$name}`='" . $db->real_escape_string($value) . "'";
            }
        }

        if (!$db->query("INSERT INTO {$p}users SET " . implode(', ', $sets))) {
            throw new \RuntimeException('Could not create a CalDAV test user: ' . $db->error);
        }

        $userId = (int) $db->insert_id;

        if (!$db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$userId}, {$groupId})")) {
            $db->query("DELETE FROM {$p}users WHERE id={$userId}");
            throw new \RuntimeException('Could not assign a CalDAV test user to the team group: ' . $db->error);
        }

        return $userId;
    }

    private function addMfa(int $userId): void
    {
        $db = $this->suite->db;
        $p = $this->suite->prefix;
        $result = $db->query("SHOW COLUMNS FROM {$p}user_mfa");
        $columns = [];

        while ($result && ($column = $result->fetch_assoc())) {
            $columns[$column['Field']] = $column;
        }

        $now = gmdate('Y-m-d H:i:s');
        $wanted = [
            'user_id' => (string) $userId,
            'title' => 'CalDAV protocol test factor',
            'method' => 'totp',
            'default' => '1',
            'created_on' => $now,
            'last_used' => null,
            'options' => '{}',
        ];
        $sets = [];

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $wanted)) {
                $value = $wanted[$name];
                $sets[] = "`{$name}`=" . ($value === null ? 'NULL' : "'" . $db->real_escape_string($value) . "'");
            } elseif ($name !== 'id' && $column['Null'] !== 'YES' && $column['Default'] === null) {
                $sets[] = "`{$name}`='0'";
            }
        }

        if (!$db->query("INSERT INTO {$p}user_mfa SET " . implode(', ', $sets))) {
            throw new \RuntimeException('Could not create a CalDAV test second factor: ' . $db->error);
        }
    }
}

return static function (TeamSuite $suite): void {
    $admin = '/var/www/html/administrator/components/com_appointments/src/';

    // TeamSuite::isStub() reflects service classes; they load without Joomla.
    defined('_JEXEC') or define('_JEXEC', 1);
    spl_autoload_register(static function (string $class) use ($admin): void {
        $prefix = 'Advans\\Component\\Appointments\\Administrator\\';

        if (str_starts_with($class, $prefix)) {
            $file = $admin . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    $p = $suite->prefix;
    $db = $suite->db;
    $https = str_starts_with($suite->base, 'https://');

    /**
     * One request to an absolute URL (plain HTTP or the suite's HTTPS base).
     *
     * @return array{status: int, headers: string, body: string}
     */
    $request = static function (string $method, string $url, array $headers = [], ?string $body = null, ?string $sourceIp = null): array {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($sourceIp !== null) {
            $options[CURLOPT_INTERFACE] = $sourceIp;
        }

        if (str_starts_with($url, 'https://') && is_file('/etc/ssl/appointments-test/ca.pem')) {
            $options[CURLOPT_CAINFO] = '/etc/ssl/appointments-test/ca.pem';
        }

        curl_setopt_array($ch, $options);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    };

    $header = static function (array $response, string $name): ?string {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $response['headers'], $m) ? $m[1] : null;
    };

    $headersOk = static function (array $response) use ($header): bool {
        return $header($response, 'Cache-Control') === 'private, no-store'
            && $header($response, 'X-Robots-Tag') === 'noindex, nofollow'
            && $header($response, 'Set-Cookie') === null
            && stripos($response['headers'], 'X-Sabre') === false;
    };

    $sessions = static function () use ($suite, $p): int {
        return $suite->count("SELECT COUNT(*) FROM {$p}session");
    };

    $suite->test('CalDAV plugin installed and disabled after installation', static function () use ($suite, $p) {
        return $suite->count("SELECT COUNT(*) FROM {$p}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav' AND enabled=0") === 1;
    });

    foreach (['/.well-known/caldav', '/dav/'] as $path) {
        $suite->test("{$path} does not answer as CalDAV while the plugin is disabled", static function () use ($suite, $path) {
            $response = $suite->http('PROPFIND', $path, ['Depth: 0']);

            return $response['status'] !== 207 && stripos($response['headers'], 'DAV:') === false;
        });
    }

    $suite->test('The bundled sabre/dav copy is installed with its autoload map', static function () {
        $lib = '/var/www/html/administrator/components/com_appointments/lib';

        return is_file($lib . '/autoload.php') && is_file($lib . '/sabre/dav/lib/DAV/Server.php') && is_file($lib . '/sabre/dav/LICENSE')
            && !is_dir($lib . '/sabre/dav/lib/CardDAV');
    });

    // Switch the plugin and the feature on for the rest of this part.
    $result = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'");
    $originalParams = (string) ($result ? $result->fetch_assoc()['params'] : '{}');
    $params = json_decode($originalParams, true) ?: [];
    $params['teamcal_enabled'] = 1;
    $statement = $db->prepare("UPDATE {$p}extensions SET params = ? WHERE type='component' AND element='com_appointments'");
    $encoded = json_encode($params);
    $statement->bind_param('s', $encoded);
    $statement->execute();
    $db->query("UPDATE {$p}extensions SET enabled = 1 WHERE type='plugin' AND folder='system' AND element='appointmentsdav'");

    $fixtureForCleanup = null;

    try {
        $suite->test('Plain HTTP is refused with 403, no body and no redirect', static function () use ($request, $headersOk) {
            foreach (['/dav/', '/dav/principals/', '/.well-known/caldav'] as $path) {
                $response = $request('PROPFIND', 'http://localhost' . $path, ['Depth: 0']);

                if ($response['status'] !== 403 || $response['body'] !== '' || stripos($response['headers'], "\nLocation:") !== false || !$headersOk($response)) {
                    return false;
                }
            }

            return true;
        });

        $suite->test('Other site addresses are not taken over by the CalDAV plugin', static function () use ($request) {
            $response = $request('GET', 'http://localhost/index.php');

            return $response['status'] === 200 && stripos($response['headers'], 'X-Robots-Tag: noindex, nofollow') === false;
        });

        if (!$https) {
            foreach ([
                'Over HTTPS the well-known address redirects every method to the DAV address',
                'Over HTTPS every method is authenticated first (401 with a Basic challenge)',
                'Over HTTPS a 401 says whether the Authorization header reached PHP',
                'A CalDAV request leaves no Joomla session row and no cookie',
                'MKCALENDAR reaches PHP and is refused by the server, not by the web server',
            ] as $name) {
                $suite->pending($name, 'G1');
            }
        } else {
            $suite->test('Over HTTPS the well-known address redirects every method to the DAV address', static function () use ($suite, $request, $header, $headersOk) {
                foreach (['GET', 'PROPFIND', 'OPTIONS', 'PUT'] as $method) {
                    $response = $request($method, $suite->base . '/.well-known/caldav', ['Depth: 0']);

                    // Same host: the path only, so no Host header comes back in the answer.
                    if ($response['status'] !== 301 || $header($response, 'Location') !== '/dav/' || !$headersOk($response)) {
                        return false;
                    }
                }

                return true;
            });

            $suite->test('Over HTTPS every method is authenticated first (401 with a Basic challenge)', static function () use ($suite, $request, $header, $headersOk) {
                foreach (['OPTIONS', 'PROPFIND', 'REPORT', 'PUT', 'DELETE', 'MKCALENDAR'] as $method) {
                    $response = $request($method, $suite->base . '/dav/', ['Depth: 0', 'Content-Type: application/xml'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"/>');

                    if ($response['status'] !== 401 || !str_starts_with((string) $header($response, 'WWW-Authenticate'), 'Basic realm="')
                        || $header($response, 'X-Appointments-Authorization') !== null || $response['body'] !== '' || !$headersOk($response)) {
                        return false;
                    }
                }

                return true;
            });

            $suite->test('Over HTTPS a 401 says whether the Authorization header reached PHP', static function () use ($suite, $request, $header) {
                $response = $request('PROPFIND', $suite->base . '/dav/', ['Depth: 0', 'Authorization: Bearer not-basic']);

                return $response['status'] === 401 && $header($response, 'X-Appointments-Authorization') === 'received';
            });

            $suite->test('A CalDAV request leaves no Joomla session row and no cookie', static function () use ($suite, $request, $sessions, $headersOk) {
                $before = $sessions();

                for ($i = 0; $i < 3; $i++) {
                    $response = $request('PROPFIND', $suite->base . '/dav/', ['Depth: 0']);

                    if (!$headersOk($response)) {
                        return false;
                    }
                }

                return $sessions() === $before;
            });

            $suite->test('MKCALENDAR reaches PHP and is refused by the server, not by the web server', static function () use ($suite, $request, $header) {
                $response = $request('MKCALENDAR', $suite->base . '/dav/calendars/u1/new/');

                return $response['status'] === 401 && $header($response, 'WWW-Authenticate') !== null;
            });
        }

        // Every authenticated case below goes through the installed HTTPS endpoint.
        $needs = [
            'B' => ['Advans\\Component\\Appointments\\Administrator\\Service\\Team\\KeyService'],
            'C' => [
                'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\GrantService',
                'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\CalendarService',
            ],
            'D' => ['Advans\\Component\\Appointments\\Administrator\\Service\\Team\\DeviceService'],
            'E' => ['Advans\\Component\\Appointments\\Administrator\\Service\\Team\\TimingIndex'],
            'F' => [
                'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\LockoutService',
                'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\CleanupService',
                'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\AuditService',
            ],
        ];
        $missing = static function (string ...$packages) use ($suite, $needs, $https): string {
            $open = [];

            foreach ($packages as $package) {
                foreach ($needs[$package] as $class) {
                    if ($suite->isStub($class)) {
                        $open[] = $package;
                        break;
                    }
                }
            }

            if (!$https && !in_array('G', $open, true)) {
                $open[] = 'G';
            }

            return implode(', ', array_unique($open));
        };

        $fixture = null;
        $fixtureError = null;
        $setupMissing = $missing('B', 'C', 'D', 'F');

        if ($https && $setupMissing === '') {
            try {
                $fixture = new TeamDavFixture($suite);
                $fixtureForCleanup = $fixture;
                $fixture->create();
            } catch (\Throwable $e) {
                $fixtureError = $e;
                $fixture = null;
            }
        }

        if ($setupMissing !== '' || !$https) {
            $suite->pending('Create isolated authenticated CalDAV fixture', $setupMissing !== '' ? $setupMissing : 'G');
        } else {
            $suite->test('Create isolated authenticated CalDAV fixture', static fn () => $fixture !== null);
        }

        $send = static function (
            string $method,
            string $path,
            string $account,
            array $headers = [],
            ?string $body = null,
            ?string $password = null,
            ?string $sourceIp = null
        ) use ($suite, $request, &$fixture, $fixtureError): array {
            if (!$fixture) {
                throw new \RuntimeException('CalDAV fixture setup failed: ' . ($fixtureError?->getMessage() ?? 'not available'));
            }

            $user = $fixture->accounts[$account];
            $headers[] = 'Authorization: Basic ' . base64_encode($user['username'] . ':' . ($password ?? $user['app_password']));

            return $request($method, $suite->base . $path, $headers, $body, $sourceIp);
        };

        $ics = static function (string $uid, string $summary): string {
            return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Appointments CalDAV tests//EN\r\n"
                . "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20261110T100000Z\r\n"
                . "DTSTART:20261112T090000Z\r\nDTEND:20261112T100000Z\r\nSUMMARY:{$summary}\r\n"
                . "END:VEVENT\r\nEND:VCALENDAR\r\n";
        };

        $propfind = static function (string $account, string $path, string $depth, string $properties = '<d:resourcetype/>') use ($send): array {
            $body = '<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $properties . '</d:prop></d:propfind>';

            return $send('PROPFIND', $path, $account, ['Depth: ' . $depth, 'Content-Type: application/xml'], $body);
        };

        $sync = static function (string $account, string $path, string $token = '') use ($send): array {
            $body = '<?xml version="1.0"?><d:sync-collection xmlns:d="DAV:"><d:sync-token>'
                . htmlspecialchars($token, ENT_XML1) . '</d:sync-token><d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>';

            return $send('REPORT', $path, $account, ['Depth: 1', 'Content-Type: application/xml'], $body);
        };

        $httpCases = [
            [
                'A Joomla password is refused like an invalid app password, while the real app password works',
                ['B', 'C', 'D', 'F'],
                static function (TeamDavFixture $f) use ($send, $propfind, $header): bool {
                    $path = '/dav/principals/u' . $f->accounts['reader']['id'] . '/';
                    $joomla = $send('PROPFIND', '/dav/', 'reader', ['Depth: 0', 'Content-Type: application/xml'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>', $f->accounts['reader']['password']);
                    $wrong = $send('PROPFIND', '/dav/', 'reader', ['Depth: 0'], null, str_repeat('A', 32));
                    $good = $propfind('reader', $path, '0', '<d:displayname/>');

                    return $joomla['status'] === 401 && $wrong['status'] === 401
                        && $joomla['body'] === $wrong['body'] && $header($joomla, 'WWW-Authenticate') === $header($wrong, 'WWW-Authenticate')
                        && $good['status'] === 207;
                },
            ],
            [
                'Discovery exposes only the authenticated principal and default-deny hides an ungranted calendar',
                ['B', 'C', 'D', 'F'],
                static function (TeamDavFixture $f) use ($propfind): bool {
                    $principalPath = '/dav/principals/u' . $f->accounts['reader']['id'] . '/';
                    $root = $propfind('reader', '/dav/', '0', '<d:current-user-principal/>');
                    $principal = $propfind('reader', $principalPath, '0', '<c:calendar-home-set/>');
                    $principals = $propfind('reader', '/dav/principals/', '1');
                    $ownHome = $propfind('reader', $f->home('reader'), '1');
                    $deniedHome = $propfind('denied', $f->home('denied'), '1');
                    $foreign = $propfind('denied', $f->calendar('denied'), '0');

                    return $root['status'] === 207 && str_contains($root['body'], $principalPath)
                        && $principal['status'] === 207 && str_contains($principal['body'], $f->home('reader'))
                        && $principals['status'] === 207 && substr_count($principals['body'], '/dav/principals/u') === 1
                        && $ownHome['status'] === 207 && str_contains($ownHome['body'], $f->calendarUri)
                        && $deniedHome['status'] === 207 && !str_contains($deniedHome['body'], $f->calendarUri)
                        && $foreign['status'] === 404;
                },
            ],
            [
                'Free/busy is redacted, read is read-only, and write enforces ETag and UID preconditions',
                ['B', 'C', 'D', 'E', 'F'],
                static function (TeamDavFixture $f) use ($send, $propfind, $ics, $header): bool {
                    $uid = 'role-' . bin2hex(random_bytes(6));
                    $summary = 'Confidential CalDAV protocol fixture';
                    $ownerPath = $f->calendar('owner');
                    $seed = $send('PUT', $ownerPath . 'seed.ics', 'owner', ['Content-Type: text/calendar', 'If-None-Match: *'], $ics($uid, $summary));

                    if ($seed['status'] !== 201) {
                        return false;
                    }

                    $read = $send('GET', $f->calendar('reader') . 'seed.ics', 'reader');
                    $readOnly = $send('PUT', $f->calendar('reader') . 'denied.ics', 'reader', ['Content-Type: text/calendar'], $ics('read-denied', 'Denied write'));
                    $freeList = $propfind('freebusy', $f->calendar('freebusy'), '1');

                    if ($read['status'] !== 200 || !str_contains($read['body'], $summary) || $readOnly['status'] !== 403
                        || $freeList['status'] !== 207 || str_contains($freeList['body'], $uid) || str_contains($freeList['body'], 'seed.ics')
                        || !preg_match('#/dav/calendars/u\d+/[^/]+/(fb-[0-9a-f]{40}\.ics)#', $freeList['body'], $match)) {
                        return false;
                    }

                    $busy = $send('GET', $f->calendar('freebusy') . $match[1], 'freebusy');

                    if ($busy['status'] !== 200 || !str_contains($busy['body'], 'CLASS:CONFIDENTIAL')
                        || str_contains($busy['body'], $summary) || str_contains($busy['body'], $uid)) {
                        return false;
                    }

                    $writerPath = $f->calendar('writer');
                    $create = $send('PUT', $writerPath . 'write.ics', 'writer', ['Content-Type: text/calendar', 'If-None-Match: *'], $ics('writer-' . $uid, 'Write test'));
                    $etag = $header($create, 'ETag');
                    $uidConflict = $send('PUT', $writerPath . 'same-uid.ics', 'writer', ['Content-Type: text/calendar'], $ics('writer-' . $uid, 'UID conflict'));

                    if ($create['status'] !== 201 || $etag === null
                        || $uidConflict['status'] !== 403 || !str_contains($uidConflict['body'], 'no-uid-conflict')
                        || str_contains($uidConflict['body'], 'write.ics')
                        || $send('PUT', $writerPath . 'write.ics', 'writer', ['Content-Type: text/calendar', 'If-None-Match: *'], $ics('writer-' . $uid, 'Duplicate'))['status'] !== 412
                                || $send('PUT', $writerPath . 'write.ics', 'writer', ['Content-Type: text/calendar', 'If-Match: "0000"'], $ics('writer-' . $uid, 'Stale'))['status'] !== 412) {
                        return false;
                    }

                    $changed = $send('PUT', $writerPath . 'write.ics', 'writer', ['Content-Type: text/calendar', 'If-Match: ' . $etag], $ics('writer-' . $uid, 'Updated'));

                    return $changed['status'] === 204
                        && $send('DELETE', $writerPath . 'write.ics', 'writer', ['If-Match: "0000"'])['status'] === 412
                        && $send('DELETE', $writerPath . 'write.ics', 'writer')['status'] === 204
                        && $send('DELETE', $ownerPath, 'owner')['status'] === 403
                        && $send('MKCALENDAR', $ownerPath . 'new/', 'owner')['status'] === 403;
                },
            ],
            [
                'sync-collection returns incremental additions and deletion tombstones and rejects an invalid token',
                ['B', 'C', 'D', 'E', 'F'],
                static function (TeamDavFixture $f) use ($send, $sync, $ics): bool {
                    $path = $f->calendar('writer');
                    $firstPut = $send('PUT', $path . 'sync-a.ics', 'writer', ['Content-Type: text/calendar'], $ics('sync-a-' . $f->calendarId, 'Sync A'));

                    if ($firstPut['status'] !== 201) {
                        return false;
                    }

                    $initial = $sync('writer', $path);
                    preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $initial['body'], $tokenMatch);
                    $token = html_entity_decode($tokenMatch[1] ?? '', ENT_XML1);

                    if ($initial['status'] !== 207 || $token === '' || !str_contains($initial['body'], 'sync-a.ics')) {
                        return false;
                    }

                    $added = $send('PUT', $path . 'sync-b.ics', 'writer', ['Content-Type: text/calendar'], $ics('sync-b-' . $f->calendarId, 'Sync B'));
                    $delta = $sync('writer', $path, $token);

                    if ($added['status'] !== 201 || $delta['status'] !== 207 || !str_contains($delta['body'], 'sync-b.ics')
                        || str_contains($delta['body'], 'sync-a.ics')) {
                        return false;
                    }

                    $deltaTokenMatch = [];
                    preg_match('#<d:sync-token>([^<]+)</d:sync-token>#', $delta['body'], $deltaTokenMatch);
                    $deleted = $send('DELETE', $path . 'sync-b.ics', 'writer');
                    $tombstone = $sync('writer', $path, html_entity_decode($deltaTokenMatch[1] ?? '', ENT_XML1));
                    $invalid = $sync('writer', $path, 'urn:other:1');

                    if ($deleted['status'] !== 204 || $tombstone['status'] !== 207
                        || preg_match('#sync-b\.ics</d:href>\s*<d:status>HTTP/1\.1 404 Not Found</d:status>#', $tombstone['body']) !== 1
                        || $invalid['status'] !== 403 || !str_contains($invalid['body'], 'valid-sync-token')
                        || !$f->expireJournal()) {
                        return false;
                    }

                    $expired = $sync('writer', $path, '0');

                    return $expired['status'] === 403 && str_contains($expired['body'], 'valid-sync-token');
                },
            ],
            [
                'Clean-up serves empty then hidden views before revoking the device',
                ['B', 'C', 'D', 'E', 'F'],
                static function (TeamDavFixture $f) use ($send, $propfind, $ics): bool {
                    $account = $f->accounts['cleanup'];
                    $path = $f->calendar('cleanup');
                    $created = $send('PUT', $path . 'cleanup.ics', 'cleanup', ['Content-Type: text/calendar'], $ics('cleanup-' . $f->calendarId, 'Cleanup object'));
                    $before = $f->cleanupStatus('cleanup');

                    if ($created['status'] !== 201 || $before === null || !$before['known'] || $before['known_calendars'] !== 1 || $before['delivered'] !== null) {
                        return false;
                    }

                    $f->services->cleanup()->startForDevice($account['device_id'], 'test', $f->adminId);
                    $started = $f->cleanupStatus('cleanup');

                    if ($started === null || $started['state'] !== \Advans\Component\Appointments\Administrator\Helper\Team\TeamStates::DEVICE_CLEANUP_EMPTY
                        || !$started['known'] || $started['known_calendars'] !== 1 || $started['delivered'] !== null) {
                        return false;
                    }

                    $empty = $propfind('cleanup', $path, '1');
                    $delivered = $f->cleanupStatus('cleanup');

                    if ($empty['status'] !== 207 || str_contains($empty['body'], 'cleanup.ics')
                        || $delivered === null || !$delivered['known'] || $delivered['delivered'] === null
                        || $delivered['known_calendars'] !== 1
                        || $delivered['state'] !== \Advans\Component\Appointments\Administrator\Helper\Team\TeamStates::DEVICE_CLEANUP_HIDDEN) {
                        return false;
                    }

                    $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                    $f->services->cleanup()->advance($now);
                    $hidden = $propfind('cleanup', $f->home('cleanup'), '1');
                    $revoked = $f->cleanupStatus('cleanup');

                    if ($hidden['status'] !== 207 || str_contains($hidden['body'], $f->calendarUri)
                        || $revoked === null || $revoked['state'] !== \Advans\Component\Appointments\Administrator\Helper\Team\TeamStates::DEVICE_REVOKED) {
                        return false;
                    }

                    $f->services->cleanup()->advance($now);

                    return $send('PROPFIND', $f->home('cleanup'), 'cleanup', ['Depth: 1'])['status'] === 401;
                },
            ],
            [
                'An active device with no configured second factor is refused before DAV discovery',
                ['B', 'C', 'D', 'F'],
                static function (TeamDavFixture $f) use ($send): bool {
                    $response = $send('PROPFIND', '/dav/', 'nomfa', ['Depth: 0']);

                    return $response['status'] === 403 && $response['body'] === '';
                },
            ],
            [
                'Ten failed app-password sign-ins lock out the account, including its correct password',
                ['B', 'C', 'D', 'F'],
                static function (TeamDavFixture $f) use ($send, $header): bool {
                    for ($attempt = 0; $attempt < 10; $attempt++) {
                        if ($send('PROPFIND', '/dav/', 'lockout', ['Depth: 0'], null, str_repeat('A', 32), TeamDavFixture::LOCKOUT_SOURCE_IP)['status'] !== 401) {
                            return false;
                        }
                    }

                    $locked = $send('PROPFIND', '/dav/', 'lockout', ['Depth: 0'], null, null, TeamDavFixture::LOCKOUT_SOURCE_IP);

                    return $locked['status'] === 429 && (int) $header($locked, 'Retry-After') > 0;
                },
            ],
        ];

        foreach ($httpCases as [$name, $packages, $test]) {
            $open = $missing(...$packages);

            if ($open !== '') {
                $suite->pending($name, $open);
            } else {
                $suite->test($name, static function () use ($test, &$fixture, $fixtureError): bool {
                    if (!$fixture) {
                        throw new \RuntimeException('CalDAV fixture setup failed: ' . ($fixtureError?->getMessage() ?? 'not available'));
                    }

                    return $test($fixture);
                });
            }
        }

    } finally {
        if ($fixtureForCleanup instanceof TeamDavFixture) {
            $suite->test('Remove all CalDAV fixture accounts, keys, devices, calendar and recovery key', static function () use ($fixtureForCleanup): bool {
                if (!$fixtureForCleanup->cleanup()) {
                    throw new \RuntimeException(implode('; ', $fixtureForCleanup->cleanupErrors));
                }

                return true;
            });
        }

        $statement = $db->prepare("UPDATE {$p}extensions SET params = ? WHERE type='component' AND element='com_appointments'");
        $statement->bind_param('s', $originalParams);
        $statement->execute();
        $db->query("UPDATE {$p}extensions SET enabled = 0 WHERE type='plugin' AND folder='system' AND element='appointmentsdav'");
    }

    $suite->test('The plugin and the option are back as the installation left them', static function () use ($suite, $p) {
        return $suite->count("SELECT COUNT(*) FROM {$p}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav' AND enabled=0") === 1;
    });
};
