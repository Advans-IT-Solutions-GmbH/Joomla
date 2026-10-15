<?php
/**
 * Installation tests for the DMARC Reports package.
 *
 * Installing pkg_dmarcreports must register the package itself and all four
 * bundled extensions (component, dashboard module, ingestion task plugin and
 * MTA-STS system plugin) and create the component's database tables.
 */

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
        echo "=== Package Installation Tests ===\n\n";

        $this->test('Package registered in extensions table', function () {
            return $this->rowExists("type = 'package' AND element = 'pkg_dmarcreports'");
        });

        $this->test('Component com_dmarcreports registered', function () {
            return $this->rowExists("type = 'component' AND element = 'com_dmarcreports'");
        });

        $this->test('Dashboard module mod_dmarcreports registered (admin)', function () {
            return $this->rowExists("type = 'module' AND element = 'mod_dmarcreports' AND client_id = 1");
        });

        $this->test('Ingestion task plugin registered', function () {
            return $this->rowExists("type = 'plugin' AND folder = 'task' AND element = 'dmarcreports'");
        });

        $this->test('MTA-STS system plugin registered', function () {
            return $this->rowExists("type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'");
        });

        $this->test('Component tables created', function () {
            foreach (['dmarc_domains', 'dmarc_sources', 'dmarc_reports', 'dmarc_records', 'dmarc_actions'] as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows === 0) {
                    return false;
                }
            }
            return true;
        });

        $this->test('TLS-RPT tables created', function () {
            foreach (['dmarc_tlsrpt_reports', 'dmarc_tlsrpt_failures'] as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows === 0) {
                    return false;
                }
            }
            return true;
        });

        $this->test('Component directory exists', function () {
            return is_dir('/var/www/html/administrator/components/com_dmarcreports');
        });

        $this->test('Module directory exists', function () {
            return is_dir('/var/www/html/administrator/modules/mod_dmarcreports');
        });

        $this->test('Task plugin directory exists', function () {
            return is_dir('/var/www/html/plugins/task/dmarcreports');
        });

        $this->test('System plugin directory exists', function () {
            return is_dir('/var/www/html/plugins/system/dmarcmtasts');
        });

        echo "\n=== Package Installation Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function rowExists(string $where): bool
    {
        $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE $where");
        return $result && $result->num_rows > 0;
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
