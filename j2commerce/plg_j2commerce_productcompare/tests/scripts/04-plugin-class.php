<?php
/**
 * Plugin Class Tests for J2Commerce Product Compare Plugin
 * Validates the plugin class structure and methods.
 */

class PluginClassTest
{
    private $passed = 0;
    private $failed = 0;
    private $classFile;

    public function __construct()
    {
        $this->classFile = '/var/www/html/plugins/j2store/productcompare/src/Extension/ProductCompare.php';
    }

    public function run(): bool
    {
        echo "=== Plugin Class Tests ===\n\n";

        $content = file_get_contents($this->classFile);

        $this->test('Class file is readable', function () use ($content) {
            return !empty($content);
        });

        $this->test('Has onAfterDispatch method', function () use ($content) {
            return strpos($content, 'function onAfterDispatch') !== false;
        });

        $this->test('Has onJ2StoreAfterDisplayProduct method', function () use ($content) {
            return strpos($content, 'function onJ2StoreAfterDisplayProduct') !== false;
        });

        $this->test('Has onJ2StoreAfterDisplayProductList method', function () use ($content) {
            return strpos($content, 'function onJ2StoreAfterDisplayProductList') !== false;
        });

        $this->test('Has onAjaxProductcompare method', function () use ($content) {
            return strpos($content, 'function onAjaxProductcompare') !== false;
        });

        $this->test('Has onAfterRender method', function () use ($content) {
            return strpos($content, 'function onAfterRender') !== false;
        });

        $this->test('Has renderCompareButton method', function () use ($content) {
            return strpos($content, 'function renderCompareButton') !== false;
        });

        $this->test('Has getProductsData method', function () use ($content) {
            return strpos($content, 'function getProductsData') !== false;
        });

        $this->test('Has renderLayout method', function () use ($content) {
            return strpos($content, 'function renderLayout') !== false;
        });

        $this->test('Uses FileLayout for template overrides', function () use ($content) {
            return strpos($content, 'FileLayout') !== false;
        });

        $this->test('Uses Joomla CMSPlugin or extends correct base', function () use ($content) {
            return strpos($content, 'CMSPlugin') !== false || strpos($content, 'extends Plugin') !== false;
        });

        $this->test('Uses DatabaseAwareTrait or gets DB', function () use ($content) {
            return strpos($content, 'DatabaseAwareTrait') !== false
                || strpos($content, 'getDbo') !== false
                || strpos($content, 'getDatabase') !== false
                || strpos($content, 'Factory::getContainer') !== false;
        });

        $this->test('Uses WebAssetManager for assets', function () use ($content) {
            return strpos($content, 'getWebAssetManager') !== false;
        });

        $this->test('Uses addScriptOptions for JS config', function () use ($content) {
            return strpos($content, 'addScriptOptions') !== false;
        });

        echo "\n=== Plugin Class Test Summary ===\n";
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

$test = new PluginClassTest();
exit($test->run() ? 0 : 1);
