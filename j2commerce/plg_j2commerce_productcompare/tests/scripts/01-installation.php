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

    private function dbq(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        // The installer sets folder=j2store on J4/J5 (J2Store 4) and folder=j2commerce on J6.
        $expectedFolder = (getenv('J2COMMERCE_STACK') === 'j6') ? 'j2commerce' : 'j2store';

        $this->test('Plugin exists in #__extensions', function () use ($expectedFolder) {
            $query = $this->dbq()
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($expectedFolder));
            $this->db->setQuery($query);
            return (int) $this->db->loadResult() === 1;
        });

        $this->test('Plugin is enabled', function () use ($expectedFolder) {
            $query = $this->dbq()
                ->select($this->db->quoteName('enabled'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($expectedFolder));
            $this->db->setQuery($query);
            return (int) $this->db->loadResult() === 1;
        });

        $this->test('Plugin folder matches stack (' . $expectedFolder . ')', function () use ($expectedFolder) {
            $query = $this->dbq()
                ->select($this->db->quoteName('folder'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'));
            $this->db->setQuery($query);
            return $this->db->loadResult() === $expectedFolder;
        });

        $this->test('Plugin class file deployed', function () use ($expectedFolder) {
            return file_exists(JPATH_PLUGINS . '/' . $expectedFolder . '/productcompare/src/Extension/ProductCompare.php');
        });

        $this->test('Services provider deployed', function () use ($expectedFolder) {
            return file_exists(JPATH_PLUGINS . '/' . $expectedFolder . '/productcompare/services/provider.php');
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
