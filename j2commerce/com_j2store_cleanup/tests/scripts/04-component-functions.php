<?php
/**
 * Component Functions Tests for J2Store Cleanup
 *
 * Calls the actual functions from j2store_cleanup.php rather than
 * checking for their names with strpos().
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

// Bootstrap the component functions without executing the request handler.
// We define _JEXEC and stub $app so the file can be included safely.
$app = Factory::getApplication();
$db  = Factory::getContainer()->get('DatabaseDriver');

// Prevent the component from running its task dispatcher on include
$_GET['task'] = 'display';

$mainFile = JPATH_BASE . '/administrator/components/com_j2store_cleanup/j2store_cleanup.php';

class ComponentFunctionsTest
{
    private $passed = 0;
    private $failed = 0;
    private $mainFile;
    private $tmpDir;

    public function __construct(string $mainFile)
    {
        $this->mainFile = $mainFile;
        $this->tmpDir   = sys_get_temp_dir() . '/j2cleanup_fn_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function __destruct()
    {
        $this->removeDir($this->tmpDir);
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ $name\n";
            $this->passed++;
        } else {
            echo "✗ $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Component Functions Tests ===\n\n";

        // --- File existence ---
        echo "--- File ---\n";
        $this->test('Main component file exists', file_exists($this->mainFile));

        if (!file_exists($this->mainFile)) {
            echo "Cannot continue — main file missing\n";
            return false;
        }

        // Include the file to load the functions (task=display is a no-op)
        // Suppress output from the display task
        ob_start();
        try {
            include_once $this->mainFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->test('Main file includable without fatal error', false, $e->getMessage());
            return false;
        }
        ob_end_clean();

        $this->test('getExtensionPath() defined',   function_exists('getExtensionPath'));
        $this->test('getIssuePatterns() defined',    function_exists('getIssuePatterns'));
        $this->test('scanForIssues() defined',       function_exists('scanForIssues'));
        $this->test('classifyExtension() defined',   function_exists('classifyExtension'));

        if (!function_exists('getExtensionPath') || !function_exists('getIssuePatterns')
            || !function_exists('scanForIssues') || !function_exists('classifyExtension')) {
            echo "Cannot continue — required functions missing\n";
            return false;
        }

        $this->testGetExtensionPath();
        $this->testGetIssuePatterns();
        $this->testScanForIssues();
        $this->testClassifyExtension();

        echo "\n=== Component Functions Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function testGetExtensionPath(): void
    {
        echo "\n--- getExtensionPath() ---\n";

        $component = (object)['type' => 'component', 'element' => 'com_content', 'folder' => '', 'client_id' => 1];
        $path = getExtensionPath($component);
        $this->test('Component (admin) path contains /administrator/components',
            $path !== null && strpos($path, 'administrator/components/com_content') !== false);

        $plugin = (object)['type' => 'plugin', 'element' => 'j2store', 'folder' => 'system', 'client_id' => 0];
        $path = getExtensionPath($plugin);
        $this->test('Plugin path contains /plugins/system/j2store',
            $path !== null && strpos($path, 'plugins/system/j2store') !== false);

        $module = (object)['type' => 'module', 'element' => 'mod_menu', 'folder' => '', 'client_id' => 1];
        $path = getExtensionPath($module);
        $this->test('Module (admin) path contains /administrator/modules',
            $path !== null && strpos($path, 'administrator/modules/mod_menu') !== false);

        $file = (object)['type' => 'file', 'element' => 'joomla', 'folder' => '', 'client_id' => 0];
        $path = getExtensionPath($file);
        $this->test('File type returns null (no single path)', $path === null);
    }

    private function testGetIssuePatterns(): void
    {
        echo "\n--- getIssuePatterns() ---\n";

        $patterns = getIssuePatterns();
        $this->test('Returns array',                  is_array($patterns));
        $this->test('Has joomla key',                 isset($patterns['joomla']));
        $this->test('Has j2store key',                isset($patterns['j2store']));
        $this->test('joomla patterns is array',       is_array($patterns['joomla']));
        $this->test('joomla patterns not empty',      !empty($patterns['joomla']));

        // On any Joomla version, J3 legacy classes must be in the patterns
        $allLabels = implode(' ', array_values($patterns['joomla']));
        $this->test('JPlugin pattern present',        strpos($allLabels, 'JPlugin') !== false);
        $this->test('JModel pattern present',         strpos($allLabels, 'JModel') !== false);
    }

    private function testScanForIssues(): void
    {
        echo "\n--- scanForIssues() ---\n";

        $patterns = getIssuePatterns();

        // Clean file — no issues
        $dir = $this->tmpDir . '/clean';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/a.php', "<?php\nuse Joomla\\CMS\\Factory;\n\$app = Factory::getApplication();\n");
        $issues = scanForIssues($dir, $patterns);
        $this->test('Clean file returns empty issues', empty($issues));

        // Legacy file — issues found
        $dir2 = $this->tmpDir . '/legacy';
        mkdir($dir2, 0755, true);
        file_put_contents($dir2 . '/b.php', "<?php\n\$m = new JModelLegacy();\n");
        $issues2 = scanForIssues($dir2, $patterns);
        $this->test('Legacy file returns issues', !empty($issues2));

        // Non-existent dir — no crash
        $issues3 = scanForIssues('/nonexistent_path_xyz', $patterns);
        $this->test('Non-existent dir returns empty', empty($issues3));

        // Commented code not flagged
        $dir4 = $this->tmpDir . '/commented';
        mkdir($dir4, 0755, true);
        file_put_contents($dir4 . '/c.php', "<?php\n// JPlugin::registerEvent();\n/* JModel::getInstance(); */\n\$x = 1;\n");
        $issues4 = scanForIssues($dir4, $patterns);
        $this->test('Commented legacy code not flagged', empty($issues4));
    }

    private function testClassifyExtension(): void
    {
        echo "\n--- classifyExtension() ---\n";

        $patterns = getIssuePatterns();

        // com_j2store is always core
        $ext = (object)['element' => 'com_j2store', 'type' => 'component', 'folder' => '', 'client_id' => 1];
        $result = classifyExtension((object)['version' => '4.0.20', 'author' => 'J2Commerce'], $ext, $patterns);
        $this->test('com_j2store classified as core', $result['status'] === 'core');

        // Extension with no files
        $ext2 = (object)['element' => 'plg_nonexistent_xyz', 'type' => 'plugin', 'folder' => 'j2store', 'client_id' => 0];
        $result2 = classifyExtension((object)['version' => '1.0', 'author' => 'Test'], $ext2, $patterns);
        $this->test('Missing files → no-files status', $result2['status'] === 'no-files');

        // Compatible extension
        $cleanDir = JPATH_PLUGINS . '/j2store/plg_test_clean_fn_' . time();
        @mkdir($cleanDir, 0755, true);
        file_put_contents($cleanDir . '/plugin.php', "<?php\nuse Joomla\\CMS\\Factory;\n\$app = Factory::getApplication();\n");
        $ext3 = (object)['element' => basename($cleanDir), 'type' => 'plugin', 'folder' => 'j2store', 'client_id' => 0];
        $result3 = classifyExtension((object)['version' => '2.0', 'author' => 'Test'], $ext3, $patterns);
        $this->test('Clean extension → compatible', $result3['status'] === 'compatible');
        $this->removeDir($cleanDir);

        // Incompatible extension
        $legacyDir = JPATH_PLUGINS . '/j2store/plg_test_legacy_fn_' . time();
        @mkdir($legacyDir, 0755, true);
        file_put_contents($legacyDir . '/plugin.php', "<?php\nnew JModelLegacy();\n");
        $ext4 = (object)['element' => basename($legacyDir), 'type' => 'plugin', 'folder' => 'j2store', 'client_id' => 0];
        $result4 = classifyExtension((object)['version' => '1.0', 'author' => 'Old Vendor'], $ext4, $patterns);
        $this->test('Legacy extension → incompatible', $result4['status'] === 'incompatible');
        $this->test('Incompatible has issues list', !empty($result4['issues']));
        $this->removeDir($legacyDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

$test = new ComponentFunctionsTest($mainFile);
exit($test->run() ? 0 : 1);
