<?php
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
    
    public function __construct() { 
        $this->db = Factory::getDbo(); 
    }
    
    private function test($name, $condition, $message = '') {
        if ($condition) {
            echo "✓ $name... PASS\n";
            $this->passed++;
            return true;
        } else {
            echo "✗ $name... FAIL" . ($message ? " - $message" : "") . "\n";
            $this->failed++;
            return false;
        }
    }
    
    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";
        
        // Test 1: Plugin is installed
        $query = $this->db->getQuery(true)
            ->select('extension_id, enabled')
            ->from('#__extensions')
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerce'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('privacy'));
        
        $this->db->setQuery($query);
        $plugin = $this->db->loadObject();
        
        $this->test('Plugin is installed', $plugin !== null, 'Plugin not found in database');
        
        if ($plugin) {
            $this->test('Plugin is enabled', $plugin->enabled == 1, 'Plugin is disabled');
        }
        
        // Test 2: Plugin files exist
        $this->test('Main plugin file exists', 
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/services/provider.php'));
        
        $this->test('Extension class exists', 
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php'));
        
        $this->test('Task class exists', 
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Task/AutoCleanupTask.php'));
        
        // Test 3: Language files exist
        $this->test('German language file exists', 
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/language/de-DE/plg_privacy_j2commerce.ini'));
        
        $this->test('English language file exists', 
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/language/en-GB/plg_privacy_j2commerce.ini'));

        // Test 4: Bundled override source files exist in plugin directory
        $overrideSrc = JPATH_BASE . '/plugins/privacy/j2commerce/overrides/com_j2store';
        $this->test('Override source directory exists',
            is_dir($overrideSrc));
        $this->test('Override: checkout/default_shipping_payment.php',
            file_exists($overrideSrc . '/checkout/default_shipping_payment.php'));
        $this->test('Override: myprofile/default.php',
            file_exists($overrideSrc . '/myprofile/default.php'));
        $this->test('Override: myprofile/default_addresses.php',
            file_exists($overrideSrc . '/myprofile/default_addresses.php'));

        // Test 5: Overrides deployed to at least one active frontend template
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('element'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('template'))
            ->where($this->db->quoteName('client_id') . ' = 0')
            ->where($this->db->quoteName('enabled') . ' = 1');
        $this->db->setQuery($query);
        $templates = $this->db->loadColumn() ?: [];

        $deployedCount = 0;
        foreach ($templates as $tpl) {
            $dest = JPATH_BASE . '/templates/' . $tpl . '/html/com_j2store/myprofile/default.php';
            if (file_exists($dest)) {
                $deployedCount++;
            }
        }
        $this->test(
            'Overrides deployed to at least one template',
            $deployedCount > 0,
            'No active template has the MyProfile override. Check postflight output.'
        );

        echo "\n=== Installation Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        
        return $this->failed === 0;
    }
}

$test = new InstallationTest();
exit($test->run() ? 0 : 1);
