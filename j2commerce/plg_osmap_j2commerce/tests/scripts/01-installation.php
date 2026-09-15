<?php
/**
 * Installation Tests for OSMap J2Commerce Plugin
 *
 * The test environment enables all plugins by SQL after the installation. The
 * entrypoint records the plugin states before that step in
 * /tmp/test-state/plugins-before-activation.tsv, so this test can check what
 * the installer itself did (legacy plugin disabled, update site registered)
 * independently of the test setup.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class InstallationTest
{
    private const STATE_FILE = '/tmp/test-state/plugins-before-activation.tsv';
    private const UPDATE_URL = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_osmap_j2commerce/updates/update.xml';

    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }

    public function run(): bool
    {
        echo "=== Installation Tests ===\n\n";

        $this->test('Plugin registered in #__extensions', function () {
            return $this->extensionId() > 0;
        });

        $this->test('Plugin is enabled (activated by the test setup)', function () {
            $query = $this->db->getQuery(true)
                ->select('enabled')
                ->from('#__extensions')
                ->where('element = ' . $this->db->quote('j2commerce'))
                ->where('folder = ' . $this->db->quote('osmap'));
            return (int) $this->db->setQuery($query)->loadResult() === 1;
        });

        $this->test('Plugin file exists', function () {
            return file_exists(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php');
        });

        $this->test('XML manifest exists', function () {
            return file_exists(JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.xml');
        });

        echo "\n--- Installer effects (state before test setup) ---\n";

        $state = $this->readStateFile();

        if ($state === null) {
            $this->test('Plugin state before activation was recorded by the test environment', fn () => false);
        } else {
            $this->test('Plugin row existed before activation', fn () => isset($state['osmap/j2commerce']));

            if (isset($state['osmap/j2store'])) {
                $this->test(
                    'Legacy plugin osmap/j2store was disabled by the installer',
                    fn () => $state['osmap/j2store'] === 0
                );
            } else {
                echo "INFO legacy plugin osmap/j2store is not installed in this environment\n";
            }
        }

        $this->test('Exactly one update site is linked to the plugin', function () {
            return count($this->linkedUpdateSites()) === 1;
        });

        $this->test('The linked update site is the current update server', function () {
            $sites = $this->linkedUpdateSites();
            return count($sites) === 1 && $sites[0] === self::UPDATE_URL;
        });

        echo "\n=== Installation Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function extensionId(): int
    {
        $query = $this->db->getQuery(true)
            ->select('extension_id')
            ->from('#__extensions')
            ->where('element = ' . $this->db->quote('j2commerce'))
            ->where('folder = ' . $this->db->quote('osmap'))
            ->where('type = ' . $this->db->quote('plugin'));

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * @return string[] locations of update sites linked to the plugin
     */
    private function linkedUpdateSites(): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('s.location'))
            ->from($this->db->quoteName('#__update_sites', 's'))
            ->join(
                'INNER',
                $this->db->quoteName('#__update_sites_extensions', 'map'),
                $this->db->quoteName('map.update_site_id') . ' = ' . $this->db->quoteName('s.update_site_id')
            )
            ->where($this->db->quoteName('map.extension_id') . ' = ' . $this->extensionId());

        return $this->db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * @return array<string, int>|null "folder/element" => enabled
     */
    private function readStateFile(): ?array
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

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else       { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new InstallationTest();
exit($test->run() ? 0 : 1);
