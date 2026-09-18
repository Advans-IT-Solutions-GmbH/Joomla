<?php
/**
 * Media Files Tests for J2Commerce Product Compare Plugin
 */

class MediaFilesTest
{
    private $passed = 0;
    private $failed = 0;
    private $mediaPath = '/var/www/html/media/plg_j2commerce_productcompare';

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

        // --- Structural asset registration (not keyword matching) ---
        $this->test('joomla.asset.json registers the script asset "plg_j2commerce_productcompare"', function () {
            $asset = $this->findAsset('plg_j2commerce_productcompare', 'script');
            return $asset !== null && $this->assetFile($asset, 'js') === $this->mediaPath . '/js/productcompare.js';
        });

        $this->test('Script asset depends on "core" (Joomla.getOptions, Joomla.Text)', function () {
            $asset = $this->findAsset('plg_j2commerce_productcompare', 'script');
            return $asset !== null && in_array('core', (array) ($asset['dependencies'] ?? []), true);
        });

        $this->test('JS sends the form token from the script options', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'options.token') && str_contains($content, 'body.append(this.token');
        });

        $this->test('JS posts form-encoded product IDs (products[]), not JSON', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'new URLSearchParams()')
                && str_contains($content, "'products[]'")
                && !str_contains($content, "'Content-Type': 'application/json'")
                && !str_contains($content, 'JSON.stringify({');
        });

        $this->test('JS reads its texts through Joomla.Text', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'Joomla.Text._(');
        });

        $this->test('JS gives the compare-bar remove button an accessible name (aria-label)', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            // The remove button renders only the "×" glyph, so it needs an aria-label
            // built from the translated JS_REMOVE text for screen-reader users.
            return str_contains($content, "remove.setAttribute('aria-label', text('JS_REMOVE'");
        });

        $this->test('joomla.asset.json registers the style asset "plg_j2commerce_productcompare.css"', function () {
            $asset = $this->findAsset('plg_j2commerce_productcompare.css', 'style');
            return $asset !== null && $this->assetFile($asset, 'css') === $this->mediaPath . '/css/productcompare.css';
        });

        // --- JS reads exactly the script options the plugin injects ---
        $this->test('JS reads the plugin script options key', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, "Joomla.getOptions('plg_j2commerce_productcompare')")
                || str_contains($content, 'Joomla.getOptions("plg_j2commerce_productcompare")');
        });

        $this->test('JS consumes maxProducts script option', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'options.maxProducts');
        });

        $this->test('JS consumes ajaxUrl script option', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'options.ajaxUrl');
        });

        $this->test('JS binds the compare button selector rendered by the plugin', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            // tmpl/button.php renders class="j2store-compare-btn"
            return str_contains($content, 'j2store-compare-btn');
        });

        $this->test('JS targets the compare bar + modal containers rendered by onAfterRender', function () {
            $content = file_get_contents($this->mediaPath . '/js/productcompare.js');
            return str_contains($content, 'j2store-compare-bar')
                && str_contains($content, 'j2store-compare-modal');
        });

        $this->test('CSS styles the compare button + bar selectors', function () {
            $content = file_get_contents($this->mediaPath . '/css/productcompare.css');
            return str_contains($content, '.j2store-compare-btn')
                && str_contains($content, '.j2store-compare-bar');
        });

        echo "\n=== Media Files Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * File a relative asset URI resolves to, the way HTMLHelper does it:
     * "<extension>/<file>" → media/<extension>/<css|js>/<file>. A URI that
     * already contains the type folder would be looked up in css/css/… and
     * the asset would silently not be rendered.
     */
    private function assetFile(array $asset, string $typeFolder): ?string
    {
        $uri = (string) ($asset['uri'] ?? '');

        if ($uri === '' || !str_contains($uri, '/')) {
            return null;
        }

        [$extension, $file] = explode('/', $uri, 2);

        if (str_contains($file, '/')) {
            return null;
        }

        $path = '/var/www/html/media/' . $extension . '/' . $typeFolder . '/' . $file;

        return is_file($path) ? $path : null;
    }

    private function findAsset(string $name, string $type): ?array
    {
        $data = json_decode(file_get_contents($this->mediaPath . '/joomla.asset.json'), true);
        if (!is_array($data) || empty($data['assets']) || !is_array($data['assets'])) {
            return null;
        }
        foreach ($data['assets'] as $asset) {
            if (($asset['name'] ?? null) === $name && ($asset['type'] ?? null) === $type) {
                return $asset;
            }
        }
        return null;
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
