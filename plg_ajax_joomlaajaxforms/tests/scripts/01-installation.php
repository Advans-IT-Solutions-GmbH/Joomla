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
        $allPassed = $this->testNoConsoleNoise() && $allPassed;
        $allPassed = $this->testScriptHasNoFixedTexts() && $allPassed;

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

    /**
     * The shipped script must stay quiet on a live site: developer traces only
     * through formsDebug(), which prints while the plugin option "debug" is on.
     */
    private function testNoConsoleNoise(): bool
    {
        $jsFile = '/var/www/html/media/plg_ajax_joomlaajaxforms/js/joomlaajaxforms.js';
        $source = is_file($jsFile) ? (string) file_get_contents($jsFile) : '';
        $passed = true;

        echo "Test: Script writes no unconditional console output... ";
        $helper = $this->jsFunctionBody($source, 'function formsDebug');
        // console.log may only be reached through formsDebug(), which is the one
        // place that checks the debug option first.
        $logCalls = preg_match_all('/console\.log\b/', $source);
        $inHelper = $helper !== null ? preg_match_all('/console\.log\b/', $helper) : 0;

        if ($source !== '' && $helper !== null && $logCalls > 0 && $logCalls === $inHelper) {
            echo "PASS\n";
        } else {
            echo "FAIL ({$logCalls} console.log uses, {$inHelper} of them inside formsDebug())\n";
            $passed = false;
        }

        echo "Test: Developer traces are gated by the debug option... ";
        if ($helper !== null
            && str_contains($helper, "Joomla.getOptions('plg_ajax_joomlaajaxforms')")
            && str_contains($helper, '.debug')
            && str_contains($helper, 'if (enabled')
        ) {
            echo "PASS\n";
        } else {
            echo "FAIL (formsDebug() does not read the debug script option)\n";
            $passed = false;
        }

        echo "Test: Every JSON.parse() sits inside a try block... ";
        $unguarded = $this->unguardedJsonParse($source);
        if ($source !== '' && $unguarded === []) {
            echo "PASS\n";
        } else {
            echo "FAIL (" . count($unguarded) . " unguarded call(s))\n";
            $passed = false;
        }

        echo "Test: Debug option is declared in the manifest, off by default... ";
        $manifest = '/var/www/html/plugins/ajax/joomlaajaxforms/joomlaajaxforms.xml';
        $ok       = false;

        if (is_file($manifest)) {
            $xml = simplexml_load_file($manifest);
            foreach ($xml->xpath('//field[@name="debug"]') ?: [] as $field) {
                $ok = (string) $field['default'] === '0'
                    && (string) $field['label'] === 'PLG_AJAX_JOOMLAAJAXFORMS_DEBUG';
            }
        }

        if ($ok) {
            echo "PASS\n";
        } else {
            echo "FAIL (no debug field with default 0)\n";
            $passed = false;
        }

        echo "Test: Debug option is translated in de-DE, en-GB and fr-FR... ";
        $missing = [];
        foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
            $ini = '/var/www/html/plugins/ajax/joomlaajaxforms/language/' . $tag . '/plg_ajax_joomlaajaxforms.ini';
            $values = is_file($ini) ? (parse_ini_file($ini, false, INI_SCANNER_RAW) ?: []) : [];

            foreach (['PLG_AJAX_JOOMLAAJAXFORMS_DEBUG', 'PLG_AJAX_JOOMLAAJAXFORMS_DEBUG_DESC'] as $key) {
                if (empty($values[$key])) {
                    $missing[] = $tag . '/' . $key;
                }
            }

            if ($tag === 'de-DE' && str_contains((string) ($values['PLG_AJAX_JOOMLAAJAXFORMS_DEBUG_DESC'] ?? ''), 'ß')) {
                $missing[] = 'de-DE uses an eszett';
            }
        }

        if ($missing === []) {
            echo "PASS\n";
        } else {
            echo "FAIL (" . implode(', ', $missing) . ")\n";
            $passed = false;
        }

        return $passed;
    }

    /**
     * The script shows only texts it received from the plugin or the page (or
     * fetched from the endpoint), never a text of its own, so a site in any
     * language shows its own language.
     */
    private function testScriptHasNoFixedTexts(): bool
    {
        $jsFile = '/var/www/html/media/plg_ajax_joomlaajaxforms/js/joomlaajaxforms.js';
        $source = is_file($jsFile) ? (string) file_get_contents($jsFile) : '';
        $passed = true;

        echo "Test: getFormsLang() takes a key only, without a fallback text... ";
        if ($source !== '' && preg_match('/getFormsLang\(\s*\'[A-Z_]+\'\s*,/', $source) === 0) {
            echo "PASS\n";
        } else {
            echo "FAIL\n";
            $passed = false;
        }

        echo "Test: Script contains none of the former fixed texts... ";
        $found = [];
        foreach (['An error occurred', 'Please try again', 'Profile saved', 'Schliessen', 'Close'] as $text) {
            if (str_contains($source, "'" . $text) || str_contains($source, '"' . $text)) {
                $found[] = $text;
            }
        }
        if ($source !== '' && $found === []) {
            echo "PASS\n";
        } else {
            echo "FAIL (" . implode(', ', $found) . ")\n";
            $passed = false;
        }

        echo "Test: aria-label and messages come from getFormsLang()... ";
        if ($source !== ''
            && preg_match("/setAttribute\(\s*'aria-label'\s*,\s*'/", $source) === 0
            && str_contains($source, "getFormsLang('CLOSE')")
        ) {
            echo "PASS\n";
        } else {
            echo "FAIL\n";
            $passed = false;
        }

        echo "Test: Missing texts are fetched from the endpoint (task=texts)... ";
        if (str_contains($source, "'&task=texts'") && str_contains($source, 'JoomlaAjaxForms.loadTexts()')) {
            echo "PASS\n";
        } else {
            echo "FAIL\n";
            $passed = false;
        }

        return $passed;
    }

    /** Body of `…name…{ … }` in JavaScript source, or null. */
    private function jsFunctionBody(string $source, string $name): ?string
    {
        $start = strpos($source, $name);

        if ($start === false) {
            return null;
        }

        $brace = strpos($source, '{', $start);

        if ($brace === false) {
            return null;
        }

        $depth = 0;

        for ($i = $brace; $i < \strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return null;
    }

    /**
     * Offsets of JSON.parse( calls outside any try block, found by scanning
     * braces, so a call that is moved out of its guard is caught as well.
     *
     * @return int[]
     */
    private function unguardedJsonParse(string $source): array
    {
        $tryRanges = [];
        $offset    = 0;

        while (($start = strpos($source, 'try', $offset)) !== false) {
            $offset = $start + 3;
            $brace  = strpos($source, '{', $start);

            if ($brace === false) {
                break;
            }

            $depth = 0;

            for ($i = $brace; $i < \strlen($source); $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $tryRanges[] = [$brace, $i];
                        break;
                    }
                }
            }
        }

        $unguarded = [];
        $offset    = 0;

        while (($pos = strpos($source, 'JSON.parse(', $offset)) !== false) {
            $offset   = $pos + 1;
            $isInside = false;

            foreach ($tryRanges as [$from, $to]) {
                if ($pos > $from && $pos < $to) {
                    $isInside = true;
                    break;
                }
            }

            if (!$isInside) {
                $unguarded[] = $pos;
            }
        }

        return $unguarded;
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
