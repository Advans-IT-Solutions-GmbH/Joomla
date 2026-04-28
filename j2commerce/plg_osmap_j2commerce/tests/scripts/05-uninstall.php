<?php
/**
 * Uninstall Tests for OSMap J2Commerce Plugin
 *
 * Uses Joomla CLI (extension:remove) so the full Joomla installer
 * pipeline runs — including script.php uninstall() and all events.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class UninstallTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else       { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Uninstall Tests ===\n\n";

        $query = $this->db->getQuery(true)
            ->select('extension_id')
            ->from('#__extensions')
            ->where('element = ' . $this->db->quote('j2commerce'))
            ->where('folder = '  . $this->db->quote('osmap'))
            ->where('type = '    . $this->db->quote('plugin'));
        $extensionId = (int) $this->db->setQuery($query)->loadResult();

        $this->test('Extension ID found before uninstall', function () use ($extensionId) {
            return $extensionId > 0;
        });

        if (!$extensionId) {
            echo "Cannot proceed — plugin not found in #__extensions\n";
            echo "\n=== Uninstall Test Summary ===\n";
            echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
            return false;
        }

        $output   = [];
        $exitCode = 0;
        exec("php /var/www/html/cli/joomla.php extension:remove {$extensionId} --no-interaction 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);

        $this->test('Uninstall command executed', function () use ($exitCode, $outputStr) {
            echo "  Output: {$outputStr}\n";
            return $exitCode === 0;
        });

        $this->test('Plugin removed from #__extensions', function () {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__extensions')
                ->where('element = ' . $this->db->quote('j2commerce'))
                ->where('folder = '  . $this->db->quote('osmap'));
            return (int) $this->db->setQuery($query)->loadResult() === 0;
        });

        $this->test('Plugin files removed', function () {
            return !file_exists(JPATH_BASE . '/plugins/osmap/j2commerce/j2commerce.php');
        });

        echo "\n=== Uninstall Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }
}

$test = new UninstallTest();
exit($test->run() ? 0 : 1);
