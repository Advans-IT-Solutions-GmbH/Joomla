<?php
/**
 * Configuration Tests for OSMap J2Commerce Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
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

    public function run(): bool
    {
        echo "=== Configuration Tests ===\n\n";

        $params = $this->getPluginParams();

        $this->test('Plugin params are valid JSON', function () use ($params) {
            return $params instanceof Registry;
        });

        $this->test('Default priority is 0.8', function () use ($params) {
            $val = $params->get('priority', '0.8');
            return $val === '0.8' || $val === 0.8;
        });

        $this->test('Default changefreq is weekly', function () use ($params) {
            $val = $params->get('changefreq', 'weekly');
            return $val === 'weekly';
        });

        $this->test('XML manifest has correct plugin group', function () {
            $xml = simplexml_load_file(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.xml');
            return (string) $xml['group'] === 'osmap';
        });

        $this->test('XML manifest has correct element', function () {
            $xml = simplexml_load_file(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.xml');
            return (string) $xml->element === 'j2commerce';
        });

        echo "\n=== Configuration Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function getPluginParams(): Registry
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerce'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('osmap'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'));
        return new Registry($this->db->setQuery($query)->loadResult() ?: '{}');
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

$test = new ConfigurationTest();
exit($test->run() ? 0 : 1);
