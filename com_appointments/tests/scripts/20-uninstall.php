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
    private $siteSecret;

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
        $this->siteSecret = $config->secret;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Uninstall Tests ===\n\n";

        $extensionId = $this->extensionId();
        $aclFixture = $this->prepareEventtypeAclFixture();

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
        foreach ([dirname($anchorPath), dirname($legacyAnchorPath)] as $folder) {
            if (!is_dir($folder) && !mkdir($folder, 0755, true) && !is_dir($folder)) {
                throw new \RuntimeException('Could not create an epoch anchor test directory.');
            }
        }
        $commonId = 1800000001;
        $stableOnlyId = 1800000002;
        $legacyOnlyId = 1800000003;
        $stableData = [$commonId => 5, $stableOnlyId => 3];
        $legacyData = [$commonId => 8, $legacyOnlyId => 4];
        $mergedData = [$commonId => 8, $stableOnlyId => 3, $legacyOnlyId => 4];
        $anchorContent = $this->epochAnchorBytes($mergedData);
        if (file_put_contents($anchorPath, $this->epochAnchorBytes($stableData)) === false
            || file_put_contents($legacyAnchorPath, $this->epochAnchorBytes($legacyData)) === false) {
            throw new \RuntimeException('Could not create the dual epoch anchor test fixtures.');
        }
        $this->insertSchedulerTasks();
        $this->testConflictingRetainedAsset($extensionId, $anchorPath, $legacyAnchorPath);

        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);
        clearstatcache(true);
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

        $this->test('Retained uninstall preserves component and event-type ACL assets', function () use ($aclFixture) {
            $componentAsset = $this->assetRow('com_appointments.retained');
            $eventtypeAsset = $this->assetRow($aclFixture['asset_name']);

            return $componentAsset !== null && $componentAsset['rules'] === $aclFixture['component_rules']
                && $eventtypeAsset !== null && $eventtypeAsset['rules'] === $aclFixture['rules'];
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

        $conflict = $this->insertConflictAsset('com_appointments');
        $retained = $this->assetRow('com_appointments.retained');
        $output = [];
        $exitCode = 0;
        exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/tmp/extension.zip --no-interaction 2>&1', $output, $exitCode);
        clearstatcache(true);
        try {
            $this->test('Reinstall conflict preserves retained ACL and anchor without registering the component', function () use ($retained, $anchorPath, $anchorContent) {
                return $this->extensionId() === 0 && $this->assetRow('com_appointments.retained') === $retained
                    && is_file($anchorPath) && file_get_contents($anchorPath) === $anchorContent;
            });
            $this->test('Fresh reinstall conflict loads translated guidance from the incoming package', function () use ($output) {
                $text = implode("\n", $output);

                return str_contains($text, 'permission') && !str_contains($text, 'COM_APPOINTMENTS_INSTALL_RETAINED_ACL_CONFLICT');
            });
        } finally {
            $this->removeConflictAsset($conflict);
        }

        $output = [];
        $exitCode = 0;
        exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=/tmp/extension.zip --no-interaction 2>&1', $output, $exitCode);
        clearstatcache(true);
        $this->test('Component can be reinstalled with retained data and its stable epoch anchor', function () use ($exitCode, $anchorPath, $legacyAnchorPath, $anchorContent) {
            return $exitCode === 0 && $this->extensionId() > 0
                && is_file($anchorPath) && file_get_contents($anchorPath) === $anchorContent
                && !is_file($legacyAnchorPath);
        });
        $this->test('Reinstall restores the component ACL tree and event-type deny rule', function () use ($aclFixture) {
            $componentAsset = $this->assetRow('com_appointments');
            $eventtypeAsset = $this->assetRow($aclFixture['asset_name']);
            $eventtype = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}appointments_eventtypes WHERE id = {$aclFixture['eventtype_id']}"
            );
            $row = $eventtype ? $eventtype->fetch_assoc() : null;

            return $componentAsset !== null && $componentAsset['rules'] === $aclFixture['component_rules']
                && $eventtypeAsset !== null && $eventtypeAsset['rules'] === $aclFixture['rules']
                && $row && (int) $row['cnt'] === 1;
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
        clearstatcache(true);
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
        $this->test('Explicit delete choice removes the retained component and event-type ACL assets', function () use ($aclFixture) {
            return $this->assetRow('com_appointments') === null
                && $this->assetRow('com_appointments.retained') === null
                && $this->assetRow($aclFixture['asset_name']) === null;
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

    private function testConflictingRetainedAsset(int $extensionId, string $anchorPath, string $legacyAnchorPath): void
    {
        $conflict = $this->insertConflictAsset('com_appointments.retained');
        $componentAsset = $this->assetRow('com_appointments');
        $stable = file_get_contents($anchorPath);
        $legacy = file_get_contents($legacyAnchorPath);
        $configHash = hash_file('sha256', '/var/www/html/administrator/components/com_appointments/config.xml');
        $snapshot = function (): array {
            return $this->db->query(
                "SELECT extension_id FROM {$this->dbPrefix}extensions"
                . " WHERE element = 'com_appointments' OR (element IN ('appointments','appointmentsdav') AND type = 'plugin')"
                . ' ORDER BY extension_id'
            )->fetch_all(MYSQLI_ASSOC);
        };
        $extensions = $snapshot();
        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);
        clearstatcache(true);
        try {
            $this->test('Conflicting retained ACL stops Joomla before removing registration, plugins, files, assets or anchors', function () use (
                $extensionId, $componentAsset, $anchorPath, $legacyAnchorPath, $stable, $legacy, $configHash, $extensions, $snapshot
            ) {
                return $this->extensionId() === $extensionId
                    && $snapshot() === $extensions
                    && $this->assetRow('com_appointments') === $componentAsset
                    && is_file('/var/www/html/administrator/components/com_appointments/config.xml')
                    && hash_file('sha256', '/var/www/html/administrator/components/com_appointments/config.xml') === $configHash
                    && is_file($anchorPath) && file_get_contents($anchorPath) === $stable
                    && is_file($legacyAnchorPath) && file_get_contents($legacyAnchorPath) === $legacy;
            });
            $this->test('Uninstall conflict displays translated permission recovery guidance', function () use ($output) {
                $text = implode("\n", $output);

                return str_contains($text, 'permission') && !str_contains($text, 'COM_APPOINTMENTS_UNINSTALL_RETAINED_ACL_CONFLICT');
            });
        } finally {
            $this->removeConflictAsset($conflict);
        }
    }

    private function insertConflictAsset(string $name): array
    {
        $root = $this->db->query("SELECT id, rgt, level FROM {$this->dbPrefix}assets WHERE parent_id = 0")->fetch_assoc();
        $right = (int) $root['rgt'];
        $parentId = (int) $root['id'];
        $level = (int) $root['level'] + 1;
        $name = $this->db->real_escape_string($name);
        if (!$this->db->query("UPDATE {$this->dbPrefix}assets SET rgt = rgt + 2 WHERE rgt >= {$right}")
            || !$this->db->query("UPDATE {$this->dbPrefix}assets SET lft = lft + 2 WHERE lft > {$right}")
            || !$this->db->query(
                "INSERT INTO {$this->dbPrefix}assets (parent_id,lft,rgt,level,name,title,rules)"
                . " VALUES ({$parentId},{$right}," . ($right + 1) . ",{$level},'{$name}','Conflict fixture','{}')"
            )) {
            throw new \RuntimeException('Could not create the conflicting asset fixture.');
        }

        return ['id' => (int) $this->db->insert_id, 'right' => $right + 1];
    }

    private function removeConflictAsset(array $fixture): void
    {
        $id = (int) $fixture['id'];
        $right = (int) $fixture['right'];
        if (!$this->db->query("DELETE FROM {$this->dbPrefix}assets WHERE id = {$id}")
            || !$this->db->query("UPDATE {$this->dbPrefix}assets SET lft = lft - 2 WHERE lft > {$right}")
            || !$this->db->query("UPDATE {$this->dbPrefix}assets SET rgt = rgt - 2 WHERE rgt > {$right}")) {
            throw new \RuntimeException('Could not remove the conflicting asset fixture.');
        }
    }

    private function prepareEventtypeAclFixture(): array
    {
        $time = gmdate('Y-m-d H:i:s');
        $title = 'uninstall-acl-' . bin2hex(random_bytes(6));
        $escapedTitle = $this->db->real_escape_string($title);
        if (!$this->db->query(
            "INSERT INTO {$this->dbPrefix}appointments_schedules (title, timezone, owner_user_id, created_utc, modified_utc)"
            . " VALUES ('{$escapedTitle}', 'UTC', 0, '{$time}', '{$time}')"
        )) {
            throw new \RuntimeException('Could not create the retained ACL test schedule.');
        }

        $scheduleId = (int) $this->db->insert_id;
        $token = bin2hex(random_bytes(32));
        if (!$this->db->query(
            "INSERT INTO {$this->dbPrefix}appointments_eventtypes"
            . " (title, alias, schedule_id, host_user_id, feed_token, created_utc, modified_utc)"
            . " VALUES ('{$escapedTitle}', '{$escapedTitle}', {$scheduleId}, 0, '{$token}', '{$time}', '{$time}')"
        )) {
            throw new \RuntimeException('Could not create the retained ACL test event type.');
        }

        $eventtypeId = (int) $this->db->insert_id;
        $assetName = 'com_appointments.eventtype.' . $eventtypeId;
        $escapedAssetName = $this->db->real_escape_string($assetName);
        $rules = '{"core.delete":{"2147483647":-1}}';
        $escapedRules = $this->db->real_escape_string($rules);
        $component = $this->assetRow('com_appointments');

        if ($component === null) {
            throw new \RuntimeException('The Appointments component asset is missing before uninstall.');
        }

        $componentId = (int) $component['id'];
        $right = (int) $component['rgt'];
        $level = (int) $component['level'] + 1;
        if (!$this->db->query("UPDATE {$this->dbPrefix}assets SET rgt = rgt + 2 WHERE rgt >= {$right}")
            || !$this->db->query("UPDATE {$this->dbPrefix}assets SET lft = lft + 2 WHERE lft > {$right}")
            || !$this->db->query(
            "INSERT INTO {$this->dbPrefix}assets (parent_id, lft, rgt, level, name, title, rules)"
            . " VALUES ({$componentId}, {$right}, " . ($right + 1) . ", {$level}, '{$escapedAssetName}',"
            . " '{$escapedTitle}', '{$escapedRules}')"
        )) {
            throw new \RuntimeException('Could not create the retained ACL test asset.');
        }

        $assetId = (int) $this->db->insert_id;
        if (!$this->db->query(
            "UPDATE {$this->dbPrefix}appointments_eventtypes SET asset_id = {$assetId} WHERE id = {$eventtypeId}"
        )) {
            throw new \RuntimeException('Could not link the retained ACL test asset.');
        }

        return [
            'asset_name' => $assetName,
            'component_rules' => $component['rules'],
            'eventtype_id' => $eventtypeId,
            'rules' => $rules,
        ];
    }

    private function assetRow(string $name): ?array
    {
        $escapedName = $this->db->real_escape_string($name);
        $result = $this->db->query(
            "SELECT id, lft, rgt, level, rules FROM {$this->dbPrefix}assets WHERE name = '{$escapedName}'"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row ?: null;
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

    private function epochAnchorBytes(array $userEpochs): string
    {
        ksort($userEpochs, SORT_NUMERIC);
        $data = [
            'calendar' => [],
            'calendar_key' => [],
            'log' => [],
            'user' => $userEpochs,
        ];
        $document = ['v' => 2];

        foreach ($data as $kind => $map) {
            $document[$kind] = (object) $map;
        }

        $key = hash_hmac('sha256', 'com_appointments:team-anchor', $this->siteSecret, true);
        $canonical = 'com_appointments/epoch-anchor/v2|' . json_encode($document, JSON_THROW_ON_ERROR);
        $document['mac'] = hash_hmac('sha256', $canonical, $key);

        return "<?php defined('_JEXEC') or die;\n" . json_encode($document, JSON_THROW_ON_ERROR) . "\n";
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
