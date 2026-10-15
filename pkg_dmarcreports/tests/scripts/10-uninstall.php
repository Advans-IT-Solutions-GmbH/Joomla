<?php
/**
 * Uninstall tests for the DMARC Reports package.
 *
 * Removing the package must remove the package registration and every bundled
 * extension, delete the installed directories, drop the component tables and
 * clean up the update sites.
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
        echo "=== Package Uninstall Tests ===\n\n";

        $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE type = 'package' AND element = 'pkg_dmarcreports'");
        $row = $result ? $result->fetch_assoc() : null;
        $extensionId = $row ? (int) $row['extension_id'] : 0;

        $this->test('Package extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);

        $this->test('Uninstall command executed', function () use ($exitCode, $output) {
            if ($exitCode !== 0) {
                echo '    ' . implode("\n    ", $output) . "\n";
            }
            return $exitCode === 0;
        });

        $this->test('Package removed from #__extensions', function () {
            return $this->countRows("type = 'package' AND element = 'pkg_dmarcreports'") === 0;
        });

        $this->test('Component removed from #__extensions', function () {
            return $this->countRows("type = 'component' AND element = 'com_dmarcreports'") === 0;
        });

        $this->test('Module removed from #__extensions', function () {
            return $this->countRows("type = 'module' AND element = 'mod_dmarcreports'") === 0;
        });

        $this->test('Task plugin removed from #__extensions', function () {
            return $this->countRows("type = 'plugin' AND folder = 'task' AND element = 'dmarcreports'") === 0;
        });

        $this->test('System plugin removed from #__extensions', function () {
            return $this->countRows("type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'") === 0;
        });

        $this->test('Component files removed', function () {
            return !is_dir('/var/www/html/administrator/components/com_dmarcreports');
        });

        $this->test('Module files removed', function () {
            return !is_dir('/var/www/html/administrator/modules/mod_dmarcreports');
        });

        $this->test('Task plugin files removed', function () {
            return !is_dir('/var/www/html/plugins/task/dmarcreports');
        });

        $this->test('System plugin files removed', function () {
            return !is_dir('/var/www/html/plugins/system/dmarcmtasts');
        });

        $this->test('Component tables dropped', function () {
            $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}dmarc_domains'");
            return $result && $result->num_rows === 0;
        });

        $this->test('Update sites removed (no orphan links)', function () {
            $result = $this->db->query(
                "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}update_sites WHERE location LIKE '%dmarcreports%'"
            );
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 0;
        });

        echo "\n=== Package Uninstall Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function countRows(string $where): int
    {
        $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE $where");
        $row = $result ? $result->fetch_assoc() : null;
        return $row ? (int) $row['cnt'] : -1;
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
