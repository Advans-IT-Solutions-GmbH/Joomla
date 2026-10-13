<?php
/**
 * Structure tests for the DMARC Reports package.
 *
 * Verifies that the package manifest and the key files of every bundled
 * extension were installed on disk.
 */

class StructureTest
{
    private $passed = 0;
    private $failed = 0;

    public function run(): bool
    {
        echo "=== Package Structure Tests ===\n\n";

        $this->test('Package manifest installed', function () {
            return file_exists('/var/www/html/administrator/manifests/packages/pkg_dmarcreports.xml');
        });

        $this->test('Component entry point installed', function () {
            return file_exists('/var/www/html/administrator/components/com_dmarcreports/services/provider.php');
        });

        $this->test('Component dispatcher installed', function () {
            return is_dir('/var/www/html/administrator/components/com_dmarcreports/src');
        });

        $this->test('Module entry point installed', function () {
            return file_exists('/var/www/html/administrator/modules/mod_dmarcreports/mod_dmarcreports.php')
                || file_exists('/var/www/html/administrator/modules/mod_dmarcreports/services/provider.php');
        });

        $this->test('Task plugin service provider installed', function () {
            return file_exists('/var/www/html/plugins/task/dmarcreports/services/provider.php');
        });

        $this->test('Task plugin form manifest installed', function () {
            return file_exists('/var/www/html/plugins/task/dmarcreports/forms/ingest.xml');
        });

        $this->test('System plugin service provider installed', function () {
            return file_exists('/var/www/html/plugins/system/dmarcmtasts/services/provider.php');
        });

        $this->test('Component language file installed', function () {
            return file_exists('/var/www/html/administrator/components/com_dmarcreports/language/en-GB/com_dmarcreports.ini')
                || file_exists('/var/www/html/administrator/language/en-GB/com_dmarcreports.ini');
        });

        echo "\n=== Package Structure Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new StructureTest();
exit($test->run() ? 0 : 1);
