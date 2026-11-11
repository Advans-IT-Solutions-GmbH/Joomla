<?php
/**
 * Standalone synthetic lifecycle test. No network, Joomla installation or credentials.
 * A local fake Close API stands in for every remote organisation; nothing contacts a real account.
 * SQLite executes the queue SQL; MySQL advisory/row locks are represented, not concurrency-tested.
 * Run: php -d extension=pdo_sqlite -d extension=sodium -d extension=mbstring tests/close-mock.php
 */

namespace Joomla\Database {
    interface DatabaseInterface {}
}
namespace Joomla\CMS {
    final class Factory
    {
        public static function getApplication(): object
        {
            return new class {
                public function get(string $name, mixed $default = null): string
                {
                    return str_repeat('synthetic-site-secret-', 3);
                }
            };
        }
    }
}
namespace Joomla\CMS\Component {
    final class ComponentHelper
    {
        public static object $params;
        public static function getParams(string $name): object { return self::$params; }
    }
}
namespace Advans\Component\Appointments\Administrator\Service\Close {
    function time(): int { return $GLOBALS['close_mock_time'] ?? \time(); }
}

namespace {
    define('_JEXEC', 1);
    $src = dirname(__DIR__) . '/administrator/components/com_appointments/src/';
    spl_autoload_register(static function (string $class) use ($src): void {
        $prefix = 'Advans\\Component\\Appointments\\Administrator\\';
        if (str_starts_with($class, $prefix)) {
            require_once $src . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    use Advans\Component\Appointments\Administrator\Service\Close\Client;
    use Advans\Component\Appointments\Administrator\Service\Close\DeliveryException;
    use Advans\Component\Appointments\Administrator\Service\Close\Profile;
    use Advans\Component\Appointments\Administrator\Service\Close\Queue;
    use Advans\Component\Appointments\Administrator\Service\Close\Settings;
    use Advans\Component\Appointments\Administrator\Service\PrivacyOutcome;
    use Joomla\CMS\Component\ComponentHelper;

    final class Params
    {
        public function __construct(public array $values) {}
        public function get(string $name, mixed $default = null): mixed { return $this->values[$name] ?? $default; }
    }

    final class MemoryDatabase implements \Joomla\Database\DatabaseInterface
    {
        public \PDO $pdo;
        private string $sql = '';
        public bool $locked = false;
        public bool $failWrite = false;
        public array $lockNames = [];
        public ?\Closure $afterCommit = null;
        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE test_appointments_close_delivery (
                booking_id INTEGER PRIMARY KEY, profile TEXT, uid TEXT, target_sequence INTEGER, state TEXT, operation TEXT,
                reason TEXT, binding TEXT, organization_id TEXT, lead_id TEXT, contact_id TEXT, contact_created INTEGER, activity_id TEXT,
                attempts INTEGER, next_attempt INTEGER, last_attempt INTEGER, mac TEXT)');
            $this->pdo->exec('CREATE TABLE test_appointments_close_limits (profile TEXT PRIMARY KEY, paused_until INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_bookings (
                id INTEGER PRIMARY KEY, uid TEXT, sequence INTEGER, eventtype_id INTEGER, anonymised_utc TEXT,
                invitee_name TEXT, invitee_email TEXT, invitee_phone TEXT, start_utc TEXT, end_utc TEXT,
                state INTEGER, custom_fields TEXT, guest_emails TEXT, conference_url TEXT)');
            $this->pdo->exec('CREATE TABLE test_appointments_eventtypes (id INTEGER PRIMARY KEY, title TEXT)');
            $this->pdo->exec("INSERT INTO test_appointments_eventtypes VALUES (1,'Synthetic first call'),(2,'Synthetic IT call'),"
                . "(3,'Synthetic product call'),(4,'Synthetic unrouted call'),(5,'Synthetic activity call')");
            $this->pdo->exec('CREATE TABLE test_appointments_fields (id INTEGER PRIMARY KEY, label TEXT)');
            $this->pdo->exec("INSERT INTO test_appointments_fields VALUES (11,'Company'),(12,'Role'),(13,'Topic'),(14,'Systems'),"
                . "(31,'Company or club'),(32,'Request type'),(33,'Excel version'),(99,'Never transferred')");
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_cleanup (request_id INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_outcomes (request_id INTEGER PRIMARY KEY, complete INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_staff (request_id INTEGER PRIMARY KEY, payload TEXT)');
        $this->pdo->exec('CREATE TABLE test_appointments_privacy_review (request_id INTEGER)');
        }
        public function getPrefix(): string { return 'test_'; }
        public function quoteName(string $name): string { return '`' . str_replace('#__', 'test_', $name) . '`'; }
        public function quote(string $value): string { return $this->pdo->quote($value); }
        public array $queries = [];
        public function setQuery(string $query): self
        {
            $this->queries[] = $query;
            $this->sql = str_replace([' FOR UPDATE', 'ON DUPLICATE KEY UPDATE complete = VALUES(complete)'],
                ['', 'ON CONFLICT(request_id) DO UPDATE SET complete = excluded.complete'], $query);
            return $this;
        }
        public function loadAssoc(): array|false { return $this->pdo->query($this->sql)->fetch(\PDO::FETCH_ASSOC); }
        public function loadAssocList(): array { return $this->pdo->query($this->sql)->fetchAll(\PDO::FETCH_ASSOC); }
        public function execute(): bool { $this->pdo->exec($this->sql); return true; }
        public function loadResult(): mixed
        {
            if ($this->sql === 'SELECT DATABASE()') { return 'synthetic_database'; }
            if (str_contains($this->sql, 'GET_LOCK')) {
                $this->lockNames[] = $this->sql;
                return $this->locked ? 0 : ($this->locked = true);
            }
            if (str_contains($this->sql, 'RELEASE_LOCK')) {
                $this->locked = false;
                return 1;
            }
            $value = $this->pdo->query($this->sql)->fetchColumn();
            return $value === false ? null : $value;
        }
        public function transactionStart(): void { $this->pdo->beginTransaction(); }
        public function transactionCommit(): void
        {
            $this->pdo->commit();
            if ($this->afterCommit !== null) {
                ($this->afterCommit)();
            }
        }
        public function transactionRollback(): void { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
        public function insertObject(string $table, object &$row): bool
        {
            $values = (array) $row;
            $query = $this->pdo->prepare('INSERT INTO ' . $this->quoteName($table) . ' (`'
                . implode('`,`', array_keys($values)) . '`) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
            return $query->execute(array_values($values));
        }
        public function updateObject(string $table, object &$row, string $key): bool
        {
            if ($this->failWrite && str_contains($table, 'close') && ($row->state ?? '') === 'done') {
                $this->failWrite = false;
                throw new \RuntimeException('Synthetic persistence failure after remote success.');
            }
            $values = (array) $row;
            $id = $values[$key];
            unset($values[$key]);
            $sets = array_map(static fn ($name): string => '`' . $name . '` = ?', array_keys($values));
            $query = $this->pdo->prepare('UPDATE ' . $this->quoteName($table) . ' SET ' . implode(',', $sets) . ' WHERE `' . $key . '` = ?');
            return $query->execute([...array_values($values), $id]);
        }
    }

    /** One synthetic Close organisation. It refuses DELETE so any deletion attempt fails loudly. */
    final class FakeClose
    {
        public array $requests = [];
        public array $contacts = [];
        public array $objects = [];
        public string $mode = 'ok';
        public ?string $orgId = null;
        public array $leadFields = ['cf_origin' => false, 'cf_funnel' => false, 'cf_systems' => true, 'cf_product' => false];
        private int $next = 0;
        public function __construct(public string $org) {}
        public function handle(string $method, string $path, ?array $payload): array
        {
            $this->requests[] = [$method, $path, $payload];
            if ($method === 'DELETE') {
                throw new \LogicException('Synthetic Close refuses DELETE.');
            }
            if ($this->mode === '429') {
                return ['code' => 429, 'retry_after' => 60];
            }
            if ($method === 'GET' && $path === '/api/v1/me/') {
                return ['code' => 200, 'data' => ['id' => 'user_synthetic', 'organizations' => [['id' => $this->orgId ?? 'orga_' . $this->org]]]];
            }
            if ($path === '/api/v1/data/search/' && $this->mode === 'search503') {
                return ['code' => 503];
            }
            if ($path === '/api/v1/data/search/') {
                $email = $payload['query']['queries'][1]['related_query']['condition']['value'];
                $found = array_values(array_filter($this->contacts, static fn ($contact) =>
                    strcasecmp($contact['emails'][0]['email'], $email) === 0));
                return ['code' => 200, 'data' => ['data' => $this->mode === 'ambiguous' ? [$found[0] ?? [], $found[0] ?? []] : $found, 'cursor' => null]];
            }
            if ($method === 'GET' && preg_match('~^/api/v1/custom_field/lead/(cf_\w+)/$~', $path, $match)) {
                return isset($this->leadFields[$match[1]]) ? ['code' => 200, 'data' => ['id' => $match[1],
                    'accepts_multiple_values' => $this->leadFields[$match[1]]]] : ['code' => 404, 'body' => '{}'];
            }
            if ($method === 'GET' && $path === '/api/v1/custom_activity/actitype_synthetic/') {
                return ['code' => 200, 'data' => ['id' => 'actitype_synthetic', 'fields' => array_map(static fn ($id) =>
                    ['id' => $id, 'type' => 'text'], ['cf_uid', 'cf_seq', 'cf_start', 'cf_end', 'cf_state', 'cf_source',
                        'cf_event', 'cf_campaign', 'cf_new', 'cf_topic'])]];
            }
            if ($method === 'POST' && $path === '/api/v1/lead/') {
                $lead = 'lead_' . $this->org . ++$this->next;
                $contact = 'cont_' . $this->org . $this->next;
                $this->objects[$lead] = $payload;
                $this->contacts[$contact] = ['id' => $contact, 'lead_id' => $lead] + $payload['contacts'][0];
                return ['code' => 200, 'data' => ['id' => $lead, 'contacts' => [['id' => $contact]]]];
            }
            if ($method === 'GET' && preg_match('~^/api/v1/contact/(cont_\w+)/$~', $path, $match)) {
                return isset($this->contacts[$match[1]]) ? ['code' => 200, 'data' => $this->contacts[$match[1]]] : ['code' => 404, 'body' => '{}'];
            }
            if ($method === 'POST' && in_array($path, ['/api/v1/activity/note/', '/api/v1/activity/custom/'], true)) {
                $id = 'acti_' . $this->org . ++$this->next;
                $this->objects[$id] = $payload + ['id' => $id, 'kind' => $path];
                if ($this->mode === 'timeout') {
                    throw new \RuntimeException('Synthetic timeout after remote write.');
                }
                if ($this->mode === '503') {
                    return ['code' => 503];
                }
                return ['code' => 200, 'data' => $this->objects[$id]];
            }
            if (preg_match('~^/api/v1/activity/(?:note|custom)/(acti_\w+)/$~', $path, $match) && isset($this->objects[$match[1]])) {
                if ($method === 'PUT') {
                    $this->objects[$match[1]] = array_replace($this->objects[$match[1]], $payload);
                }
                return ['code' => 200, 'data' => $this->objects[$match[1]]];
            }
            return ['code' => 404, 'body' => '{}'];
        }
        public function count(string $method, string $path = ''): int
        {
            return count(array_filter($this->requests, static fn ($request) => $request[0] === $method
                && ($path === '' || $request[1] === $path)));
        }
    }

    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) { throw new \RuntimeException('FAIL ' . $label); }
        $checks++;
        echo 'PASS ' . $label . "\n";
    };
    $profileMain = ['key' => 'main', 'eventtypes' => ['1', '2'], 'origin' => 'https://main.example.test',
        'credential_env' => 'SYNTHETIC_MAIN_KEY', 'delivery' => 'note', 'note_answers' => '11,12,13,14',
        'campaign' => 'Synthetic campaign', 'source' => 'Website booking', 'lead_name' => '11', 'contact_title' => '12',
        'lead_answers' => '{"14":"cf_systems"}', 'lead_values' => '{"cf_origin":"Website","cf_funnel":"Website | First call"}'];
    $profileQr = ['key' => 'qr', 'eventtypes' => ['3'], 'origin' => 'https://qr.example.test',
        'credential_env' => 'SYNTHETIC_QR_KEY', 'delivery' => 'note', 'note_answers' => '31,32,33',
        'campaign' => 'Product', 'lead_name' => '31', 'lead_answers' => '{"32":"cf_product"}'];
    $params = new Params(['close_enabled' => 0, 'close_profiles' => ['close_profiles0' => $profileMain, 'close_profiles1' => $profileQr]]);
    ComponentHelper::$params = $params;
    $db = new MemoryDatabase();
    $remote = ['main' => new FakeClose('main'), 'qr' => new FakeClose('qr')];
    $origins = [];
    $factory = static function (Profile $profile) use (&$remote, &$origins): \Closure {
        if ($profile->credential === 'SYNTHETIC_MISSING_KEY') {
            throw new \RuntimeException('Synthetic missing credential.');
        }
        $origins[$profile->key] = $profile->origin;
        return static fn (string $method, string $path, ?array $payload): array => $remote[$profile->key]->handle($method, $path, $payload);
    };
    $translate = static fn (string $key): string => $key;
    $queue = new Queue($db, $params, $factory, static fn (array $row): array => $row, $translate, 'Europe/Zurich');
    $all = static function () use (&$remote): array {
        return array_merge(...array_map(static fn (FakeClose $close) => $close->requests, array_values($remote)));
    };
    $staffScope = static function (int $id) use ($db): void {
        $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider(
            (string) \Joomla\CMS\Factory::getApplication()->get('secret'));
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode(['user_id' => 0, 'address' => str_repeat('a', 64), 'complete' => true,
                'attempt_pending' => false, 'attempt' => str_repeat('b', 32)], JSON_THROW_ON_ERROR),
            'appointments-privacy-staff:' . $id, $nonce, $keys->key('privacy-staff')));
        $db->pdo->prepare('INSERT OR REPLACE INTO test_appointments_privacy_staff VALUES (?,?)')->execute([$id, $payload]);
    };
    $booking = static fn (int $id, int $event, string $email, array $answers): array => ['id' => $id,
        'uid' => str_pad(dechex($id), 64, '0', STR_PAD_LEFT), 'sequence' => 0, 'eventtype_id' => $event, 'anonymised_utc' => null,
        'invitee_name' => 'Synthetic Invitee ' . $id, 'invitee_email' => $email, 'invitee_phone' => '+41000000' . $id,
        'start_utc' => '2027-01-10 10:00:00', 'end_utc' => '2027-01-10 10:30:00', 'state' => 1,
        'custom_fields' => json_encode($answers + ['99' => 'NEVER_EXPORT']), 'guest_emails' => 'NEVER_GUEST',
        'conference_url' => 'NEVER_CAPABILITY'];
    $enrol = static function (array $row) use ($db): array {
        $object = (object) $row;
        $db->insertObject('#__appointments_bookings', $object);
        $db->transactionStart();
        Queue::changed($db, $row, created: true);
        $db->transactionCommit();
        return $row;
    };
    $mutate = static function (array $row, array $fields, bool $erase = false) use ($db): array {
        $row = array_replace($row, $fields);
        $db->transactionStart();
        $object = (object) $row;
        $db->updateObject('#__appointments_bookings', $object, 'id');
        Queue::changed($db, $row, $erase);
        $db->transactionCommit();
        return $row;
    };
    $state = static fn (int $id): array|false => $db->setQuery('SELECT * FROM test_appointments_close_delivery WHERE booking_id = ' . $id)->loadAssoc();
    $lifeSciences = ['11' => 'Synthetic Pharma AG', '12' => 'Head of QA', '13' => 'Validation question', '14' => ['ERP', 'LIMS']];

    // Disabled: nothing is enrolled and nothing is sent.
    $enrol($booking(1, 1, 'one@example.test', $lifeSciences));
    $check($state(1) === false && $queue->run()['disabled'] === 1 && $all() === [], 'disabled: no enrolment and no network');
    $params->values['close_enabled'] = 1;

    // Routing per event type.
    $enrol($booking(2, 4, 'unrouted@example.test', []));
    $check($state(2) === false, 'event type without a profile is never enrolled');
    $db->queries = [];
    $first = $enrol($booking(3, 1, 'new@example.test', $lifeSciences));
    $check(preg_grep('/FOR UPDATE|^DELETE/', $db->queries) === [],
        'enrolment takes no locking read on a missing key, so concurrent bookings cannot deadlock on a gap lock');
    $check($state(3)['profile'] === 'main', 'event type is routed to its profile at enrolment');
    $qr = $enrol($booking(4, 3, 'known@example.test', ['31' => 'Synthetic Club', '32' => 'Template', '33' => 'Excel for Windows']));
    $remote['qr']->contacts['cont_known'] = ['id' => 'cont_known', 'lead_id' => 'lead_known',
        'emails' => [['email' => 'KNOWN@example.test']], 'name' => 'Existing'];
    $result = $queue->run();
    $check($result['failed'] === 0 && $state(3)['state'] === 'done' && $state(4)['state'] === 'done', 'both profiles deliver');
    $check($origins === ['main' => 'https://main.example.test', 'qr' => 'https://qr.example.test'],
        'each profile uses its own origin and credential reference');
    $check($remote['main']->count('POST', '/api/v1/lead/') === 1 && $remote['qr']->count('POST', '/api/v1/lead/') === 0
        && !str_contains(json_encode($remote['main']->requests), 'known@example.test')
        && !str_contains(json_encode($remote['qr']->requests), 'new@example.test'),
        'a booking reaches only the organisation of its profile');

    // New lead: enriched like the scheduling integration, plus company, title and origin fields.
    $lead = $remote['main']->objects['lead_main1'];
    $check($lead['name'] === 'Synthetic Pharma AG' && $lead['contacts'][0]['title'] === 'Head of QA'
        && $lead['contacts'][0]['emails'][0]['email'] === 'new@example.test'
        && $lead['contacts'][0]['phones'][0]['phone'] === '+410000003', 'new lead uses company as name and function as contact title');
    $check($lead['custom.cf_origin'] === 'Website' && $lead['custom.cf_funnel'] === 'Website | First call'
        && $lead['custom.cf_systems'] === ['ERP', 'LIMS'], 'new lead receives fixed origin values and multi-value answer fields');
    $check($remote['main']->count('GET', '/api/v1/custom_field/lead/cf_systems/') === 1,
        'lead fields are verified before personal data is sent');
    $note = $remote['main']->objects['acti_main2'];
    $check($note['kind'] === '/api/v1/activity/note/' && $note['lead_id'] === 'lead_main1' && $note['contact_id'] === 'cont_main1',
        'booking becomes one note on the new lead and contact');
    $check(str_contains($note['note'], 'Company: Synthetic Pharma AG') && str_contains($note['note'], 'Systems: ERP, LIMS')
        && str_contains($note['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_CAMPAIGN: Synthetic campaign')
        && str_contains($note['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_SOURCE: Website booking')
        && str_contains($note['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_NEW_CONTACT: COM_APPOINTMENTS_CLOSE_NOTE_YES')
        && str_contains($note['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_START: 2027-01-10 11:00 (Europe/Zurich)')
        && str_contains($note['note'], $first['uid']), 'note carries labelled answers, campaign, source, local time and UID');
    $check($note['title'] === 'COM_APPOINTMENTS_CLOSE_NOTE_TITLE' || str_contains($note['title'], 'Synthetic first call'),
        'note title names the event type');
    $serialized = json_encode($all());
    $check(!str_contains($serialized, 'NEVER_'), 'no unlisted answer, guest or conference capability leaves the website');

    // Existing contact: reused, never changed.
    $qrNote = $remote['qr']->objects['acti_qr1'];
    $check($qrNote['lead_id'] === 'lead_known' && $qrNote['contact_id'] === 'cont_known'
        && $remote['qr']->count('PUT') === 0 && $remote['qr']->count('GET', '/api/v1/custom_field/lead/cf_product/') === 1
        && $remote['qr']->requests[0][1] === '/api/v1/me/' && $remote['qr']->requests[1][1] === '/api/v1/custom_field/lead/cf_product/'
        && str_contains($qrNote['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_NEW_CONTACT: COM_APPOINTMENTS_CLOSE_NOTE_NO'),
        'known e-mail reuses contact and lead without any write to them');

    // Reschedule and cancellation update the same note; nothing is deleted.
    $first = $mutate($first, ['sequence' => 1, 'start_utc' => '2027-01-11 09:00:00', 'end_utc' => '2027-01-11 09:30:00']);
    $queue->run();
    $check($remote['main']->count('POST', '/api/v1/activity/note/') === 1 && $remote['main']->count('PUT', '/api/v1/activity/note/acti_main2/') === 1
        && str_contains($remote['main']->objects['acti_main2']['note'], '2027-01-11 10:00 (Europe/Zurich)'),
        'reschedule updates the same note');
    $first = $mutate($first, ['sequence' => 2, 'state' => 2]);
    $queue->run();
    $updated = $remote['main']->objects['acti_main2'];
    $check($updated['title'] === 'COM_APPOINTMENTS_CLOSE_NOTE_TITLE_CANCELLED'
        && str_contains($updated['note'], 'COM_APPOINTMENTS_CLOSE_NOTE_STATE_CANCELLED') && $remote['main']->count('POST', '/api/v1/activity/note/') === 1,
        'cancellation sets the cancelled status on the same note');
    $db->transactionStart();
    Queue::changed($db, array_replace($first, ['sequence' => 1]));
    $db->transactionCommit();
    $before = count($all());
    $queue->run();
    $check(count($all()) === $before && (int) $state(3)['target_sequence'] === 2, 'stale versions cannot regress delivery');

    // Website erasure only drops the local link.
    $before = count($all());
    $mutate($first, ['sequence' => 3, 'anonymised_utc' => '2027-02-01 00:00:00', 'invitee_email' => null], true);
    $queue->run(20, true);
    $check($state(3) === false && count($all()) === $before && isset($remote['main']->objects['acti_main2'], $remote['main']->contacts['cont_main1']),
        'erasure removes the local link and leaves note, contact and lead in Close untouched');
    $mutate($qr, ['sequence' => 1, 'anonymised_utc' => '2027-02-01 00:00:00'], true);
    $db->pdo->exec('DELETE FROM test_appointments_bookings WHERE id = 4');
    $check($state(4) === false && count($all()) === $before, 'hard deletion after transfer sends nothing to Close');
    $pending = $enrol($booking(5, 2, 'later@example.test', ['11' => 'Later Ltd']));
    $mutate($pending, ['sequence' => 1, 'anonymised_utc' => '2027-02-01 00:00:00'], true);
    $queue->run();
    $check($state(5) === false && count($all()) === $before, 'erasure before delivery cancels the transfer locally');

    // A privacy request never waits for Close, even with blocked and uncertain transfers outstanding.
    $remote['main']->mode = 'ambiguous';
    $blocked = $enrol($booking(6, 1, 'new@example.test', $lifeSciences));
    $queue->run();
    $check($state(6)['state'] === 'blocked' && $state(6)['reason'] === 'ambiguous_contact'
        && $remote['main']->count('POST', '/api/v1/lead/') === 1, 'several e-mail matches block visibly without choosing a lead');
    $remote['main']->mode = 'ok';
    $staffScope(500);
    PrivacyOutcome::record($db, 500, true);
    $check(!PrivacyOutcome::incomplete($db, 500), 'formal privacy completion is independent of outstanding Close transfers');
    $outcome = file_get_contents(dirname(__DIR__) . '/administrator/components/com_appointments/src/Service/PrivacyOutcome.php');
    $service = file_get_contents(dirname(__DIR__) . '/administrator/components/com_appointments/src/Service/PrivacyService.php');
    $check(!str_contains($outcome, 'Close') && !str_contains($service, 'Close'), 'privacy services carry no Close dependency');
    $mutate($blocked, ['sequence' => 1, 'anonymised_utc' => '2027-02-01 00:00:00'], true);
    $check($state(6) === false, 'erasing a blocked transfer leaves no obligation behind');

    // Rate limits are per profile.
    $remote['main']->mode = '429';
    $limited = $enrol($booking(7, 1, 'limit@example.test', $lifeSciences));
    $other = $enrol($booking(8, 3, 'other@example.test', ['31' => 'Other Club', '32' => 'Usage']));
    $queue->run();
    $check($state(7)['state'] === 'retry' && $state(8)['state'] === 'done', 'a 429 pauses only its own profile');
    $remote['main']->mode = 'ok';
    $before = count($remote['main']->requests);
    $late = $enrol($booking(9, 2, 'late@example.test', ['11' => 'Late AG']));
    $enrol($booking(19, 3, 'healthy@example.test', ['31' => 'Healthy Club']));
    $result = $queue->run(1);
    $check(count($remote['main']->requests) === $before && $result['deferred'] >= 1, 'the paused profile waits for Retry-After, also for new bookings');
    $check($state(19)['state'] === 'done' && $state(9)['state'] === 'pending',
        'paused bookings do not fill the batch, other profiles still progress');
    $GLOBALS['close_mock_time'] = time() + 61;
    $queue->run();
    $check($state(7)['state'] === 'done' && $state(9)['state'] === 'done', 'delivery resumes after Retry-After');

    // Uncertain creation and read-only reconciliation.
    $remote['main']->mode = 'timeout';
    $uncertain = $enrol($booking(10, 1, 'new@example.test', $lifeSciences));
    $queue->run();
    $check($state(10)['state'] === 'uncertain' && $state(10)['operation'] === 'create_activity', 'timeout after a create is durable uncertainty');
    $before = count($all());
    $queue->run(20, true);
    $check(count($all()) === $before, 'safe retry never replays an uncertain create');
    $remote['main']->mode = 'ok';
    $created = array_key_last($remote['main']->objects);
    try { $queue->reconcile(10, 'acti_main2'); $check(false, 'foreign note rejected'); }
    catch (\RuntimeException $e) { $check($state(10)['state'] === 'uncertain', 'reconciliation rejects a note of another booking'); }
    $queue->reconcile(10, $created);
    $queue->run();
    $check($state(10)['activity_id'] === $created && $state(10)['state'] === 'done'
        && $remote['main']->count('PUT', '/api/v1/activity/note/' . $created . '/') === 1, 'verified reconciliation resumes with an update');
    $remote['main']->mode = '503';
    $enrol($booking(11, 1, 'new@example.test', $lifeSciences));
    $queue->run();
    $check($state(11)['state'] === 'uncertain', 'server error during a create is not assumed safe to repeat');
    $remote['main']->mode = 'ok';
    $enrol($booking(12, 1, 'new@example.test', $lifeSciences));
    $db->failWrite = true;
    $queue->run();
    $check($state(12)['state'] === 'uncertain', 'persistence failure after remote create keeps the precommitted intent');
    $raced = $enrol($booking(13, 1, 'new@example.test', $lifeSciences));
    $beforePosts = $remote['main']->count('POST', '/api/v1/activity/note/');
    $db->afterCommit = static function () use ($db, $state, $mutate, $raced): void {
        if (($state(13)['reason'] ?? '') === 'in_flight') {
            $db->afterCommit = null;
            $mutate($raced, ['sequence' => 1, 'anonymised_utc' => '2027-02-01 00:00:00'], true);
        }
    };
    $queue->run();
    $db->afterCommit = null;
    $check($remote['main']->count('POST', '/api/v1/activity/note/') === $beforePosts && $state(13) === false, 'erasure between intent and send stops the request');

    // Configuration faults never break a booking and never reach the network.
    $params->values['close_profiles']['close_profiles1']['eventtypes'] = ['1', '3'];
    $broken = $enrol($booking(14, 1, 'new@example.test', $lifeSciences));
    $check($state(14)['profile'] === '' && $state(14)['state'] === 'pending', 'invalid configuration still records the booking for later routing');
    $before = count($all());
    try { $queue->run(); $check(false, 'invalid configuration rejected'); }
    catch (\InvalidArgumentException $e) { $check(count($all()) === $before, 'overlapping event types stop delivery before any call'); }
    $params->values['close_profiles']['close_profiles1']['eventtypes'] = ['3'];
    $queue->run();
    $check($state(14)['profile'] === 'main' && $state(14)['state'] === 'done', 'a repaired configuration resolves the profile later');
    $params->values['close_profiles']['close_profiles0']['origin'] = 'https://other.example.test';
    $mutate($broken, ['sequence' => 1, 'state' => 2]);
    $before = count($all());
    $queue->run();
    $check($state(14)['state'] === 'blocked' && $state(14)['reason'] === 'profile_changed' && count($all()) === $before,
        'a changed destination never updates records of another organisation');
    $params->values['close_profiles']['close_profiles0']['origin'] = 'https://main.example.test';
    $params->values['close_profiles']['close_profiles1']['credential_env'] = 'SYNTHETIC_MISSING_KEY';
    $enrol($booking(15, 3, 'nokey@example.test', ['31' => 'No Key']));
    $queue->run();
    $check($state(15)['state'] === 'blocked' && $state(15)['reason'] === 'credential', 'a missing key blocks only its profile');
    $params->values['close_profiles']['close_profiles1']['credential_env'] = 'SYNTHETIC_QR_KEY';
    $remote['main']->leadFields = ['cf_origin' => false, 'cf_funnel' => false];
    $before = $remote['main']->count('POST', '/api/v1/lead/');
    $enrol($booking(16, 2, 'unknown-field@example.test', $lifeSciences));
    $queue->run();
    $check($state(16)['reason'] === 'http_404' && $remote['main']->count('POST', '/api/v1/lead/') === $before
        && !str_contains(json_encode($all()), 'unknown-field@example.test'),
        'an unknown lead field blocks before any personal data, including the e-mail search, is sent');
    $remote['main']->leadFields = ['cf_origin' => false, 'cf_funnel' => false, 'cf_systems' => true];

    // The authenticated organisation is bound per record and checked before personal data.
    $remote['main']->orgId = 'orga_replaced';
    $late = $mutate($late, ['sequence' => 1, 'state' => 2]);
    $before = count($remote['main']->requests);
    $queue->run();
    $after = array_slice($remote['main']->requests, $before);
    $check($state(9)['state'] === 'blocked' && $state(9)['reason'] === 'organization_changed'
        && array_column($after, 1) === ['/api/v1/me/'], 'a key of another organisation is detected before any booking data is sent');
    $remote['main']->orgId = null;
    $queue->run(20, true);
    $check($state(9)['state'] === 'done' && str_contains($remote['main']->objects[$state(9)['activity_id']]['title'], 'CANCELLED'),
        'the original organisation resumes the bound record');
    $params->values['close_profiles']['close_profiles1']['organization_id'] = 'orga_expected';
    $before = count($remote['qr']->requests);
    $enrol($booking(18, 3, 'expected@example.test', ['31' => 'Expected Club']));
    $queue->run();
    $check($state(18)['reason'] === 'organization_mismatch'
        && array_column(array_slice($remote['qr']->requests, $before), 1) === ['/api/v1/me/'],
        'an expected organisation that differs blocks before any personal data');
    unset($params->values['close_profiles']['close_profiles1']['organization_id']);
    $params->values['close_profiles']['close_profiles0']['organization_id'] = 'orga_main';
    $mutate($late, ['sequence' => 2, 'state' => 1]);
    $queue->run();
    $check($state(9)['state'] === 'done', 'adding a matching expected organisation later keeps existing records deliverable');
    unset($params->values['close_profiles']['close_profiles0']['organization_id']);
    $remote['main']->mode = 'search503';
    $enrol($booking(20, 1, 'failed-search@example.test', $lifeSciences));
    $queue->run();
    $check($state(20)['state'] === 'retry' && $state(20)['organization_id'] === 'orga_main' && $state(20)['binding'] !== '',
        'the destination is committed before the first request with personal data');
    $remote['main']->mode = 'ok';
    $remote['main']->orgId = 'orga_replaced';
    $GLOBALS['close_mock_time'] += 301;
    $before = count($remote['main']->requests);
    $queue->run();
    $check($state(20)['reason'] === 'organization_changed'
        && array_column(array_slice($remote['main']->requests, $before), 1) === ['/api/v1/me/'],
        'after a failed search a replaced key cannot receive the booking');
    $remote['main']->orgId = null;
    $check((new Profile(array_replace($profileMain, ['lead_name' => '20,10,20'])))->leadName === [20, 10],
        'field selectors keep their configured precedence');

    // Optional custom activity, only where the plan offers it.
    $params->values['close_profiles']['close_profiles2'] = ['key' => 'activity', 'eventtypes' => ['5'],
        'origin' => 'https://activity.example.test', 'credential_env' => 'SYNTHETIC_ACTIVITY_KEY', 'delivery' => 'activity',
        'activity_type' => 'actitype_synthetic', 'campaign' => 'Activity campaign',
        'activity_fields' => '{"uid":"cf_uid","sequence":"cf_seq","start":"cf_start","end":"cf_end","state":"cf_state","source":"cf_source","event_type":"cf_event","campaign":"cf_campaign","contact_created":"cf_new"}',
        'activity_answers' => '{"13":"cf_topic"}'];
    $remote['activity'] = new FakeClose('activity');
    $activity = $enrol($booking(17, 5, 'activity@example.test', ['13' => 'Topic answer']));
    $queue->run();
    $custom = $remote['activity']->objects['acti_activity2'];
    $check($custom['kind'] === '/api/v1/activity/custom/' && $custom['custom.cf_event'] === 'Synthetic activity call'
        && $custom['custom.cf_campaign'] === 'Activity campaign' && $custom['custom.cf_new'] === 'yes'
        && $custom['custom.cf_topic'] === 'Topic answer' && $custom['custom.cf_state'] === 'confirmed',
        'custom activity mode writes booking, campaign and answer fields');
    $mutate($activity, ['sequence' => 1, 'state' => 2]);
    $queue->run();
    $check($remote['activity']->objects['acti_activity2']['custom.cf_state'] === 'cancelled'
        && $remote['activity']->count('POST', '/api/v1/activity/custom/') === 1, 'custom activity is updated, never duplicated');

    // Nothing in this component can delete in Close.
    $methods = array_unique(array_column($all(), 0));
    sort($methods);
    $check($methods === ['GET', 'POST', 'PUT'], 'only GET, POST and PUT ever reached Close');
    $client = new Client(new Profile($profileMain), static fn () => ['code' => 204]);
    try { $client->request('DELETE', '/api/v1/activity/note/acti_x/'); $check(false, 'DELETE refused'); }
    catch (DeliveryException $e) { $check($e->reason === 'method', 'the client refuses DELETE before any transport'); }
    $sources = implode("\n", array_map('file_get_contents', glob(dirname(__DIR__) . '/administrator/components/com_appointments/src/Service/Close/*.php')));
    $check(!preg_match("~'DELETE'\s*,\s*\\\$path|request\('DELETE'~", $sources), 'no Close request path issues DELETE');

    // Configuration validation.
    foreach ([
        'http origin' => ['origin' => 'http://main.example.test'],
        'origin with path' => ['origin' => 'https://main.example.test/api'],
        'lowercase credential' => ['credential_env' => 'synthetic'],
        'missing event types' => ['eventtypes' => []],
        'bad key' => ['key' => 'Main Key'],
        'fixed and answer on one lead field' => ['lead_values' => '{"cf_systems":"x"}'],
        'list instead of object' => ['lead_answers' => '["cf_systems"]'],
        'activity without fields' => ['delivery' => 'activity', 'activity_type' => 'actitype_synthetic'],
    ] as $label => $change) {
        try { new Profile(array_replace($profileMain, $change)); $check(false, 'rejects ' . $label); }
        catch (\InvalidArgumentException|\JsonException $e) { $check(true, 'profile rejects ' . $label); }
    }
    foreach (['duplicate key' => [$profileMain, $profileMain], 'no profile' => []] as $label => $profiles) {
        try { new Settings(new Params(['close_profiles' => $profiles])); $check(false, 'rejects ' . $label); }
        catch (\InvalidArgumentException $e) { $check(true, 'settings reject ' . $label); }
    }
    $settings = new Settings(new Params(['close_profiles' => json_encode([$profileMain, $profileQr])]));
    $check($settings->profileFor(3)?->key === 'qr' && $settings->profileFor(4) === null, 'stored JSON profiles route the same way');

    // Integrity and serialization.
    $db->locked = true;
    $before = count($all());
    $refused = false;
    try { $queue->run(); }
    catch (\RuntimeException $e) { $refused = $e->getMessage() === 'Close delivery is already running.'; }
    $check($refused && count($all()) === $before, 'competing worker refused before network');
    $db->locked = false;
    $db->pdo->exec("UPDATE test_appointments_close_delivery SET activity_id='acti_forged' WHERE booking_id=9");
    $rejected = false;
    try { $queue->rows(); }
    catch (\RuntimeException $e) { $rejected = true; }
    $check($rejected, 'metadata tampering rejected');
    $check(!str_contains(json_encode($db->pdo->query('SELECT * FROM test_appointments_close_delivery')->fetchAll(\PDO::FETCH_ASSOC)), '@example.test'),
        'durable delivery queue contains no invitee payload');
    echo "Close synthetic lifecycle: {$checks} checks passed; no network, real account or credentials used.\n";
}
