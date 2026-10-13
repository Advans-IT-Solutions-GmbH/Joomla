<?php
/**
 * Installation tests for the DMARC Reports dashboard module.
 */

class InstallationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $base = '/var/www/html/administrator/modules/mod_dmarcreports';

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

        $this->test('Module registered in extensions table', function () {
            $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'mod_dmarcreports' AND type = 'module'");
            return $result && $result->num_rows > 0;
        });

        $this->test('Module is an administrator module (client_id = 1)', function () {
            $result = $this->db->query("SELECT client_id FROM {$this->dbPrefix}extensions WHERE element = 'mod_dmarcreports' AND type = 'module'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['client_id'] === 1;
        });

        $this->test('Module is enabled', function () {
            $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element = 'mod_dmarcreports' AND type = 'module'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['enabled'] === 1;
        });

        $this->test('Module directory exists', function () {
            return is_dir($this->base);
        });

        $this->test('Service provider exists', function () {
            return file_exists($this->base . '/services/provider.php');
        });

        $this->test('Language file en-GB exists', function () {
            return file_exists('/var/www/html/administrator/language/en-GB/mod_dmarcreports.ini')
                || file_exists($this->base . '/language/en-GB/mod_dmarcreports.ini');
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
