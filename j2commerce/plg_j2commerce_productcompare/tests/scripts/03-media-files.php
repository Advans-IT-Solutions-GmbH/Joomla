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

        // --- No user-visible text reaches the visitor without a language key ---
        // An English literal may only appear as the fallback argument of
        // text('KEY', 'fallback'), never as the value that is printed. Otherwise a
        // German or French site shows English.
        foreach ([
            'Remove from Compare',
            'Remove from comparison',
            'You can only compare up to',
            'You can compare up to',
            'Product #',
            'Clear all products from comparison?',
            'Remove all products from the comparison?',
            'Please select at least 2 products to compare',
            'Loading...',
            'Loading comparison...',
            'The comparison could not be loaded.',
            'Compare',
        ] as $literal) {
            $this->test("JS uses \"{$literal}\" only as a language fallback", function () use ($literal) {
                return $this->literalIsAlwaysFallback($literal);
            });
        }

        $this->test('Every alert() and confirm() shows a translated text', function () {
            preg_match_all('/\b(?:alert|confirm)\(\s*([^\n]{0,20})/', $this->scriptSource(), $matches);

            foreach ($matches[1] ?? [] as $argument) {
                if (!str_starts_with(ltrim($argument), 'text(') && !str_starts_with(ltrim($argument), 'format(text(')) {
                    return false;
                }
            }

            return ($matches[1] ?? []) !== [];
        });

        // --- Every text key the script asks for is translated in all languages ---
        foreach ($this->scriptTextKeys() as $key) {
            foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
                $this->test("Language {$tag} translates {$key}", function () use ($key, $tag) {
                    $value = $this->languageValue($tag, $key);

                    return $value !== null && $value !== '';
                });
            }

            $this->test("de-DE translation of {$key} uses Swiss spelling (no eszett)", function () use ($key) {
                $value = (string) $this->languageValue('de-DE', $key);

                return !str_contains($value, 'ß');
            });
        }

        $this->test('Every text key the script uses is registered with Text::script()', function () {
            $registered = $this->registeredScriptTexts();

            foreach ($this->scriptTextKeys() as $key) {
                if (!in_array($key, $registered, true)) {
                    return false;
                }
            }

            return $registered !== [];
        });

        // --- A damaged localStorage entry must not break the comparison ---
        $this->test('Every JSON.parse() in the script sits inside a try block', function () {
            return $this->unguardedJsonParse($this->scriptSource()) === [];
        });

        $this->test('The stored selection is validated before it is used', function () {
            // loadFromStorage() has to survive a damaged entry: catch the parse
            // error and accept only an array of positive numeric ids.
            $body = $this->functionBody($this->scriptSource(), 'loadFromStorage');

            return $body !== null
                && str_contains($body, 'try')
                && str_contains($body, 'catch')
                && str_contains($body, 'Array.isArray')
                && preg_match('/parseInt\s*\(/', $body) === 1;
        });

        echo "\n=== Media Files Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /** Installed script source. */
    private function scriptSource(): string
    {
        static $source;

        if ($source === null) {
            $source = (string) file_get_contents($this->mediaPath . '/js/productcompare.js');
        }

        return $source;
    }

    /**
     * True when every occurrence of $literal in the script is the fallback
     * argument of a text('KEY', '<literal>') call, and not a printed value.
     */
    private function literalIsAlwaysFallback(string $literal): bool
    {
        $source = $this->scriptSource();
        $offset = 0;

        while (($pos = strpos($source, "'" . $literal, $offset)) !== false) {
            $offset = $pos + 1;
            $before = substr($source, 0, $pos);

            if (preg_match("/text\(\s*'[A-Z0-9_]+'\s*,\s*$/", $before) !== 1) {
                return false;
            }
        }

        // A double-quoted occurrence is never a fallback in this file.
        return !str_contains($source, '"' . $literal);
    }

    /**
     * Text keys the script asks for, read from its own text('KEY', …) calls, so
     * a new key has to be translated and registered without touching this test.
     */
    private function scriptTextKeys(): array
    {
        preg_match_all("/text\(\s*'([A-Z0-9_]+)'/", $this->scriptSource(), $matches);

        $keys = array_values(array_unique($matches[1] ?? []));
        sort($keys);

        return array_map(static fn (string $key): string => 'PLG_J2COMMERCE_PRODUCTCOMPARE_' . $key, $keys);
    }

    /** Keys the plugin passes to Text::script(), read from its SCRIPT_TEXTS list. */
    private function registeredScriptTexts(): array
    {
        $file = '/var/www/html/plugins/j2commerce/productcompare/src/Extension/ProductCompare.php';

        if (!is_file($file)) {
            // Joomla 5 installs the plugin in the j2store group.
            $file = '/var/www/html/plugins/j2store/productcompare/src/Extension/ProductCompare.php';
        }

        if (!is_file($file)) {
            return [];
        }

        if (preg_match('/SCRIPT_TEXTS\s*=\s*\[(.*?)\];/s', (string) file_get_contents($file), $block) !== 1) {
            return [];
        }

        preg_match_all("/'([A-Z0-9_]+)'/", $block[1], $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /** Value of $key in the installed language file of $tag, or null. */
    private function languageValue(string $tag, string $key): ?string
    {
        static $files = [];

        if (!isset($files[$tag])) {
            $files[$tag] = [];

            foreach ([
                '/var/www/html/plugins/j2commerce/productcompare/language/' . $tag . '/plg_j2commerce_productcompare.ini',
                '/var/www/html/plugins/j2store/productcompare/language/' . $tag . '/plg_j2commerce_productcompare.ini',
                '/var/www/html/administrator/language/' . $tag . '/plg_j2commerce_productcompare.ini',
                '/var/www/html/language/' . $tag . '/plg_j2commerce_productcompare.ini',
            ] as $path) {
                if (is_file($path)) {
                    $files[$tag] = array_merge($files[$tag], parse_ini_file($path, false, INI_SCANNER_RAW) ?: []);
                }
            }
        }

        $value = $files[$tag][$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Offsets of JSON.parse( calls that are not inside a try block, found by
     * scanning braces instead of matching text, so a moved call is caught too.
     *
     * @return int[]
     */
    private function unguardedJsonParse(string $source): array
    {
        $tryRanges = [];
        $offset    = 0;

        while (($start = strpos($source, 'try', $offset)) !== false) {
            $offset = $start + 3;
            $brace  = strpos($source, '{', $start);

            if ($brace === false) {
                break;
            }

            $depth = 0;

            for ($i = $brace; $i < \strlen($source); $i++) {
                if ($source[$i] === '{') {
                    $depth++;
                } elseif ($source[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $tryRanges[] = [$brace, $i];
                        break;
                    }
                }
            }
        }

        $unguarded = [];
        $offset    = 0;

        while (($pos = strpos($source, 'JSON.parse(', $offset)) !== false) {
            $offset   = $pos + 1;
            $isInside = false;

            foreach ($tryRanges as [$from, $to]) {
                if ($pos > $from && $pos < $to) {
                    $isInside = true;
                    break;
                }
            }

            if (!$isInside) {
                $unguarded[] = $pos;
            }
        }

        return $unguarded;
    }

    /** Body of a JavaScript method `name(` … `}`, or null. */
    private function functionBody(string $source, string $name): ?string
    {
        $start = strpos($source, $name . '(');

        if ($start === false) {
            return null;
        }

        $brace = strpos($source, '{', $start);

        if ($brace === false) {
            return null;
        }

        $depth = 0;

        for ($i = $brace; $i < \strlen($source); $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return null;
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
