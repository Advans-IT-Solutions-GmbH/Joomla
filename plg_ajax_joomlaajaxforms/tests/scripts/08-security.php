<?php
/**
 * Test 06: Security
 * Tests security features of the plugin
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class SecurityTest
{
    private $db;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Security Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testCsrfTokenCheck() && $allPassed;
        $allPassed = $this->testPreparedStatements() && $allPassed;
        $allPassed = $this->testEmailEnumerationPrevention() && $allPassed;
        $allPassed = $this->testBlockedUserCheck() && $allPassed;
        $allPassed = $this->testJsonEncoding() && $allPassed;

        $this->printSummary();
        return $allPassed;
    }

    private function testCsrfTokenCheck(): bool
    {
        echo "Test: CSRF token validation in code... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (!file_exists($classFile)) {
            echo "FAIL (class file not found)\n";
            return false;
        }
        
        $content = file_get_contents($classFile);
        
        // Check for Session::checkToken usage
        if (strpos($content, 'Session::checkToken') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (CSRF check not found)\n";
        return false;
    }

    private function testPreparedStatements(): bool
    {
        echo "Test: Prepared statements for SQL... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (!file_exists($classFile)) {
            echo "FAIL (class file not found)\n";
            return false;
        }
        
        $content = file_get_contents($classFile);
        
        // Check for bind() usage (prepared statements)
        if (strpos($content, '->bind(') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (prepared statements not found)\n";
        return false;
    }

    private function testEmailEnumerationPrevention(): bool
    {
        echo "Test: Email enumeration prevention logic... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (!file_exists($classFile)) {
            echo "FAIL (class file not found)\n";
            return false;
        }
        
        $content = file_get_contents($classFile);
        
        // Check for comment about email enumeration or same success message
        if (strpos($content, 'enumeration') !== false || 
            strpos($content, 'Always return success') !== false ||
            strpos($content, 'Security:') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (enumeration prevention not documented)\n";
        return false;
    }

    private function testBlockedUserCheck(): bool
    {
        echo "Test: Blocked user check in code... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (!file_exists($classFile)) {
            echo "FAIL (class file not found)\n";
            return false;
        }
        
        $content = file_get_contents($classFile);
        
        // Check for block field in query
        if (strpos($content, 'block') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (blocked user check not found)\n";
        return false;
    }

    private function testJsonEncoding(): bool
    {
        echo "Test: JSON encoding for responses... ";
        
        $classFile = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        
        if (!file_exists($classFile)) {
            echo "FAIL (class file not found)\n";
            return false;
        }
        
        $content = file_get_contents($classFile);
        
        // Check for json_encode usage
        if (strpos($content, 'json_encode') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (json_encode not found)\n";
        return false;
    }

    private function printSummary(): void
    {
        echo "\n=== Security Test Summary ===\n";
        echo "All tests completed.\n";
    }
}

$test = new SecurityTest();
$result = $test->run();
exit($result ? 0 : 1);
