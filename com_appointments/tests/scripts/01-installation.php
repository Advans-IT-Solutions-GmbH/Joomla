<?php
/**
 * Installation tests for the Appointments component.
 */

require_once __DIR__ . '/shared-database-check-lib.php';
require_once __DIR__ . '/shared-test-helpers.php';

class InstallationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        $this->test('Component registered in extensions table', function () {
            $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments' AND type = 'component'");
            return $result && $result->num_rows > 0;
        });

        $this->test('Component is enabled', function () {
            $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && $row['enabled'] == 1;
        });
        $this->test('Installed shared module URLs select the lowest administrator id or the native add page', function () {
            require __DIR__ . '/appointments-joomla.php';
            $support = JPATH_ADMINISTRATOR . '/components/com_appointments/advansinstaller';
            require_once $support . '/load.php';
            $check = require __DIR__ . '/shared-installer-module-url.php';
            $result = $check($joomlaDb, $support . '/SystemCheck.php');

            return $result['rolled_back'];
        });
        $this->test('Bundled Scheduler plugin and its license are installed', function () {
            $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element='appointments' AND type='plugin' AND folder='task'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['enabled'] === 1
                && is_file('/var/www/html/plugins/task/appointments/services/provider.php')
                && is_file('/var/www/html/plugins/task/appointments/LICENSE.txt');
        });

        $this->test('Team calendar plugins are installed and stay disabled until an operator enables them', function () {
            // Default deny: the CalDAV endpoint and the key handling at sign-in
            // are off after installation. Enabling them is a deliberate step.
            foreach (['system' => 'appointmentsdav', 'user' => 'appointments'] as $group => $element) {
                $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element='{$element}' AND type='plugin' AND folder='{$group}'");
                $row = $result ? $result->fetch_assoc() : null;

                if (!$row || (int) $row['enabled'] !== 0
                    || !is_file("/var/www/html/plugins/{$group}/{$element}/services/provider.php")
                    || !is_file("/var/www/html/plugins/{$group}/{$element}/LICENSE.txt")) {
                    return false;
                }
            }

            return true;
        });

        $this->test('Admin menu entry exists', function () {
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}menu WHERE link LIKE '%com_appointments%' AND client_id = 1");
            return $result && $result->num_rows > 0;
        });

        $this->test('Administrator directory exists', function () {
            return is_dir('/var/www/html/administrator/components/com_appointments');
        });

        $this->test('Site directory exists', function () {
            // The booking pages live in the site part; a component installed
            // without it would offer no public booking page at all.
            return is_dir('/var/www/html/components/com_appointments');
        });

        $this->test('Administrator entry service exists', function () {
            return file_exists('/var/www/html/administrator/components/com_appointments/services/provider.php');
        });

        $this->test('Administrator options form and safe defaults are installed', function () {
            $config = simplexml_load_file('/var/www/html/administrator/components/com_appointments/config.xml');

            return $config !== false && !isset($config->fields)
                && (string) $config->xpath('//field[@name="erase_mode"]')[0]['default'] === 'anonymise'
                && (string) $config->xpath('//field[@name="retention_days"]')[0]['default'] === '365'
                && (string) $config->xpath('//field[@name="audit_retention_years"]')[0]['default'] === '3'
                && (string) $config->xpath('//field[@name="conference_provider"]')[0]['default'] === 'none';
        });

        $this->test('Site entry service exists', function () {
            return file_exists('/var/www/html/components/com_appointments/services/provider.php');
        });

        $this->test('Media assets are installed and declared', function () {
            return file_exists('/var/www/html/media/com_appointments/joomla.asset.json')
                && file_exists('/var/www/html/media/com_appointments/css/appointments.css');
        });

        $this->test('Update site registered', function () {
            $result = $this->db->query(
                "SELECT s.update_site_id FROM {$this->dbPrefix}update_sites AS s"
                . " INNER JOIN {$this->dbPrefix}update_sites_extensions AS m ON m.update_site_id = s.update_site_id"
                . " INNER JOIN {$this->dbPrefix}extensions AS e ON e.extension_id = m.extension_id"
                . " WHERE e.element = 'com_appointments'"
            );
            return $result && $result->num_rows > 0;
        });

        $this->test('Schema baseline registered for future migrations', function () {
            $result = $this->db->query(
                "SELECT s.version_id FROM {$this->dbPrefix}schemas AS s"
                . " INNER JOIN {$this->dbPrefix}extensions AS e ON e.extension_id = s.extension_id"
                . " WHERE e.element = 'com_appointments' AND e.type = 'component'"
            );
            $row = $result ? $result->fetch_assoc() : null;

            // The newest file in sql/updates/mysql: an update runs every later file exactly once.
            return $row && $row['version_id'] === '1.4.0';
        });

        $this->test('Administrator language file en-GB is installed', function () {
            return file_exists('/var/www/html/administrator/language/en-GB/com_appointments.ini')
                && file_exists('/var/www/html/administrator/language/en-GB/com_appointments.sys.ini');
        });

        $this->test('Site language file en-GB is installed', function () {
            return file_exists('/var/www/html/language/en-GB/com_appointments.ini');
        });

        $this->test('Package ships de-DE and fr-FR for both parts', function () {
            // Joomla copies a <language> entry only when that language exists on
            // the target site: Installer::parseLanguages() skips an entry whose
            // destination folder is missing. The test site has en-GB only, so
            // de-DE and fr-FR are asserted in the package, which is where they
            // have to be for a site that does have those language packs. The
            // installer-messages suite then proves they are really used, because
            // it reinstalls the package once per language.
            $zip = new \ZipArchive();

            if ($zip->open('/tmp/extension.zip') !== true) {
                return false;
            }

            $expected = [
                'administrator/language/de-DE/com_appointments.ini',
                'administrator/language/de-DE/com_appointments.sys.ini',
                'administrator/language/fr-FR/com_appointments.ini',
                'administrator/language/fr-FR/com_appointments.sys.ini',
                'language/de-DE/com_appointments.ini',
                'language/fr-FR/com_appointments.ini',
            ];

            foreach ($expected as $entry) {
                if ($zip->locateName($entry) === false) {
                    $zip->close();

                    return false;
                }
            }

            $zip->close();

            return true;
        });

        $this->test('No signing secret is stored in the component parameters', function () {
            // The component derives every signing key from the Joomla site
            // secret, so no signing key is ever written to the database, printed
            // into the Options screen or lost when that screen is saved.
            //
            // Only the parameter names that would reintroduce a stored signing
            // key are rejected. A substring search for "secret" would be wrong:
            // an operator's Jitsi JWT secret is a legitimate provider setting
            // and belongs in the component parameters.
            $forbidden = ['install_secret', 'signing_secret', 'link_secret', 'hmac_secret', 'secret'];

            $result = $this->db->query("SELECT params FROM {$this->dbPrefix}extensions WHERE element = 'com_appointments'");
            $row = $result ? $result->fetch_assoc() : null;

            if (!$row) {
                return false;
            }

            $params = json_decode((string) $row['params'], true);

            if (!\is_array($params)) {
                // No parameters at all is the expected state after a fresh
                // install and satisfies the assertion.
                return true;
            }

            foreach ($forbidden as $name) {
                if (\array_key_exists($name, $params)) {
                    echo "    found forbidden parameter: {$name}\n";

                    return false;
                }
            }

            return true;
        });

        $this->test('Joomla database check reports com_appointments as up to date after a fresh install', function () {
            $problems = joomla_component_database_problems($this->db, $this->dbPrefix, 'com_appointments');
            foreach ($problems as $problem) {
                echo "    {$problem}\n";
            }

            return $problems === [];
        });
        $this->test('An update over an installation with the old manifest name leaves no database problem', function () {
            // A site installed before the manifest rename still has com_appointments.xml, and
            // the maintenance:database --fix workaround emptied the registered version.
            $admin = '/var/www/html/administrator/components/com_appointments';
            copy($admin . '/appointments.xml', $admin . '/com_appointments.xml');
            $this->db->query("UPDATE {$this->dbPrefix}extensions SET manifest_cache = JSON_SET(manifest_cache, '$.version', '')"
                . " WHERE type = 'component' AND element = 'com_appointments'");
            [$exitCode, $output] = sh_cli_install_reviewed_fixture('/var/www/html', '/tmp/extension.zip');

            if ($exitCode !== 0) {
                echo '    ' . $output . "\n";

                return false;
            }

            $problems = joomla_component_database_problems($this->db, $this->dbPrefix, 'com_appointments');
            foreach ($problems as $problem) {
                echo "    {$problem}\n";
            }

            return $problems === [];
        });
        $tasks = function (): array {
            $found = [];
            $result = $this->db->query("SELECT id, type, state, execution_rules, params FROM {$this->dbPrefix}scheduler_tasks"
                . " WHERE type IN ('plg_task_appointments.reminders','plg_task_appointments.retention') ORDER BY id");
            while ($result && ($row = $result->fetch_assoc())) {
                $found[$row['type']][] = $row;
            }

            return $found;
        };
        $reinstall = function (): bool {
            [$exitCode, $output] = sh_cli_install_reviewed_fixture('/var/www/html', '/tmp/extension.zip');
            if ($exitCode !== 0) {
                echo '    ' . $output . "\n";
            }

            return $exitCode === 0;
        };

        $this->test('Installation creates one enabled Scheduler task per routine', function () use ($tasks) {
            $found = $tasks();
            $reminders = $found['plg_task_appointments.reminders'] ?? [];
            $retention = $found['plg_task_appointments.retention'] ?? [];
            $rules = static fn (array $row): array => json_decode((string) $row['execution_rules'], true) ?: [];

            return count($reminders) === 1 && count($retention) === 1
                && (int) $reminders[0]['state'] === 1 && (int) $retention[0]['state'] === 1
                && ($rules($reminders[0])['rule-type'] ?? '') === 'interval-minutes' && (int) ($rules($reminders[0])['interval-minutes'] ?? 0) === 5
                && ($rules($retention[0])['rule-type'] ?? '') === 'interval-hours' && (int) ($rules($retention[0])['interval-hours'] ?? 0) === 24
                && (int) (json_decode((string) $reminders[0]['params'], true)['batch_size'] ?? 0) === 100;
        });

        $this->test('An update never re-enables or duplicates a task the operator disabled or trashed', function () use ($tasks, $reinstall) {
            $found = $tasks();
            $reminders = (int) ($found['plg_task_appointments.reminders'][0]['id'] ?? 0);
            $retention = (int) ($found['plg_task_appointments.retention'][0]['id'] ?? 0);
            if ($reminders === 0 || $retention === 0) {
                return false;
            }

            try {
                $this->db->query("UPDATE {$this->dbPrefix}scheduler_tasks SET state = 0 WHERE id = {$reminders}");
                $this->db->query("UPDATE {$this->dbPrefix}scheduler_tasks SET state = -2 WHERE id = {$retention}");
                if (!$reinstall()) {
                    return false;
                }
                $after = $tasks();

                return count($after['plg_task_appointments.reminders'] ?? []) === 1 && count($after['plg_task_appointments.retention'] ?? []) === 1
                    && (int) $after['plg_task_appointments.reminders'][0]['state'] === 0
                    && (int) $after['plg_task_appointments.retention'][0]['state'] === -2;
            } finally {
                $this->db->query("UPDATE {$this->dbPrefix}scheduler_tasks SET state = 1 WHERE id IN ({$reminders}, {$retention})");
            }
        });

        $this->test('An update creates a missing routine task disabled, so it changes no existing booking unasked', function () use ($tasks, $reinstall) {
            $this->db->query("DELETE FROM {$this->dbPrefix}scheduler_tasks WHERE type = 'plg_task_appointments.retention'");
            if (!$reinstall()) {
                return false;
            }
            $after = $tasks();
            $created = $after['plg_task_appointments.retention'] ?? [];
            // The later suites run the installed tasks: enable the new one again as an operator would.
            if (count($created) === 1) {
                $this->db->query("UPDATE {$this->dbPrefix}scheduler_tasks SET state = 1 WHERE id = " . (int) $created[0]['id']);
            }
            return count($created) === 1 && (int) $created[0]['state'] === 0
                && count($after['plg_task_appointments.reminders'] ?? []) === 1;
        });

        echo "\n=== Installation Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
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

$test = new InstallationTest();
exit($test->run() ? 0 : 1);
