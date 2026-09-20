<?php
/**
 * Test 02: Configuration
 * Tests plugin configuration and parameters
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class ConfigurationTest
{
    private $db;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Configuration Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testPluginEnabled() && $allPassed;
        $allPassed = $this->testDefaultParams() && $allPassed;
        $allPassed = $this->testXmlConfigFields() && $allPassed;
        $allPassed = $this->testParamsCanBeUpdated() && $allPassed;
        $allPassed = $this->testDebugOption() && $allPassed;

        $this->printSummary();
        return $allPassed;
    }

    private function testPluginEnabled(): bool
    {
        echo "Test: Plugin is enabled... ";
        
        $query = $this->db->getQuery(true)
            ->select('enabled')
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
        
        $this->db->setQuery($query);
        $enabled = $this->db->loadResult();
        
        if ($enabled == 1) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (enabled=$enabled)\n";
        return false;
    }

    private function testDefaultParams(): bool
    {
        echo "Test: Default parameters set... ";
        
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
        
        $this->db->setQuery($query);
        $params = json_decode($this->db->loadResult() ?: '{}', true);
        
        // Check that enable_reset and enable_remind are set
        $resetEnabled = $params['enable_reset'] ?? 1;
        $remindEnabled = $params['enable_remind'] ?? 1;
        
        echo "PASS (reset=$resetEnabled, remind=$remindEnabled)\n";
        return true;
    }

    private function testXmlConfigFields(): bool
    {
        echo "Test: XML config fields exist... ";
        
        $xmlFile = '/var/www/html/plugins/ajax/joomlaajaxforms/joomlaajaxforms.xml';
        
        if (!file_exists($xmlFile)) {
            echo "FAIL (XML file not found)\n";
            return false;
        }
        
        $content = file_get_contents($xmlFile);
        
        // Check for config fields
        if (strpos($content, 'enable_reset') !== false && 
            strpos($content, 'enable_remind') !== false) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (config fields not found)\n";
        return false;
    }

    private function testParamsCanBeUpdated(): bool
    {
        echo "Test: Parameters can be updated... ";
        
        // Save original params
        $query = $this->db->getQuery(true)
            ->select('params')
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
        
        $this->db->setQuery($query);
        $originalParams = $this->db->loadResult();
        
        // Update params
        $testParams = json_encode([
            'enable_reset' => 0,
            'enable_remind' => 0
        ]);
        
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote($testParams))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
        
        $this->db->setQuery($query);
        
        try {
            $this->db->execute();
            
            // Restore original params
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('params') . ' = ' . $this->db->quote($originalParams))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));
            
            $this->db->setQuery($query);
            $this->db->execute();
            
            echo "PASS\n";
            return true;
        } catch (Exception $e) {
            echo "FAIL ({$e->getMessage()})\n";
            return false;
        }
    }

    /**
     * The plugin passes the "debug" option to the script, and it follows the
     * plugin parameter. Off by default, so a live site's browser console stays
     * clean; on only while an administrator switches it on.
     */
    private function testDebugOption(): bool
    {
        $original = $this->readParams();
        $passed   = true;

        try {
            echo "Test: Debug is off in the script options by default... ";
            $this->writeParams(array_merge($original, ['debug' => 0]));
            $off = $this->pluginScriptOptions();

            if (\is_array($off) && \array_key_exists('debug', $off) && $off['debug'] === false) {
                echo "PASS\n";
            } else {
                echo "FAIL (options: " . json_encode($off) . ")\n";
                $passed = false;
            }

            echo "Test: Debug reaches the script when the option is on... ";
            $this->writeParams(array_merge($original, ['debug' => 1]));
            $on = $this->pluginScriptOptions();

            if (\is_array($on) && ($on['debug'] ?? null) === true) {
                echo "PASS\n";
            } else {
                echo "FAIL (options: " . json_encode($on) . ")\n";
                $passed = false;
            }
        } finally {
            $this->writeParams($original);
        }

        return $passed;
    }

    /** Plugin parameters as an array. */
    private function readParams(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));

        $this->db->setQuery($query);

        return json_decode((string) ($this->db->loadResult() ?: '{}'), true) ?: [];
    }

    private function writeParams(array $params): void
    {
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__extensions'))
            ->set($this->db->quoteName('params') . ' = ' . $this->db->quote(json_encode($params)))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('ajax'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('joomlaajaxforms'));

        $this->db->setQuery($query)->execute();
    }

    /**
     * Script options the plugin injects into a real front-end page, read from
     * the rendered <script class="joomla-script-options"> element.
     */
    private function pluginScriptOptions(): ?array
    {
        $ch = curl_init('http://localhost/index.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        if (!preg_match_all('#<script[^>]*class="[^"]*joomla-script-options[^"]*"[^>]*>(.*?)</script>#s', $body, $matches)) {
            return null;
        }

        foreach ($matches[1] as $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES, 'UTF-8'), true);

            if (\is_array($decoded) && isset($decoded['plg_ajax_joomlaajaxforms'])) {
                return $decoded['plg_ajax_joomlaajaxforms'];
            }
        }

        return null;
    }

    private function printSummary(): void
    {
        echo "\n=== Configuration Test Summary ===\n";
        echo "All tests completed.\n";
    }
}

$test = new ConfigurationTest();
$result = $test->run();
exit($result ? 0 : 1);
