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

    private $db;
    private $dbPrefix;
    private $baseUrl;
    private $cookieJar = '/tmp/suite-schedules-cookies.txt';
    private $adminId = 0;
    private $originalParams = '';
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

        $result = $this->db->query("SELECT id FROM {$schedules} WHERE title = '{$title}'");

        while ($result && ($row = $result->fetch_assoc())) {
            $id = (int) $row['id'];
            $this->db->query("DELETE FROM {$rules} WHERE schedule_id = {$id}");
        }

        $this->db->query("DELETE FROM {$schedules} WHERE title = '{$title}'");
    }

    private function login(): bool
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
            'username' => getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin',
            'passwd'   => getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#',
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

        return $this->request('/administrator/index.php?option=com_appointments', $fields);
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
