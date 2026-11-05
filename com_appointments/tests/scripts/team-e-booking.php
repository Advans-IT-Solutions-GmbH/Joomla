<?php
/**
 * Work package E: booking page and internet calendars.
 *
 * - Schema and installer defaults of E.
 * - Switched on, a calendar error never fails a booking (runs against any stub).
 * - End to end, once A, B and C ship their services: a calendar event blocks a
 *   time slot, a booking lands sealed in the target calendar with the booking's
 *   UID, cancellation and anonymisation remove it. Until then these tests are
 *   reported as pending.
 *
 * Cleans up every row it creates, including its own recovery key, while
 * preserving any recovery key that existed before this fixture.
 */

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SlotTakenException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Administrator\Service\BookingRecords;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridge;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Advans\Component\Appointments\Administrator\Service\Team\TimingIndex;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

return static function (TeamSuite $suite): void {
    $suite->test('Event types carry an optional target calendar', static function () use ($suite) {
        $result = $suite->db->query("SHOW COLUMNS FROM {$suite->prefix}appointments_eventtypes LIKE 'calendar_id'");
        $row = $result ? $result->fetch_assoc() : null;

        return $row && $row['Null'] === 'YES';
    });

    $suite->test('A fresh installation records the token feeds as switched off', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $row = $result ? $result->fetch_assoc() : null;
        $params = $row ? json_decode((string) $row['params'], true) : null;

        return is_array($params) && ($params['legacy_feeds'] ?? null) === '0';
    });

    require_once __DIR__ . '/appointments-fixtures.php';
    require_once __DIR__ . '/appointments-joomla.php';

    $suite->test('The timing index and the booking bridge are implemented', static function () use ($suite) {
        return !$suite->isStub(TimingIndex::class) && !$suite->isStub(BookingCalendarBridge::class);
    });

    $sample = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:e-sample\r\nDTSTAMP:20270101T000000Z\r\n"
        . "DTSTART;TZID=Europe/Zurich:20270329T100000\r\nDTEND;TZID=Europe/Zurich:20270329T110000\r\nSUMMARY:Private\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    if (!VendorAutoloader::register()) {
        $suite->pending('The timing index runs on the bundled calendar library', 'A');
    } else {
        $suite->test('The timing index runs on the bundled calendar library', static function () use ($sample) {
            $index = new TimingIndex();
            $skeleton = $index->extract($sample);
            $windows = $index->expand($skeleton, new DateTimeImmutable('2027-03-29 00:00:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2027-03-30 00:00:00', new DateTimeZone('UTC')), new DateTimeZone('UTC'));

            return !str_contains($skeleton, 'UID') && !str_contains($skeleton, 'Private') && count($windows) === 1
                && $windows[0]->getStart()->format('Y-m-d H:i') === '2027-03-29 08:00';
        });
    }

    $fixture = new AppointmentsFixture('team-e');
    $originalParams = (string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0];
    $switch = static function (bool $on) use ($suite, $originalParams): void {
        $params = json_decode($originalParams, true) ?: [];
        $params['teamcal_enabled'] = $on ? '1' : '0';
        $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string(json_encode($params))
            . "' WHERE type='component' AND element='com_appointments'");
        ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', $on ? '1' : '0');
    };

    try {
        $suite->test('The migration seals whole rows of 1.3 and leaves a row with a sealed field alone', static function () use ($suite, $fixture) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $keys = new KeyProvider((string) (new JConfig())->secret);
            $event = $fixture->event();
            $created = '2026-01-05 08:00:00';
            $insert = static function (array $fields) use ($suite, $event, $created): int {
                $row = ['uid' => bin2hex(random_bytes(32)), 'eventtype_id' => $event, 'start_utc' => '2026-01-10 09:00:00',
                    'end_utc' => '2026-01-10 09:30:00', 'state' => 2, 'created_utc' => $created, 'modified_utc' => $created];
                $row = array_replace($row, $fields);
                $suite->db->query("INSERT INTO {$suite->prefix}appointments_bookings (`" . implode('`,`', array_keys($row)) . "`) VALUES ('"
                    . implode("','", array_map([$suite->db, 'real_escape_string'], array_map('strval', $row))) . "')");

                return (int) $suite->db->insert_id;
            };
            $legacy = $insert(['invitee_name' => 'Legacy Invitee', 'invitee_email' => 'legacy@example.test', 'guest_emails' => 'guest@example.test']);
            $mixedUid = bin2hex(random_bytes(32));
            $mixed = $insert(['uid' => $mixedUid, 'invitee_name' => 'Sealed Invitee', 'invitee_email' => 'planted@example.test']);
            $sealedName = (new BookingData($keys))->seal($fixture->stored($mixed))['invitee_name'];
            $suite->db->query("UPDATE {$suite->prefix}appointments_bookings SET invitee_name='"
                . $suite->db->real_escape_string($sealedName) . "' WHERE id={$mixed}");
            $v1 = 'v1:' . base64_encode(random_bytes(64));
            $unsupported = $insert(['invitee_name' => $v1, 'invitee_email' => 'unsupported@example.test']);

            // A migration that runs (the token of an update one minute ago).
            $params = json_decode((string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0], true) ?: [];
            $params[BookingData::MIGRATION_OPTION] = BookingData::migrationToken($keys, time() + 86400, time() - 60);
            $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string(json_encode($params))
                . "' WHERE type='component' AND element='com_appointments'");

            for ($round = 0; $round < 50 && BookingRecords::migrate($db, $keys, 500) > 0; $round++) {
            }

            $data = new BookingData($keys);
            $stored = $fixture->stored($legacy);
            $sealed = BookingData::isSealed($stored['invitee_name']) && BookingData::isSealed($stored['invitee_email']) && BookingData::isSealed($stored['guest_emails']);
            $opened = $data->open($stored);
            $failed = [];
            $planted = $fixture->stored($mixed);
            $mixedOpened = $data->openLenient($planted, $failed);
            $unsupportedStored = $fixture->stored($unsupported);
            $unsupportedFailed = [];
            $unsupportedOpened = $data->openLenient($unsupportedStored, $unsupportedFailed);
            $token = (string) ((json_decode((string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0], true) ?: [])[BookingData::MIGRATION_OPTION] ?? '');

            return $sealed && $opened['invitee_email'] === 'legacy@example.test' && $opened['guest_emails'] === 'guest@example.test'
                && $planted['invitee_email'] === 'planted@example.test' && $mixedOpened['invitee_email'] === null && $failed === ['invitee_email']
                && $unsupportedStored['invitee_name'] === $v1 && $unsupportedStored['invitee_email'] === 'unsupported@example.test'
                && $unsupportedOpened['invitee_name'] === null && $unsupportedOpened['invitee_email'] === null
                && $unsupportedFailed === ['invitee_name', 'invitee_email']
                && $mixedOpened['invitee_name'] === 'Sealed Invitee' && BookingData::migrationDone($token, $keys);
        });

        $suite->test('After the migration window no booking becomes unreadable, and only the administrator action encrypts it', static function () use ($suite, $fixture) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $keys = new KeyProvider((string) (new JConfig())->secret);
            $event = $fixture->event();
            $created = '2026-01-05 08:00:00';
            $uid = bin2hex(random_bytes(32));
            $suite->db->query("INSERT INTO {$suite->prefix}appointments_bookings (uid, eventtype_id, start_utc, end_utc, state, created_utc, modified_utc,"
                . " invitee_name, invitee_email) VALUES ('{$uid}', {$event}, '2026-01-12 09:00:00', '2026-01-12 09:30:00', 2, '{$created}', '{$created}',"
                . " 'Window Invitee', 'window@example.test')");
            $id = (int) $suite->db->insert_id;
            $readParams = static fn (): array => json_decode((string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0], true) ?: [];

            // An update 100 days ago whose migration never finished: the clock is past the window.
            $cutoff = time() - 100 * 86400;
            $token = BookingData::migrationToken($keys, $cutoff + BookingData::MIGRATION_DAYS * 86400, $cutoff);
            $params = $readParams();
            $params[BookingData::MIGRATION_OPTION] = $token;
            $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string(json_encode($params))
                . "' WHERE type='component' AND element='com_appointments'");

            $migrated = BookingRecords::migrate($db, $keys, 500);
            $stored = $fixture->stored($id);
            $expect = static function (bool $condition, string $failure): void {
                if (!$condition) {
                    throw new RuntimeException($failure);
                }
            };
            $expect($migrated === 0, 'The expired migration window must not automatically encrypt bookings.');
            $expect($stored !== null, 'The expired-window booking must remain present in the database.');
            $expect($stored['invitee_email'] === 'window@example.test' && !BookingData::isSealed((string) $stored['invitee_email']),
                'The expired-window booking must remain stored as readable legacy data.');
            $failed = [];
            $opened = BookingData::forToken($keys, $token)->openLenient($stored, $failed);
            $expect($failed === [] && $opened['invitee_email'] === 'window@example.test',
                'The expired-window legacy booking must still open without unreadable fields.');
            $expect(!empty($opened[BookingData::UNVERIFIED]), 'An expired-window legacy booking must be marked not verified.');
            $expect(BookingRecords::unverified($db, $keys) >= 1, 'The expired-window booking must be counted as unverified.');

            // The booking list shows it as not verified, and the action needs the form token.
            $admin = new AppointmentsHttp('http://localhost', 'team-e-admin');
            $admin->login();
            $list = $admin->request('/administrator/index.php?option=com_appointments&view=bookings&filter_eventtype_id=' . $event);
            $shown = str_contains($list, 'Window Invitee') && str_contains($list, 'Not verified') && str_contains($list, 'bookings.encrypt');
            $expect($shown, 'The administrator booking list must show the unverified legacy row and the encrypt action.');
            $admin->request('/administrator/index.php?option=com_appointments&task=bookings.encrypt', ['task' => 'bookings.encrypt']);
            $refused = $admin->status === 403 && $fixture->stored($id)['invitee_email'] === 'window@example.test';
            $expect($refused, 'The encrypt action must reject a missing form token without changing the booking.');
            $admin->request('/administrator/index.php?option=com_appointments&task=bookings.encrypt', [$admin->token($list) => 1]);
            $sealed = $fixture->stored($id);
            $expect($sealed !== null && BookingData::isSealed((string) $sealed['invitee_email'])
                && BookingData::isSealed((string) $sealed['invitee_name']), 'The authorized administrator action must encrypt every legacy personal field.');
            $expect((new BookingData($keys))->open($sealed)['invitee_email'] === 'window@example.test',
                'The administrator-encrypted booking must still open to its original address.');
            $audit = $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_audit WHERE booking_id={$id} AND action='encrypt' AND path='admin'");
            $expect($audit === 1, 'The administrator encryption must create exactly one audit row.');
            $done = BookingData::migrationDone((string) ($readParams()[BookingData::MIGRATION_OPTION] ?? ''), $keys);
            $expect($done, 'The administrator action must persist the migration completion marker.');

            return true;
        });

        $suite->test('An audit SQL failure rolls back legacy encryption and a retry records each booking once', static function () use ($suite, $fixture) {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $keys = new KeyProvider((string) (new JConfig())->secret);
            $event = $fixture->event();
            $cutoff = time() - (BookingData::MIGRATION_LIMIT_DAYS + 10) * 86400;
            $created = gmdate('Y-m-d H:i:s', $cutoff - 86400);
            $ids = [];

            foreach ([0, 1] as $index) {
                $uid = bin2hex(random_bytes(32));
                $suite->db->query("INSERT INTO {$suite->prefix}appointments_bookings"
                    . " (uid,eventtype_id,host_user_id,start_utc,end_utc,state,created_utc,modified_utc,invitee_name,invitee_email)"
                    . " VALUES ('{$uid}',{$event},{$fixture->host},'{$created}','{$created}',2,'{$created}','{$created}',"
                    . " 'Audit Invitee {$index}','audit{$index}@example.test')");
                $ids[] = (int) $suite->db->insert_id;
            }

            $params = json_decode((string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions"
                . " WHERE type='component' AND element='com_appointments'")->fetch_row()[0], true) ?: [];
            $token = BookingData::migrationToken($keys, $cutoff + BookingData::MIGRATION_DAYS * 86400, $cutoff);
            $params[BookingData::MIGRATION_OPTION] = $token;
            $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string(json_encode($params))
                . "' WHERE type='component' AND element='com_appointments'");
            $trigger = 'suite_e_audit_' . bin2hex(random_bytes(6));
            $suite->db->query("CREATE TRIGGER `{$trigger}` BEFORE INSERT ON {$suite->prefix}appointments_audit FOR EACH ROW"
                . " BEGIN IF NEW.booking_id={$ids[1]} AND NEW.action='encrypt' THEN SIGNAL SQLSTATE '45000'"
                . " SET MESSAGE_TEXT='Injected encryption audit failure'; END IF; END");

            try {
                try {
                    BookingRecords::encryptRemaining($db, $keys, $fixture->host);

                    throw new RuntimeException('The audit SQL failure must propagate.');
                } catch (\Joomla\Database\Exception\ExecutionFailureException $exception) {
                    if (!str_contains($exception->getMessage(), 'Injected encryption audit failure')) {
                        throw $exception;
                    }
                }

                foreach ($ids as $index => $id) {
                    if ($fixture->stored($id)['invitee_email'] !== "audit{$index}@example.test") {
                        throw new RuntimeException('A failed audit insert must roll back every encrypted row in the batch.');
                    }
                }

                if ($suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_audit WHERE booking_id IN ("
                    . implode(',', $ids) . ") AND action='encrypt'") !== 0) {
                    throw new RuntimeException('A failed audit insert must leave no partial encrypt audit rows.');
                }
            } finally {
                $suite->db->query("DROP TRIGGER `{$trigger}`");
            }

            if (BookingRecords::encryptRemaining($db, $keys, $fixture->host) < 2) {
                throw new RuntimeException('Retrying after an audit failure must encrypt the rolled-back rows.');
            }

            foreach ($ids as $index => $id) {
                $row = $fixture->stored($id);
                if (!BookingData::isSealed($row['invitee_email'])
                    || (new BookingData($keys))->open($row)['invitee_email'] !== "audit{$index}@example.test"
                    || $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_audit WHERE booking_id={$id} AND action='encrypt'") !== 1) {
                    throw new RuntimeException('A successful retry must keep each address readable and record exactly one audit row.');
                }
            }

            return true;
        });

        $suite->test('A sealing failure rolls back the reserved booking identity', static function () use ($suite, $fixture) {
            $event = $fixture->event();
            $eventRow = $fixture->db->query("SELECT * FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $start = $now->modify('+5 days')->setTime(16, 0);

            try {
                (new BookingStore(Factory::getContainer()->get(DatabaseInterface::class)))->create($eventRow,
                    ['invitee_name' => new stdClass(), 'invitee_email' => 'rollback@example.test'], $start, $now, [1], 'en-GB');

                throw new RuntimeException('An invalid personal field must fail sealing after the identity is reserved.');
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'A booking field must be text.') {
                    throw $exception;
                }
            }

            return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_bookings WHERE eventtype_id={$event}") === 0;
        });

        $suite->test('An encrypted update failure rolls back the reserved booking identity', static function () use ($suite, $fixture) {
            $event = $fixture->event();
            $eventRow = $fixture->db->query("SELECT * FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
            $trigger = 'suite_e_' . bin2hex(random_bytes(6));
            $suite->db->query("CREATE TRIGGER `{$trigger}` BEFORE UPDATE ON {$suite->prefix}appointments_bookings FOR EACH ROW"
                . " BEGIN IF NEW.eventtype_id={$event} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected encrypted booking update failure'; END IF; END");

            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

                try {
                    (new BookingStore(Factory::getContainer()->get(DatabaseInterface::class)))->create($eventRow,
                        ['invitee_name' => 'Rollback Invitee', 'invitee_email' => 'rollback@example.test'],
                        $now->modify('+6 days')->setTime(16, 0), $now, [1], 'en-GB');

                    throw new RuntimeException('The injected encrypted booking update failure was not propagated.');
                } catch (\Joomla\Database\Exception\ExecutionFailureException $exception) {
                    if (!str_contains($exception->getMessage(), 'Injected encrypted booking update failure')) {
                        throw $exception;
                    }
                }
            } finally {
                $suite->db->query("DROP TRIGGER `{$trigger}`");
            }

            return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_bookings WHERE eventtype_id={$event}") === 0;
        });

        $suite->test('A complete encrypted booking transplant reveals no victim data to another confirmation session', static function () use ($suite, $fixture) {
            $victimEvent = $fixture->event();
            $attackerEvent = $fixture->event();
            $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+7 days')->format('Y-m-d');
            $victim = $fixture->booking($victimEvent, $day . ' 10:00:00')['booking'];
            $attacker = $fixture->booking($attackerEvent, $day . ' 14:00:00');
            $victimId = (int) $victim['id'];
            $attackerId = (int) $attacker['booking']['id'];
            $secretUrl = 'https://meeting.example.test/victim-private-room';
            $fixture->update($victimId, ['conference_state' => 'ok', 'conference_url' => $secretUrl]);
            $before = $fixture->stored($attackerId);
            $source = $fixture->stored($victimId);
            $set = static function (array $fields) use ($suite): string {
                $values = [];
                foreach ($fields as $column => $value) {
                    $values[] = '`' . $column . '`=' . ($value === null ? 'NULL' : "'" . $suite->db->real_escape_string((string) $value) . "'");
                }

                return implode(',', $values);
            };
            $copied = array_intersect_key($source, array_flip(array_merge(['uid', 'created_utc'], BookingData::COLUMNS)));
            // Accommodate UID uniqueness without changing the attacker's ID or event ownership.
            $suite->db->query("UPDATE {$suite->prefix}appointments_bookings SET uid='" . bin2hex(random_bytes(32)) . "' WHERE id={$victimId}");

            try {
                $suite->db->query("UPDATE {$suite->prefix}appointments_bookings SET " . $set($copied) . ",conference_state='ok' WHERE id={$attackerId}");
                $failed = [];
                $opened = (new BookingData(new KeyProvider((string) (new JConfig())->secret)))->openLenient($fixture->stored($attackerId), $failed);
                $page = $attacker['browser']->request('/index.php?option=com_appointments&view=booking');

                return in_array('conference_url', $failed, true) && $opened['conference_url'] === null
                    && $attacker['browser']->status === 200 && !str_contains($page, $secretUrl);
            } finally {
                $restore = array_intersect_key($before, array_flip(array_merge(['uid', 'created_utc', 'conference_state'], BookingData::COLUMNS)));
                $suite->db->query("UPDATE {$suite->prefix}appointments_bookings SET " . $set($restore) . " WHERE id={$attackerId}");
                $suite->db->query("UPDATE {$suite->prefix}appointments_bookings SET uid='" . $suite->db->real_escape_string($source['uid']) . "' WHERE id={$victimId}");
            }
        });

        $suite->test('Switched on, a calendar error never fails a booking', static function () use ($suite, $fixture, $switch) {
            $switch(true);
            $event = $fixture->event();
            // A target calendar that does not exist: every calendar step fails or finds
            // nothing; the locked booking transaction must still book, and nothing is written.
            $suite->db->query("UPDATE {$suite->prefix}appointments_eventtypes SET calendar_id=2147483647 WHERE id={$event}");
            $row = $suite->db->query("SELECT * FROM {$suite->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
            $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days')->setTime(10, 0);
            $booking = (new BookingStore(Factory::getContainer()->get(DatabaseInterface::class)))->create($row,
                ['invitee_name' => 'Suite Invitee', 'invitee_first_name' => 'Suite', 'invitee_last_name' => 'Invitee',
                    'invitee_email' => 'invitee@example.test', 'invitee_timezone' => 'UTC', 'invitee_language' => 'en-GB'],
                $start, new DateTimeImmutable('now', new DateTimeZone('UTC')), [1], 'en-GB');

            return (int) $booking['id'] > 0
                && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_calendar_objects WHERE booking_id=" . (int) $booking['id']) === 0;
        });

        $missing = [];
        foreach ([[CalendarObjectStore::class, 'A'], [KeyService::class, 'B'], [CalendarService::class, 'C'], [GrantService::class, 'C']] as [$class, $package]) {
            if ($suite->isStub($class)) {
                $missing[$package] = true;
            }
        }
        if (!VendorAutoloader::register()) {
            $missing['A'] = true;
        }

        $endToEnd = ['A calendar event blocks the time slot it covers', 'A booking lands sealed in the target calendar with its UID',
            'Cancelling a booking removes its calendar object', 'Anonymising a booking removes its calendar object'];

        if ($missing !== []) {
            ksort($missing);
            foreach ($endToEnd as $name) {
                $suite->pending($name, implode(', ', array_keys($missing)));
            }

            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $config = new JConfig();
        $params = new Registry($originalParams);
        $params->set('teamcal_enabled', '1');
        $team = new TeamServices($db, new KeyProvider((string) $config->secret), $params,
            static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $admin = $fixture->host;
        $user = 0;
        $calendar = 0;
        $createdRecoveryFingerprint = null;

        try {
            // A team member as host, with a key pair and a second factor.
            $tag = 'suite-e-' . bin2hex(random_bytes(4));
            $password = bin2hex(random_bytes(12)) . 'Aa1!';
            $time = gmdate('Y-m-d H:i:s');
            $suite->db->query("INSERT INTO {$suite->prefix}users (name, username, email, password, block, sendEmail, registerDate, activation, params)"
                . " VALUES ('{$tag}', '{$tag}', '{$tag}@example.test', '" . $suite->db->real_escape_string(password_hash($password, PASSWORD_BCRYPT))
                . "', 0, 0, '{$time}', '', '{}')");
            $user = (int) $suite->db->insert_id;
            $group = (int) ((array) ($params->get('team_groups') ?? []))[0];
            $suite->db->query("INSERT INTO {$suite->prefix}user_usergroup_map (user_id, group_id) VALUES ({$user}, {$group}), ({$user}, 2)");
            $suite->db->query("INSERT INTO {$suite->prefix}user_mfa (user_id, title, method, `default`, options, created_on)"
                . " VALUES ({$user}, 'suite', 'totp', 1, '{}', '{$time}')");

            if ($team->keys()->recoveryFingerprint() === null) {
                $team->keys()->createRecoveryKey($admin);
                $createdRecoveryFingerprint = $team->keys()->recoveryFingerprint();
            }

            $keys = $team->keys()->createUserKey($user, $password);
            $calendar = $team->calendars()->create($admin, ['title' => $tag, 'owner_user_id' => $user, 'blocks_availability' => true]);
            $event = $fixture->event();
            $suite->db->query("UPDATE {$suite->prefix}appointments_eventtypes SET host_user_id={$user}, calendar_id={$calendar} WHERE id={$event}");
            $eventRow = $suite->db->query("SELECT * FROM {$suite->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
            $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+4 days')->format('Ymd');
            $busy = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\nUID:{$tag}-busy\r\nDTSTAMP:20270101T000000Z\r\n"
                . "DTSTART:{$day}T110000Z\r\nDTEND:{$day}T120000Z\r\nSUMMARY:Team event\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $ring = $team->keys()->openKeyring($calendar, $keys);
            $team->objects()->put($ring, $tag . '-busy.ics', $busy, null, true, null);

            $store = new BookingStore($db);
            $invitee = ['invitee_name' => 'Suite Invitee', 'invitee_first_name' => 'Suite', 'invitee_last_name' => 'Invitee',
                'invitee_email' => 'invitee@example.test', 'invitee_timezone' => 'UTC', 'invitee_language' => 'en-GB'];
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $at = static fn (string $hour): DateTimeImmutable => new DateTimeImmutable(substr($day, 0, 4) . '-' . substr($day, 4, 2) . '-' . substr($day, 6, 2) . ' ' . $hour, new DateTimeZone('UTC'));
            $levels = [1, 2, 3, 5, 6];
            $objects = static fn (int $booking): int => $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_calendar_objects"
                . " WHERE calendar_id={$calendar} AND booking_id={$booking}");

            $suite->test($endToEnd[0], static function () use ($store, $eventRow, $invitee, $at, $now, $levels) {
                try {
                    $store->create($eventRow, $invitee, $at('11:00:00'), $now, $levels, 'en-GB');

                    return false;
                } catch (SlotTakenException $e) {
                    return true;
                }
            });

            $booking = $store->create($eventRow, $invitee, $at('14:00:00'), $now, $levels, 'en-GB');
            $suite->test($endToEnd[1], static function () use ($suite, $calendar, $booking, $objects) {
                $row = $suite->db->query("SELECT enc_scheme, payload, timing FROM {$suite->prefix}appointments_calendar_objects"
                    . " WHERE calendar_id={$calendar} AND booking_id=" . (int) $booking['id'])->fetch_assoc();

                return $objects((int) $booking['id']) === 1 && (int) $row['enc_scheme'] === TeamStates::SCHEME_SEALED
                    && !str_contains($row['payload'], (string) $booking['uid']) && !str_contains($row['payload'], 'invitee@example.test')
                    && !str_contains($row['timing'], 'DTSTART');
            });

            $store->changeState((int) $booking['id'], 2, $now);
            $suite->test($endToEnd[2], static fn () => $objects((int) $booking['id']) === 0);

            $second = $store->create($eventRow, $invitee, $at('15:00:00'), $now, $levels, 'en-GB');
            $written = $objects((int) $second['id']) === 1;
            $store->erase((int) $second['id'], $now, 'admin', false, $admin);
            $suite->test($endToEnd[3], static fn () => $written && $objects((int) $second['id']) === 0);
        } finally {
            if ($calendar > 0) {
                try {
                    $team->calendars()->delete($admin, $calendar);
                } catch (\Throwable $e) {
                    $suite->db->query("DELETE FROM {$suite->prefix}appointments_calendars WHERE id={$calendar}");
                }
            }
            if ($user > 0) {
                foreach (['appointments_calendar_key_wraps', 'appointments_calendar_grants', 'appointments_user_keys'] as $table) {
                    $suite->db->query("DELETE FROM {$suite->prefix}{$table} WHERE user_id={$user}");
                }
                $suite->db->query("DELETE FROM {$suite->prefix}user_mfa WHERE user_id={$user}");
                $suite->db->query("DELETE FROM {$suite->prefix}user_usergroup_map WHERE user_id={$user}");
                $suite->db->query("DELETE FROM {$suite->prefix}appointments_hosts WHERE user_id={$user}");
                $suite->db->query("DELETE FROM {$suite->prefix}users WHERE id={$user}");
            }
            if ($createdRecoveryFingerprint !== null) {
                $suite->db->query("DELETE FROM {$suite->prefix}appointments_keyring WHERE purpose='recovery'"
                    . " AND fingerprint='" . $suite->db->real_escape_string($createdRecoveryFingerprint) . "' AND created_by={$admin}");
            }
        }
    } finally {
        $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string($originalParams)
            . "' WHERE type='component' AND element='com_appointments'");
        ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', (string) ((json_decode($originalParams, true) ?: [])['teamcal_enabled'] ?? '0'));
        $fixture->cleanup();
    }
};
