<?php
/**
 * Installation tests for the DMARC Reports component.
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
        echo "=== Installation Tests ===\n\n";

        $this->test('Component registered in extensions table', function () {
            $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_dmarcreports' AND type = 'component'");
            return $result && $result->num_rows > 0;
        });

        $this->test('Component is enabled', function () {
            $result = $this->db->query("SELECT enabled FROM {$this->dbPrefix}extensions WHERE element = 'com_dmarcreports'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && $row['enabled'] == 1;
        });

        $this->test('Admin menu entry exists', function () {
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}menu WHERE link LIKE '%com_dmarcreports%' AND client_id = 1");
            return $result && $result->num_rows > 0;
        });

        $this->test('Component directory exists', function () {
            return is_dir('/var/www/html/administrator/components/com_dmarcreports');
        });

        $this->test('Dispatcher / entry service exists', function () {
            return file_exists('/var/www/html/administrator/components/com_dmarcreports/services/provider.php');
        });

        $this->test('Language file en-GB exists', function () {
            return file_exists('/var/www/html/administrator/language/en-GB/com_dmarcreports.ini')
                || file_exists('/var/www/html/administrator/components/com_dmarcreports/language/en-GB/com_dmarcreports.ini');
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
