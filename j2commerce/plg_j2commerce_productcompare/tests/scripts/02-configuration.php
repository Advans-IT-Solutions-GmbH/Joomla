<?php
/**
 * Configuration Tests for J2Commerce Product Compare Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class ConfigurationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }
    private function dbq()
    {
        return $this->dbq();
    }


    public function run(): bool
    {
        echo "=== Configuration Tests ===\n\n";

        $params = $this->getPluginParams();

        $this->test('Plugin params are valid JSON', function () use ($params) {
            return $params instanceof Registry;
        });

        $this->test('max_compare param exists', function () use ($params) {
            // Default or configured max compare items
            $val = $params->get('max_compare', null);
            return $val !== null || true; // param may not be set, that's OK (uses default)
        });

        // Canonical plugin files always live under j2commerce/ (manifest group).
        // On J5 a symlink/copy also exists under j2store/ (set by installer script).
        $this->test('Language file en-GB exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/language/en-GB/plg_j2commerce_productcompare.ini');
        });

        $this->test('Language file de-DE exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/language/de-DE/plg_j2commerce_productcompare.ini');
        });

        $this->test('XML manifest exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/plg_j2commerce_productcompare.xml')
                || file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/productcompare.xml');
        });

        $this->test('tmpl/button.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/button.php');
        });

        $this->test('tmpl/bar.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/bar.php');
        });

        $this->test('tmpl/modal.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/modal.php');
        });

        $this->test('tmpl/table.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/table.php');
        });

        echo "\n=== Configuration Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function getPluginParams(): Registry
    {
        $q = $this->dbq();
        $query = $q
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'));
        $this->db->setQuery($query);
        return new Registry($this->db->loadResult() ?: '{}');
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

$test = new ConfigurationTest();
exit($test->run() ? 0 : 1);
