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
 * Cleans up every row it creates; a site recovery key it had to create stays,
 * because it is site-wide key material other parts may rely on.
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
            $sealedName = (new BookingData($keys))->seal($mixedUid, $created, ['invitee_name' => 'Sealed Invitee'])['invitee_name'];
            $mixed = $insert(['uid' => $mixedUid, 'invitee_name' => $sealedName, 'invitee_email' => 'planted@example.test']);

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
            $token = (string) ((json_decode((string) $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0], true) ?: [])[BookingData::MIGRATION_OPTION] ?? '');

            return $sealed && $opened['invitee_email'] === 'legacy@example.test' && $opened['guest_emails'] === 'guest@example.test'
                && $planted['invitee_email'] === 'planted@example.test' && $mixedOpened['invitee_email'] === null && $failed === ['invitee_email']
                && $mixedOpened['invitee_name'] === 'Sealed Invitee' && BookingData::migrationDone($token, $keys);
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
        }
    } finally {
        $suite->db->query("UPDATE {$suite->prefix}extensions SET params='" . $suite->db->real_escape_string($originalParams)
            . "' WHERE type='component' AND element='com_appointments'");
        ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', (string) ((json_decode($originalParams, true) ?: [])['teamcal_enabled'] ?? '0'));
        $fixture->cleanup();
    }
};
