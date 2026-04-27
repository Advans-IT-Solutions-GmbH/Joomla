<?php
/**
 * Media Files Tests for J2Commerce Product Compare Plugin
 */

class MediaFilesTest
{
    private $passed = 0;
    private $failed = 0;
    private $mediaPath = '/var/www/html/media/plg_j2store_productcompare';

    public function run(): bool
    {
        echo "=== Media Files Tests ===\n\n";

        $this->test('Media directory exists', function () {
            return is_dir($this->mediaPath);
        });

        $this->test('CSS file deployed', function () {
            return file_exists($this->mediaPath . '/css/productcompare.css');
        });

        $this->test('JS file deployed', function () {
            return file_exists($this->mediaPath . '/js/productcompare.js');
        });

        $this->test('CSS file is not empty', function () {
            $file = $this->mediaPath . '/css/productcompare.css';
            return file_exists($file) && filesize($file) > 50;
        });

        $this->test('JS file is not empty', function () {
            $file = $this->mediaPath . '/js/productcompare.js';
            return file_exists($file) && filesize($file) > 50;
        });

        $this->test('joomla.asset.json deployed', function () {
            return file_exists($this->mediaPath . '/joomla.asset.json');
        });

        $this->test('joomla.asset.json is valid JSON', function () {
            $content = file_get_contents($this->mediaPath . '/joomla.asset.json');
            return json_decode($content) !== null;
        });

        $this->test('JS contains compare functionality', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return strpos($content, 'compare') !== false || strpos($content, 'Compare') !== false;
        });

        $this->test('JS reads config via Joomla.getOptions', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return strpos($content, 'Joomla.getOptions') !== false;
        });

        $this->test('CSS contains compare styles', function () {
            $content = file_get_contents($this->mediaPath . '/css/productcompare.css');
            return strpos($content, 'compare') !== false || strpos($content, 'Compare') !== false;
        });

        echo "\n=== Media Files Test Summary ===\n";
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

$test = new MediaFilesTest();
exit($test->run() ? 0 : 1);
