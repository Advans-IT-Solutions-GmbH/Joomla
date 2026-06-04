<?php
/**
 * @package     J2Commerce.ProductCompare
 * Installation Tests for J2Commerce Product Compare Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
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

        $this->test('Plugin exists in #__extensions', function () {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('j2store'));
            $this->db->setQuery($query);
            return (int) $this->db->loadResult() === 1;
        });

        $this->test('Plugin is enabled', function () {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('enabled'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('j2store'));
            $this->db->setQuery($query);
            return (int) $this->db->loadResult() === 1;
        });

        $this->test('Plugin folder is j2store', function () {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('folder'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'));
            $this->db->setQuery($query);
            return $this->db->loadResult() === 'j2store';
        });

        $this->test('Plugin class file deployed', function () {
            return file_exists(JPATH_PLUGINS . '/j2store/productcompare/src/Extension/ProductCompare.php');
        });

        $this->test('Services provider deployed', function () {
            return file_exists(JPATH_PLUGINS . '/j2store/productcompare/services/provider.php');
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
