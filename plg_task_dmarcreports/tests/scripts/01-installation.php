<?php
/**
 * Installation tests for the DMARC Reports ingestion task plugin.
 */

class InstallationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $base = '/var/www/html/plugins/task/dmarcreports';

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

        $this->test('Plugin registered in extensions table', function () {
            $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'dmarcreports' AND type = 'plugin' AND folder = 'task'");
            return $result && $result->num_rows > 0;
        });

        $this->test('Plugin is a site plugin (client_id = 0)', function () {
            $result = $this->db->query("SELECT client_id FROM {$this->dbPrefix}extensions WHERE element = 'dmarcreports' AND type = 'plugin' AND folder = 'task'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['client_id'] === 0;
        });

        $this->test('Plugin directory exists', function () {
            return is_dir($this->base);
        });

        $this->test('Service provider exists', function () {
            return file_exists($this->base . '/services/provider.php');
        });

        $this->test('Task form manifest installed', function () {
            return file_exists($this->base . '/forms/ingest.xml');
        });

        $this->test('Language file en-GB exists', function () {
            return file_exists('/var/www/html/administrator/language/en-GB/plg_task_dmarcreports.ini')
                || file_exists($this->base . '/language/en-GB/plg_task_dmarcreports.ini');
        });

        // The shared installer creates the required ingestion task (ScheduledTasks).
        $this->test('Installation created exactly one ingestion task, switched on, every hour, auto-create off', function () {
            $result = $this->db->query("SELECT state, execution_rules, params FROM {$this->dbPrefix}scheduler_tasks WHERE type = 'plg_task_dmarcreports.ingest'");
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $rules = json_decode((string) ($rows[0]['execution_rules'] ?? ''), true) ?: [];
            $params = json_decode((string) ($rows[0]['params'] ?? ''), true) ?: [];

            return count($rows) === 1 && (int) $rows[0]['state'] === 1
                && ($rules['rule-type'] ?? '') === 'interval-hours' && (int) ($rules['interval-hours'] ?? 0) === 1
                && array_key_exists('auto_create_domains', $params) && (int) $params['auto_create_domains'] === 0;
        });

        $this->test('An update keeps the switched-on ingestion task unchanged and alone', function () {
            $snapshot = function (): array {
                $result = $this->db->query("SELECT * FROM {$this->dbPrefix}scheduler_tasks WHERE type = 'plg_task_dmarcreports.ingest' ORDER BY id");

                return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            };
            $before = $snapshot();
            exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=' . escapeshellarg($this->package()) . ' --no-interaction 2>&1', $output, $exitCode);
            $text = (string) preg_replace('/\s+/u', ' ', implode("\n", $output));

            return $exitCode === 0 && count($before) === 1 && $snapshot() === $before && !str_contains($text, 'required scheduled task');
        });

        echo "\n=== Installation Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /** A private copy of the candidate package: the installer may delete the file it installs from. */
    private function package(): string
    {
        $copy = sys_get_temp_dir() . '/dmarc-task-update-' . bin2hex(random_bytes(4)) . '.zip';
        copy('/tmp/extension.zip', $copy);

        return $copy;
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
