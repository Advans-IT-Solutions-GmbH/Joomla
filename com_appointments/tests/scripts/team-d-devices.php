<?php
/**
 * Work package D: «My calendars», devices, account activation and the mails of
 * the internet calendars.
 *
 * Part 1 runs always: schema, 404 while off, form tokens, the activation page,
 * the mail templates, the refusals of the device service before any key is
 * needed, «last synchronised» throttling and the device list.
 *
 * Part 2 is the web flow with a real sign-in and second factor (TOTP) in the
 * container: add a device, rule checkbox, double submission, no grant, locked
 * key (423), mail, the app password shown once, authentication, removal. It needs
 * the key session (B), grants and calendars (C) and the log and clean-up (F);
 * while one of them is still a stub, its tests are reported as pending.
 *
 * Cleans up every row it creates except log rows (the hash chain stays intact
 * for part F) and restores the component and plugin settings it changes.
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\AuditService;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupService;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceService;
use Advans\Component\Appointments\Administrator\Service\Team\EpochAnchor;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\InvitationService;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutService;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\KeySession;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Administrator\Table\TemplateTable;
use Joomla\Registry\Registry;

/**
 * A browser session with its own cookie jar that verifies the test certificate
 * like the suite does and does not follow redirects.
 */
final class TeamDBrowser
{
    public int $status = 0;
    public string $headers = '';
    private string $jar;

    public function __construct(private string $base)
    {
        $this->jar = sys_get_temp_dir() . '/team-d-' . bin2hex(random_bytes(8)) . '.cookies';
    }

    public function __destruct()
    {
        if (is_file($this->jar)) {
            unlink($this->jar);
        }
    }

    public function request(string $path, ?array $post = null, int $follow = 5): string
    {
        $url = str_starts_with($path, 'http') ? $path : rtrim($this->base, '/') . '/' . ltrim($path, '/');
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
        ];

        if (is_file('/etc/ssl/appointments-test/ca.pem')) {
            $options[CURLOPT_CAINFO] = '/etc/ssl/appointments-test/ca.pem';
        }

        if ($post !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($post);
        }

        curl_setopt_array($ch, $options);
        $raw = (string) curl_exec($ch);
        $this->status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        // Since PHP 8 the jar is written only when the handle is freed: write it now, so the
        // redirect after a sign-in sends the regenerated session cookie.
        if (!curl_setopt($ch, CURLOPT_COOKIELIST, 'FLUSH')) {
            throw new \RuntimeException('The cookie jar could not be written.');
        }

        unset($ch);
        $this->headers = substr($raw, 0, $size);
        $body = substr($raw, $size);

        if ($follow > 0 && $this->status >= 300 && $this->status < 400 && $location !== '') {
            return $this->request($location, null, $follow - 1);
        }

        return $body;
    }

    public function header(string $name): string
    {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $this->headers, $m) === 1 ? $m[1] : '';
    }

    /** Remove one cookie from the jar (Netscape format), e.g. the key escrow of the session. */
    public function dropCookie(string $name): void
    {
        if (!is_file($this->jar)) {
            return;
        }

        $lines = array_filter(file($this->jar) ?: [], static fn (string $line): bool => !preg_match('/\t' . preg_quote($name, '/') . '\t/', $line));
        file_put_contents($this->jar, implode('', $lines));
    }

    /**
     * Hidden inputs, the token and the action of the form whose action contains $task.
     *
     * @return array{action: string, fields: array<string, string>}|null
     */
    public static function form(string $html, string $task): ?array
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);

        foreach ((new DOMXPath($document))->query('//form') as $form) {
            $action = html_entity_decode($form->getAttribute('action'));
            $fields = [];

            foreach ((new DOMXPath($document))->query('.//input', $form) as $input) {
                $name = $input->getAttribute('name');

                if ($name !== '' && \in_array($input->getAttribute('type'), ['hidden', ''], true)) {
                    $fields[$name] = $input->getAttribute('value');
                }
            }

            if (str_contains($action, $task) || ($fields['task'] ?? '') === $task) {
                return ['action' => $action, 'fields' => $fields];
            }
        }

        return null;
    }
}

