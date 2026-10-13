<?php
/**
 * Structure tests for the Appointments component: the database schema and the
 * key source files shipped in the installed package.
 */

class StructureTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $admin = '/var/www/html/administrator/components/com_appointments';
    private $site = '/var/www/html/components/com_appointments';

    /**
     * Every table the install schema creates, without the `#__` prefix.
     */
    private const TABLES = [
        'appointments_schedules',
        'appointments_schedule_rules',
        'appointments_hosts',
        'appointments_eventtypes',
        'appointments_blocked',
        'appointments_bookings',
        'appointments_fields',
        'appointments_templates',
        'appointments_audit',
        'appointments_ratelimit',
    ];

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Structure Tests ===\n\n";

        foreach (self::TABLES as $table) {
            $this->test("Table #__{$table} exists", function () use ($table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                return $result && $result->num_rows === 1;
            });
        }

        $this->test('Bookings table carries the generated slot column', function () {
            $result = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}appointments_bookings LIKE 'state_active'");
            return $result && $result->num_rows === 1;
        });

        $this->test('One active booking per slot is enforced by a unique index', function () {
            // The concurrency guarantee of this component is a database
            // constraint, not a check in PHP: the second simultaneous insert
            // for the same slot has to fail. A missing index would make that
            // guarantee disappear without any other test noticing.
            $result = $this->db->query("SHOW INDEX FROM {$this->dbPrefix}appointments_bookings WHERE Key_name = 'idx_active_slot'");

            if (!$result) {
                return false;
            }

            $columns = [];

            while ($row = $result->fetch_assoc()) {
                if ((int) $row['Non_unique'] !== 0) {
                    return false;
                }

                $columns[(int) $row['Seq_in_index']] = $row['Column_name'];
            }

            ksort($columns);

            return array_values($columns) === ['eventtype_id', 'start_utc', 'state_active'];
        });

        $this->test('A cancelled booking frees the slot again', function () {
            // Explicit UTC, never the server's default zone: the whole schema
            // stores UTC and a test must not be the one place that guesses.
            $utc = new \DateTimeZone('UTC');
            $reference = new \DateTimeImmutable('now', $utc);
            $now = $reference->format('Y-m-d H:i:s');
            $start = $reference->add(new \DateInterval('P10D'))->format('Y-m-d H:i:s');
            $end = $reference->add(new \DateInterval('P10DT20M'))->format('Y-m-d H:i:s');
            $table = "{$this->dbPrefix}appointments_bookings";

            // mysqli reports a duplicate key as an exception by default, so the
            // refusal is caught here instead of being read from a return value.
            $insert = function (string $uid, int $state) use ($table, $start, $end, $now): bool {
                try {
                    return (bool) $this->db->query(
                        "INSERT INTO {$table} (uid, eventtype_id, host_user_id, start_utc, end_utc, state, created_utc, modified_utc)"
                        . " VALUES ('{$uid}', 9901, 0, '{$start}', '{$end}', {$state}, '{$now}', '{$now}')"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9901");

            $firstConfirmed = $insert('structure-test-1', 1);
            // The second confirmed booking for the same slot must be refused.
            $secondRefused = !$insert('structure-test-2', 1);
            // After cancelling the first one, the slot is free again.
            $this->db->query("UPDATE {$table} SET state = 2 WHERE uid = 'structure-test-1'");
            $thirdAccepted = $insert('structure-test-3', 1);

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9901");

            return $firstConfirmed && $secondRefused && $thirdAccepted;
        });

        $this->test('A host has exactly one row, and its feed token is unique', function () {
            // The host row is the serialisation target of the booking
            // transaction: two submissions for one host have to lock the same
            // row. That only holds while the user id is the primary key, so a
            // second row for the same host must be refused. The feed token on
            // the same table is a bearer credential and must be unique too.
            $table = "{$this->dbPrefix}appointments_hosts";
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $userId = 990401;
            $tokenA = str_pad('structurehostA', 64, 'a', STR_PAD_LEFT);
            $tokenB = str_pad('structurehostB', 64, 'b', STR_PAD_LEFT);

            $insert = function (int $user, string $token) use ($table, $now): bool {
                try {
                    return (bool) $this->db->query(
                        "INSERT INTO {$table} (user_id, feed_token, created_utc, modified_utc)"
                        . " VALUES ({$user}, '{$token}', '{$now}', '{$now}')"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $this->db->query("DELETE FROM {$table} WHERE user_id IN ({$userId}, " . ($userId + 1) . ')');

            $first = $insert($userId, $tokenA);
            // A second row for the same host must not exist.
            $duplicateHostRefused = !$insert($userId, $tokenB);
            // Another host must not be able to carry the same feed token.
            $duplicateTokenRefused = !$insert($userId + 1, $tokenA);
            // A different host with its own token is accepted.
            $secondHostAccepted = $insert($userId + 1, $tokenB);

            $primaryKey = false;
            $result = $this->db->query("SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'");

            if ($result) {
                $columns = [];

                while ($row = $result->fetch_assoc()) {
                    $columns[] = $row['Column_name'];
                }

                $primaryKey = $columns === ['user_id'];
            }

            $this->db->query("DELETE FROM {$table} WHERE user_id IN ({$userId}, " . ($userId + 1) . ')');

            return $first && $duplicateHostRefused && $duplicateTokenRefused
                && $secondHostAccepted && $primaryKey;
        });

        $this->test('A pending booking holds the slot as well', function () {
            // State 0 is slot-holding by design: a request that is waiting for
            // the host's approval must not be overtaken. A regression that
            // generated state_active for confirmed bookings only would pass the
            // test above while letting two people request one slot.
            $utc = new \DateTimeZone('UTC');
            $reference = new \DateTimeImmutable('now', $utc);
            $now = $reference->format('Y-m-d H:i:s');
            $start = $reference->add(new \DateInterval('P15D'))->format('Y-m-d H:i:s');
            $end = $reference->add(new \DateInterval('P15DT20M'))->format('Y-m-d H:i:s');
            $table = "{$this->dbPrefix}appointments_bookings";

            $insert = function (string $uid, int $state) use ($table, $start, $end, $now): bool {
                try {
                    return (bool) $this->db->query(
                        "INSERT INTO {$table} (uid, eventtype_id, host_user_id, start_utc, end_utc, state, created_utc, modified_utc)"
                        . " VALUES ('{$uid}', 9903, 0, '{$start}', '{$end}', {$state}, '{$now}', '{$now}')"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9903");

            $pending = $insert('structure-pending-1', 0);
            // A confirmed booking must not overtake a pending request.
            $confirmedRefused = !$insert('structure-pending-2', 1);
            // A second pending request for the same slot is refused too.
            $pendingRefused = !$insert('structure-pending-3', 0);

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9903");

            return $pending && $confirmedRefused && $pendingRefused;
        });

        $this->test('A reschedule moves the booking and keeps its calendar identity', function () {
            // A reschedule updates the row: one UID, one row, a raised sequence.
            // Moving onto a slot another active booking holds has to fail on the
            // same unique index that protects a new booking.
            $utc = new \DateTimeZone('UTC');
            $reference = new \DateTimeImmutable('now', $utc);
            $now = $reference->format('Y-m-d H:i:s');
            $slotA = $reference->add(new \DateInterval('P20D'))->format('Y-m-d H:i:s');
            $slotB = $reference->add(new \DateInterval('P21D'))->format('Y-m-d H:i:s');
            $endB = $reference->add(new \DateInterval('P21DT20M'))->format('Y-m-d H:i:s');
            $table = "{$this->dbPrefix}appointments_bookings";

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9902");

            $insert = function (string $uid, string $start) use ($table, $now): bool {
                $end = (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))
                    ->add(new \DateInterval('PT20M'))->format('Y-m-d H:i:s');

                try {
                    return (bool) $this->db->query(
                        "INSERT INTO {$table} (uid, eventtype_id, host_user_id, start_utc, end_utc, state, created_utc, modified_utc)"
                        . " VALUES ('{$uid}', 9902, 0, '{$start}', '{$end}', 1, '{$now}', '{$now}')"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $move = function (string $uid, string $start, string $end) use ($table): bool {
                try {
                    return (bool) $this->db->query(
                        "UPDATE {$table} SET start_utc = '{$start}', end_utc = '{$end}',"
                        . " sequence = sequence + 1 WHERE uid = '{$uid}'"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $first = $insert('structure-move-1', $slotA);
            $blocker = $insert('structure-move-2', $slotB);
            // Slot B is taken, so the move has to be refused.
            $refused = !$move('structure-move-1', $slotB, $endB);

            // Free slot B again, then the same move has to succeed.
            $this->db->query("UPDATE {$table} SET state = 2 WHERE uid = 'structure-move-2'");
            $moved = $move('structure-move-1', $slotB, $endB);

            $result = $this->db->query(
                "SELECT COUNT(*) AS rows_for_uid, MAX(sequence) AS seq, MAX(start_utc) AS start_utc"
                . " FROM {$table} WHERE uid = 'structure-move-1'"
            );
            $row = $result ? $result->fetch_assoc() : null;

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9902");

            return $first && $blocker && $refused && $moved && $row
                && (int) $row['rows_for_uid'] === 1
                && (int) $row['seq'] === 1
                && $row['start_utc'] === $slotB;
        });

        $this->test('The audit table holds no data of an invitee', function () {
            // Deletion evidence must never become a second copy of the data it
            // documents, so the exact column set is pinned here: an added
            // column fails this test. `actor_user_id` is the one operator
            // identifier the table keeps, for the admin path only.
            $result = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}appointments_audit");

            if (!$result) {
                return false;
            }

            $columns = [];

            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }

            sort($columns);
            $expected = ['action', 'actor_user_id', 'booking_id', 'id', 'occurred_utc', 'path'];

            return $columns === $expected;
        });

        $this->test('A schedule rule is either weekly or date-specific', function () {
            // The same table carries both shapes: a weekly row has a weekday
            // and no date, a date-specific row has a date and no weekday. Both
            // columns are therefore nullable, and a schema change that made
            // either of them NOT NULL would break one of the two shapes.
            $rules = "{$this->dbPrefix}appointments_schedule_rules";
            $schedules = "{$this->dbPrefix}appointments_schedules";
            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            $this->db->query("DELETE FROM {$schedules} WHERE title = 'structure-fixture'");
            $this->db->query(
                "INSERT INTO {$schedules} (title, timezone, owner_user_id, published, created_utc, modified_utc)"
                . " VALUES ('structure-fixture', 'Europe/Zurich', 0, 1, '{$now}', '{$now}')"
            );
            $scheduleId = (int) $this->db->insert_id;

            try {
                $weekly = $this->db->query(
                    "INSERT INTO {$rules} (schedule_id, weekday, specific_date, start_time, end_time)"
                    . " VALUES ({$scheduleId}, 2, NULL, '10:00:00', '12:00:00')"
                );
                $override = $this->db->query(
                    "INSERT INTO {$rules} (schedule_id, weekday, specific_date, start_time, end_time, is_unavailable)"
                    . " VALUES ({$scheduleId}, NULL, '2026-12-24', '00:00:00', '00:00:00', 1)"
                );

                $result = $this->db->query(
                    "SELECT COUNT(*) AS total, SUM(is_unavailable) AS closed FROM {$rules}"
                    . " WHERE schedule_id = {$scheduleId}"
                );
                $row = $result ? $result->fetch_assoc() : null;

                $stored = $row && (int) $row['total'] === 2 && (int) $row['closed'] === 1;
            } finally {
                $this->db->query("DELETE FROM {$rules} WHERE schedule_id = {$scheduleId}");
                $this->db->query("DELETE FROM {$schedules} WHERE id = {$scheduleId}");
            }

            return $weekly && $override && $stored;
        });

        $this->testScheduleReferences();
        $this->testScheduleMigration();

        $files = [
            'Administrator provider'  => $this->admin . '/services/provider.php',
            'Component class'         => $this->admin . '/src/Extension/AppointmentsComponent.php',
            'KeyProvider helper'      => $this->admin . '/src/Helper/KeyProvider.php',
            'AvailabilityWindow'      => $this->admin . '/src/Helper/AvailabilityWindow.php',
            'ScheduleResolver'        => $this->admin . '/src/Helper/ScheduleResolver.php',
            'SlotEngine'              => $this->admin . '/src/Helper/SlotEngine.php',
            'SlotRules'               => $this->admin . '/src/Helper/SlotRules.php',
            'Schedule table'          => $this->admin . '/src/Table/ScheduleTable.php',
            'Blocked table'           => $this->admin . '/src/Table/BlockedTable.php',
            'Schedule form'           => $this->admin . '/forms/schedule.xml',
            'Schedule rule form'      => $this->admin . '/forms/schedulerule.xml',
            'Schedule override form'  => $this->admin . '/forms/scheduleoverride.xml',
            'Blocked period form'     => $this->admin . '/forms/blockedperiod.xml',
            'Schedules layout'        => $this->admin . '/tmpl/schedules/default.php',
            'Schedule layout'         => $this->admin . '/tmpl/schedule/edit.php',
            'Blocked periods layout'  => $this->admin . '/tmpl/blockeds/default.php',
            'Blocked period layout'   => $this->admin . '/tmpl/blocked/edit.php',
            'Dashboard view'          => $this->admin . '/src/View/Dashboard/HtmlView.php',
            'Dashboard layout'        => $this->admin . '/tmpl/dashboard/default.php',
            'Install SQL'             => $this->admin . '/sql/install.mysql.utf8.sql',
            'Uninstall SQL'           => $this->admin . '/sql/uninstall.mysql.utf8.sql',
            'Schema migration'        => $this->admin . '/sql/updates/mysql/1.0.0.sql',
            'Access rules'            => $this->admin . '/access.xml',
            'Administrator LICENSE'   => $this->admin . '/LICENSE.txt',
            'Site provider'           => $this->site . '/services/provider.php',
            'Site event type list'    => $this->site . '/src/View/Eventtypes/HtmlView.php',
            'Site layout'             => $this->site . '/tmpl/eventtypes/default.php',
            'Site LICENSE'            => $this->site . '/LICENSE.txt',
        ];

        foreach ($files as $label => $path) {
            $this->test("{$label} is installed", function () use ($path) {
                return file_exists($path);
            });
        }

        $this->testCalendarFields();

        echo "\n=== Structure Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function connection(): \mysqli
    {
        $config = new \JConfig();
        $db = new \mysqli($config->host, $config->user, $config->password, $config->db);
        $db->query('SET SESSION innodb_lock_wait_timeout = 5');

        return $db;
    }

    private function refused(callable $operation, int $code): bool
    {
        try {
            $operation();
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() === $code) {
                return true;
            }

            throw $e;
        }

        return false;
    }

    private function waiting(\mysqli $db): bool
    {
        $read = [$db];
        $error = [$db];
        $reject = [];

        return \mysqli::poll($read, $error, $reject, 0, 200000) === 0;
    }

    private function testScheduleReferences(): void
    {
        $schedules = "{$this->dbPrefix}appointments_schedules";
        $events = "{$this->dbPrefix}appointments_eventtypes";
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO {$schedules} (title, created_utc, modified_utc)"
            . " VALUES ('structure-reference', '{$now}', '{$now}')"
        );
        $scheduleId = (int) $this->db->insert_id;
        $insert = "INSERT INTO {$events} (title, alias, schedule_id, feed_token, created_utc, modified_utc)"
            . " VALUES ('structure-reference', 'structure-reference', {$scheduleId},"
            . " '" . str_repeat('c', 64) . "', '{$now}', '{$now}')";

        try {
            $this->test('Event types require an explicit existing schedule', function () use ($events, $insert, $scheduleId) {
                $column = $this->db->query("SHOW COLUMNS FROM {$events} LIKE 'schedule_id'")->fetch_assoc();
                $zeroRefused = $this->refused(
                    fn () => $this->db->query(str_replace(", {$scheduleId},", ', 0,', $insert)),
                    1452
                );

                return $column['Null'] === 'NO' && $column['Default'] === null && $zeroRefused;
            });

            $this->test('An assignment committed first prevents concurrent deletion', function () use ($schedules, $events, $insert, $scheduleId) {
                $other = $this->connection();

                try {
                    $this->db->begin_transaction();
                    $this->db->query($insert);
                    $other->query("DELETE FROM {$schedules} WHERE id = {$scheduleId}", MYSQLI_ASYNC);
                    $waited = $this->waiting($other);
                    $this->db->commit();
                    $refused = $this->refused(fn () => $other->reap_async_query(), 1451);
                    $present = $this->db->query("SELECT id FROM {$schedules} WHERE id = {$scheduleId}")->num_rows === 1;

                    return $waited && $refused && $present;
                } finally {
                    $this->db->rollback();
                    $other->close();
                    $this->db->query("DELETE FROM {$events} WHERE schedule_id = {$scheduleId}");
                }
            });

            foreach (['insert', 'reassignment'] as $operation) {
                $this->test("Deletion committed first refuses a waiting {$operation}", function () use ($schedules, $events, $insert, $scheduleId, $operation) {
                    $other = $this->connection();
                    $this->db->query(
                        "INSERT INTO {$schedules} (title, created_utc, modified_utc)"
                        . " SELECT title, created_utc, modified_utc FROM {$schedules} WHERE id = {$scheduleId}"
                    );
                    $deletedId = (int) $this->db->insert_id;

                    try {
                        if ($operation === 'reassignment') {
                            $this->db->query($insert);
                            $sql = "UPDATE {$events} SET schedule_id = {$deletedId} WHERE schedule_id = {$scheduleId}";
                        } else {
                            $sql = str_replace(", {$scheduleId},", ", {$deletedId},", $insert);
                        }

                        $this->db->begin_transaction();
                        $this->db->query("SELECT id FROM {$schedules} WHERE id = {$deletedId} FOR UPDATE");
                        $this->db->query("DELETE FROM {$schedules} WHERE id = {$deletedId}");
                        $other->query($sql, MYSQLI_ASYNC);
                        $waited = $this->waiting($other);
                        $this->db->commit();
                        $refused = $this->refused(fn () => $other->reap_async_query(), 1452);
                        $orphans = $this->db->query(
                            "SELECT id FROM {$events} WHERE schedule_id = {$deletedId}"
                        )->num_rows;

                        return $waited && $refused && $orphans === 0;
                    } finally {
                        $this->db->rollback();
                        $other->close();
                        $this->db->query("DELETE FROM {$events} WHERE schedule_id IN ({$scheduleId}, {$deletedId})");
                        $this->db->query("DELETE FROM {$schedules} WHERE id = {$deletedId}");
                    }
                });
            }
        } finally {
            $this->db->query("DELETE FROM {$events} WHERE schedule_id = {$scheduleId}");
            $this->db->query("DELETE FROM {$schedules} WHERE id = {$scheduleId}");
        }
    }

    private function testScheduleMigration(): void
    {
        $schedules = "{$this->dbPrefix}appointments_upgrade_test_schedules";
        $events = "{$this->dbPrefix}appointments_upgrade_test_eventtypes";
        $migration = file_get_contents($this->admin . '/sql/updates/mysql/1.0.0.sql');

        if ($migration === false) {
            $this->test('Schema migration can be read', static fn () => false);

            return;
        }

        $sql = str_replace(
            '#__appointments_',
            $this->dbPrefix . 'appointments_upgrade_test_',
            preg_replace('/^--.*$/m', '', $migration)
        );

        foreach ([false, true] as $orphaned) {
            $this->test(
                $orphaned ? 'Legacy orphan references prevent migration without data loss' : 'Legacy schema migration preserves valid event types and enforces references',
                function () use ($schedules, $events, $sql, $orphaned) {
                    try {
                        // CREATE LIKE deliberately does not copy foreign keys.
                        $this->db->query("CREATE TABLE {$schedules} LIKE {$this->dbPrefix}appointments_schedules");
                        $this->db->query("CREATE TABLE {$events} LIKE {$this->dbPrefix}appointments_eventtypes");
                        $this->db->query("ALTER TABLE {$events} ALTER COLUMN schedule_id SET DEFAULT 0");
                        $this->db->query(
                            "INSERT INTO {$schedules} (id, title, created_utc, modified_utc)"
                            . " VALUES (1, 'legacy-reference', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
                        );
                        $scheduleId = $orphaned ? 0 : 1;
                        $this->db->query(
                            "INSERT INTO {$events} (id, alias, schedule_id, feed_token, created_utc, modified_utc)"
                            . " VALUES (1, 'legacy-reference', {$scheduleId}, '" . str_repeat('d', 64)
                            . "', '2026-01-01 00:00:00', '2026-01-01 00:00:00')"
                        );

                        if ($orphaned) {
                            $enforced = $this->refused(fn () => $this->db->query($sql), 1452);
                        } else {
                            $this->db->query($sql);
                            $enforced = $this->refused(
                                fn () => $this->db->query("DELETE FROM {$schedules} WHERE id = 1"),
                                1451
                            );
                        }

                        $row = $this->db->query("SELECT id, schedule_id FROM {$events}")->fetch_assoc();

                        return $enforced && (int) $row['id'] === 1 && (int) $row['schedule_id'] === $scheduleId;
                    } finally {
                        $this->db->query("DROP TABLE IF EXISTS {$events}");
                        $this->db->query("DROP TABLE IF EXISTS {$schedules}");
                    }
                }
            );
        }
    }

    /**
     * Every calendar field in the component, checked for the two defaults of
     * Joomla's calendar field that are traps rather than conveniences.
     *
     * - **No translated submit format.** With `translateformat="true"` the
     *   field submits the active language's `DATE_FORMAT_FILTER_DATETIME`, so
     *   the same form sends 2026-06-02 14:00:00 in English and 02.06.2026
     *   14:00:00 in German. Code that has to read the submitted text before
     *   Joomla converts it, to refuse an impossible date or a local time the
     *   clock change skips, cannot do that against a format that changes with
     *   the reader.
     * - **An explicit filter.** When the attribute is missing, the field
     *   defaults its *display* filter to `user_utc` while its output filter
     *   stays unset, so a stored bare date is converted on the way out and not
     *   on the way back in. For an operator west of UTC that moves the date one
     *   day earlier on every round trip.
     *
     * The test covers the forms as a set rather than the two fields this stage
     * happens to have, so a field added later is covered without anybody
     * remembering this.
     */
    private function testCalendarFields(): void
    {
        $forms = glob($this->admin . '/forms/*.xml') ?: [];

        $this->test('There are calendar fields to check at all', function () use ($forms) {
            return $forms !== [];
        });

        $checked = 0;

        foreach ($forms as $form) {
            $xml = simplexml_load_file($form);

            if ($xml === false) {
                $this->test(basename($form) . ' is readable XML', function () {
                    return false;
                });

                continue;
            }

            foreach ($xml->xpath('//field[@type="calendar"]') ?: [] as $field) {
                $checked++;
                $name      = (string) $field['name'];
                $where     = basename($form) . ':' . $name;
                $translate = strtolower((string) $field['translateformat']);
                $filter    = strtolower((string) $field['filter']);

                $this->test($where . ' does not submit a translated format', function () use ($translate) {
                    return $translate === '' || $translate === 'false' || $translate === '0';
                });

                $this->test($where . ' names its filter explicitly', function () use ($filter) {
                    return $filter !== '';
                });

                $this->test($where . ' declares the format it submits', function () use ($field) {
                    return (string) $field['format'] !== '';
                });
            }
        }

        $this->test('Every calendar field was checked', function () use ($checked) {
            return $checked >= 3;
        });
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new StructureTest();
exit($test->run() ? 0 : 1);
