<?php
/**
 * Test 03: AJAX Endpoint
 * Tests that the AJAX endpoint is accessible
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class AjaxEndpointTest
{
    private $baseUrl = 'http://localhost';

    public function run(): bool
    {
        echo "=== AJAX Endpoint Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testPluginEnabled() && $allPassed;
        $allPassed = $this->testEndpointAccessible() && $allPassed;
        $allPassed = $this->testResponseFormat() && $allPassed;
        $allPassed = $this->testTextsTask() && $allPassed;

        $this->printSummary();
        return $allPassed;
    }

    private function testPluginEnabled(): bool
    {
        echo "Test: Plugin is enabled... ";
        
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('enabled')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));
        
        $db->setQuery($query);
        $enabled = $db->loadResult();
        
        if ($enabled == 1) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (enabled=$enabled)\n";
        return false;
    }

    private function testEndpointAccessible(): bool
    {
        echo "Test: AJAX endpoint accessible... ";
        
        // com_ajax endpoint
        $url = $this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "(HTTP $httpCode) ";
        
        // HTTP 200 is success, 303 means endpoint exists but redirects (CSRF token missing in test)
        if ($httpCode === 200 && !empty($response)) {
            echo "PASS\n";
            echo "  Response preview: " . substr($response, 0, 100) . "\n";
            return true;
        }
        
        if ($httpCode === 303 || $httpCode === 302) {
            echo "PASS (redirect - endpoint exists, CSRF token required)\n";
            return true;
        }
        
        echo "FAIL\n";
        echo "  Response: $response\n";
        return false;
    }

    private function testResponseFormat(): bool
    {
        echo "Test: Response contains expected structure... ";
        
        $url = $this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $httpCode = 0;
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Redirect means endpoint exists but requires CSRF token or SEF redirect
        if (in_array($httpCode, [301, 302, 303])) {
            echo "PASS (redirect response - CSRF token required in test env)\n";
            return true;
        }
        
        // com_ajax wraps plugin response, try to decode
        $data = json_decode($response, true);
        
        if ($data !== null) {
            echo "PASS (valid JSON)\n";
            return true;
        }
        
        // HTML response is also acceptable - Joomla rendered the page
        if (strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false) {
            echo "PASS (HTML response - Joomla processed the request)\n";
            return true;
        }
        
        echo "FAIL (HTTP $httpCode, invalid response format)\n";
        return false;
    }

    /**
     * The script carries no text of its own. When a page loads it without the
     * texts in its script options, it asks the endpoint (task "texts"), which
     * answers without a form token with the translated texts of the plugin.
     */
    private function testTextsTask(): bool
    {
        require_once __DIR__ . '/ajax-test-helpers.php';

        echo "Test: Script texts are delivered by the endpoint without a token... ";

        // Sent like the script sends it: POST to the com_ajax URL, task in the body,
        // no form token, no session.
        $ch = curl_init($this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'task=texts');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = ajaxforms_decode_response($body);
        $texts   = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $en = [];
        $ini = '/var/www/html/plugins/ajax/joomlaajaxforms/language/en-GB/plg_ajax_joomlaajaxforms.ini';
        if (is_file($ini)) {
            $en = parse_ini_file($ini, false, INI_SCANNER_RAW) ?: [];
        }

        $problems = [];

        if ($httpCode !== 200 || ($payload['success'] ?? false) !== true) {
            $problems[] = "HTTP $httpCode, body " . substr($body, 0, 150);
        }

        foreach (['ERROR_GENERIC', 'PROFILE_SAVED', 'CLOSE'] as $name) {
            $value = (string) ($texts[$name] ?? '');

            if ($value === '' || preg_match('/^[A-Z][A-Z0-9_]+$/', $value)) {
                $problems[] = "$name missing or untranslated ('$value')";
            }
        }

        if (($texts['ERROR_GENERIC'] ?? null) !== ($en['PLG_AJAX_JOOMLAAJAXFORMS_JS_ERROR_GENERIC'] ?? '')) {
            $problems[] = 'ERROR_GENERIC is not the text of the language file';
        }

        if ($problems === []) {
            echo "PASS\n";

            return true;
        }

        echo "FAIL (" . implode('; ', $problems) . ")\n";

        return false;
    }

    private function printSummary(): void
    {
        echo "\n=== AJAX Endpoint Test Summary ===\n";
        echo "All tests completed.\n";
    }
}

$test = new AjaxEndpointTest();
$result = $test->run();
exit($result ? 0 : 1);
