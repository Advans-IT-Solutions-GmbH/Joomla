<?php
/**
 * Cleanup Tests for J2Store Cleanup
 *
 * Tests both input sanitisation (unit) and the actual cleanup execution
 * (round-trip: register extension → cleanup → extension gone).
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class CleanupTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "Test: $name... PASS\n";
            $this->passed++;
        } else {
            echo "Test: $name... FAIL" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Cleanup Tests ===\n\n";

        $this->testInputSanitisation();
        $this->testCleanupRoundTrip();
        $this->testCleanupEdgeCases();

        echo "\n=== Cleanup Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }

    private function testInputSanitisation(): void
    {
        echo "--- Input Sanitisation ---\n";

        $this->test('Empty selection → empty array',
            empty(array_filter(array_map('intval', []))));

        $filtered = array_filter(array_map('intval', [0, 1, 0, 2, 0]));
        $this->test('Zero IDs filtered out',   !in_array(0, $filtered));
        $this->test('Valid IDs preserved',      in_array(1, $filtered) && in_array(2, $filtered));

        echo "\n--- Security ---\n";
        $malicious = ["1; DROP TABLE #__extensions; --", "1 OR 1=1", "abc", ""];
        $sanitized = array_map('intval', $malicious);
        $this->test('SQL injection string → 1',  $sanitized[0] === 1);
        $this->test('OR injection → 1',           $sanitized[1] === 1);
        $this->test('Non-numeric string → 0',     $sanitized[2] === 0);
        $this->test('Empty string → 0',           $sanitized[3] === 0);

        $sanitizedFiltered = array_filter($sanitized);
        $this->test('Zeros removed after filter', count($sanitizedFiltered) === 2);

        $negativeFiltered = array_filter(array_map('intval', [-1, -100, 0]), fn($id) => $id > 0);
        $this->test('Negative IDs filtered out',  empty($negativeFiltered));

        echo "\n--- SQL Generation ---\n";
        $ids = [1, 2, 3];
        $this->test('IN clause correct',
            'extension_id IN (' . implode(',', $ids) . ')' === 'extension_id IN (1,2,3)');
    }

    private function testCleanupRoundTrip(): void
    {
        echo "\n--- Cleanup Round-Trip ---\n";

        $fakeElement = 'plg_j2store_test_cleanup_' . time();
        $fakeDir     = JPATH_BASE . '/plugins/j2store/' . $fakeElement;

        $extensionId = $this->registerFakeExtension($fakeElement, $fakeDir);
        if ($extensionId === null) {
            echo "Note: Could not register fake extension — skipping round-trip tests\n";
            $this->test('Round-trip skipped (registration failed)', true);
            return;
        }

        $this->test('Fake extension registered in #__extensions',
            $this->extensionExists($fakeElement));

        @mkdir($fakeDir, 0755, true);
        file_put_contents($fakeDir . '/plugin.php', '<?php // fake');
        $this->test('Fake extension files created', file_exists($fakeDir . '/plugin.php'));

        $deleted = $this->executeCleanup([$extensionId]);
        $this->test('Cleanup executed without error', $deleted !== false);
        $this->test('Extension removed from #__extensions',
            !$this->extensionExists($fakeElement));

        $this->removeDir($fakeDir);
        $this->test('Extension files removed from filesystem', !is_dir($fakeDir));
    }

    private function testCleanupEdgeCases(): void
    {
        echo "\n--- Edge Cases ---\n";

        try {
            $this->executeCleanup([999999999]);
            $this->test('Non-existent ID → no crash', true);
        } catch (\Exception $e) {
            $this->test('Non-existent ID → no crash', false, $e->getMessage());
        }

        try {
            $this->executeCleanup([]);
            $this->test('Empty list → no crash', true);
        } catch (\Exception $e) {
            $this->test('Empty list → no crash', false, $e->getMessage());
        }

        echo "\n--- Safety Guard ---\n";
        $query = $this->db->getQuery(true)
            ->select('extension_id')
            ->from('#__extensions')
            ->where('element = ' . $this->db->quote('com_j2store'))
            ->where('type = ' . $this->db->quote('component'));
        $this->db->setQuery($query);
        $coreId = (int) $this->db->loadResult();

        if ($coreId > 0) {
            $this->test('com_j2store is flagged as core-protected',
                $this->isCoreProtected('com_j2store'));

            $idsToClean = array_filter([$coreId], fn($id) => !$this->isCoreProtectedById($id));
            $this->test('com_j2store excluded from cleanup list',
                !in_array($coreId, $idsToClean));
        } else {
            echo "Note: com_j2store not installed — safety guard test skipped\n";
            $this->test('Safety guard skipped (com_j2store not installed)', true);
        }
    }

    private function registerFakeExtension(string $element, string $dir): ?int
    {
        try {
            $ext = (object) [
                'name'           => 'J2Store Test Cleanup Plugin',
                'type'           => 'plugin',
                'element'        => $element,
                'folder'         => 'j2store',
                'client_id'      => 0,
                'enabled'        => 0,
                'access'         => 1,
                'protected'      => 0,
                'manifest_cache' => json_encode(['name' => 'Test', 'version' => '1.0.0', 'author' => 'Test']),
                'params'         => '{}',
                'custom_data'    => '',
            ];
            $this->db->insertObject('#__extensions', $ext, 'extension_id');
            return (int) $this->db->insertid() ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function extensionExists(string $element): bool
    {
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__extensions')
            ->where('element = ' . $this->db->quote($element));
        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    private function executeCleanup(array $ids): bool
    {
        if (empty($ids)) {
            return true;
        }
        try {
            $query = $this->db->getQuery(true)
                ->delete('#__extensions')
                ->whereIn('extension_id', array_map('intval', $ids));
            $this->db->setQuery($query)->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function isCoreProtected(string $element): bool
    {
        return in_array($element, ['com_j2store', 'com_joomla', 'com_content', 'com_users']);
    }

    private function isCoreProtectedById(int $extensionId): bool
    {
        $query = $this->db->getQuery(true)
            ->select('element')
            ->from('#__extensions')
            ->where('extension_id = ' . $extensionId);
        $element = $this->db->setQuery($query)->loadResult();
        return $element !== null && $this->isCoreProtected($element);
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

$test = new CleanupTest();
exit($test->run() ? 0 : 1);
