<?php
/**
 * Uninstall tests for the Appointments component.
 */

class UninstallTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;

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
        'appointments_keyring',
        'appointments_user_keys',
        'appointments_calendars',
        'appointments_calendar_keys',
        'appointments_calendar_grants',
        'appointments_calendar_key_wraps',
        'appointments_calendar_objects',
        'appointments_calendar_changes',
        'appointments_calendar_busy',
        'appointments_devices',
        'appointments_device_calendars',
        'appointments_invitations',
        'appointments_teamlog',
        'appointments_teamlog_head',
        'appointments_auth_failures',
        'appointments_eventtype_calendars',
    ];

    private const TASK_TYPES = [
        'plg_task_appointments.reminders',
        'plg_task_appointments.retention',
        'plg_task_appointments.teamcleanup',
        'plg_task_appointments.teamlog',
        'plg_task_appointments.teaminvitations',
        'plg_task_appointments.teamretention',
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
        echo "=== Uninstall Tests ===\n\n";

        $extensionId = $this->extensionId();

        $this->test('Extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        $this->setUninstallChoice(null);
        $now = gmdate('Y-m-d H:i:s');
        $marker = 'uninstall-retained-' . bin2hex(random_bytes(6));
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}appointments_schedules (title, timezone, owner_user_id, created_utc, modified_utc)"
            . " VALUES ('{$marker}', 'UTC', 0, '{$now}', '{$now}')"
        );
        $anchorPath = '/var/www/html/administrator/components/com_appointments-data/epochs.php';
        $legacyAnchorPath = '/var/www/html/administrator/components/com_appointments/data/epochs.php';
        if (is_file($anchorPath) && is_file($legacyAnchorPath)) {
            throw new \RuntimeException('Both stable and legacy epoch anchors exist before the test.');
        }
        $anchorContent = is_file($anchorPath) ? file_get_contents($anchorPath) : null;
        if ($anchorContent === false) {
            throw new \RuntimeException('Could not read the epoch anchor test fixture.');
        }
        if ($anchorContent === null && is_file($legacyAnchorPath)) {
            $anchorContent = file_get_contents($legacyAnchorPath);
            if ($anchorContent === false) {
                throw new \RuntimeException('Could not read the legacy epoch anchor test fixture.');
            }
        }
        if ($anchorContent === null) {
            if (!is_dir(dirname($legacyAnchorPath)) && !mkdir(dirname($legacyAnchorPath), 0755, true)) {
                throw new \RuntimeException('Could not create the epoch anchor test directory.');
            }
            $anchorContent = "Appointments uninstall test anchor\n";
            if (file_put_contents($legacyAnchorPath, $anchorContent) !== strlen($anchorContent)) {
                throw new \RuntimeException('Could not create the epoch anchor test fixture.');
            }
        } elseif (is_file($anchorPath)) {
            if (!is_dir(dirname($legacyAnchorPath)) && !mkdir(dirname($legacyAnchorPath), 0755, true)) {
                throw new \RuntimeException('Could not create the legacy epoch anchor test directory.');
            }
            if (!rename($anchorPath, $legacyAnchorPath)) {
                throw new \RuntimeException('Could not stage the legacy epoch anchor test fixture.');
            }
        }
        $this->insertSchedulerTasks();

        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);
        $this->test('Uninstall command executed', function () use ($exitCode) {
            return $exitCode === 0;
        });

        $this->test('Component registration is removed before the preservation reinstall', function () {
            return $this->extensionId() === 0;
        });

        $this->test('No uninstall choice keeps every Appointments table and its data', function () use ($marker) {
            foreach (self::TABLES as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows !== 1) {
                    return false;
                }
            }
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}appointments_schedules WHERE title = '{$marker}'"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 1;
        });

        $this->test('All six Appointments Scheduler task types are removed', function () {
            $types = "'" . implode("','", self::TASK_TYPES) . "'";
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}scheduler_tasks WHERE type IN ({$types})"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0;
        });

        $this->test('Legacy epoch anchor is migrated and preserved outside the component directory', function () use ($anchorPath, $legacyAnchorPath, $anchorContent) {
            return is_file($anchorPath) && file_get_contents($anchorPath) === $anchorContent
                && !is_file($legacyAnchorPath);
        });

        $output = [];
        $exitCode = 0;
        exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/tmp/extension.zip --no-interaction 2>&1', $output, $exitCode);
        $this->test('Component can be reinstalled with retained data and its stable epoch anchor', function () use ($exitCode, $anchorPath, $legacyAnchorPath, $anchorContent) {
            return $exitCode === 0 && $this->extensionId() > 0
                && is_file($anchorPath) && file_get_contents($anchorPath) === $anchorContent
                && !is_file($legacyAnchorPath);
        });
        $this->test('Reinstalled Scheduler routines stay disabled until reviewed', function () {
            $types = "'plg_task_appointments.reminders','plg_task_appointments.retention'";
            $result = $this->db->query(
                "SELECT COUNT(*) AS total, SUM(state = 1) AS enabled FROM {$this->dbPrefix}scheduler_tasks WHERE type IN ({$types})"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['total'] === 2 && (int) $row['enabled'] === 0;
        });

        $deleteExtensionId = $this->extensionId();
        $this->setUninstallChoice('delete');
        $this->insertSchedulerTasks();
        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $deleteExtensionId --no-interaction 2>&1", $output, $exitCode);
        $this->test('Uninstall command with explicit delete choice executed', function () use ($exitCode) {
            return $exitCode === 0;
        });

        $this->test('Explicit delete choice removes all Appointments tables', function () {
            foreach (self::TABLES as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows !== 0) {
                    return false;
                }
            }

            return true;
        });

        $this->test('Explicit delete choice removes both epoch anchors and all task types', function () use ($anchorPath, $legacyAnchorPath) {
            $types = "'" . implode("','", self::TASK_TYPES) . "'";
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}scheduler_tasks WHERE type IN ({$types})"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0 && !is_file($anchorPath) && !is_file($legacyAnchorPath);
        });

        $this->test('Component registration is removed', function () {
            return $this->extensionId() === 0;
        });
        $this->test('Administrator and site component files are removed', function () {
            return !is_dir('/var/www/html/administrator/components/com_appointments')
                && !is_dir('/var/www/html/components/com_appointments');
        });
        $this->test('Bundled task plugin files and registration are removed', function () {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE element='appointments' AND type='plugin' AND folder='task'"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0 && !is_dir('/var/www/html/plugins/task/appointments');
        });
        $this->test('Bundled privacy plugin and installed languages removed', function () {
            $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE element='appointments' AND type='plugin' AND folder='privacy'");
            $row = $result ? $result->fetch_assoc() : null;
            if (!$row || (int) $row['cnt'] !== 0 || is_dir('/var/www/html/plugins/privacy/appointments')) {
                return false;
            }
            foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
                foreach (['ini', 'sys.ini'] as $suffix) {
                    if (is_file('/var/www/html/administrator/language/' . $tag . '/plg_privacy_appointments.' . $suffix)) {
                        return false;
                    }
                }
            }

            return true;
        });

        $this->test('Bundled team calendar plugins and their languages removed', function () {
            foreach (['system' => 'appointmentsdav', 'user' => 'appointments'] as $group => $element) {
                $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE element='{$element}' AND type='plugin' AND folder='{$group}'");
                $row = $result ? $result->fetch_assoc() : null;

                if (!$row || (int) $row['cnt'] !== 0 || is_dir("/var/www/html/plugins/{$group}/{$element}")) {
                    return false;
                }

                foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
                    foreach (['ini', 'sys.ini'] as $suffix) {
                        if (is_file("/var/www/html/administrator/language/{$tag}/plg_{$group}_{$element}.{$suffix}")) {
                            return false;
                        }
                    }
                }
            }

            return true;
        });
        $this->test('No update site is still linked to the removed extension', function () use ($deleteExtensionId) {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}update_sites_extensions"
                . " WHERE extension_id = {$deleteExtensionId}"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0;
        });

        $this->test('Administrator menu entry removed', function () {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}menu"
                . " WHERE link LIKE '%com_appointments%' AND client_id = 1"
            );
            $row = $result ? $result->fetch_assoc() : null;

            return $row && (int) $row['cnt'] === 0;
        });

        echo "\n=== Uninstall Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function extensionId(): int
    {
        $result = $this->db->query(
            "SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments' AND type = 'component'"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['extension_id'] : 0;
    }

    private function setUninstallChoice(?string $choice): void
    {
        $result = $this->db->query(
            "SELECT params FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments' AND type = 'component'"
        );
        $row = $result ? $result->fetch_assoc() : null;
        $params = $row ? json_decode((string) $row['params'], true) : [];
        $params = is_array($params) ? $params : [];

        if ($choice === null) {
            unset($params['uninstall_data_action']);
        } else {
            $params['uninstall_data_action'] = $choice;
        }

        $encoded = $this->db->real_escape_string(json_encode($params, JSON_THROW_ON_ERROR));
        $this->db->query(
            "UPDATE {$this->dbPrefix}extensions SET params = '{$encoded}' WHERE element = 'com_appointments' AND type = 'component'"
        );
    }

    private function insertSchedulerTasks(): void
    {
        $time = gmdate('Y-m-d H:i:s');

        foreach (self::TASK_TYPES as $type) {
            $escapedType = $this->db->real_escape_string($type);
            $this->db->query(
                "INSERT INTO {$this->dbPrefix}scheduler_tasks"
                . " (title,type,execution_rules,cron_rules,state,params,created,created_by)"
                . " VALUES ('Appointments removal test','{$escapedType}','{\"rule-type\":\"manual\"}',"
                . "'{\"type\":\"manual\",\"exp\":\"\"}',1,'{}','{$time}',0)"
            );
        }
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

$test = new UninstallTest();
exit($test->run() ? 0 : 1);