/** RFC 6238 code of a Base32 secret, as Joomla's TOTP method checks it. */
function team_d_totp(string $base32, int $time): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    foreach (str_split(strtoupper(rtrim($base32, '='))) as $char) {
        $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
    }

    $key = '';

    foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
        $key .= chr(bindec($byte));
    }

    $hash = hash_hmac('sha1', pack('J', intdiv($time, 30)), $key, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

return static function (TeamSuite $suite): void {
    $db = $suite->db;
    $p = $suite->prefix;
    // «My calendars» opens only through its menu item (appointments-menu.php).
    require_once __DIR__ . '/appointments-menu.php';
    $myCalendars = '/index.php?option=com_appointments&view=mycalendars&Itemid=' . appointments_menu_item('index.php?option=com_appointments&view=mycalendars');

    // ------------------------------------------------------------------
    // Part 1: always
    // ------------------------------------------------------------------

    $suite->test('Devices store a keyed hash and a wrapped key, never the app password', static function () use ($suite) {
        $columns = $suite->columns('appointments_devices');

        return in_array('secret_hash', $columns, true) && in_array('usk_wrapped', $columns, true)
            && !in_array('password', $columns, true) && !in_array('app_password', $columns, true);
    });

    $suite->test('«My calendars» answers 404 while the internet calendars are off', static function () use ($suite, $myCalendars) {
        // With strict routing Joomla first redirects to the SEF address of the view; follow it.
        $browser = new TeamDBrowser($suite->base);
        $browser->request($myCalendars);

        if ($browser->status !== 404) {
            throw new RuntimeException('HTTP ' . $browser->status);
        }

        return true;
    });

    $suite->test('The activation page answers 404 while the internet calendars are off', static function () use ($suite) {
        return $suite->http('GET', '/index.php?option=com_appointments&view=activation&token=' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='))['status'] === 404;
    });

    $suite->test('Device and activation tasks refuse a request without form token', static function () use ($suite) {
        $statuses = [];

        foreach (['devices.create', 'devices.rename', 'devices.remove', 'activation.complete'] as $task) {
            $browser = new TeamDBrowser($suite->base);
            $browser->request('/index.php?option=com_appointments&task=' . $task, ['name' => 'x', 'rule' => 1], 0);
            $statuses[$task] = $browser->status . ($browser->header('Location') !== '' ? ' -> ' . $browser->header('Location') : '');
        }

        if (array_values(array_unique(array_map('intval', $statuses))) !== [403]) {
            throw new RuntimeException(json_encode($statuses));
        }

        return true;
    });

    $suite->test('The seven internet calendar mails are site-wide DB templates in all three languages', static function () use ($suite, $p) {
        $purposes = "'team_device_added','team_invitation','team_invitation_accepted','team_grant_changed','team_access_suspended','team_calendar_deleted','team_account_locked'";

        return $suite->count("SELECT COUNT(*) FROM {$p}appointments_templates WHERE eventtype_id = 0 AND published = 1 AND purpose IN ({$purposes}) AND language IN ('en-GB','de-DE','fr-FR')") === 21
            && $suite->count("SELECT COUNT(*) FROM {$p}appointments_templates WHERE eventtype_id <> 0 AND purpose IN ({$purposes})") === 0
            && $suite->count("SELECT COUNT(*) FROM {$p}appointments_templates WHERE purpose = 'team_device_added' AND body LIKE '%{device_name}%' AND body NOT LIKE '%password}%'") === 3;
    });

    require_once __DIR__ . '/appointments-joomla.php';
    require_once __DIR__ . '/appointments-mail.php';

    $tag = 'teamd' . bin2hex(random_bytes(4));
    $state = ['users' => [], 'calendars' => [], 'devices' => [], 'params' => null, 'plugins' => [], 'recovery' => false, 'mfa' => []];
    $joomla = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
    $enabled = new TeamServices(
        $joomla,
        new KeyProvider((string) \Joomla\CMS\Factory::getApplication()->get('secret')),
        new Registry(['teamcal_enabled' => 1]),
        static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
    );
    $user = static function (string $name, string $password, array $groups) use ($db, $p, $tag, &$state): int {
        $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
        $login = $tag . $name;
        $db->query("INSERT INTO {$p}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$login}','{$login}','{$login}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
        $id = (int) $db->insert_id;

        foreach ($groups as $group) {
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$id}, " . (int) $group . ')');
        }

        $state['users'][] = $id;

        return $id;
    };
    $device = static function (int $userId, string $name, int $deviceState) use ($db, $p, &$state): int {
        $hash = random_bytes(32);
        $db->query("INSERT INTO {$p}appointments_devices (user_id,name,client,secret_hash,kek_salt,usk_wrapped,user_key_version,state,rule_version,rule_accepted_utc,created_utc,row_mac) VALUES ({$userId},'" . $db->real_escape_string($name) . "','davx5',X'" . bin2hex($hash) . "',X'" . bin2hex(random_bytes(16)) . "',X'" . bin2hex(random_bytes(40)) . "',1,{$deviceState},'1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),X'" . bin2hex(random_bytes(32)) . "')");
        $state['devices'][] = (int) $db->insert_id;

        return (int) $db->insert_id;
    };
    $refusal = static function (callable $fn): int {
        try {
            $fn();
        } catch (TeamException $e) {
            return $e->getCode();
        }

        return 0;
    };

    try {
        $suite->test('Calendar notices use each account language and same-language defaults without a published DB template', static function () use ($enabled, $user, $db, $joomla, $p, $tag) {
            foreach (['de-DE', 'en-GB', 'fr-FR'] as $language) {
                $id = $user('mail-' . $language, 'Calendar mail fixture 1!', [2]);
                $params = $db->real_escape_string(json_encode(['language' => $language], JSON_THROW_ON_ERROR));
                $db->query("UPDATE {$p}users SET params='{$params}' WHERE id={$id}");
                $address = $tag . 'mail-' . $language . '@example.test';
                $strings = parse_ini_file(JPATH_ADMINISTRATOR . '/components/com_appointments/language/' . $language . '/com_appointments.ini', false, INI_SCANNER_RAW);
                $expected = strtr($strings['COM_APPOINTMENTS_TEAM_D_MAIL_DEVICE_ADDED_SUBJECT'], [
                    '{site_name}' => (string) \Joomla\CMS\Factory::getApplication()->get('sitename'),
                    '{user_name}' => $tag . 'mail-' . $language,
                ]);
                if (\Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddress($joomla, strtoupper($address), 'de-DE') !== $language) {
                    return false;
                }

                foreach ([true, false] as $published) {
                    $db->query("UPDATE {$p}appointments_templates SET published=" . ($published ? 1 : 0) . " WHERE eventtype_id=0 AND purpose='team_device_added' AND language='{$language}'");
                    try {
                        $before = AppointmentsMail::snapshot();
                        $enabled->mailer()->deviceAdded($id, 'Mail fixture device', 'other', new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
                        $messages = AppointmentsMail::messagesSince($before);
                        if (count($messages) !== 1) {
                            return false;
                        }
                        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $messages[0]);
                        if (!preg_match('/^Subject:\s*(.*)$/mi', $headers, $subject)
                            || iconv_mime_decode(trim($subject[1]), 0, 'UTF-8') !== $expected
                            || !str_contains(AppointmentsMail::decoded($messages[0]), 'Mail fixture device')
                            || str_contains(AppointmentsMail::decoded($messages[0]), 'COM_APPOINTMENTS_')
                        ) {
                            return false;
                        }
                    } finally {
                        $db->query("UPDATE {$p}appointments_templates SET published=1 WHERE eventtype_id=0 AND purpose='team_device_added' AND language='{$language}'");
                    }
                }
            }

            return \Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddress($joomla, $tag . '-no-account@example.test', 'fr-FR') === 'fr-FR';
        });

        $suite->test('A mixed recipient batch resolves each account independently and leaves unknown guests on the site default', static function () use ($user, $db, $joomla, $p, $tag) {
            $addresses = [];
            $expected = [];

            foreach (['de-DE', 'en-GB', 'fr-FR'] as $language) {
                $id = $user('mail-batch-' . $language, 'Calendar mail fixture 1!', [2]);
                $params = $db->real_escape_string(json_encode(['language' => $language], JSON_THROW_ON_ERROR));
                $db->query("UPDATE {$p}users SET params='{$params}' WHERE id={$id}");
                $address = "\t " . strtoupper($tag . 'mail-batch-' . $language . '@example.test') . " \r\n";
                $addresses[] = $address;
                $expected[$address] = $language;
            }

            $guest = $tag . 'mail-batch-guest@example.test';
            $addresses[] = $guest;
            $expected[$guest] = 'fr-FR';

            return \Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddresses($joomla, $addresses, 'fr-FR') === $expected;
        });

        foreach ([
            'Unicode sharp S' => ["\u{1E9E}", false],
            'Georgian case' => ["\u{1C90}", false],
            'trimmed controls' => ['', true],
        ] as $case => [$letter, $controls]) {
            $suite->test("Mail preference lookup handles $case independently of SQL casing", static function () use ($user, $db, $joomla, $p, $tag, $case, $letter, $controls) {
                $id = $user('mail-' . str_replace(' ', '-', $case), 'Calendar mail fixture 1!', [2]);
                $stored = $tag . '-' . str_replace(' ', '-', $case) . $letter . '@example.test';
                $requested = mb_strtolower($stored, 'UTF-8');
                if ($controls) {
                    $stored = "\t " . $stored . " \r\n";
                }
                $email = $db->real_escape_string($stored);
                $params = $db->real_escape_string(json_encode(['language' => 'fr-FR'], JSON_THROW_ON_ERROR));
                $db->query("UPDATE {$p}users SET email='{$email}', params='{$params}' WHERE id={$id}");
                $matches = \Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddress($joomla, $requested, 'de-DE') === 'fr-FR';
                if ($letter === "\u{1E9E}") {
                    $other = $user('mail-distinct-ss', 'Calendar mail fixture 1!', [2]);
                    $distinct = str_replace("\u{00DF}", 'ss', $requested);
                    $email = $db->real_escape_string($distinct);
                    $params = $db->real_escape_string(json_encode(['language' => 'en-GB'], JSON_THROW_ON_ERROR));
                    $db->query("UPDATE {$p}users SET email='{$email}', params='{$params}' WHERE id={$other}");
                    $matches = $matches
                        && \Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddress($joomla, $requested, 'de-DE') === 'fr-FR'
                        && \Advans\Component\Appointments\Administrator\Helper\MailLanguage::forAddress($joomla, $distinct, 'de-DE') === 'en-GB';
                }

                return $matches;
            });
        }

        $suite->test('A template of an internet calendar mail cannot be bound to an event type', static function () use ($joomla) {
            $table = new TemplateTable($joomla);
            $table->bind(['eventtype_id' => 5, 'purpose' => 'team_device_added', 'language' => 'en-GB', 'subject' => 'S', 'body' => 'B', 'published' => 1]);
            $refused = $table->check() === false;
            $table = new TemplateTable($joomla);
            $table->bind(['eventtype_id' => 0, 'purpose' => 'team_device_added', 'language' => 'en-US', 'subject' => 'S', 'body' => 'B', 'published' => 1]);

            return $refused && $table->check() === true;
        });

        $suite->test('A Joomla password, a malformed app password and an unknown app password never authenticate', static function () use ($enabled, $tag) {
            $devices = $enabled->devices();

            return $devices->authenticate($tag . 'nobody', 'Correct horse battery staple 1!') === null
                && $devices->authenticate($tag . 'nobody', 'ABCD-EFGH') === null
                && $devices->authenticate($tag . 'nobody', AppPassword::generate()['display']) === null;
        });

        $suite->test('«Add device» without the confirmed usage rule is refused before anything else', static function () use ($enabled, $refusal, $suite, $p) {
            $before = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices");
            $keys = KeyContext::create(999999, 1, random_bytes(32), random_bytes(32));
            $code = $refusal(static fn () => $enabled->devices()->create($keys, 'Phone', 'davx5', false));

            return $code === 403 && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices") === $before;
        });

        $suite->test('«Add device» for an account without second factor or grant creates nothing', static function () use ($enabled, $refusal, $user, $suite, $p) {
            $id = $user('nomfa', 'Nomfa-' . bin2hex(random_bytes(6)) . '!', [2]);
            $keys = KeyContext::create($id, 1, random_bytes(32), random_bytes(32));
            $code = $refusal(static fn () => $enabled->devices()->create($keys, 'Phone', 'davx5', true));

            // 403 from the grant service once it exists, 501 while it is a stub: never a device.
            return \in_array($code, [403, 501], true) && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$id}") === 0;
        });

        $suite->test('«Add device» fails closed when a security check cannot be made', static function () use ($enabled, $user, $suite, $db, $p) {
            $id = $user('broken', 'Broken-' . bin2hex(random_bytes(6)) . '!', [2]);
            $keys = KeyContext::create($id, 1, random_bytes(32), random_bytes(32));
            // The second-factor check cannot read its table: an infrastructure error, not a refusal.
            $db->query("RENAME TABLE {$p}user_mfa TO {$p}user_mfa_teamd");

            try {
                $enabled->devices()->create($keys, 'Phone', 'davx5', true);
                $thrown = false;
            } catch (\Throwable $e) {
                $thrown = true;
            } finally {
                $db->query("RENAME TABLE {$p}user_mfa_teamd TO {$p}user_mfa");
            }

            return $thrown && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$id}") === 0;
        });

        $suite->test('The device list shows no revoked device and no hash, salt or wrapped key', static function () use ($enabled, $user, $device) {
            $id = $user('list', 'List-' . bin2hex(random_bytes(6)) . '!', [2]);
            $active = $device($id, 'Active phone', TeamStates::DEVICE_ACTIVE);
            $device($id, 'Revoked phone', TeamStates::DEVICE_REVOKED);
            $cleanup = $device($id, 'Leaving phone', TeamStates::DEVICE_CLEANUP_EMPTY);
            $rows = $enabled->devices()->listForUser($id);
            $ids = array_map(static fn (object $row): int => $row->id, $rows);
            $columns = array_keys((array) $rows[0]);
            sort($ids);

            return $ids === [$active, $cleanup]
                && $columns === ['id', 'name', 'client', 'state', 'created_utc', 'last_sync_utc'];
        });

        $suite->test('«Last synchronised» is written at most once a minute, the first sync of a calendar and a changed role always, the sync token only rises', static function () use ($enabled, $joomla, $user, $device, $db, $p, $tag, &$state) {
            $id = $user('touch', 'Touch-' . bin2hex(random_bytes(6)) . '!', [2]);
            $deviceId = $device($id, 'Touch phone', TeamStates::DEVICE_ACTIVE);
            $db->query("INSERT INTO {$p}appointments_calendars (uri,title,owner_user_id,row_mac,created_utc,modified_utc) VALUES ('" . bin2hex(random_bytes(16)) . "','{$tag} touch',{$id},X'" . bin2hex(random_bytes(32)) . "',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $calendarId = (int) $db->insert_id;
            $state['calendars'][] = $calendarId;
            $session = new DeviceSession($deviceId, $id, $tag . 'touch', TeamStates::DEVICE_ACTIVE, KeyContext::create($id, 1, random_bytes(32), random_bytes(32)));
            $enabled->devices()->touch($session, $calendarId, 3, 7);
            $first = $db->query("SELECT last_sync_utc FROM {$p}appointments_devices WHERE id = {$deviceId}")->fetch_assoc()['last_sync_utc'];
            $pair = $db->query("SELECT last_sync_utc, role FROM {$p}appointments_device_calendars WHERE device_id = {$deviceId} AND calendar_id = {$calendarId}")->fetch_assoc();
            // A time in the past within the minute: a second sync keeps it.
            $db->query("UPDATE {$p}appointments_devices SET last_sync_utc = UTC_TIMESTAMP() - INTERVAL 30 SECOND WHERE id = {$deviceId}");
            $kept = $db->query("SELECT last_sync_utc FROM {$p}appointments_devices WHERE id = {$deviceId}")->fetch_assoc()['last_sync_utc'];
            $later = new TeamServices($joomla, $enabled->keyProvider(), $enabled->params(), static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            $later->devices()->touch($session, $calendarId, 4, 5);
            $tokens = $db->query("SELECT synctoken, role FROM {$p}appointments_device_calendars WHERE device_id = {$deviceId} AND calendar_id = {$calendarId}")->fetch_assoc();
            $same = $db->query("SELECT last_sync_utc FROM {$p}appointments_devices WHERE id = {$deviceId}")->fetch_assoc()['last_sync_utc'];
            $db->query("UPDATE {$p}appointments_devices SET last_sync_utc = UTC_TIMESTAMP() - INTERVAL 2 MINUTE WHERE id = {$deviceId}");
            $old = $db->query("SELECT last_sync_utc FROM {$p}appointments_devices WHERE id = {$deviceId}")->fetch_assoc()['last_sync_utc'];
            (new TeamServices($joomla, $enabled->keyProvider(), $enabled->params(), static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))))->devices()->touch($session, null);
            $updated = $db->query("SELECT last_sync_utc FROM {$p}appointments_devices WHERE id = {$deviceId}")->fetch_assoc()['last_sync_utc'];

            return $first !== null && $pair !== null && (int) $pair['role'] === 3 && $same === $kept && $updated !== $old
                // The sync token only rises, the role is the latest one, written at once.
                && (int) $tokens['synctoken'] === 7 && (int) $tokens['role'] === 4;
        });

        // ------------------------------------------------------------------
        // Part 2: the web flow with a real second factor
        // ------------------------------------------------------------------

        $stubs = [];

        foreach (['B' => [KeySession::class, KeyService::class, EpochAnchor::class], 'C' => [GrantService::class, CalendarService::class, InvitationService::class], 'F' => [AuditService::class, CleanupService::class, LockoutService::class]] as $package => $classes) {
            foreach ($classes as $class) {
                if ($suite->isStub($class)) {
                    $stubs[$package] = true;
                }
            }
        }

        $flow = [
            'D: «Add device» from a form issued earlier is refused once the grant is gone',
            'D: «Add device» needs the confirmed usage rule',
            'D: «Add device» after sign-in with password and second factor shows the app password once, no-store',
            'D: The confirmation and its rule version are in the device row and the log',
            'D: The user receives a mail for the new device, without the app password',
            'D: A second submission of the same form creates no second device and shows no password',
            'D: The app password authenticates the device, the Joomla password never does',
            'D: Without the key in the session «Add device» answers 423',
            'D: «Remove device» starts the clean-up and raises the device epoch',
            'D: A failing security check during «Add device» creates no device and shows only a general message',
            'D: The activation link is exchanged for a session value and the browser continues without the token',
            'D: The form value of the exchange is useless in another session',
            'D: The activation refuses a password that breaks the rules or does not match',
            'D: The activation sets the password and continues at the sign-in',
            'D: A used activation link answers 404',
            'D: A revoked device row played back into the database does not authenticate',
            'D: A device row and user key row rolled back together are refused by the anchor',
            'D: A revocation inside a transaction that rolls back leaves the anchor below the database',
            'D: A device whose owner was changed in the database stays revoked for its real owner',
            'D: A device row with a broken MAC is refused without an exception',
            'D: A user key row with a broken MAC refuses every device and «Add device»',
            'D: An eligible member opens «My calendars» through its menu item',
            'D: A guest gets 404 for «My calendars» and every device task while the feature is on',
            'D: A signed-in account outside the internet calendar groups gets 403 for the page and the device layout, devices.display is 404',
            'D: A Super User outside the internet calendar groups gets 403 for «My calendars»',
            'D: «Add device» refuses a signed-in account outside the groups with 403 and creates nothing',
            'D: «Rename device» refuses a signed-in account outside the groups with 403 and changes nothing',
            'D: «Remove device» refuses a signed-in account outside the groups with 403 and changes nothing',
            'D: A member without the configured access level gets 403 for the page and every device task',
        ];

        if ($stubs !== []) {
            foreach ($flow as $name) {
                $suite->pending($name, implode(', ', array_keys($stubs)));
            }

            return;
        }

        $params = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc()['params'];
        $state['params'] = $params;
        $decoded = json_decode((string) $params, true) ?: [];
        $teamGroup = (int) (($decoded['team_groups'] ?? [])[0] ?? 0);
        $decoded['teamcal_enabled'] = 1;
        $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string(json_encode($decoded)) . "' WHERE type='component' AND element='com_appointments'");

        foreach ([['user', 'appointments'], ['multifactorauth', 'totp']] as [$folder, $element]) {
            $row = $db->query("SELECT extension_id, enabled FROM {$p}extensions WHERE type='plugin' AND folder='{$folder}' AND element='{$element}'")->fetch_assoc();
            $state['plugins'][] = [(int) $row['extension_id'], (int) $row['enabled']];
            $db->query("UPDATE {$p}extensions SET enabled=1 WHERE extension_id=" . (int) $row['extension_id']);
        }

        // The component options of this process were read before the feature was switched on:
        // the services get the options as stored now, not the cached ones.
        $services = new TeamServices($joomla, $enabled->keyProvider(), new Registry($decoded), static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $password = 'Member-' . bin2hex(random_bytes(8)) . '!';
        $member = $user('member', $password, [$teamGroup]);
        $admin = (int) $db->query("SELECT id FROM {$p}users WHERE username='" . $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin') . "'")->fetch_assoc()['id'];

        // A TOTP method for the member, stored through Joomla's own table (it encrypts the options).
        $secret = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        $mfa = \Joomla\CMS\Factory::getApplication()->bootComponent('com_users')->getMVCFactory()->createTable('Mfa', 'Administrator');
        $mfa->save(['user_id' => $member, 'title' => 'Test', 'method' => 'totp', 'default' => 1, 'options' => ['key' => $secret]]);

        if ($suite->count("SELECT COUNT(*) FROM {$p}appointments_keyring WHERE purpose = 'recovery'") === 0) {
            $services->keys()->createRecoveryKey($admin);
            $state['recovery'] = true;
        }

        // Calendar creation needs the owner's public key before the browser session releases
        // its escrow. Seed that fixture separately; device creation still needs real password/MFA sign-in.
        $services->keys()->createUserKey($member, $password)->wipe();

        $signIn = static function (TeamDBrowser $browser, string $username, string $password, string $secret): bool {
            $login = TeamDBrowser::form($browser->request('/index.php?option=com_users&view=login'), 'user.login');

            if ($login === null) {
                return false;
            }

            $html = $browser->request($login['action'], $login['fields'] + ['username' => $username, 'password' => $password]);
            $captive = TeamDBrowser::form($html, 'captive.validate');

            if ($captive === null) {
                return false;
            }

            $browser->request($captive['action'], $captive['fields'] + ['code' => team_d_totp($secret, time())]);

            return true;
        };
        $page = static fn (TeamDBrowser $browser): string => $browser->request($myCalendars);
        $submit = static function (TeamDBrowser $browser, string $html, array $fields) {
            $form = TeamDBrowser::form($html, 'devices.create');

            return $form === null ? '' : $browser->request($form['action'], $form['fields'] + $fields, 0);
        };
        $browser = new TeamDBrowser($suite->base);
        $signedIn = $signIn($browser, $tag . 'member', $password, $secret);
        $deviceName = $tag . ' phone';
        $created = '';
        $createdPage = '';
        $mails = [];

        $calendar = $services->calendars()->create($admin, ['title' => $tag . ' calendar', 'owner_user_id' => $member]);
        $state['calendars'][] = $calendar;

        $suite->test($flow[0], static function () use ($browser, $page, $signedIn, $suite, $db, $p, $member, $calendar) {
            // The page offered the form while the grant existed; the grant goes before it is sent.
            $form = $signedIn ? TeamDBrowser::form($page($browser), 'devices.create') : null;
            $grant = $db->query("SELECT * FROM {$p}appointments_calendar_grants WHERE calendar_id = {$calendar} AND user_id = {$member}")->fetch_assoc();

            if ($form === null || $grant === null) {
                return false;
            }

            $db->query("DELETE FROM {$p}appointments_calendar_grants WHERE id = " . (int) $grant['id']);

            try {
                $browser->request($form['action'], $form['fields'] + ['name' => 'Ungranted', 'client' => 'davx5', 'rule' => 1]);
                $count = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}");
            } finally {
                // The same row back, byte for byte, so its MAC stays valid for the next tests.
                $values = array_map(static fn ($v): string => $v === null ? 'NULL' : "'" . $db->real_escape_string((string) $v) . "'", $grant);
                $db->query("INSERT INTO {$p}appointments_calendar_grants (" . implode(',', array_map(static fn (string $k): string => '`' . $k . '`', array_keys($grant))) . ') VALUES (' . implode(',', $values) . ')');
            }

            return $count === 0;
        });

        $suite->test($flow[1], static function () use ($browser, $page, $submit, $suite, $p, $member, $deviceName) {
            $html = $page($browser);
            $submit($browser, $html, ['name' => $deviceName, 'client' => 'davx5']);

            return str_contains($html, 'name="rule"') && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === 0;
        });

        $suite->test($flow[2], static function () use ($browser, $page, $deviceName, &$created, &$createdPage) {
            $html = $page($browser);
            $form = TeamDBrowser::form($html, 'devices.create');

            if ($form === null) {
                return false;
            }

            $before = AppointmentsMail::snapshot();
            $createdPage = $browser->request($form['action'], $form['fields'] + ['name' => $deviceName, 'client' => 'davx5', 'rule' => 1], 0);
            $GLOBALS['teamDMails'] = AppointmentsMail::messagesSince($before);
            $GLOBALS['teamDForm'] = $form;
            preg_match('/id="appointments-app-password"[^>]*>([A-Z2-7]{4}(?:-[A-Z2-7]{4}){7})</', $createdPage, $m);
            $created = $m[1] ?? '';

            return $created !== '' && str_contains($browser->header('Cache-Control'), 'no-store')
                && $browser->header('Referrer-Policy') === 'no-referrer'
                // The outcome takes the focus, so it is in view and read out below template modules.
                && preg_match('#<p class="com-appointments-status" role="status" tabindex="-1" autofocus data-appointments-result>#', $createdPage) === 1
                && !str_contains($page($browser), $created);
        });

        $suite->test($flow[3], static function () use ($db, $p, $member) {
            $row = $db->query("SELECT id, rule_version, rule_accepted_utc, state FROM {$p}appointments_devices WHERE user_id = {$member}")->fetch_assoc();
            $log = $row ? $db->query("SELECT details FROM {$p}appointments_teamlog WHERE event = 'device.created' AND subject_user_id = {$member} ORDER BY id DESC LIMIT 1")->fetch_assoc() : null;
            $details = json_decode((string) ($log['details'] ?? ''), true) ?: [];

            return $row !== null && $row['rule_version'] === TeamStates::DEVICE_RULE_VERSION && $row['rule_accepted_utc'] !== null
                && (int) $row['state'] === TeamStates::DEVICE_ACTIVE
                && (string) ($details['rule_version'] ?? '') === TeamStates::DEVICE_RULE_VERSION
                && (int) ($details['device_id'] ?? 0) === (int) $row['id'];
        });

        $suite->test($flow[4], static function () use ($tag, $deviceName, &$created) {
            $mails = $GLOBALS['teamDMails'] ?? [];

            foreach ($mails as $mime) {
                $text = AppointmentsMail::decoded($mime);

                if (str_contains($mime, $tag . 'member@example.test') && str_contains($text, $deviceName)) {
                    return $created !== '' && !str_contains($text, $created) && !str_contains($text, str_replace('-', '', $created))
                        && !preg_match('/Content-Type:\s*text\/calendar/i', $mime);
                }
            }

            return false;
        });

        $suite->test($flow[5], static function () use ($browser, $suite, $p, $member) {
            $form = $GLOBALS['teamDForm'] ?? null;

            if ($form === null) {
                return false;
            }

            $html = $browser->request($form['action'], $form['fields'] + ['name' => 'Second ' . bin2hex(random_bytes(2)), 'client' => 'davx5', 'rule' => 1]);

            return $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === 1
                && !preg_match('/id="appointments-app-password"/', $html);
        });

        $suite->test($flow[6], static function () use ($services, $tag, $password, &$created, &$state, $db, $p, $member) {
            $session = $created !== '' ? $services->devices()->authenticate(strtoupper($tag) . 'MEMBER', strtolower($created)) : null;
            $state['devices'] = array_merge($state['devices'], array_map('intval', array_column($db->query("SELECT id FROM {$p}appointments_devices WHERE user_id = {$member}")->fetch_all(MYSQLI_ASSOC), 'id')));

            return $session !== null && $session->isActive() && $session->userId === $member
                && $services->devices()->authenticate($tag . 'member', $password) === null
                && $services->devices()->authenticate($tag . 'other', $created) === null;
        });

        // Replay protection (5.11): the device epoch and its anchor.
        $rowOf = static fn (string $table, string $where): ?array => $db->query("SELECT * FROM {$p}{$table} WHERE {$where}")->fetch_assoc() ?: null;
        $restore = static function (string $table, array $row, string $key) use ($db, $p): void {
            $sets = [];

            foreach ($row as $column => $value) {
                $sets[] = '`' . $column . '` = ' . ($value === null ? 'NULL' : "'" . $db->real_escape_string((string) $value) . "'");
            }

            $db->query("UPDATE {$p}{$table} SET " . implode(', ', $sets) . " WHERE `{$key}` = '" . $db->real_escape_string((string) $row[$key]) . "'");
        };
        $probe = static function (string $name) use ($services, $member, $password): array {
            $keys = $services->keys()->unlockWithPassword($member, $password);

            return $keys === null ? [] : $services->devices()->create($keys, $name, 'other', true);
        };

        $suite->test($flow[15], static function () use ($services, $probe, $rowOf, $restore, $tag) {
            $made = $probe($tag . ' replay');

            if ($made === []) {
                return false;
            }

            $saved = $rowOf('appointments_devices', 'id = ' . (int) $made['device_id']);
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');
            $restore('appointments_devices', $saved, 'id');
            $refused = $services->devices()->authenticate($tag . 'member', $made['app_password']) === null;
            // The played-back row says «active»: revoke it again, so later tests see only real devices.
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');

            return $refused;
        });

        $suite->test($flow[16], static function () use ($services, $probe, $rowOf, $restore, $tag, $member, &$created) {
            $made = $probe($tag . ' rollback');

            if ($made === []) {
                return false;
            }

            $device = $rowOf('appointments_devices', 'id = ' . (int) $made['device_id']);
            $key = $rowOf('appointments_user_keys', 'user_id = ' . $member);
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');
            $after = $rowOf('appointments_user_keys', 'user_id = ' . $member);
            // A database rolled back as a whole: the old key row with its older epoch and the active device.
            $restore('appointments_user_keys', $key, 'user_id');
            $restore('appointments_devices', $device, 'id');
            $refused = $services->devices()->authenticate($tag . 'member', $made['app_password']) === null;
            $restore('appointments_user_keys', $after, 'user_id');
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');

            return $refused && $services->devices()->authenticate($tag . 'member', $created) !== null;
        });

        $suite->test($flow[17], static function () use ($services, $probe, $joomla, $tag, $member, &$created, $suite, $p) {
            $made = $probe($tag . ' outer');

            if ($made === []) {
                return false;
            }

            $before = $suite->count("SELECT device_epoch FROM {$p}appointments_user_keys WHERE user_id = {$member}");
            $joomla->transactionStart();
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');
            $joomla->transactionRollback();

            // The epoch is back where it was, the anchor did not run ahead, the devices still work.
            $ok = $suite->count("SELECT device_epoch FROM {$p}appointments_user_keys WHERE user_id = {$member}") === $before
                && $services->anchor()->minimum(\Advans\Component\Appointments\Administrator\Service\Team\EpochAnchorInterface::KIND_USER, $member) <= $before
                && $services->devices()->authenticate($tag . 'member', $created) !== null;
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');

            return $ok;
        });

        $suite->test($flow[18], static function () use ($services, $probe, $rowOf, $restore, $tag, $db, $p, $user) {
            $made = $probe($tag . ' owner');

            if ($made === []) {
                return false;
            }

            $other = $user('other', 'Other-' . bin2hex(random_bytes(8)) . '!', [2]);
            $saved = $rowOf('appointments_devices', 'id = ' . (int) $made['device_id']);
            $db->query("UPDATE {$p}appointments_devices SET user_id = {$other} WHERE id = " . (int) $made['device_id']);
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');
            $restore('appointments_devices', $saved, 'id');
            $refused = $services->devices()->authenticate($tag . 'member', $made['app_password']) === null;
            $services->devices()->revoke(null, (int) $made['device_id'], 'test');

            return $refused;
        });

        $suite->test($flow[19], static function () use ($services, $probe, $tag, $db, $p) {
            $made = $probe($tag . ' broken');

            if ($made === []) {
                return false;
            }

            $db->query("UPDATE {$p}appointments_devices SET row_mac = X'" . bin2hex(random_bytes(32)) . "' WHERE id = " . (int) $made['device_id']);

            try {
                $refused = $services->devices()->authenticate($tag . 'member', $made['app_password']) === null;
            } catch (\Throwable $e) {
                $refused = false;
            }

            $services->devices()->revoke(null, (int) $made['device_id'], 'test');

            return $refused;
        });

        $suite->test($flow[20], static function () use ($services, $rowOf, $restore, $tag, $db, $p, $member, $password, &$created, $refusal, $suite) {
            $keys = $services->keys()->unlockWithPassword($member, $password);
            $key = $rowOf('appointments_user_keys', 'user_id = ' . $member);

            if ($keys === null || $key === null) {
                return false;
            }

            $db->query("UPDATE {$p}appointments_user_keys SET row_mac = X'" . bin2hex(random_bytes(32)) . "' WHERE user_id = {$member}");

            try {
                $refusedDevice = $services->devices()->authenticate($tag . 'member', $created) === null;
                $devicesBefore = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}");
                // The key service refuses the changed row (409) before the device service's own check (403).
                $refusedAdd = \in_array($refusal(static fn () => $services->devices()->create($keys, $tag . ' broken key', 'other', true)), [403, 409], true)
                    && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === $devicesBefore;
            } catch (\Throwable $e) {
                $refusedDevice = $refusedAdd = false;
            } finally {
                $restore('appointments_user_keys', $key, 'user_id');
            }

            return $refusedDevice && $refusedAdd && $services->devices()->authenticate($tag . 'member', $created) !== null;
        });

        $suite->test($flow[7], static function () use ($suite, $p, $member, $tag, $password, $secret, $signIn, $page) {
            // A session of its own: the key and factor probes above may have closed the first one's keys.
            $browser = new TeamDBrowser($suite->base);
            $signIn($browser, $tag . 'member', $password, $secret);
            $form = TeamDBrowser::form($page($browser), 'devices.create');

            if ($form === null) {
                echo '    keyless add: page answered ' . $browser->status . " without the «Add device» form\n";

                return false;
            }

            // Over HTTPS the key cookie carries the __Host- prefix.
            $browser->dropCookie('appointments_keys_site');
            $browser->dropCookie('__Host-appointments_keys_site');
            $before = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}");
            $html = $browser->request($form['action'], $form['fields'] + ['name' => 'Locked phone', 'client' => 'davx5', 'rule' => 1], 0);
            // No «Add device» form: only that form carries the one-time form value; other forms of
            // the page (e.g. the sign-out module) may use the requested address …task=devices.create.
            $noForm = !preg_match('/name="form_nonce"/', $html);
            $noPassword = !preg_match('/id="appointments-app-password"/', $html);
            $unchanged = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === $before;

            if ($browser->status !== 423 || !($noForm && $noPassword && $unchanged)) {
                echo '    keyless add: answered ' . $browser->status . ($browser->header('Location') === '' ? ' without' : ' with') . ' Location, no form '
                    . (int) $noForm . ', no password ' . (int) $noPassword . ', unchanged ' . (int) $unchanged . "\n";
            }

            return $browser->status === 423 && $noForm && $noPassword && $unchanged;
        });
        $suite->test($flow[8], static function () use ($suite, $p, $member, $tag, $password, $secret, $signIn, $page, $myCalendars) {
            $browser = new TeamDBrowser($suite->base);
            $signIn($browser, $tag . 'member', $password, $secret);
            $id = (int) $suite->count("SELECT id FROM {$p}appointments_devices WHERE user_id = {$member} AND state = 1 ORDER BY id LIMIT 1");
            $html = $browser->request($myCalendars . '&layout=device&id=' . $id);
            $form = TeamDBrowser::form($html, 'devices.remove');

            if ($form === null) {
                return false;
            }

            $epoch = $suite->count("SELECT device_epoch FROM {$p}appointments_user_keys WHERE user_id = {$member}");
            $browser->request($form['action'], $form['fields'] + ['confirm' => 1]);

            // The clean-up step raised the user's device epoch (replay protection, 5.11).
            return $suite->count("SELECT state FROM {$p}appointments_devices WHERE id = {$id}") === TeamStates::DEVICE_CLEANUP_EMPTY
                && $suite->count("SELECT device_epoch FROM {$p}appointments_user_keys WHERE user_id = {$member}") > $epoch;
        });

        $suite->test($flow[9], static function () use ($suite, $p, $member, $tag, $password, $secret, $signIn, $page, $db) {
            $browser = new TeamDBrowser($suite->base);
            $signIn($browser, $tag . 'member', $password, $secret);
            $form = TeamDBrowser::form($page($browser), 'devices.create');

            if ($form === null) {
                return false;
            }

            $before = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}");
            $db->query("RENAME TABLE {$p}user_mfa TO {$p}user_mfa_teamd");

            try {
                $html = $browser->request($form['action'], $form['fields'] + ['name' => 'Broken phone', 'client' => 'davx5', 'rule' => 1], 0);
                $status = $browser->status;
            } finally {
                $db->query("RENAME TABLE {$p}user_mfa_teamd TO {$p}user_mfa");
            }

            // The answer is a redirect back to the page, which shows the general message.
            $html .= $status >= 300 && $status < 400 ? $browser->request($browser->header('Location')) : '';

            $unchanged = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === $before;
            $noPassword = !preg_match('/id="appointments-app-password"/', $html);
            $noDetail = !str_contains($html, 'user_mfa') && !str_contains($html, 'SQL');

            if (!($unchanged && $noPassword && $noDetail)) {
                echo '    security check: answered ' . $status . ', final ' . $browser->status . ', unchanged ' . (int) $unchanged
                    . ', no password ' . (int) $noPassword . ', no detail ' . (int) $noDetail . "\n";
            }

            return $unchanged && $noPassword && $noDetail;
        });

        // ------------------------------------------------------------------
        // Access: only accounts that may use internet calendars, on the server,
        // also at the address without a menu item (the test site has none).
        // ------------------------------------------------------------------

        $tokenOf = static function (TeamDBrowser $browser): string {
            // The sign-in page carries the form token of this session (sign-in or sign-out form).
            $html = $browser->request('/index.php?option=com_users&view=login');

            return preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m) === 1 ? $m[1] : '';
        };
        $tasks = static function (TeamDBrowser $browser, string $token, int $deviceId): array {
            $statuses = [];

            foreach ([
                'devices.create' => ['name' => 'Refused phone', 'client' => 'davx5', 'rule' => 1, 'rule_version' => TeamStates::DEVICE_RULE_VERSION, 'form_nonce' => bin2hex(random_bytes(16))],
                'devices.rename' => ['id' => $deviceId, 'name' => 'Refused rename'],
                'devices.remove' => ['id' => $deviceId, 'confirm' => 1],
            ] as $task => $fields) {
                $browser->request('/index.php?option=com_appointments&task=' . $task, [$token => 1] + $fields, 0);
                $statuses[$task] = $browser->status;
            }

            return $statuses;
        };
        $deviceRow = static fn (int $id): ?array => $db->query("SELECT name, state, row_mac FROM {$p}appointments_devices WHERE id = {$id}")->fetch_assoc() ?: null;
        $outsiders = [];

        foreach (['outsider' => [2], 'superuser' => [8]] as $name => $groups) {
            $outsiderPassword = 'Outsider-' . bin2hex(random_bytes(8)) . '!';
            $outsiderId = $user($name, $outsiderPassword, $groups);
            $outsiderMfa = \Joomla\CMS\Factory::getApplication()->bootComponent('com_users')->getMVCFactory()->createTable('Mfa', 'Administrator');
            $outsiderMfa->save(['user_id' => $outsiderId, 'title' => 'Test', 'method' => 'totp', 'default' => 1, 'options' => ['key' => $secret]]);
            $outsiders[$name] = ['id' => $outsiderId, 'password' => $outsiderPassword, 'device' => $device($outsiderId, $tag . ' ' . $name . ' phone', TeamStates::DEVICE_ACTIVE)];
        }

        $outsiderBrowser = new TeamDBrowser($suite->base);
        $outsiderSignedIn = $signIn($outsiderBrowser, $tag . 'outsider', $outsiders['outsider']['password'], $secret);
        $outsiderToken = $outsiderSignedIn ? $tokenOf($outsiderBrowser) : '';
        // Taken before the refused requests, so a change made by any of them is detected.
        $outsiderBefore = $deviceRow($outsiders['outsider']['device']);
        $outsiderStatuses = $outsiderToken !== '' ? $tasks($outsiderBrowser, $outsiderToken, $outsiders['outsider']['device']) : [];

        $suite->test($flow[21], static function () use ($suite, $tag, $password, $secret, $signIn, $myCalendars) {
            $browser = new TeamDBrowser($suite->base);
            $signIn($browser, $tag . 'member', $password, $secret);
            $html = $browser->request($myCalendars);

            if ($browser->status !== 200) {
                echo '    eligible member: page answered ' . $browser->status . "\n";
            }

            return $browser->status === 200 && str_contains($html, 'appointments');
        });

        $suite->test($flow[22], static function () use ($suite, $tokenOf, $tasks, $outsiders, $myCalendars) {
            $guest = new TeamDBrowser($suite->base);
            $guest->request($myCalendars);
            $page = $guest->status;
            $token = $tokenOf($guest);
            $statuses = $token !== '' ? $tasks($guest, $token, $outsiders['outsider']['device']) : [];

            if ($page !== 404 || array_values(array_unique($statuses)) !== [404]) {
                echo '    guest: page ' . $page . ', tasks ' . json_encode($statuses) . "\n";
            }

            return $page === 404 && \count($statuses) === 3 && array_values(array_unique($statuses)) === [404];
        });

        $suite->test($flow[23], static function () use ($outsiderBrowser, $outsiderSignedIn, $outsiders, $myCalendars) {
            if (!$outsiderSignedIn) {
                return false;
            }

            $statuses = [];

            foreach ([
                'page' => $myCalendars,
                'device' => $myCalendars . '&layout=device&id=' . $outsiders['outsider']['device'],
                // No task of the site displays a view: the dispatcher answers 404 for everyone (MenuViews).
                'display' => '/index.php?option=com_appointments&task=devices.display',
            ] as $key => $path) {
                $html = $outsiderBrowser->request($path);
                // Joomla's error page with the «not authorised» message, never an empty page or the form.
                $content = trim($html) !== '' && !str_contains($html, 'devices.create') && !str_contains($html, 'form_nonce');
                $statuses[$key] = $outsiderBrowser->status . ($content ? '' : ' (empty or with the form)');
            }

            $expected = ['page' => '403', 'device' => '403', 'display' => '404'];

            if ($statuses !== $expected) {
                echo '    outsider: ' . json_encode($statuses) . "\n";
            }

            return $statuses === $expected;
        });

        $suite->test($flow[24], static function () use ($suite, $tag, $secret, $signIn, $outsiders, $myCalendars) {
            $browser = new TeamDBrowser($suite->base);

            if (!$signIn($browser, $tag . 'superuser', $outsiders['superuser']['password'], $secret)) {
                return false;
            }

            $browser->request($myCalendars);

            return $browser->status === 403;
        });

        foreach (['devices.create' => 25, 'devices.rename' => 26, 'devices.remove' => 27] as $task => $index) {
            $suite->test($flow[$index], static function () use ($task, $outsiderStatuses, $outsiderBefore, $deviceRow, $suite, $p, $outsiders) {
                $id = $outsiders['outsider']['id'];
                $unchanged = $outsiderBefore !== null && $deviceRow($outsiders['outsider']['device']) === $outsiderBefore
                    && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$id}") === 1;

                if (($outsiderStatuses[$task] ?? 0) !== 403 || !$unchanged) {
                    echo '    outsider ' . $task . ': ' . ($outsiderStatuses[$task] ?? 'not sent') . ', unchanged ' . (int) $unchanged . "\n";
                }

                return ($outsiderStatuses[$task] ?? 0) === 403 && $unchanged;
            });
        }

        $suite->test($flow[28], static function () use ($suite, $db, $p, $tag, $password, $secret, $signIn, $tokenOf, $tasks, $decoded, $member, $deviceRow, $myCalendars) {
            // A level that holds no group: the member keeps the team group but lacks the level.
            $db->query("INSERT INTO {$p}viewlevels (title, ordering, rules) VALUES ('" . $db->real_escape_string($tag . ' level') . "', 0, '[]')");
            $level = (int) $db->insert_id;
            $restricted = $decoded;
            $restricted['team_viewlevel'] = $level;
            $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string(json_encode($restricted)) . "' WHERE type='component' AND element='com_appointments'");
            $deviceId = (int) $suite->count("SELECT id FROM {$p}appointments_devices WHERE user_id = {$member} ORDER BY id LIMIT 1");
            $before = $deviceId > 0 ? $deviceRow($deviceId) : null;
            $count = $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}");

            try {
                $browser = new TeamDBrowser($suite->base);
                $signIn($browser, $tag . 'member', $password, $secret);
                $browser->request($myCalendars);
                $page = $browser->status;
                $token = $tokenOf($browser);
                $statuses = $token !== '' && $deviceId > 0 ? $tasks($browser, $token, $deviceId) : [];
            } finally {
                $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string(json_encode($decoded)) . "' WHERE type='component' AND element='com_appointments'");
                $db->query("DELETE FROM {$p}viewlevels WHERE id = {$level}");
            }

            $unchanged = $before !== null && $deviceRow($deviceId) === $before
                && $suite->count("SELECT COUNT(*) FROM {$p}appointments_devices WHERE user_id = {$member}") === $count;

            if ($page !== 403 || array_values(array_unique($statuses)) !== [403] || !$unchanged) {
                echo '    member without level: page ' . $page . ', tasks ' . json_encode($statuses) . ', unchanged ' . (int) $unchanged . "\n";
            }

            return $page === 403 && \count($statuses) === 3 && array_values(array_unique($statuses)) === [403] && $unchanged;
        });

        // ------------------------------------------------------------------
        // Account activation (an administrator created the account)
        // ------------------------------------------------------------------

        // Team accounts are invited only by core.admin with a real second factor: a temporary
        // Super User of this suite with its own TOTP method, removed with the other fixture users.
        $inviter = $user('inviter', 'Unused-' . bin2hex(random_bytes(12)) . '!', [8]);
        $inviterMfa = \Joomla\CMS\Factory::getApplication()->bootComponent('com_users')->getMVCFactory()->createTable('Mfa', 'Administrator');
        $inviterMfa->save(['user_id' => $inviter, 'title' => 'Test', 'method' => 'totp', 'default' => 1, 'options' => ['key' => $secret]]);
        $fresh = $user('fresh', 'Unused-' . bin2hex(random_bytes(12)) . '!', [$teamGroup]);
        $hashBefore = (string) $db->query("SELECT password FROM {$p}users WHERE id = {$fresh}")->fetch_assoc()['password'];
        $mailsBefore = AppointmentsMail::snapshot();
        $services->invitations()->invite($inviter, $fresh);
        $link = '';

        foreach (AppointmentsMail::messagesSince($mailsBefore) as $mime) {
            if (preg_match('#https://[^\s"<>]*token=[A-Za-z0-9_-]{43}[^\s"<>]*#', AppointmentsMail::decoded($mime), $m) === 1
                && str_contains($m[0], 'activation')) {
                $link = html_entity_decode($m[0]);
            }
        }

        // The browser follows the complete link of the mail, as the invited person does: HTTPS,
        // the site address, path and query exactly as sent. Only the masked form is printed.
        $linkPath = $link !== '' && str_starts_with($link, 'https://') && preg_match('/[?&]token=[A-Za-z0-9_-]{43}(?:&|$)/', $link) === 1
            ? $link
            : '';

        if ($linkPath === '') {
            echo '    activation: no HTTPS activation link in ' . \count(AppointmentsMail::messagesSince($mailsBefore)) . " new mail(s)\n";
        } else {
            echo '    activation: mail link ' . (preg_replace(['/token=[^&\s]*/', '#^https://[^/]*#'], ['token=[masked]', 'https://[host]'], $link) ?? '') . "\n";
        }

        $activation = new TeamDBrowser($suite->base);
        $activationForm = null;

        $suite->test($flow[10], static function () use ($activation, $linkPath, &$activationForm) {
            if ($linkPath === '' || str_contains($linkPath, 'index.php/index.php')) {
                return false;
            }

            $activation->request($linkPath, null, 0);
            $location = $activation->header('Location');

            // A canonical-address redirect (e.g. strict SEF routing) keeps the query; a browser follows it.
            for ($hop = 0; $hop < 3 && \in_array($activation->status, [301, 302, 307, 308], true) && str_contains($location, 'token='); $hop++) {
                $activation->request($location, null, 0);
                $location = $activation->header('Location');
            }

            if ($activation->status !== 303 || $location === '' || str_contains($location, 'token=')) {
                // Never the token itself: only where the answer points to.
                echo '    activation: exchange answered ' . $activation->status . ' to '
                    . (preg_replace('/token=[^&\s]*/', 'token=[masked]', $location) ?: 'no Location') . "\n";

                return false;
            }

            $html = $activation->request($location);
            $activationForm = TeamDBrowser::form($html, 'activation.complete');

            if ($activationForm === null) {
                echo '    activation: page answered ' . $activation->status . " without the activation form\n";
            }

            return $activationForm !== null && !isset($activationForm['fields']['token']) && isset($activationForm['fields']['nonce'])
                && str_contains($activation->header('Cache-Control'), 'no-store') && $activation->header('Referrer-Policy') === 'no-referrer';
        });

        $suite->test($flow[11], static function () use ($suite, &$activationForm, $db, $p, $fresh, $hashBefore) {
            if ($activationForm === null) {
                return false;
            }

            $other = new TeamDBrowser($suite->base);
            $otherForm = TeamDBrowser::form($other->request('/index.php?option=com_users&view=login'), 'user.login');
            $token = array_key_first(array_filter($otherForm['fields'] ?? [], static fn ($v, $k): bool => $v === '1' && preg_match('/^[a-f0-9]{32}$/', (string) $k) === 1, ARRAY_FILTER_USE_BOTH));
            $strong = 'Strong-' . bin2hex(random_bytes(10)) . '!Aa1';
            $other->request($activationForm['action'], [(string) $token => 1, 'nonce' => $activationForm['fields']['nonce'], 'password1' => $strong, 'password2' => $strong], 0);

            return $other->status === 404 && (string) $db->query("SELECT password FROM {$p}users WHERE id = {$fresh}")->fetch_assoc()['password'] === $hashBefore;
        });

        $suite->test($flow[12], static function () use ($activation, &$activationForm, $db, $p, $fresh, $hashBefore) {
            if ($activationForm === null) {
                return false;
            }

            foreach ([['0', '0'], ['Strong-' . bin2hex(random_bytes(10)) . '!Aa1', 'Different-' . bin2hex(random_bytes(10)) . '!Aa1']] as [$one, $two]) {
                $html = $activation->request($activationForm['action'], $activationForm['fields'] + ['password1' => $one, 'password2' => $two]);
                $activationForm = TeamDBrowser::form($html, 'activation.complete') ?? $activationForm;
            }

            return (string) $db->query("SELECT password FROM {$p}users WHERE id = {$fresh}")->fetch_assoc()['password'] === $hashBefore;
        });

        $suite->test($flow[13], static function () use ($activation, &$activationForm, $db, $p, $fresh) {
            if ($activationForm === null) {
                return false;
            }

            $strong = 'Strong-' . bin2hex(random_bytes(10)) . '!Aa1';
            $activation->request($activationForm['action'], $activationForm['fields'] + ['password1' => $strong, 'password2' => $strong], 0);
            $location = $activation->header('Location');

            return $activation->status >= 300 && $activation->status < 400 && (str_contains($location, 'com_users') || str_contains($location, 'login'))
                && password_verify($strong, (string) $db->query("SELECT password FROM {$p}users WHERE id = {$fresh}")->fetch_assoc()['password']);
        });

        $suite->test($flow[14], static function () use ($suite, $linkPath) {
            $again = new TeamDBrowser($suite->base);
            $again->request($linkPath);

            return $linkPath !== '' && $again->status === 404;
        });
    } finally {
        // Own rows only; the log keeps its rows so that the chain of part F still verifies.
        foreach ($state['calendars'] as $id) {
            $db->query("UPDATE {$p}appointments_eventtypes SET calendar_id = NULL WHERE calendar_id = " . (int) $id);
            $db->query("DELETE FROM {$p}appointments_calendars WHERE id = " . (int) $id);
        }

        foreach ($state['users'] as $id) {
            $db->query("DELETE FROM {$p}appointments_devices WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}appointments_user_keys WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}appointments_calendar_grants WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}user_mfa WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}appointments_invitations WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}user_usergroup_map WHERE user_id = {$id}");
            $db->query("DELETE FROM {$p}session WHERE userid = {$id}");
            $db->query("DELETE FROM {$p}users WHERE id = {$id}");
        }

        if ($state['recovery']) {
            $db->query("DELETE FROM {$p}appointments_keyring WHERE purpose = 'recovery'");
        }

        if ($state['params'] !== null) {
            $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string($state['params']) . "' WHERE type='component' AND element='com_appointments'");
        }

        foreach ($state['plugins'] as [$id, $was]) {
            $db->query("UPDATE {$p}extensions SET enabled={$was} WHERE extension_id={$id}");
        }
    }
};
