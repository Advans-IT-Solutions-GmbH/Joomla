<?php
/**
 * Uninstall Tests for J2Commerce Product Compare Plugin
 * Verifies clean removal of the plugin.
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
        if ($this->db->connect_error) {
            die("DB connection failed: " . $this->db->connect_error . "\n");
        }
    }

    public function run(): bool
    {
        echo "=== Uninstall Tests ===\n\n";

        // Get extension ID before uninstall — search both possible folders since
        // the installer may have set folder=j2store on J5 even though the manifest
        // group is j2commerce.
        $result = $this->db->query(
            "SELECT extension_id FROM {$this->dbPrefix}extensions"
            . " WHERE element = 'productcompare' AND type = 'plugin'"
            . " AND folder IN ('j2store','j2commerce') LIMIT 1"
        );
        $row = $result ? $result->fetch_assoc() : null;
        $extensionId = $row ? (int) $row['extension_id'] : 0;

        $this->test('Extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        // Uninstall via Joomla CLI. The removal must succeed cleanly: exit code 0
        // and no error in the output.
        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove $extensionId --no-interaction 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);
        echo "  CLI output: $outputStr\n";
        echo "  CLI exit code: $exitCode\n";

        $this->test('extension:remove exits with code 0', function () use ($exitCode) {
            return $exitCode === 0;
        });

        // Match only the explicit markers Joomla's CLI emits, not bare substrings
        // like "error"/"warning" that appear in benign summaries (e.g. "Errors: 0").
        // Exit code and the post-conditions below already guard the removal.
        $this->test('extension:remove reports no error', function () use ($outputStr) {
            return !preg_match('/\[ERROR\]|\[WARNING\]|\[CAUTION\]|not removed/i', $outputStr);
        });

        $this->test('Plugin removed from #__extensions', function () {
            $result = $this->db->query(
                "SELECT COUNT(*) as cnt FROM {$this->dbPrefix}extensions"
                . " WHERE element = 'productcompare' AND type = 'plugin'"
                . " AND folder IN ('j2store','j2commerce')"
            );
            $row = $result ? $result->fetch_assoc() : null;
            return $row && (int) $row['cnt'] === 0;
        });

        // On J5 Joomla installs the files under j2commerce/ and the installer
        // copies them to j2store/; after uninstall both folders must be gone,
        // including a leftover symlink.
        foreach (['j2commerce', 'j2store'] as $folder) {
            $this->test("plugins/$folder/productcompare removed", function () use ($folder) {
                $path = '/var/www/html/plugins/' . $folder . '/productcompare';
                return !file_exists($path) && !is_link($path);
            });
        }

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
