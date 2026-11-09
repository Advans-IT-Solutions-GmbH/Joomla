<?php
/**
 * Standalone synthetic lifecycle test. No network, Joomla installation or credentials.
 * SQLite executes the queue SQL; MySQL advisory/row locks are represented, not concurrency-tested.
 * Run: php -d extension=pdo_sqlite tests/close-mock.php
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
        public string $database = 'synthetic_database';
        public array $lockNames = [];
        public ?\Closure $afterCommit = null;
        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE test_appointments_close (
                booking_id INTEGER PRIMARY KEY, uid TEXT, target_sequence INTEGER, erased INTEGER, request_id INTEGER,
                state TEXT, operation TEXT, reason TEXT, binding TEXT, lead_id TEXT, contact_id TEXT,
                contact_created INTEGER, activity_id TEXT, attempts INTEGER, next_attempt INTEGER, mac TEXT,
                organization_id TEXT, last_attempt INTEGER, rate_limit_until INTEGER, subject_hash TEXT)');
            $this->pdo->exec('CREATE TABLE test_appointments_bookings (
                id INTEGER PRIMARY KEY, uid TEXT, sequence INTEGER, anonymised_utc TEXT,
                invitee_name TEXT, invitee_email TEXT, invitee_phone TEXT, start_utc TEXT, end_utc TEXT,
                state INTEGER, custom_fields TEXT, guest_emails TEXT, conference_url TEXT)');
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_cleanup (request_id INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_outcomes (request_id INTEGER PRIMARY KEY, complete INTEGER)');
            $this->pdo->exec('CREATE TABLE test_appointments_privacy_staff (request_id INTEGER PRIMARY KEY, payload TEXT)');
            $this->pdo->exec('CREATE TABLE test_privacy_requests (id INTEGER PRIMARY KEY, email TEXT)');
        }
        public function getPrefix(): string { return 'test_'; }
        public function quoteName(string $name): string { return '`' . str_replace('#__', 'test_', $name) . '`'; }
        public function quote(string $value): string { return $this->pdo->quote($value); }
        public function setQuery(string $query): self
        {
            $this->sql = str_replace([' FOR UPDATE', 'ON DUPLICATE KEY UPDATE complete = VALUES(complete)'],
                ['', 'ON CONFLICT(request_id) DO UPDATE SET complete = excluded.complete'], $query);
            return $this;
        }
        public function loadAssoc(): array|false { return $this->pdo->query($this->sql)->fetch(\PDO::FETCH_ASSOC); }
        public function loadAssocList(): array { return $this->pdo->query($this->sql)->fetchAll(\PDO::FETCH_ASSOC); }
        public function execute(): bool { $this->pdo->exec($this->sql); return true; }
        public function loadResult(): mixed
        {
            if ($this->sql === 'SELECT DATABASE()') { return $this->database; }
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

    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) { throw new \RuntimeException('FAIL ' . $label); }
        $checks++;
        echo 'PASS ' . $label . "\n";
    };
    $params = new Params(['close_enabled' => 1, 'close_origin' => 'https://crm.example.test',
        'close_credential_env' => 'SYNTHETIC_CLOSE_KEY', 'close_type' => 'actitype_fixture',
        'close_fields' => json_encode(['uid' => 'cf_uid', 'sequence' => 'cf_seq', 'start' => 'cf_start',
            'end' => 'cf_end', 'state' => 'cf_state', 'source' => 'cf_source']),
        'close_questions' => '{"7":"cf_question"}', 'close_delete_activity' => 1]);
    ComponentHelper::$params = $params;
    $db = new MemoryDatabase();
    $staffScope = static function (int $id, bool $complete, bool $attemptPending = false) use ($db): void {
        $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider(
            (string) \Joomla\CMS\Factory::getApplication()->get('secret'));
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode(['user_id' => 0, 'address' => str_repeat('a', 64), 'complete' => $complete,
                'attempt_pending' => $attemptPending, 'attempt' => $attemptPending ? str_repeat('b', 32) : null], JSON_THROW_ON_ERROR),
            'appointments-privacy-staff:' . $id, $nonce, $keys->key('privacy-staff')));
        $stmt = $db->pdo->prepare('INSERT OR REPLACE INTO test_appointments_privacy_staff VALUES (?,?)');
        $stmt->execute([$id, $payload]);
    };
    $requests = [];
    $mode = 'existing';
    $activity = [];
    $transport = static function (string $method, string $path, ?array $payload) use (&$requests, &$mode, &$activity, $db): array {
        $requests[] = [$method, $path, $payload];
        if ($path === '/api/v1/custom_activity/actitype_fixture/') {
            $ids = ['uid', 'seq', 'start', 'end', 'state', 'source', 'question'];
            return ['code' => 200, 'data' => ['id' => 'actitype_fixture',
                'organization_id' => $mode === 'foreignorg' ? 'orga_other' : 'orga_fixture', 'fields' => array_map(static fn ($name) => [
                'id' => 'cf_' . $name, 'type' => 'text', 'custom_activity_type_id' => 'actitype_fixture',
            ], $ids)]];
        }
        if ($path === '/api/v1/data/search/') {
            $contact = ['id' => 'cont_fixture', 'lead_id' => 'lead_fixture', 'emails' => [['email' => 'invitee@example.test']]];
            return ['code' => 200, 'data' => ['data' => $mode === 'new' ? [] : ($mode === 'ambiguous' ? [$contact, $contact] : [$contact]), 'cursor' => null]];
        }
        if ($path === '/api/v1/lead/') {
            return ['code' => 200, 'data' => ['id' => 'lead_fixture', 'contacts' => [['id' => 'cont_fixture']]]];
        }
        if ($method === 'GET' && $path === '/api/v1/contact/cont_fixture/') {
            return $mode === 'manually_cleaned' ? ['code' => 404] : ['code' => 200, 'data' => ['id' => 'cont_fixture']];
        }
        if ($method === 'POST' && $path === '/api/v1/activity/custom/') {
            $activity = $payload + ['id' => 'acti_fixture'];
            if ($mode === 'timeout') { throw new \RuntimeException('Synthetic timeout after remote write.'); }
            if ($mode === '429') { return ['code' => 429, 'retry_after' => 60]; }
            if ($mode === '503') { return ['code' => 503]; }
            return ['code' => 200, 'data' => $activity];
        }
        if ($method === 'GET' && $path === '/api/v1/activity/custom/acti_fixture/') {
            return $mode === 'manually_cleaned' ? ['code' => 404] : ['code' => 200, 'data' => $activity];
        }
        if ($method === 'PUT') {
            $activity = array_replace($activity, $payload ?? []);
            return ['code' => 200, 'data' => $activity];
        }
        if ($method === 'DELETE') {
            $activity = [];
            return ['code' => 204, 'body' => ''];
        }
        throw new \RuntimeException('Unexpected synthetic request.');
    };
    $queue = new Queue($db, $params, $transport, static fn (array $row): array => $row);
    $booking = static fn (int $id): array => ['id' => $id, 'uid' => str_pad(dechex($id), 64, '0', STR_PAD_LEFT),
        'sequence' => 0, 'anonymised_utc' => null, 'invitee_name' => 'Synthetic Invitee', 'invitee_email' => 'invitee@example.test',
        'invitee_phone' => '+41000000000', 'start_utc' => '2027-01-10 10:00:00', 'end_utc' => '2027-01-10 11:00:00',
        'state' => 0, 'custom_fields' => '{"7":"Approved answer","8":"NEVER_EXPORT"}',
        'guest_emails' => 'NEVER_GUEST', 'conference_url' => 'NEVER_CAPABILITY'];
    $enrol = static function (int $id) use ($db, $booking): array {
        $row = $booking($id);
        $object = (object) $row;
        $db->insertObject('#__appointments_bookings', $object);
        $db->transactionStart();
        Queue::changed($db, $row, created: true);
        $db->transactionCommit();
        return $row;
    };
    $state = static fn (int $id): array|false => $db->setQuery('SELECT * FROM test_appointments_close WHERE booking_id = ' . $id)->loadAssoc();
    $mutate = static function (array $row, array $fields, bool $erase = false, int $request = 0) use ($db): array {
        $row = array_replace($row, $fields);
        $db->transactionStart();
        $object = (object) $row;
        $db->updateObject('#__appointments_bookings', $object, 'id');
        Queue::changed($db, $row, $erase, $request);
        $db->transactionCommit();
        return $row;
    };
    $posts = static function () use (&$requests): int {
        return count(array_filter($requests, static fn ($request): bool =>
            $request[0] === 'POST' && $request[1] !== '/api/v1/data/search/'));
    };

    $params->values['close_enabled'] = 0;
    $row = $enrol(1);
    $check($state(1) === false && $queue->run()['disabled'] === 1 && $requests === [], 'disabled: no enrolment, network or fake erasure obligation');
    $mutate($row, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true);
    $check(!$queue->hasPendingErasure(1), 'never enrolled erasure completes locally');
    $params->values['close_enabled'] = 1;
    $row = $enrol(2);
    $check($queue->run()['failed'] === 0 && $state(2)['state'] === 'done', 'pending booking creates activity for exact existing contact');
    $check($posts() === 1, 'existing contact: no contact or lead write');
    $serialized = json_encode($requests);
    $check(!str_contains($serialized, 'NEVER_') && str_contains($serialized, 'Approved answer'), 'only whitelisted answers, no guest or capability payload');
    $check($activity['custom.cf_state'] === 'pending' && $activity['status'] === 'published', 'booking state is not activity publication status');
    $before = count($requests);
    $queue->run();
    $check(count($requests) === $before, 'completed UID/sequence is not replayed');
    $row = $mutate($row, ['state' => 1, 'sequence' => 1]);
    $queue->run();
    $check($activity['custom.cf_state'] === 'confirmed' && $posts() === 1, 'confirmation updates same remote activity');
    $db->transactionStart();
    Queue::changed($db, array_replace($row, ['sequence' => 0]));
    $db->transactionCommit();
    $before = count($requests);
    $queue->run();
    $check(count($requests) === $before && (int) $state(2)['target_sequence'] === 1, 'stale and duplicate lifecycle versions cannot regress delivery');
    $row = $mutate($row, ['sequence' => 2, 'start_utc' => '2027-01-11 10:00:00', 'end_utc' => '2027-01-11 11:00:00']);
    $queue->run();
    $check($activity['custom.cf_start'] === '2027-01-11T10:00:00Z' && $activity['custom.cf_seq'] === '2', 'reschedule updates UTC times and sequence');
    $row = $mutate($row, ['sequence' => 3, 'state' => 2]);
    $queue->run();
    $check($activity['custom.cf_state'] === 'cancelled' && $posts() === 1, 'cancellation updates instead of duplicating booking');
    $row = $mutate($row, ['sequence' => 4, 'anonymised_utc' => '2027-01-01 00:00:00', 'invitee_email' => null], true, 77);
    $check($queue->pendingPrivacy(77) === 1, 'formal request retains erasure provenance');
    $db->pdo->exec('DELETE FROM test_appointments_bookings WHERE id = 2');
    $mode = 'foreignorg';
    $before = count(array_filter($requests, static fn ($request) => $request[0] === 'DELETE'));
    $queue->run();
    $check($queue->pendingPrivacy(77) === 1 && $state(2)['reason'] === 'organization_changed'
        && count(array_filter($requests, static fn ($request) => $request[0] === 'DELETE')) === $before,
        'credential organization change cannot delete elsewhere or clear original privacy obligation');
    $mode = 'existing';
    $queue->run(20, true, 77);
    $check($activity === [] && $queue->pendingPrivacy(77) === 0, 'remote activity cleanup survives hard-deleted canonical booking');

    $row = $enrol(3);
    $mode = 'ambiguous';
    $before = $posts();
    $queue->run();
    $check($state(3)['state'] === 'blocked' && $state(3)['reason'] === 'ambiguous_contact' && $posts() === $before,
        'multiple email matches block visibly without selecting a lead');
    $mutate($row, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true);
    $check(!$queue->hasPendingErasure(3), 'blocked never-sent booking purges locally without remote work');

    $mode = 'timeout';
    $row = $enrol(4);
    $queue->run();
    $check($state(4)['state'] === 'uncertain', 'timeout after create becomes durable uncertain');
    $before = count($requests);
    $queue->run(20, true);
    $check(count($requests) === $before, 'manual safe retry does not replay uncertain create');
    $mode = 'foreignorg';
    try { $queue->reconcile(4, 'acti_fixture'); $check(false, 'foreign organization reconciliation rejected'); }
    catch (DeliveryException $e) { $check($state(4)['state'] === 'uncertain', 'foreign organization cannot reconcile an uncertain Activity'); }
    $mode = 'existing';
    $queue->reconcile(4, 'acti_fixture');
    $queue->run();
    $check($state(4)['activity_id'] === 'acti_fixture' && $state(4)['state'] === 'done', 'verified reconciliation resumes via PUT without POST');
    $params->values['close_delete_activity'] = 0;
    $row = $mutate($row, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00', 'invitee_email' => null], true);
    $db->pdo->exec('DELETE FROM test_appointments_bookings WHERE id=4');
    $db->pdo->exec("INSERT INTO test_privacy_requests VALUES (89,'invitee@example.test'),(189,' INVITEE@example.test '),(289,'unrelated@example.test')");
    $params->values['close_enabled'] = 0;
    $check($state(4)['request_id'] === 0 && $queue->pendingPrivacy(89) === 1,
        'later formal request independently discovers unassociated cleanup after canonical hard deletion');
    $queue->associatePrivacy('invitee@example.test', 89);
    $queue->associatePrivacy(' INVITEE@example.test ', 189);
    $staffScope(189, true);
    PrivacyOutcome::record($db, 189, true);
    $check(PrivacyOutcome::incomplete($db, 189) && $queue->pendingPrivacy(89) === 1,
        'new request cannot complete or hide first request while same-subject CRM cleanup remains pending');
    $check($queue->pendingPrivacy(289) === 0, 'unrelated subject inherits no known CRM obligation');
    $authentic = $state(4);
    $legacy = $authentic;
    unset($legacy['subject_hash']);
    $legacy['request_id'] = 0;
    $legacy['mac'] = (new \ReflectionMethod(Queue::class, 'mac'))->invoke(null, $legacy);
    $legacy['subject_hash'] = '';
    $legacyRow = (object) $legacy;
    $db->updateObject(Queue::TABLE, $legacyRow, 'booking_id');
    $check($queue->pendingPrivacy(289) === 1,
        'legacy erased pending cleanup with unrecoverable subject fails closed for later requests');
    $authenticRow = (object) $authentic;
    $db->updateObject(Queue::TABLE, $authenticRow, 'booking_id');
    $db->pdo->exec("UPDATE test_appointments_close SET subject_hash='" . str_repeat('f', 64) . "' WHERE booking_id=4");
    $rejected = false;
    try { $queue->pendingPrivacy(189); }
    catch (\RuntimeException $error) { $rejected = true; }
    $check($rejected, 'subject tampering cannot filter an unauthenticated obligation out of completion checks');
    $db->updateObject(Queue::TABLE, $authenticRow, 'booking_id');
    $check($queue->run()['disabled'] === 1 && $queue->pendingPrivacy(189) === 1,
        'disabled delivery preserves cross-request erasure obligation without network');
    $params->values['close_enabled'] = 1;
    $queue->associatePrivacy('invitee@example.test', 89);
    $staffScope(89, true);
    PrivacyOutcome::record($db, 89, true);
    $check(PrivacyOutcome::incomplete($db, 89), 'native Complete gate rejects a success flag while own remote cleanup is pending');
    $db->pdo->exec('UPDATE test_appointments_privacy_outcomes SET complete=1 WHERE request_id=89');
    $check(PrivacyOutcome::incomplete($db, 89), 'stale successful formal outcome does not hide durable remote obligation');
    $mode = 'manually_cleaned';
    $activity = [];
    $before = $posts() + count(array_filter($requests, static fn ($request) => in_array($request[0], ['PUT', 'DELETE'], true)));
    $queue->run(20, true, 89);
    $check($posts() + count(array_filter($requests, static fn ($request) => in_array($request[0], ['PUT', 'DELETE'], true))) === $before,
        'manually erased own Activity is verified without automated deletion approval or any writes');
    PrivacyOutcome::record($db, 89, true);
    $check(!PrivacyOutcome::incomplete($db, 89), 'native outcome can complete only after verified own cleanup');
    PrivacyOutcome::record($db, 189, true);
    $check(!PrivacyOutcome::incomplete($db, 189) && $state(4)['subject_hash'] === '',
        'verified cleanup releases both requests and drops the no-longer-needed subject association');
    $staffScope(89, true, true);
    PrivacyOutcome::record($db, 89, true);
    $check(PrivacyOutcome::incomplete($db, 89), 'resolved CRM and staff rows cannot hide an unfinished authenticated removal attempt');
    $db->pdo->exec('UPDATE test_appointments_privacy_outcomes SET complete=1 WHERE request_id=89');
    $check(PrivacyOutcome::incomplete($db, 89), 'stale success independently checks the authenticated attempt marker after CRM cleanup');
    $staffScope(89, false);
    PrivacyOutcome::record($db, 89, true);
    $check(PrivacyOutcome::incomplete($db, 89), 'resolved CRM cleanup cannot mask pending authenticated staff cleanup');
    $db->pdo->exec('UPDATE test_appointments_privacy_outcomes SET complete=1 WHERE request_id=89');
    $check(PrivacyOutcome::incomplete($db, 89), 'stale successful outcome independently checks both CRM and staff provenance');
    $staffScope(89, true);
    PrivacyOutcome::record($db, 89, true);
    $check(!PrivacyOutcome::incomplete($db, 89), 'both independently resolved scopes allow the formal outcome');
    $db->pdo->exec('DELETE FROM test_appointments_privacy_staff WHERE request_id=89');
    $check(PrivacyOutcome::incomplete($db, 89), 'missing required staff provenance fails closed after CRM cleanup succeeds');
    $staffScope(89, true);

    $params->values['close_delete_activity'] = 1;
    $mode = 'new';
    $row = $enrol(5);
    $queue->run();
    $check($state(5)['contact_created'] === 1 && $state(5)['state'] === 'done', 'new invitee creates lead with contact, then linked activity');
    $mutate($row, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true, 78);
    $queue->run();
    $check($queue->pendingPrivacy(78) === 1 && $state(5)['reason'] === 'contact_retention_review', 'new CRM contact retention is not falsely reported as complete erasure');
    $params->values['close_retain_contact'] = 1;
    $queue->run(20, true);
    $check($queue->pendingPrivacy(78) === 0, 'explicit separate contact retention policy resolves only own outstanding review');
    $check(count(array_filter($requests, static fn ($request): bool => $request[0] === 'DELETE' && !str_contains($request[1], '/activity/custom/'))) === 0,
        'never delete CRM contact or lead');
    $params->values['close_retain_contact'] = 0;
    $mode = 'new';
    $manual = $enrol(12);
    $queue->run();
    $mutate($manual, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true, 90);
    $queue->run();
    $check($queue->pendingPrivacy(90) === 1, 'existing own Contact still requires retention or proven manual cleanup');
    $mode = 'manually_cleaned';
    $before = count($requests);
    $queue->run(20, true, 90);
    $newRequests = array_slice($requests, $before);
    $check($queue->pendingPrivacy(90) === 0 && $params->get('close_retain_contact') === 0
        && count(array_filter($newRequests, static fn ($request) => $request[0] !== 'GET')) === 0,
        'read-only proof of manual own Contact cleanup resolves without enabling global retention');

    $mode = '429';
    $rateLimited = $enrol(6);
    $queue->run();
    $check($state(6)['state'] === 'retry' && $state(6)['next_attempt'] >= time() + 55, 'rate limit schedules Retry-After');
    $before = count($requests);
    $queue->run();
    $check(count($requests) === $before, 'scheduled retry delay is respected');
    $retryAt = $state(6)['next_attempt'];
    $mutate($rateLimited, ['sequence' => 1, 'state' => 1]);
    $queue->run();
    $check(count($requests) === $before && $state(6)['next_attempt'] === $retryAt,
        'canonical mutation preserves outstanding Retry-After');
    $duringCooldown = $enrol(11);
    $queue->run();
    $check(count($requests) === $before, 'shared cooldown blocks newly enrolled bookings before any network call');
    $mutate($rateLimited, ['sequence' => 2, 'anonymised_utc' => '2027-01-01 00:00:00'], true);
    $mutate($duringCooldown, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true);
    $queue->run();
    $check(count($requests) === $before, 'shared cooldown survives local erasure of rate-limited booking');
    $GLOBALS['close_mock_time'] = time() + 61;

    $mode = '503';
    $enrol(7);
    $queue->run();
    $check($state(7)['state'] === 'uncertain', 'server error during POST is not assumed to be safe to repeat');
    $mode = 'existing';
    $row10 = $enrol(10);
    $beforeWrites = $posts();
    $db->afterCommit = static function () use ($db, $state, $mutate, $row10): void {
        if (($state(10)['reason'] ?? '') === 'in_flight') {
            $db->afterCommit = null;
            $mutate($row10, ['sequence' => 1, 'anonymised_utc' => '2027-01-01 00:00:00'], true);
        }
    };
    $queue->run();
    $check($posts() === $beforeWrites && !$queue->hasPendingErasure(10), 'erasure between intent and send prevents stale PII delivery');
    $db->afterCommit = null;
    $db->locked = true;
    $before = count($requests);
    $refused = false;
    try { $queue->run(); }
    catch (\RuntimeException $e) { $refused = $e->getMessage() === 'Close delivery is already running.'; }
    $check($refused && count($requests) === $before, 'competing worker refused before network');
    $db->locked = false;
    $enrol(8);
    $db->failWrite = true;
    $queue->run();
    $check($state(8)['state'] === 'uncertain', 'persistence failure after remote create retains precommitted intent');
    $otherDb = new MemoryDatabase();
    $otherDb->database = 'synthetic_other_database';
    $otherQueue = new Queue($otherDb, $params, $transport, static fn (array $row): array => $row);
    $seed = static function (int $id, bool $inexact = false) use ($otherDb, $booking): void {
        $row = $booking($id);
        if ($inexact) { $row['invitee_email'] = 'blocked@example.test'; }
        $object = (object) $row;
        $otherDb->insertObject('#__appointments_bookings', $object);
        $otherDb->transactionStart();
        Queue::changed($otherDb, $row, created: true);
        $otherDb->transactionCommit();
    };
    $mode = 'timeout';
    $seed(1);
    $otherQueue->run();
    $check($db->lockNames[0] !== $otherDb->lockNames[0], 'same-prefix installations in different databases use distinct named locks');
    $mode = 'ambiguous';
    for ($id = 2; $id <= 36; $id++) { $seed($id, $id <= 26); }
    $otherQueue->run(100);
    $mode = 'existing';
    $otherQueue->run(20, true);
    $otherQueue->run(20, true);
    $check((int) $otherDb->pdo->query("SELECT COUNT(*) FROM test_appointments_close WHERE booking_id>=27 AND state='done'")->fetchColumn() === 10,
        'failed blocked rechecks rotate so later repaired deliveries are reachable');
    for ($id = 37; $id <= 140; $id++) { $seed($id); }
    $oldPage = $otherQueue->rows(100);
    $check($otherQueue->count() === 140 && count($otherQueue->rows()) === 100
        && count(array_filter($oldPage, static fn ($item) => (int) $item['booking_id'] === 1 && $item['state'] === 'uncertain')) === 1,
        'pagination keeps old uncertain delivery reachable beyond one hundred newer records');
    $row = $enrol(9);
    $db->transactionStart();
    $object = (object) ['id' => 9, 'sequence' => 1];
    $db->updateObject('#__appointments_bookings', $object, 'id');
    Queue::changed($db, array_replace($row, ['sequence' => 1]));
    $db->transactionRollback();
    $check((int) $state(9)['target_sequence'] === 0, 'booking rollback also rolls back enqueue');
    $db->pdo->exec("UPDATE test_appointments_close SET activity_id='acti_forged' WHERE booking_id=9");
    $rejected = false;
    try { $queue->run(); }
    catch (\RuntimeException $e) { $rejected = true; }
    $check($rejected, 'metadata tampering rejected');

    foreach (['http://crm.example.test', 'https://user@crm.example.test', 'https://crm.example.test/path'] as $origin) {
        try { new Settings(new Params(array_replace($params->values, ['close_origin' => $origin]))); $check(false, 'invalid origin rejected'); }
        catch (\InvalidArgumentException $e) { $check(true, 'invalid origin rejected'); }
    }
    try { new Settings(new Params([])); $check(false, 'unconfigured settings blocked'); }
    catch (\InvalidArgumentException $e) { $check(true, 'unconfigured settings blocked before network'); }
    $before = count($requests);
    try { (new Queue($db, new Params(['close_enabled' => 1]), $transport))->run(); $check(false, 'incomplete activation blocked'); }
    catch (\InvalidArgumentException $e) { $check(count($requests) === $before, 'incomplete activation makes no external calls'); }
    $fieldIds = array_merge(array_values((new Settings($params))->fields), ['cf_question']);
    foreach (['foreign_type', 'wrong_type', 'multiple_values', 'unmapped_required'] as $fault) {
        $catalog = array_map(static fn ($id) => ['id' => $id,
            'custom_activity_type_id' => $fault === 'foreign_type' ? 'actitype_other' : 'actitype_fixture',
            'type' => $fault === 'wrong_type' ? 'number' : 'text', 'accepts_multiple_values' => $fault === 'multiple_values'], $fieldIds);
        if ($fault === 'unmapped_required') {
            $catalog[] = ['id' => 'cf_missing', 'custom_activity_type_id' => 'actitype_fixture', 'type' => 'text', 'required' => true];
        }
        $client = new \Advans\Component\Appointments\Administrator\Service\Close\Client(new Settings($params),
            static fn () => ['code' => 200, 'data' => [
                'id' => $fault === 'foreign_type' ? 'actitype_other' : 'actitype_fixture',
                'organization_id' => 'orga_fixture', 'fields' => $catalog]]);
        try { $client->validateFields(); $check(false, 'invalid catalog blocked'); }
        catch (\Advans\Component\Appointments\Administrator\Service\Close\DeliveryException $e) {
            $check($e->state === 'blocked', 'field catalog rejects ' . $fault);
        }
    }
    $check(!str_contains(json_encode($db->pdo->query('SELECT * FROM test_appointments_close')->fetchAll(\PDO::FETCH_ASSOC)), 'invitee@example.test'),
        'durable delivery queue contains no invitee payload');
    echo "Close synthetic lifecycle: {$checks} checks passed; no network or credentials used.\n";
}
