<?php
/**
 * Uninstall Tests for J2Commerce Import/Export
 */

class UninstallTest
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
        echo "=== Uninstall Tests ===\n\n";

        // Get extension ID
        $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_j2commerce_importexport' AND type = 'component'");
        $row = $result ? $result->fetch_assoc() : null;
        $extensionId = $row ? (int) $row['extension_id'] : 0;

        $this->test('Extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        // Uninstall via Joomla CLI
        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);

        $this->test('Uninstall command executed', function () use ($exitCode, $outputStr) {
            echo "  Output: $outputStr\n";
            return $exitCode === 0;
        });

        $this->test('Component removed from #__extensions', function () {
            $result = $this->db->query("SELECT COUNT(*) as cnt FROM {$this->dbPrefix}extensions WHERE element = 'com_j2commerce_importexport'");
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 0;
        });

        $this->test('Component files removed', function () {
            return !file_exists('/var/www/html/administrator/components/com_j2commerce_importexport/src/Model/ExportModel.php');
        });

        echo "\n=== Uninstall Test Summary ===\n";
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

$test = new UninstallTest();
exit($test->run() ? 0 : 1);
