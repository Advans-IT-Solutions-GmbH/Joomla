<?php
/**
 * Test 01: Installation
 * Tests plugin installation and registration
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

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testPackageExists() && $allPassed;
        $allPassed = $this->testPluginRegistered() && $allPassed;
        $allPassed = $this->testFilesInstalled() && $allPassed;
        $allPassed = $this->testServiceProvider() && $allPassed;
        $allPassed = $this->testLanguageFiles() && $allPassed;
        $allPassed = $this->testJavaScriptFiles() && $allPassed;

        $this->printSummary();
        return $allPassed;
    }

    private function testPackageExists(): bool
    {
        echo "Test: Package file exists... ";
        
        $packagePath = '/tmp/extension.zip';
        if (file_exists($packagePath)) {
            $size = filesize($packagePath);
            $sizeKB = round($size / 1024, 2);
            echo "PASS (Size: {$sizeKB} KB)\n";
            return true;
        }
        
        echo "FAIL (File not found)\n";
        return false;
    }

    private function testPluginRegistered(): bool
    {
        echo "Test: Plugin registered in database... ";
        
        $query = $this->db->getQuery(true)
            ->select('extension_id, enabled')
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
        
        $this->db->setQuery($query);
        $plugin = $this->db->loadObject();
        
        if (!$plugin) {
            echo "FAIL (Not registered)\n";
            return false;
        }

        // The test environment enables the plugin after installation; a
        // disabled plugin means the environment is not the one under test.
        if ((int) $plugin->enabled !== 1) {
            echo "FAIL (ID: {$plugin->extension_id}, plugin is disabled)\n";
            return false;
        }

        echo "PASS (ID: {$plugin->extension_id}, Status: enabled)\n";
        return true;
    }

    private function testFilesInstalled(): bool
    {
        echo "Test: Plugin files installed... ";
        
        $requiredFiles = [
            '/var/www/html/plugins/ajax/joomlaajaxforms/joomlaajaxforms.xml',
            '/var/www/html/plugins/ajax/joomlaajaxforms/services/provider.php'
        ];
        
        $missing = [];
        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                $missing[] = basename($file);
            }
        }
        
        if (empty($missing)) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (Missing: " . implode(', ', $missing) . ")\n";
        return false;
    }

    private function testServiceProvider(): bool
    {
        echo "Test: Plugin class exists... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (file_exists($classFile)) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL\n";
        return false;
    }

    private function testLanguageFiles(): bool
    {
        echo "Test: Language files installed... ";
        
        $requiredFiles = [
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/en-GB/plg_ajax_joomlaajaxforms.ini',
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/en-GB/plg_ajax_joomlaajaxforms.sys.ini',
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/de-DE/plg_ajax_joomlaajaxforms.ini',
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/de-DE/plg_ajax_joomlaajaxforms.sys.ini',
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/fr-FR/plg_ajax_joomlaajaxforms.ini',
            '/var/www/html/plugins/ajax/joomlaajaxforms/language/fr-FR/plg_ajax_joomlaajaxforms.sys.ini'
        ];
        
        $missing = [];
        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                $missing[] = basename(dirname($file)) . '/' . basename($file);
            }
        }
        
        if (empty($missing)) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (Missing: " . implode(', ', $missing) . ")\n";
        return false;
    }

    private function testJavaScriptFiles(): bool
    {
        echo "Test: JavaScript files installed... ";
        
        $jsFile = '/var/www/html/media/plg_ajax_joomlaajaxforms/js/joomlaajaxforms.js';
        
        if (file_exists($jsFile)) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL\n";
        return false;
    }

    private function printSummary(): void
    {
        echo "\n=== Installation Test Summary ===\n";
        echo "All tests completed.\n";
    }
}

$test = new InstallationTest();
$result = $test->run();
exit($result ? 0 : 1);
