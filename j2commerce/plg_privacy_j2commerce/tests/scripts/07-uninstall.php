<?php
/**
 * Uninstall Tests for the J2Commerce Privacy package
 *
 * Uses Joomla CLI (extension:remove) so the full Joomla installer pipeline runs, including the
 * uninstall() of the privacy plugin and all events. The plugins are removed with the package; the
 * privacy plugin alone cannot be removed (blockChildUninstall).
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class UninstallTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
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

    private function extensionId(string $type, string $element, string $folder = ''): int
    {
        $query = $this->db->getQuery(true)
            ->select('extension_id')
            ->from('#__extensions')
            ->where('type = '    . $this->db->quote($type))
            ->where('element = ' . $this->db->quote($element))
            ->where('folder = '  . $this->db->quote($folder));

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /** @return array{0: int, 1: string} */
    private function remove(int $extensionId): array
    {
        $output   = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:remove {$extensionId} --no-interaction 2>&1", $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    public function run(): bool
    {
        echo "=== Uninstall Tests ===\n\n";

        $pluginId  = $this->extensionId('plugin', 'j2commerce', 'privacy');
        $packageId = $this->extensionId('package', 'pkg_privacy_j2commerce');

        $this->test('Plugin found before uninstall', fn () => $pluginId > 0);
        $this->test('Package found before uninstall', fn () => $packageId > 0);

        if (!$pluginId || !$packageId) {
            echo "Cannot proceed — plugin or package not found in #__extensions\n";
            echo "\n=== Uninstall Test Summary ===\n";
            echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
            return false;
        }

        // The package blocks the uninstallation of a bundled plugin on its own (blockChildUninstall):
        // removing only the privacy plugin would leave the consent and task plugins behind.
        [, $outputStr] = $this->remove($pluginId);
        $this->test('Uninstalling the privacy plugin alone is refused', function () use ($pluginId, $outputStr) {
            echo "  Output: {$outputStr}\n";
            return $this->extensionId('plugin', 'j2commerce', 'privacy') === $pluginId
                && file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php');
        });

        [$exitCode, $outputStr] = $this->remove($packageId);

        $this->test('Package uninstall command executed', function () use ($exitCode, $outputStr) {
            echo "  Output: {$outputStr}\n";
            return $exitCode === 0;
        });

        $this->test('Package removed from #__extensions', fn () => $this->extensionId('package', 'pkg_privacy_j2commerce') === 0);

        $this->test('Package manifest folder removed', function () {
            return !is_file(JPATH_ADMINISTRATOR . '/manifests/packages/pkg_privacy_j2commerce.xml')
                && !is_dir(JPATH_ADMINISTRATOR . '/manifests/packages/privacy_j2commerce');
        });

        $this->test('Plugin removed from #__extensions', function () {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__extensions')
                ->where('element = ' . $this->db->quote('j2commerce'))
                ->where('folder = '  . $this->db->quote('privacy'));
            return (int) $this->db->setQuery($query)->loadResult() === 0;
        });

        $this->test('Plugin files removed', function () {
            return !file_exists(JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php');
        });

        $this->test('Plugin overrides directory removed', function () {
            return !is_dir(JPATH_BASE . '/plugins/privacy/j2commerce/overrides');
        });

        $this->test('Bundled task plugin removed from #__extensions', fn () => $this->extensionId('plugin', 'j2commerceprivacy', 'task') === 0);

        $this->test('Bundled task plugin files removed', function () {
            return !is_dir(JPATH_BASE . '/plugins/task/j2commerceprivacy');
        });

        $this->test('Bundled consent system plugin removed from #__extensions', fn () => $this->extensionId('plugin', 'j2commerceprivacy', 'system') === 0);

        $this->test('Bundled consent system plugin files removed', function () {
            return !is_dir(JPATH_BASE . '/plugins/system/j2commerceprivacy');
        });

        $this->test('Bundled scheduler tasks removed', function () {
            $tables = $this->db->getTableList();

            if (!in_array($this->db->getPrefix() . 'scheduler_tasks', $tables, true)) {
                return true;
            }

            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__scheduler_tasks')
                ->where('type = ' . $this->db->quote('plg_task_j2commerceprivacy.autocleanup'));
            return (int) $this->db->setQuery($query)->loadResult() === 0;
        });

        echo "\n=== Uninstall Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }
}

$test = new UninstallTest();
exit($test->run() ? 0 : 1);
