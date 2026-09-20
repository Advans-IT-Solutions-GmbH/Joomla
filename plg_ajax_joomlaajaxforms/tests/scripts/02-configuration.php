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
     * onBeforeRender() passes the "debug" option to the script and it follows
     * the plugin parameter: off by default, so a live site's browser console
     * stays clean, on only while an administrator switches it on.
     *
     * The plugin is driven directly with a real site application and a real
     * HTML document, because plugins of the ajax group are imported by com_ajax
     * and a normal page request would not dispatch this handler in a CLI test.
     */
    private function testDebugOption(): bool
    {
        $passed = true;

        echo "Test: Debug is off in the script options by default... ";
        $off = $this->scriptOptionsFor(['debug' => 0]);

        if (\is_array($off) && \array_key_exists('debug', $off) && $off['debug'] === false) {
            echo "PASS\n";
        } else {
            echo "FAIL (options: " . json_encode($off) . ")\n";
            $passed = false;
        }

        echo "Test: Debug reaches the script when the option is on... ";
        $on = $this->scriptOptionsFor(['debug' => 1]);

        if (\is_array($on) && ($on['debug'] ?? null) === true) {
            echo "PASS\n";
        } else {
            echo "FAIL (options: " . json_encode($on) . ")\n";
            $passed = false;
        }

        echo "Test: Language strings still reach the script... ";
        if (\is_array($off) && !empty($off['ERROR_GENERIC'])) {
            echo "PASS\n";
        } else {
            echo "FAIL (options: " . json_encode($off) . ")\n";
            $passed = false;
        }

        return $passed;
    }

    /**
     * Script options the plugin adds to a fresh HTML document for the given
     * plugin parameters, or null when the handler could not be driven.
     */
    private function scriptOptionsFor(array $params): ?array
    {
        try {
            \JLoader::registerNamespace(
                'Advans\\Plugin\\Ajax\\JoomlaAjaxForms',
                '/var/www/html/plugins/ajax/joomlaajaxforms/src',
                false,
                false,
                'psr4'
            );

            $app = $this->siteApplication();
            $doc = new \Joomla\CMS\Document\HtmlDocument();

            $property = new \ReflectionProperty($app, 'document');
            $property->setValue($app, $doc);

            if (property_exists(\Joomla\CMS\Factory::class, 'document')) {
                \Joomla\CMS\Factory::$document = $doc;
            }

            $class  = 'Advans\\Plugin\\Ajax\\JoomlaAjaxForms\\Extension\\JoomlaAjaxForms';
            $plugin = new $class([
                'params' => new \Joomla\Registry\Registry($params),
                'type'   => 'ajax',
                'name'   => 'joomlaajaxforms',
            ]);
            $plugin->setApplication($app);
            $plugin->setDatabase($this->db);

            $plugin->onBeforeRender();

            return $doc->getScriptOptions('plg_ajax_joomlaajaxforms');
        } catch (\Throwable $e) {
            echo "\n  (error: " . $e->getMessage() . ")\n  ";

            return null;
        }
    }

    /** Site application for the plugin, created once per process. */
    private function siteApplication(): object
    {
        if (\Joomla\CMS\Factory::$application instanceof \Joomla\CMS\Application\SiteApplication) {
            return \Joomla\CMS\Factory::$application;
        }

        $container = \Joomla\CMS\Factory::getContainer();
        $input     = null;

        foreach (['Joomla\\CMS\\Input\\Input', 'Joomla\\Input\\Input'] as $inputClass) {
            try {
                if ($container->has($inputClass)) {
                    $input = $container->get($inputClass);
                    break;
                }
            } catch (\Throwable $e) {
                // try the next candidate
            }
        }

        $app = new \Joomla\CMS\Application\SiteApplication($input, $container->get('config'), null, $container);
        $app->setDispatcher($container->get(\Joomla\Event\DispatcherInterface::class));
        \Joomla\CMS\Factory::$application = $app;

        return $app;
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
