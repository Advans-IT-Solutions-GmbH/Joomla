<?php
/**
 * Installation Tests for OSMap J2Commerce Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class InstallationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        $this->test('Plugin registered in #__extensions', function () {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__extensions')
                ->where('element = ' . $this->db->quote('j2commerce'))
                ->where('folder = ' . $this->db->quote('osmap'))
                ->where('type = ' . $this->db->quote('plugin'));
            return (int) $this->db->setQuery($query)->loadResult() === 1;
        });

        $this->test('Plugin is enabled', function () {
            $query = $this->db->getQuery(true)
                ->select('enabled')
                ->from('#__extensions')
                ->where('element = ' . $this->db->quote('j2commerce'))
                ->where('folder = ' . $this->db->quote('osmap'));
            return (int) $this->db->setQuery($query)->loadResult() === 1;
        });

        $this->test('Plugin file exists', function () {
            return file_exists(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php');
        });

        $this->test('XML manifest exists', function () {
            return file_exists(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.xml');
        });

        echo "\n=== Installation Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else       { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new InstallationTest();
exit($test->run() ? 0 : 1);
