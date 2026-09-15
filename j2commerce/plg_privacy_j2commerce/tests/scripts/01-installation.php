<?php
/**
 * Installation Tests for the Privacy - J2Commerce plugin.
 *
 * The test environment installs the plugin through the Joomla web installer
 * (the Installer singleton used by the backend) and afterwards enables all
 * plugins by SQL. The plugin states before that SQL step are recorded in
 * /tmp/test-state/plugins-before-activation.tsv, so the checks below can tell
 * what the installer itself did.
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
    private const STATE_FILE  = '/tmp/test-state/plugins-before-activation.tsv';
    private const METHOD_FILE = '/tmp/test-state/privacy-install-method';
    private const UPDATE_URL  = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_privacy_j2commerce/updates/update.xml';

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

    private function extensionRow(string $folder, string $element): ?object
    {
        $query = $this->db->getQuery(true)
            ->select('extension_id, enabled')
            ->from('#__extensions')
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote($folder));

        return $this->db->setQuery($query)->loadObject() ?: null;
    }

    /**
     * @return string[] update site locations linked to the extension
     */
    private function linkedUpdateSites(int $extensionId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('s.location'))
            ->from($this->db->quoteName('#__update_sites', 's'))
            ->join(
                'INNER',
                $this->db->quoteName('#__update_sites_extensions', 'map'),
                $this->db->quoteName('map.update_site_id') . ' = ' . $this->db->quoteName('s.update_site_id')
            )
            ->where($this->db->quoteName('map.extension_id') . ' = ' . $extensionId);

        return $this->db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * @return array<string,int>|null
     */
    private function stateBeforeActivation(): ?array
    {
        if (!is_file(self::STATE_FILE)) {
            return null;
        }

        $state = [];

        foreach (file(self::STATE_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = explode("\t", $line);

            if (count($parts) === 3) {
                $state[$parts[0] . '/' . $parts[1]] = (int) $parts[2];
            }
        }

        return $state;
    }

    private function isJ6(): bool
    {
        return in_array($this->db->getPrefix() . 'j2commerce_products', $this->db->getTableList(), true);
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        // Test 1: Plugin is installed
        $plugin = $this->extensionRow('privacy', 'j2commerce');
        $this->test('Plugin is installed', $plugin !== null, 'Plugin not found in database');

        if ($plugin) {
            $this->test('Plugin is enabled (activated by the test setup)', $plugin->enabled == 1, 'Plugin is disabled');
        }

        $method = is_file(self::METHOD_FILE) ? trim((string) file_get_contents(self::METHOD_FILE)) : '';
        $this->test('Plugin was installed through the Joomla web installer', $method === 'web',
            'recorded install method: ' . ($method === '' ? 'none' : $method));

        // Test 2: Plugin files exist
        $this->test('Main plugin file exists',
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/services/provider.php'));

        $this->test('Extension class exists',
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php'));

        $taskPlugin = $this->extensionRow('task', 'j2commerceprivacy');
        $this->test('Bundled task plugin is installed', $taskPlugin !== null, 'Task plugin not found in database');

        $state = $this->stateBeforeActivation();

        if ($state === null) {
            $this->test('Plugin state before test setup was recorded', false, self::STATE_FILE . ' missing');
        } else {
            $this->test(
                'Installer enabled the bundled task plugin (state before test setup)',
                ($state['task/j2commerceprivacy'] ?? null) === 1,
                'recorded: ' . var_export($state['task/j2commerceprivacy'] ?? null, true)
            );
        }

        $this->test('Task plugin class exists',
            file_exists(JPATH_BASE . '/plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php'));

        $systemPlugin = $this->extensionRow('system', 'j2commerceprivacy');
        $this->test('Bundled consent system plugin is installed', $systemPlugin !== null, 'System plugin not found in database');

        if ($state !== null) {
            $this->test(
                'Installer enabled the bundled consent system plugin (state before test setup)',
                ($state['system/j2commerceprivacy'] ?? null) === 1,
                'recorded: ' . var_export($state['system/j2commerceprivacy'] ?? null, true)
            );
        }

        $this->test('Consent system plugin class exists',
            file_exists(JPATH_BASE . '/plugins/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php'));
        $this->test('Consent repository class exists',
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Consent/ConsentRepository.php'));
        $this->test('Privacy tab layout exists',
            file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/layouts/privacy_tab.php'));

        // Test 3: Update server registered for the privacy plugin itself
        if ($plugin) {
            $sites = $this->linkedUpdateSites((int) $plugin->extension_id);
            $this->test('Exactly one update site is linked to the privacy plugin', count($sites) === 1,
                count($sites) . ' linked: ' . implode(', ', $sites));
            $this->test('The linked update site is the current update server',
                count($sites) === 1 && $sites[0] === self::UPDATE_URL);
        }

        if ($taskPlugin) {
            $this->test('No update site of the privacy plugin is attached to the task plugin',
                !in_array(self::UPDATE_URL, $this->linkedUpdateSites((int) $taskPlugin->extension_id), true));
        }

        // Test 4: Language files exist
        foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
            $this->test("$tag language file exists",
                file_exists(JPATH_BASE . "/plugins/privacy/j2commerce/language/$tag/plg_privacy_j2commerce.ini"));
        }

        // Test 5: Bundled override source files exist for both J2Commerce generations
        $overrideFiles = [
            'checkout/default_shipping_payment.php',
            'myprofile/default.php',
            'myprofile/default_addresses.php',
        ];

        foreach (['com_j2store', 'com_j2commerce'] as $component) {
            $overrideSrc = JPATH_BASE . '/plugins/privacy/j2commerce/overrides/' . $component;
            $this->test("Override source directory exists ($component)", is_dir($overrideSrc));

            foreach ($overrideFiles as $file) {
                $this->test("Override source: $component/$file", file_exists($overrideSrc . '/' . $file));
            }
        }

        // Test 6: Overrides deployed for the component of this stack
        $component = $this->isJ6() ? 'com_j2commerce' : 'com_j2store';
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('template'))
            ->from($this->db->quoteName('#__template_styles'))
            ->where($this->db->quoteName('client_id') . ' = 0');
        $this->db->setQuery($query);
        $templates = $this->db->loadColumn() ?: [];

        $deployedCount = 0;
        foreach ($templates as $tpl) {
            $dest = JPATH_BASE . '/templates/' . $tpl . '/html/' . $component . '/myprofile/default.php';
            if (file_exists($dest)) {
                $deployedCount++;
            }
        }
        $this->test(
            "Overrides deployed to at least one template ($component)",
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
