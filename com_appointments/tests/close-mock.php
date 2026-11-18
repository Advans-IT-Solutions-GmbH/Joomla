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
    use Advans\Component\Appointments\Administrator\Service\Close\CredentialStore;
    use Advans\Component\Appointments\Administrator\Service\Close\DeliveryException;
    use Advans\Component\Appointments\Administrator\Service\Close\Profile;
    use Advans\Component\Appointments\Administrator\Service\Close\Queue;
    use Advans\Component\Appointments\Administrator\Service\Close\Settings;
    use Advans\Component\Appointments\Administrator\Service\Close\Transport;
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
                attempts INTEGER, next_attempt INTEGER, last_attempt INTEGER, mac TEXT, match_rule TEXT, match_candidates TEXT)');
            $this->pdo->exec('CREATE TABLE test_appointments_close_limits (profile TEXT PRIMARY KEY, paused_until INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_close_credentials (eventtype_id INTEGER PRIMARY KEY, secret TEXT,
                modified_utc TEXT, modified_by INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_bookings (
                id INTEGER PRIMARY KEY, uid TEXT, sequence INTEGER, eventtype_id INTEGER, anonymised_utc TEXT,
                invitee_name TEXT, invitee_first_name TEXT, invitee_last_name TEXT, invitee_email TEXT, invitee_phone TEXT, start_utc TEXT, end_utc TEXT,
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
        /** The related object (or contact_name) of every contact search, in order. */
        public array $searches = [];
        /** [offset, limit] of every search page read. */
        public array $pages = [];
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
                // The three searches of Client::match(): e-mail and telephone of a related object, the contact name.
                $condition = $payload['query']['queries'][1];
                $this->searches[] = $condition['related_object_type'] ?? 'contact_name';
                $value = $condition['related_query']['condition']['value'] ?? $condition['condition']['value'];
                $found = array_values(array_filter($this->contacts, static function (array $contact) use ($condition, $value): bool {
                    return match ($condition['related_object_type'] ?? '') {
                        'contact_email' => in_array(strtolower($value), array_map(static fn ($e) => strtolower($e['email']), $contact['emails'] ?? []), true),
                        'contact_phone' => in_array($value, array_map(static fn ($p) => Client::e164($p['phone']), $contact['phones'] ?? []), true),
                        default => strcasecmp(trim((string) ($contact['name'] ?? '')), $value) === 0,
                    };
                }));
                if ($this->mode === 'ambiguous') {
                    $found = [$found[0] ?? [], $found[0] ?? []];
                }
                // Pages as Close returns them: the cursor of the next page, null after the last one.
                $limit = (int) $payload['_limit'];
                $offset = (int) ($payload['cursor'] ?? 0);
                $this->pages[] = [$offset, $limit];
                return ['code' => 200, 'data' => ['data' => array_slice($found, $offset, $limit),
                    'cursor' => count($found) > $offset + $limit ? (string) ($offset + $limit) : null]];
            }
            if ($method === 'GET' && preg_match('~^/api/v1/lead/(lead_\w+)/\?_fields=id,name$~', $path, $match)) {
                return isset($this->objects[$match[1]]) ? ['code' => 200, 'data' => ['id' => $match[1], 'name' => $this->objects[$match[1]]['name'] ?? '']]
                    : ['code' => 404, 'body' => '{}'];
            }
            if ($method === 'GET' && preg_match('~^/api/v1/custom_field/lead/(cf_\w+)/$~', $path, $match)) {
                return isset($this->leadFields[$match[1]]) ? ['code' => 200, 'data' => ['id' => $match[1],
                    'accepts_multiple_values' => $this->leadFields[$match[1]]]] : ['code' => 404, 'body' => '{}'];
            }
            if ($method === 'GET' && $path === '/api/v1/custom_activity/actitype_synthetic/') {
                return ['code' => 200, 'data' => ['id' => 'actitype_synthetic', 'fields' => array_map(static fn ($id) =>
                    ['id' => $id, 'type' => 'text'], ['cf_uid', 'cf_seq', 'cf_start', 'cf_end', 'cf_state', 'cf_source',
                        'cf_event', 'cf_campaign', 'cf_new', 'cf_topic', 'cf_match'])]];
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
    // Every request with the key it was sent with: [profile, key or null for the profile variable, method, path, payload].
    $keyed = [];
    $factory = static function (Profile $profile, ?string $key = null) use (&$remote, &$origins, &$keyed): \Closure {
        if ($profile->credential === 'SYNTHETIC_MISSING_KEY' && $key === null) {
            throw new \RuntimeException('Synthetic missing credential.');
        }
        if ($profile->credential === '' && $key === null) {
            // The production rule for a profile without a variable and without a stored key.
            return Transport::forProfile($profile, null);
        }
        $origins[$profile->key] = $profile->origin;
        return static function (string $method, string $path, ?array $payload) use ($profile, $key, &$remote, &$keyed): array {
            $keyed[] = [$profile->key, $key, $method, $path, json_encode($payload)];
            if ($remote[$profile->key]->mode === 'leak') {
                throw new \RuntimeException('Synthetic transport failure for ' . $key);
            }
            if ($remote[$profile->key]->mode === 'leak401') {
                return ['code' => 401, 'body' => '{"error":"Invalid API key ' . $key . '"}'];
            }
            return $remote[$profile->key]->handle($method, $path, $payload);
        };
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
        'invitee_name' => 'Synthetic Invitee ' . $id, 'invitee_first_name' => 'Synthetic', 'invitee_last_name' => 'Invitee ' . $id,
        'invitee_email' => $email, 'invitee_phone' => '+41000000' . $id,
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
    $ambiguousNote = $remote['main']->objects[$state(6)['activity_id']] ?? [];
    $check($state(6)['state'] === 'done' && $state(6)['match_rule'] === 'ambiguous_email' && $state(6)['match_candidates'] === 'lead_main1'
        && $remote['main']->count('POST', '/api/v1/lead/') === 2 && $state(6)['lead_id'] !== 'lead_main1'
        && str_contains($ambiguousNote['note'] ?? '', 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH: COM_APPOINTMENTS_CLOSE_NOTE_MATCH_AMBIGUOUS_EMAIL')
        && str_contains($ambiguousNote['note'] ?? '', 'COM_APPOINTMENTS_CLOSE_NOTE_DUPLICATES: lead_main1'),
        'several e-mail matches never block: a new lead, and the note names the matching leads as possible duplicates');
    $remote['main']->mode = 'ok';
    $staffScope(500);
    PrivacyOutcome::record($db, 500, true);
    $check(!PrivacyOutcome::incomplete($db, 500), 'formal privacy completion is independent of outstanding Close transfers');
    $outcome = file_get_contents(dirname(__DIR__) . '/administrator/components/com_appointments/src/Service/PrivacyOutcome.php');
    $service = file_get_contents(dirname(__DIR__) . '/administrator/components/com_appointments/src/Service/PrivacyService.php');
    $check(!str_contains($outcome, 'Close') && !str_contains($service, 'Close'), 'privacy services carry no Close dependency');
    $mutate($blocked, ['sequence' => 1, 'anonymised_utc' => '2027-02-01 00:00:00'], true);
    $check($state(6) === false, 'erasing a transferred booking leaves no obligation behind');

    // Owner requirement 18.11.2026: an existing lead is found by e-mail, else by telephone, else by
    // first and last name together with the company; never by the name alone. One match: the note goes
    // to it, which is never changed. Several: a new lead whose note names them as possible duplicates.
    $main = $remote['main'];
    $seed = static function (string $contact, string $lead, string $company, string $name, string $email, string $phone) use ($main): void {
        $main->objects[$lead] = ['name' => $company];
        $main->contacts[$contact] = ['id' => $contact, 'lead_id' => $lead, 'name' => $name,
            'emails' => [['email' => $email]], 'phones' => $phone === '' ? [] : [['phone' => $phone]]];
    };
    $noteOf = static fn (int $id): string => (string) ($main->objects[$state($id)['activity_id']]['note'] ?? '');
    $person = static fn (array $row, string $first, string $last): array => array_replace($row,
        ['invitee_first_name' => $first, 'invitee_last_name' => $last, 'invitee_name' => $first . ' ' . $last]);
    $check(Client::e164('0041 79 123 45 67') === '+41791234567' && Client::e164('+41 (79) 123-45-67') === '+41791234567'
        && Client::e164('079 123 45 67') === '' && Client::e164('') === '', 'telephone numbers are compared in E.164 form only');
    $check(str_contains((string) ($remote['qr']->objects['acti_qr1']['note'] ?? ''), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH: COM_APPOINTMENTS_CLOSE_NOTE_MATCH_EMAIL')
        && str_contains((string) ($remote['main']->objects['acti_main2']['note'] ?? ''), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH: COM_APPOINTMENTS_CLOSE_NOTE_MATCH_NONE'),
        'the note says whether the contact was found by its e-mail address or is new');

    // 2. Telephone: another e-mail address, the same number written differently in Close.
    $seed('cont_phone', 'lead_phone', 'Phone Company AG', 'Someone Else', 'office@phone.example.test', '+41 000 000 41');
    $writesBefore = $main->count('PUT') + $main->count('POST', '/api/v1/lead/');
    $main->searches = [];
    $enrol($booking(41, 1, 'private-address@example.test', $lifeSciences));
    $queue->run();
    $check($state(41)['state'] === 'done' && $state(41)['lead_id'] === 'lead_phone' && $state(41)['contact_id'] === 'cont_phone'
        && $state(41)['match_rule'] === 'phone' && $main->count('PUT') + $main->count('POST', '/api/v1/lead/') === $writesBefore
        && $main->searches === ['contact_email', 'contact_phone']
        && str_contains($noteOf(41), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH: COM_APPOINTMENTS_CLOSE_NOTE_MATCH_PHONE')
        && str_contains($noteOf(41), 'COM_APPOINTMENTS_CLOSE_NOTE_EMAIL: private-address@example.test')
        && str_contains($noteOf(41), 'COM_APPOINTMENTS_CLOSE_NOTE_PHONE: +4100000041'),
        'without an e-mail match the telephone number finds the lead; the note says so and names the address and number used');
    $check($main->contacts['cont_phone']['emails'] === [['email' => 'office@phone.example.test']] && $main->objects['lead_phone'] === ['name' => 'Phone Company AG'],
        'the lead and contact found by telephone are not changed');

    // An e-mail match wins over a telephone match of another contact.
    $seed('cont_mail42', 'lead_mail42', 'Mail AG', 'Mail Person', 'mail42@example.test', '');
    $seed('cont_phone42', 'lead_phone42', 'Phone AG', 'Phone Person', 'other42@example.test', '+4100000042');
    $enrol($booking(42, 1, 'mail42@example.test', $lifeSciences));
    $queue->run();
    $check($state(42)['lead_id'] === 'lead_mail42' && $state(42)['match_rule'] === 'email', 'the e-mail address is searched first');

    // Several contacts with the number: never blocked, a new lead with both leads as possible duplicates.
    $seed('cont_dup43a', 'lead_dup43a', 'Dup A', 'Dup One', 'one43@example.test', '+4100000043');
    $seed('cont_dup43b', 'lead_dup43b', 'Dup B', 'Dup Two', 'two43@example.test', '+4100000043');
    $leadsBefore = $main->count('POST', '/api/v1/lead/');
    $main->searches = [];
    $enrol($booking(43, 1, 'nobody43@example.test', $lifeSciences));
    $queue->run();
    $check($state(43)['state'] === 'done' && $main->count('POST', '/api/v1/lead/') === $leadsBefore + 1
        && $state(43)['match_rule'] === 'ambiguous_phone' && $state(43)['match_candidates'] === 'lead_dup43a,lead_dup43b'
        && $main->searches === ['contact_email', 'contact_phone']
        && str_contains($noteOf(43), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_AMBIGUOUS_PHONE')
        && str_contains($noteOf(43), 'COM_APPOINTMENTS_CLOSE_NOTE_DUPLICATES: lead_dup43a, lead_dup43b'),
        'several telephone matches create a new lead and name the candidates; the name step is not tried');

    // 3. First and last name with the company, ignoring case and spacing.
    $seed('cont_name44', 'lead_name44', 'Muster  Pharma AG', 'Erika Muster', 'erika@muster.example.test', '');
    $row = $person($booking(44, 1, 'erika.private@example.test', ['11' => ' muster pharma ag'] + $lifeSciences), 'ERIKA', 'muster ');
    $row['invitee_phone'] = '';
    $main->searches = [];
    $enrol($row);
    $queue->run();
    $check($state(44)['lead_id'] === 'lead_name44' && $state(44)['contact_id'] === 'cont_name44' && $state(44)['match_rule'] === 'name_company'
        && $main->searches === ['contact_email', 'contact_name']
        && str_contains($noteOf(44), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_NAME_COMPANY')
        && $main->contacts['cont_name44']['emails'] === [['email' => 'erika@muster.example.test']],
        'without e-mail and telephone, name and company together find the lead, which stays unchanged');

    // The same name in another company is another person: a new lead, no duplicate.
    $row = $person($booking(45, 1, 'erika.other@example.test', ['11' => 'Other Pharma AG'] + $lifeSciences), 'Erika', 'Muster');
    $enrol($row);
    $queue->run();
    $check($state(45)['match_rule'] === 'none' && $state(45)['match_candidates'] === '' && $state(45)['lead_id'] !== 'lead_name44'
        && str_contains($noteOf(45), 'COM_APPOINTMENTS_CLOSE_NOTE_MATCH_NONE') && !str_contains($noteOf(45), 'DUPLICATES'),
        'the name alone never matches: another company gets a new lead');

    // Without a company answer the name step does not apply at all.
    $main->searches = [];
    $row = $person($booking(46, 2, 'erika.nocompany@example.test', []), 'Erika', 'Muster');
    $enrol($row);
    $queue->run();
    $check($state(46)['match_rule'] === 'none' && !in_array('contact_name', $main->searches, true),
        'without a company the name is not searched');

    // Several contacts with the name in leads of the same company: a new lead with both as duplicates.
    $seed('cont_name47a', 'lead_name47a', 'Twin GmbH', 'Max Twin', 'a47@example.test', '');
    $seed('cont_name47b', 'lead_name47b', 'twin gmbh', 'Max Twin', 'b47@example.test', '');
    $row = $person($booking(47, 1, 'max.private@example.test', ['11' => 'Twin GmbH'] + $lifeSciences), 'Max', 'Twin');
    $enrol($row);
    $queue->run();
    $check($state(47)['state'] === 'done' && $state(47)['match_rule'] === 'ambiguous_name_company'
        && $state(47)['match_candidates'] === 'lead_name47a,lead_name47b' && !in_array($state(47)['lead_id'], ['lead_name47a', 'lead_name47b'], true)
        && str_contains($noteOf(47), 'COM_APPOINTMENTS_CLOSE_NOTE_DUPLICATES: lead_name47a, lead_name47b'),
        'several name and company matches create a new lead and name the candidates');
    // Review finding: more contacts with the name than one search page of the e-mail step, only the last
    // one in the company. The company is checked on every contact with the name before anything is capped.
    for ($i = 1; $i <= 11; $i++) {
        $seed('cont_same48x' . $i, 'lead_same48x' . $i, $i === 11 ? 'Target AG' : 'Elsewhere ' . $i, 'Sam Same', 'same' . $i . '@example.test', '');
    }
    $leadsBefore = $main->count('POST', '/api/v1/lead/');
    $enrol($person($booking(48, 1, 'sam.private@example.test', ['11' => 'Target AG'] + $lifeSciences), 'Sam', 'Same'));
    $queue->run();
    $check($state(48)['match_rule'] === 'name_company' && $state(48)['lead_id'] === 'lead_same48x11'
        && $main->count('POST', '/api/v1/lead/') === $leadsBefore,
        'the only contact of the company is found among more same-named contacts than one page of the other steps');
    // Name results beyond one page are read page by page.
    for ($i = 1; $i <= 105; $i++) {
        $seed('cont_many49x' . $i, 'lead_many49x' . ($i % 40), $i === 105 ? 'Paged AG' : 'Other ' . ($i % 40), 'Pat Paged', 'pat' . $i . '@example.test', '');
    }
    $main->objects['lead_many49x25'] = ['name' => 'Paged AG'];
    $main->pages = [];
    $enrol($person($booking(49, 1, 'pat.private@example.test', ['11' => 'Paged AG'] + $lifeSciences), 'Pat', 'Paged'));
    $queue->run();
    $check(count(array_filter($main->pages, static fn (array $page): bool => $page[0] > 0)) >= 1
        && $state(49)['match_rule'] === 'ambiguous_name_company' && str_contains($state(49)['match_candidates'], 'lead_many49x25'),
        'the name step reads every result page and decides over all contacts with the name');
    $check(!str_contains(json_encode($all()), 'NEVER_'), 'the matching sends no unlisted answer, guest or conference capability');

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

    // A sequence change while the remote matching phase is checkpointed must not authenticate the old
    // booking snapshot after the rows are released.
    $racedMatch = $enrol($booking(51, 1, 'raced-match@example.test', $lifeSciences));
    $remote['main']->contacts['cont_raced_match'] = [
        'id' => 'cont_raced_match',
        'lead_id' => 'lead_raced_match',
        'emails' => [['email' => 'raced-match@example.test']],
        'name' => 'Raced Match',
    ];
    $db->afterCommit = static function () use ($db, $state, $mutate, $racedMatch): void {
        if (($state(51)['operation'] ?? '') === 'matching') {
            $db->afterCommit = null;
            $mutate($racedMatch, ['sequence' => 1]);
        }
    };
    $queue->run();
    $db->afterCommit = null;
    $check(($state(51)['match_rule'] ?? '') === 'email'
        && (int) $state(51)['target_sequence'] === 1
        && $state(51)['state'] === 'done',
        'matching revalidates a booking sequence changed after its checkpoint and resumes the new version');

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
        'activity_fields' => '{"uid":"cf_uid","sequence":"cf_seq","start":"cf_start","end":"cf_end","state":"cf_state","source":"cf_source","event_type":"cf_event","campaign":"cf_campaign","contact_created":"cf_new","matched_by":"cf_match"}',
        'activity_answers' => '{"13":"cf_topic"}'];
    $remote['activity'] = new FakeClose('activity');
    $activity = $enrol($booking(17, 5, 'activity@example.test', ['13' => 'Topic answer']));
    $queue->run();
    $custom = $remote['activity']->objects['acti_activity2'];
    $check($custom['kind'] === '/api/v1/activity/custom/' && $custom['custom.cf_event'] === 'Synthetic activity call'
        && $custom['custom.cf_campaign'] === 'Activity campaign' && $custom['custom.cf_new'] === 'yes'
        && $custom['custom.cf_topic'] === 'Topic answer' && $custom['custom.cf_state'] === 'confirmed' && $custom['custom.cf_match'] === 'none',
        'custom activity mode writes booking, campaign and answer fields');
    $mutate($activity, ['sequence' => 1, 'state' => 2]);
    $queue->run();
    $check($remote['activity']->objects['acti_activity2']['custom.cf_state'] === 'cancelled'
        && $remote['activity']->count('POST', '/api/v1/activity/custom/') === 1, 'custom activity is updated, never duplicated');

    // Keys entered per event type in the backend. Life Sciences (1) and IT (2) share the main organisation,
    // the product page (3) uses another one. All keys are synthetic dummies.
    $keyLs = 'api_SyntheticLifeSciencesKey0001.DummyOnlyAbCd';
    $keyIt = 'api_SyntheticItKey000000000002.DummyOnlyEfGh';
    $keyQr = 'api_SyntheticProductKey00003.DummyOnlyIjKl';
    $store = new CredentialStore($db);
    $store->put(1, $keyLs, 42);
    $store->put(2, $keyIt, 42);
    $store->put(3, $keyQr, 42);
    $stored = json_encode($db->pdo->query('SELECT * FROM test_appointments_close_credentials ORDER BY eventtype_id')->fetchAll(\PDO::FETCH_ASSOC));
    $check(count(json_decode($stored, true)) === 3 && !str_contains($stored, 'DummyOnly') && !str_contains($stored, 'Synthetic')
        && substr_count($stored, '"v1:') === 3, 'stored keys are sealed per event type and the database never holds a plain key');
    $check($store->status(1) === ['stored' => true, 'readable' => true, 'hint' => 'AbCd']
        && $store->status(4) === ['stored' => false, 'readable' => false, 'hint' => ''],
        'the status of a stored key reveals at most its last four characters');
    $sentWith = static function (string $email) use (&$keyed): array {
        return array_values(array_unique(array_column(array_filter($keyed,
            static fn (array $request): bool => str_contains($request[4], $email)), 1), SORT_REGULAR));
    };
    $keyed = [];
    $enrol($booking(21, 1, 'stored-ls@example.test', $lifeSciences));
    $enrol($booking(22, 2, 'stored-it@example.test', $lifeSciences));
    $enrol($booking(23, 3, 'stored-qr@example.test', ['31' => 'Stored Club']));
    $queue->run();
    $check($state(21)['state'] === 'done' && $state(22)['state'] === 'done' && $state(23)['state'] === 'done'
        && $sentWith('stored-ls@example.test') === [$keyLs] && $sentWith('stored-it@example.test') === [$keyIt]
        && $sentWith('stored-qr@example.test') === [$keyQr], 'every booking is sent with the key of its own event type');
    $check(!in_array(null, array_column($keyed, 1), true), 'a stored key takes precedence over the variable of the profile');
    foreach (['', 'api_with:colon', 'api with space'] as $unusable) {
        try {
            $store->put(3, $unusable, 42);
            $check(false, 'an unusable value is refused');
        } catch (\InvalidArgumentException $e) {
            $check($store->key(3) === $keyQr && !str_contains($e->getMessage(), $unusable === '' ? "\0" : $unusable),
                'an empty or unusable value never replaces a stored key: ' . strlen($unusable) . ' characters');
        }
    }
    try {
        $store->put(99, $keyQr, 42);
        $check(false, 'a key for a deleted event type is refused');
    } catch (\RuntimeException $e) {
        $check($store->status(99)['stored'] === false && !str_contains($e->getMessage(), 'DummyOnly'),
            'a key for an event type that no longer exists is refused and nothing is stored');
    }
    $store->delete(3);
    $keyed = [];
    $enrol($booking(24, 3, 'fallback-qr@example.test', ['31' => 'Fallback Club']));
    $queue->run();
    $check($store->status(3)['stored'] === false && $state(24)['state'] === 'done' && $sentWith('fallback-qr@example.test') === [null],
        'after deleting the key the variable of the profile applies again, so existing configurations keep working');

    // A changed site secret: the stored key is unreadable, nothing crashes, the variable still applies.
    $changed = new CredentialStore($db, 'another-synthetic-site-secret-after-a-change');
    $check($changed->key(1) === null && $changed->status(1) === ['stored' => true, 'readable' => false, 'hint' => ''],
        'a changed site secret makes a stored key unreadable without an error');
    $keyed = [];
    $enrol($booking(25, 1, 'secret-changed@example.test', $lifeSciences));
    (new Queue($db, $params, $factory, static fn (array $row): array => $row, $translate, 'Europe/Zurich', $changed))->run();
    $check($state(25)['state'] === 'done' && $sentWith('secret-changed@example.test') === [null],
        'with an unreadable key the transfer falls back to the profile variable');

    // A profile without a variable relies on the stored key alone.
    $check((new Profile(array_replace($profileQr, ['credential_env' => ''])))->credential === '', 'a profile may rely on stored keys alone');
    $params->values['close_profiles']['close_profiles1']['credential_env'] = '';
    $enrol($booking(26, 3, 'no-key@example.test', ['31' => 'No Key Club']));
    $queue->run();
    $check($state(26)['state'] === 'blocked' && $state(26)['reason'] === 'credential',
        'without a stored key and without a variable the profile blocks visibly');
    $store->put(3, $keyQr, 42);
    $queue->run(20, true);
    $check($state(26)['state'] === 'done' && $sentWith('no-key@example.test') === [$keyQr], 'a key entered later releases the blocked booking');

    // The key never leaves through an error: an HTTP error body or a transport message that contains it.
    $remote['qr']->mode = 'leak401';
    $enrol($booking(27, 3, 'leak-http@example.test', ['31' => 'Leak Club']));
    $queue->run();
    $remote['qr']->mode = 'leak';
    $enrol($booking(28, 3, 'leak-transport@example.test', ['31' => 'Leak Club']));
    $queue->run();
    $remote['qr']->mode = 'ok';
    $delivery = json_encode($db->pdo->query('SELECT * FROM test_appointments_close_delivery')->fetchAll(\PDO::FETCH_ASSOC));
    $check($state(27)['reason'] === 'http_401' && $state(28)['reason'] === 'transport' && !str_contains($delivery, 'DummyOnly'),
        'an HTTP error or a transport failure containing the key is recorded as a code only');
    try {
        (new Client(new Profile($profileQr), static fn () => throw new \RuntimeException('Failure for ' . $keyQr)))->request('GET', '/api/v1/me/');
        $check(false, 'a failing transport raises a delivery error');
    } catch (DeliveryException $e) {
        $check(!str_contains($e->getMessage(), 'DummyOnly') && $e->getPrevious() === null && !str_contains((string) $e, 'DummyOnly'),
            'a delivery error carries neither the key nor the original exception');
    }
    $params->values['close_profiles']['close_profiles1']['credential_env'] = 'SYNTHETIC_QR_KEY';

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
