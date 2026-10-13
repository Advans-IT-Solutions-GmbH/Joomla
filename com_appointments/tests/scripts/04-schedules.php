<?php
/**
 * Schedule administration tests.
 *
 * Rendering the form with a GET request proves that it renders, not that it
 * works. This suite submits one: it saves a schedule with a weekly interval and
 * a date-specific override through the real administrator controller, reads the
 * result back out of the database, proves that an invalid submission writes
 * nothing at all, and proves that a schedule an event type still uses is
 * refused for deletion.
 *
 * The suite cleans up after itself and leaves the site as it found it.
 */

class ScheduleAdminTest
{
    private const BASE_TITLE = 'suite-schedule-fixture';

    /**
     * The account used for the authorisation steps. The password only ever
     * exists in this file and in the row the suite deletes again, so it is not
     * a credential of the installation.
     */
    private const OPERATOR          = 'suite-operator';
    private const OPERATOR_PASSWORD = 'SuiteOperator123456!';

    private $db;
    private $dbPrefix;
    private $baseUrl;
    private $cookieJar = '/tmp/suite-schedules-cookies.txt';
    private $adminId = 0;
    private $originalParams = '';
    private $operatorId = 0;
    private $operatorGroup = 0;
    private $assetId = 0;
    private $originalRules = '';
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
        $this->baseUrl = rtrim(getenv('SCHEDULES_BASE_URL') ?: 'http://localhost', '/');
    }

    public function run(): bool
    {
        echo "=== Schedule Administration Tests ===\n\n";

        $this->cleanUp();

        if (!$this->login()) {
            echo "✗ Could not log into the administrator\n";

            return false;
        }

        echo "✓ Logged into the administrator\n";
        $this->passed++;

        $token = $this->tokenFromNewForm();

        if ($token === null) {
            echo "✗ Could not read the form token from the new-schedule form\n";
            $this->failed++;

            return false;
        }

        echo "✓ The new-schedule form renders and carries a token\n";
        $this->passed++;

        // --- A valid schedule with both kinds of interval -------------------
        $this->post('schedule.save', $token, $this->validFields());
        $scheduleId = $this->fixtureId();

        $this->test('The schedule was stored', function () use ($scheduleId) {
            return $scheduleId > 0;
        });

        if ($scheduleId <= 0) {
            $this->summary();

            return false;
        }

        $this->test('The time zone was stored as given', function () use ($scheduleId) {
            return $this->column($scheduleId, 'timezone') === 'Europe/Zurich';
        });

        $this->test('An administrator with core.edit.state may create a published schedule', function () use ($scheduleId) {
            return (int) $this->column($scheduleId, 'published') === 1;
        });

        $this->test('The weekly interval was stored', function () use ($scheduleId) {
            $row = $this->rule($scheduleId, 'weekday IS NOT NULL');

            return $row
                && (int) $row['weekday'] === 2
                && $row['specific_date'] === null
                && substr((string) $row['start_time'], 0, 5) === '10:00'
                && substr((string) $row['end_time'], 0, 5) === '12:00';
        });

        $this->test('The date-specific override was stored as a closed day', function () use ($scheduleId) {
            $row = $this->rule($scheduleId, 'specific_date IS NOT NULL');

            return $row
                && $row['weekday'] === null
                && $row['specific_date'] === '2026-12-24'
                && (int) $row['is_unavailable'] === 1;
        });

        $this->test('The edit form shows the stored intervals again', function () use ($scheduleId) {
            // The round trip is the point: the repeatable fields have to be
            // filled from the rule table, not only written to it.
            $html = $this->get('/administrator/index.php?option=com_appointments&task=schedule.edit&id=' . $scheduleId);

            return strpos($html, '10:00') !== false && strpos($html, '2026-12-24') !== false;
        });

        // --- The override date must survive a reader west of UTC -----------
        // A date-specific override is a plain local date in the schedule's own
        // zone, but Joomla's calendar field defaults its *display* filter to
        // user_utc when the attribute is missing. The stored 2026-12-24 was
        // then read as midnight UTC and shown to an operator in a negative
        // offset as the 23rd, which they would save on the next edit. The zone
        // is restored before anything else runs.
        try {
            if (!$this->useZoneForAdmin('America/New_York')) {
                echo "✗ Could not set the time zone of the administrator account\n";
                $this->failed++;
            } else {
                $this->test('An override keeps its date for a reader west of UTC', function () use ($scheduleId) {
                    $html = $this->get(
                        '/administrator/index.php?option=com_appointments&task=schedule.edit&id=' . $scheduleId
                    );

                    return strpos($html, '2026-12-24') !== false
                        && strpos($html, '2026-12-23') === false;
                });
            }
        } finally {
            $this->restoreAdminZone();
            $this->login();
        }

        // --- An invalid submission must write nothing ----------------------
        $before = $this->ruleCount($scheduleId);
        $fields = $this->validFields();
        $fields['jform[id]'] = (string) $scheduleId;
        $fields['jform[weekly_rules][0][start_time]'] = '99:99';
        $html = $this->post('schedule.save', $this->tokenFromEditForm($scheduleId) ?? $token, $fields);

        $this->test('An unusable time is refused', function () use ($html) {
            return stripos($html, 'hours and minutes') !== false;
        });

        $this->test('A refused submission leaves the stored intervals untouched', function () use ($scheduleId, $before) {
            // This is what the transaction is for: the schedule row must not be
            // saved with its intervals deleted.
            return $this->ruleCount($scheduleId) === $before && $before === 2;
        });

        // The override date carries no time-zone filter, so nothing normalises
        // it on the way in. The whole value therefore has to be validated: a
        // value whose first ten characters happen to be a date must not be cut
        // down to them and stored as a date nobody submitted.
        $fields = $this->validFields();
        $fields['jform[id]'] = (string) $scheduleId;
        $fields['jform[date_overrides][0][specific_date]'] = '2026-12-24garbage';
        $html = $this->post('schedule.save', $this->tokenFromEditForm($scheduleId) ?? $token, $fields);

        $this->test('An override date with a suffix is refused, not truncated', function () use ($scheduleId, $html) {
            return stripos($html, 'needs a valid date') !== false
                && $this->ruleCount($scheduleId) === 2;
        });

        $this->post('schedule.cancel', $this->tokenFromEditForm($scheduleId) ?? $token, [
            'jform[id]' => (string) $scheduleId,
        ]);

        $this->test('Cancelling the administrator edit releases the schedule checkout', function () use ($scheduleId) {
            return (int) $this->column($scheduleId, 'checked_out') === 0;
        });

        // --- The edit view is behind core.edit, whatever its spelling -------
        // Reaching view=schedule directly would show and save a stored
        // schedule without the task controllers' permission checks, so
        // DisplayController authorises it. Joomla's MVC factory resolves
        // schedule and Schedule to the same view class, so the guard has to
        // compare without regard to case or it can be stepped around by
        // holding down shift. This is the only test of that guard, so it also
        // covers the guard itself and not only the spelling.
        try {
            $operator = $this->createOperatorWithoutStatePermission();

            if ($operator === null) {
                echo "✗ Could not create an operator without the edit permission\n";
                $this->failed++;
            } else {
                $this->test('An operator without core.edit may still open a blank form', function () {
                    // The positive control: without it a 403 below could just as
                    // well mean the login or the permission failed outright.
                    [, $status] = $this->statusOf('/administrator/index.php?option=com_appointments&view=schedule');

                    return $status === 200;
                });

                foreach (['schedule', 'Schedule', 'SCHEDULE'] as $spelling) {
                    $this->test(
                        'An operator without core.edit is refused view=' . $spelling . ' with an id',
                        function () use ($spelling, $scheduleId) {
                            [, $status] = $this->statusOf(
                                '/administrator/index.php?option=com_appointments&view='
                                . $spelling . '&id=' . $scheduleId
                            );

                            return $status === 403;
                        }
                    );
                }

                foreach (['omitted', 'forged'] as $submission) {
                    $this->test(
                        'A creator without core.edit.state creates an unpublished schedule with a ' . $submission . ' state',
                        function () use ($submission) {
                            $token = $this->tokenFromNewForm();

                            if ($token === null) {
                                return false;
                            }

                            $fields = $this->validFields();
                            $title  = self::BASE_TITLE . '-operator-' . $submission;
                            $fields['jform[title]'] = $title;

                            if ($submission === 'omitted') {
                                unset($fields['jform[published]']);
                            }

                            $this->post('schedule.save', $token, $fields);
                            $row = $this->scheduleByTitle($title);

                            return $row !== null
                                && (int) $row['published'] === 0
                                && $this->ruleCount((int) $row['id']) === 2;
                        }
                    );
                }
            }
        } finally {
            $this->removeOperator();
            $this->login();
        }

        // Editing content must still work without permission to change state.
        try {
            $this->tokenFromEditForm($scheduleId);
            $operator = $this->createOperatorWithoutStatePermission(true);

            if ($operator === null) {
                echo "✗ Could not create an operator without the state permission\n";
                $this->failed++;
            } else {
                foreach (['schedule', 'Schedule', 'SCHEDULE'] as $spelling) {
                    $this->test('An editor cannot bypass checkout through view=' . $spelling, function () use ($spelling, $scheduleId) {
                        [, $status] = $this->statusOf(
                            '/administrator/index.php?option=com_appointments&view=' . $spelling . '&id=' . $scheduleId
                        );

                        return $status === 403;
                    });
                }

                $this->test('The edit task refuses a schedule checked out by another editor', function () use ($scheduleId) {
                    [, $status] = $this->statusOf(
                        '/administrator/index.php?option=com_appointments&task=schedule.edit&id=' . $scheduleId
                    );

                    return $status === 403 && (int) $this->column($scheduleId, 'checked_out') !== $this->operatorId;
                });

                $this->login();
                $this->post('schedule.cancel', $this->tokenFromListForm() ?? '', [
                    'jform[id]' => (string) $scheduleId,
                ]);
                $this->loginAs(self::OPERATOR, self::OPERATOR_PASSWORD);

                $this->test('An editor without core.edit.state preserves the existing published state', function () use ($scheduleId) {
                    $token = $this->tokenFromEditForm($scheduleId);

                    if ($token === null) {
                        return false;
                    }

                    $fields = $this->validFields();
                    $fields['jform[id]']        = (string) $scheduleId;
                    $fields['jform[timezone]']  = 'UTC';
                    $fields['jform[published]'] = '0';
                    $this->post('schedule.save', $token, $fields);

                    return $this->column($scheduleId, 'timezone') === 'UTC'
                        && (int) $this->column($scheduleId, 'published') === 1
                        && $this->ruleCount($scheduleId) === 2;
                });
            }
        } finally {
            $this->removeOperator();
            $this->login();
        }

        // --- A schedule in use must not be deleted -------------------------
        $this->createEventTypeUsing($scheduleId);
        $html = $this->post('schedules.delete', $this->tokenFromListForm() ?? $token, ['cid[]' => (string) $scheduleId]);

        $this->test('A schedule an event type uses is refused for deletion', function () use ($scheduleId, $html) {
            return $this->fixtureId() === $scheduleId
                && stripos($html, 'still assigned to an event type') !== false;
        });

        $this->removeEventTypes();
        $this->post('schedules.delete', $this->tokenFromListForm() ?? $token, ['cid[]' => (string) $scheduleId]);

        $this->test('A schedule that is no longer used is deleted with its intervals', function () use ($scheduleId) {
            return $this->fixtureId() === 0 && $this->ruleCount($scheduleId) === 0;
        });

        $this->cleanUp();
        $this->summary();

        return $this->failed === 0;
    }

    /**
     * An administrator account that may manage the component and create a
     * schedule, but may not change its state, logged in and ready to make requests.
     *
     * It is put into Joomla's own Manager group, which may log into the
     * administrator, and the component's asset rules grant that group
     * core.manage and core.create while denying core.edit.state. Editing is
     * denied unless requested for the state-preservation test. The row is built
     * from the live column list rather than a fixed one, so the suite does not
     * break on a Joomla version that adds or drops a user column.
     *
     * @return  string|null  The user name, or null when it could not be set up.
     */
    private function createOperatorWithoutStatePermission(bool $allowEdit = false): ?string
    {
        $groups    = "{$this->dbPrefix}usergroups";
        $result    = $this->db->query("SELECT id FROM {$groups} WHERE title = 'Manager' LIMIT 1");
        $groupRow  = $result ? $result->fetch_assoc() : null;

        if (!$groupRow) {
            return null;
        }

        $this->operatorGroup = (int) $groupRow['id'];

        $columns = [];
        $result  = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}users");

        while ($result && $row = $result->fetch_assoc()) {
            $columns[$row['Field']] = $row;
        }

        if ($columns === []) {
            return null;
        }

        $now    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $wanted = [
            'name'         => 'Suite Operator',
            'username'     => self::OPERATOR,
            'email'        => self::OPERATOR . '@example.test',
            'password'     => password_hash(self::OPERATOR_PASSWORD, PASSWORD_BCRYPT),
            'block'        => '0',
            'sendEmail'    => '0',
            'registerDate' => $now,
            'params'       => '{}',
            'requireReset' => '0',
        ];

        $sets = [];

        foreach ($columns as $name => $column) {
            if (array_key_exists($name, $wanted)) {
                $sets[] = "`{$name}` = '" . $this->db->real_escape_string((string) $wanted[$name]) . "'";

                continue;
            }

            // Anything the schema insists on but does not default is filled
            // with an empty value of its own kind, so a strict server does not
            // refuse the insert.
            if ($name === 'id' || $column['Null'] === 'YES' || $column['Default'] !== null) {
                continue;
            }

            $type   = strtolower((string) $column['Type']);
            $filler = '0';

            if (str_contains($type, 'char') || str_contains($type, 'text')) {
                $filler = '';
            } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
                $filler = $now;
            } elseif (str_contains($type, 'date')) {
                $filler = substr($now, 0, 10);
            }

            $sets[] = "`{$name}` = '" . $this->db->real_escape_string($filler) . "'";
        }

        if (!$this->db->query("INSERT INTO {$this->dbPrefix}users SET " . implode(', ', $sets))) {
            return null;
        }

        $this->operatorId = (int) $this->db->insert_id;
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}user_usergroup_map (user_id, group_id) "
            . "VALUES ({$this->operatorId}, {$this->operatorGroup})"
        );

        // The component's own asset decides what the group may do here.
        $assets = "{$this->dbPrefix}assets";
        $result = $this->db->query("SELECT id, rules FROM {$assets} WHERE name = 'com_appointments' LIMIT 1");
        $asset  = $result ? $result->fetch_assoc() : null;

        if (!$asset) {
            return null;
        }

        $this->assetId       = (int) $asset['id'];
        $this->originalRules = (string) $asset['rules'];
        $rules               = json_decode($this->originalRules, true);
        $rules               = \is_array($rules) ? $rules : [];
        $key                 = (string) $this->operatorGroup;

        $rules['core.manage'][$key]     = 1;
        $rules['core.create'][$key]     = 1;
        $rules['core.edit'][$key]       = $allowEdit ? 1 : 0;
        $rules['core.edit.state'][$key] = 0;

        $encoded = $this->db->real_escape_string((string) json_encode($rules));
        $this->db->query("UPDATE {$assets} SET rules = '{$encoded}' WHERE id = {$this->assetId}");

        return $this->loginAs(self::OPERATOR, self::OPERATOR_PASSWORD) ? self::OPERATOR : null;
    }

    private function removeOperator(): void
    {
        if ($this->assetId > 0) {
            $assets = "{$this->dbPrefix}assets";
            $rules  = $this->db->real_escape_string($this->originalRules);
            $this->db->query("UPDATE {$assets} SET rules = '{$rules}' WHERE id = {$this->assetId}");
            $this->assetId = 0;
        }

        if ($this->operatorId > 0) {
            $this->db->query(
                "DELETE FROM {$this->dbPrefix}user_usergroup_map WHERE user_id = {$this->operatorId}"
            );
            $this->db->query("DELETE FROM {$this->dbPrefix}users WHERE id = {$this->operatorId}");
            $this->operatorId = 0;
        }

        @unlink($this->cookieJar);
    }

    /**
     * The HTTP status and the body of a request, because an authorisation test
     * is about the status and not about what the page happens to say.
     *
     * @return  array{0:string, 1:int}
     */
    private function statusOf(string $path): array
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
        ]);

        $body   = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        return [$body === false ? '' : $body, $status];
    }

    /**
     * Give the logged-in account a time zone and log in again, because the
     * session caches the user object and the form filters read it from there.
     */
    private function useZoneForAdmin(string $zone): bool
    {
        $users  = "{$this->dbPrefix}users";
        $name   = $this->db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
        $result = $this->db->query("SELECT id, params FROM {$users} WHERE username = '{$name}' LIMIT 1");
        $row    = $result ? $result->fetch_assoc() : null;

        if (!$row) {
            return false;
        }

        $this->adminId        = (int) $row['id'];
        $this->originalParams = (string) $row['params'];

        $params             = json_decode($this->originalParams, true);
        $params             = \is_array($params) ? $params : [];
        $params['timezone'] = $zone;
        $encoded            = $this->db->real_escape_string((string) json_encode($params));

        $this->db->query("UPDATE {$users} SET params = '{$encoded}' WHERE id = {$this->adminId}");

        return $this->login();
    }

    private function restoreAdminZone(): void
    {
        if ($this->adminId === 0) {
            return;
        }

        $users  = "{$this->dbPrefix}users";
        $params = $this->db->real_escape_string($this->originalParams);
        $this->db->query("UPDATE {$users} SET params = '{$params}' WHERE id = {$this->adminId}");
        $this->adminId = 0;
    }

    /**
     * @return array<string, string>
     */
    private function validFields(): array
    {
        return [
            'jform[id]'                                 => '0',
            'jform[title]'                              => self::BASE_TITLE,
            'jform[timezone]'                           => 'Europe/Zurich',
            'jform[owner_user_id]'                      => '0',
            'jform[published]'                          => '1',
            'jform[weekly_rules][0][weekday]'           => '2',
            'jform[weekly_rules][0][start_time]'        => '10:00',
            'jform[weekly_rules][0][end_time]'          => '12:00',
            'jform[date_overrides][0][specific_date]'   => '2026-12-24',
            'jform[date_overrides][0][is_unavailable]'  => '1',
            'jform[date_overrides][0][start_time]'      => '',
            'jform[date_overrides][0][end_time]'        => '',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function scheduleByTitle(string $title)
    {
        $table  = "{$this->dbPrefix}appointments_schedules";
        $needle = $this->db->real_escape_string($title);
        $result = $this->db->query(
            "SELECT id, published FROM {$table} WHERE title = '{$needle}' ORDER BY id DESC LIMIT 1"
        );

        return $result ? $result->fetch_assoc() : null;
    }

    private function fixtureId(): int
    {
        $table = "{$this->dbPrefix}appointments_schedules";
        $title = $this->db->real_escape_string(self::BASE_TITLE);
        $result = $this->db->query("SELECT id FROM {$table} WHERE title = '{$title}' ORDER BY id DESC LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['id'] : 0;
    }

    /**
     * @return string|null
     */
    private function column(int $scheduleId, string $column)
    {
        $table = "{$this->dbPrefix}appointments_schedules";
        $result = $this->db->query("SELECT `{$column}` FROM {$table} WHERE id = {$scheduleId}");
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? $row[$column] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rule(int $scheduleId, string $condition)
    {
        $table = "{$this->dbPrefix}appointments_schedule_rules";
        $result = $this->db->query(
            "SELECT * FROM {$table} WHERE schedule_id = {$scheduleId} AND {$condition} LIMIT 1"
        );

        return $result ? $result->fetch_assoc() : null;
    }

    private function ruleCount(int $scheduleId): int
    {
        $table = "{$this->dbPrefix}appointments_schedule_rules";
        $result = $this->db->query("SELECT COUNT(*) AS total FROM {$table} WHERE schedule_id = {$scheduleId}");
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['total'] : 0;
    }

    /**
     * An event type pointing at the schedule, created directly, because this
     * suite is about the schedule screens and event types arrive in a later
     * stage.
     */
    private function createEventTypeUsing(int $scheduleId): void
    {
        $table = "{$this->dbPrefix}appointments_eventtypes";
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $token = str_pad('suiteschedule', 64, 'e', STR_PAD_LEFT);

        $this->db->query(
            "INSERT INTO {$table} (title, alias, duration_minutes, slot_step_minutes, schedule_id,"
            . " feed_token, created_utc, modified_utc)"
            . " VALUES ('" . self::BASE_TITLE . "', '" . self::BASE_TITLE . "', 20, 30, {$scheduleId},"
            . " '{$token}', '{$now}', '{$now}')"
        );
    }

    private function removeEventTypes(): void
    {
        $table = "{$this->dbPrefix}appointments_eventtypes";
        $title = $this->db->real_escape_string(self::BASE_TITLE);
        $this->db->query("DELETE FROM {$table} WHERE title = '{$title}'");
    }

    private function cleanUp(): void
    {
        $this->removeEventTypes();
        $schedules = "{$this->dbPrefix}appointments_schedules";
        $rules = "{$this->dbPrefix}appointments_schedule_rules";
        $title = $this->db->real_escape_string(self::BASE_TITLE);

        $operatorOmitted = $this->db->real_escape_string(self::BASE_TITLE . '-operator-omitted');
        $operatorForged  = $this->db->real_escape_string(self::BASE_TITLE . '-operator-forged');
        $titles = "'{$title}', '{$operatorOmitted}', '{$operatorForged}'";
        $result = $this->db->query("SELECT id FROM {$schedules} WHERE title IN ({$titles})");

        while ($result && ($row = $result->fetch_assoc())) {
            $id = (int) $row['id'];
            $this->db->query("DELETE FROM {$rules} WHERE schedule_id = {$id}");
        }

        $this->db->query("DELETE FROM {$schedules} WHERE title IN ({$titles})");
    }

    private function login(): bool
    {
        return $this->loginAs(
            getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin',
            getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#'
        );
    }

    private function loginAs(string $username, string $password): bool
    {
        @unlink($this->cookieJar);
        $token = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = $this->tokenIn($this->get('/administrator/index.php'));

            if ($token !== null) {
                break;
            }

            sleep(2);
        }

        if ($token === null) {
            return false;
        }

        $body = $this->request('/administrator/index.php', [
            'username' => $username,
            'passwd'   => $password,
            'option'   => 'com_login',
            'task'     => 'login',
            'return'   => base64_encode('index.php'),
            $token     => '1',
        ]);

        return strpos($body, 'task=logout') !== false || strpos($body, 'com_cpanel') !== false;
    }

    private function tokenFromNewForm(): ?string
    {
        return $this->tokenIn($this->get('/administrator/index.php?option=com_appointments&task=schedule.add'));
    }

    private function tokenFromEditForm(int $scheduleId): ?string
    {
        return $this->tokenIn(
            $this->get('/administrator/index.php?option=com_appointments&task=schedule.edit&id=' . $scheduleId)
        );
    }

    private function tokenFromListForm(): ?string
    {
        return $this->tokenIn($this->get('/administrator/index.php?option=com_appointments&view=schedules'));
    }

    private function tokenIn(string $html): ?string
    {
        return preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m) ? $m[1] : null;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function post(string $task, string $token, array $fields): string
    {
        $fields['task'] = $task;
        $fields[$token] = '1';

        // FormController reads the record id from the form action's query,
        // not from jform[id]; without it an edit is submitted as a new record.
        $id = isset($fields['jform[id]']) ? '&id=' . (int) $fields['jform[id]'] : '';

        return $this->request('/administrator/index.php?option=com_appointments' . $id, $fields);
    }

    private function get(string $path): string
    {
        return $this->request($path);
    }

    /**
     * @param  array<string, string>  $post
     */
    private function request(string $path, array $post = []): string
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
        ]);

        if ($post !== []) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($handle);
        curl_close($handle);

        return $body === false ? '' : $body;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }

    private function summary(): void
    {
        echo "\n=== Schedule Administration Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
    }
}

$test = new ScheduleAdminTest();
exit($test->run() ? 0 : 1);
