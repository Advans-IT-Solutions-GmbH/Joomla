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
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

$db = Factory::getContainer()->get(DatabaseInterface::class);

// Prevent the component from bootstrapping Factory::getApplication() on include.
// The component checks this constant before initialising $app/$task.
define('J2STORE_CLEANUP_FUNCTIONS_ONLY', true);

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
        // Extension names are language keys in #__extensions; the page must not
        // print the column unchanged (see getExtensionName()). Behaviour is
        // covered over HTTP by the shared backend-views suite, which needs a
        // real application; here only the inventory is asserted.
        $this->test('getExtensionName() defined',    function_exists('getExtensionName'));
        $this->test('The page never prints the raw name column',
            !str_contains((string) @file_get_contents($this->mainFile), 'htmlspecialchars($ext->name)'),
            'use getExtensionName($ext) so the language key is translated');

        if (!function_exists('getExtensionPath') || !function_exists('getIssuePatterns')
            || !function_exists('scanForIssues') || !function_exists('classifyExtension')) {
            echo "Cannot continue — required functions missing\n";
            return false;
        }

        $this->testGetExtensionPath();
        $this->testGetIssuePatterns();
        $this->testScanForIssues();
        $this->testClassifyExtension();
        $this->testPageHasNoFixedText();
        $this->testTextsFollowTheLanguage();

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
        $apis = array_column($patterns['joomla'], 'api');
        $this->test('JPlugin pattern present',        in_array('JPlugin', $apis, true));
        $this->test('JModel pattern present',         in_array('JModel', $apis, true));
        $this->test('Every pattern names the Joomla version that removes the API',
            array_filter(array_column($patterns['joomla'], 'removedIn'), fn ($v) => !in_array($v, [4, 6], true)) === []);
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

    /**
     * The page prints no text of its own: every visible text comes from a
     * language key, so supporting a further language needs language files
     * only. Two layers are checked: the literal HTML between the PHP blocks
     * (no fixed words), and — crucially — the text emitted from PHP, because a
     * hardcoded label inside `<?php echo ... ?>` would otherwise ship
     * untranslated and escape a check that simply strips the PHP away. What
     * may legitimately remain is the company name, the copyright line and
     * separators.
     *
     * The GPL notice in the copyright line is one of those legitimate remains. It
     * is a legal notice the licence requires to be kept, not user-visible prose a
     * further language would translate, and the licence is named the same in every
     * language. Behind a language key a translation could alter its wording.
     */
    private function testPageHasNoFixedText(): void
    {
        echo "\n--- Page texts ---\n";

        $source = (string) file_get_contents($this->mainFile);
        $start  = strpos($source, '<body>');
        $end    = strpos($source, '</html>');
        $this->test('Page markup found', $start !== false && $end !== false);

        if ($start === false || $end === false) {
            return;
        }

        $markup = substr($source, $start, $end - $start);

        // Layer 1: the literal HTML between the PHP blocks carries no words.
        $inlineHtml = preg_replace('/<\?php.*?\?>/s', ' ', $markup);
        $inlineHtml = preg_replace('/<(style|script)\b.*?<\/\1>/is', ' ', $inlineHtml);
        $inlineText = html_entity_decode(strip_tags($inlineHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $inlineText = $this->dropAllowedText($inlineText);

        $this->test('Literal HTML contains no fixed text', $inlineText === '', "left over: '" . mb_substr($inlineText, 0, 200) . "'");

        // Layer 2: nothing visible is emitted as a hardcoded string from PHP.
        // Every echoed string literal that survives strip_tags must come from a
        // language key (Text::…); bare labels such as the J2Store/Joomla origin
        // badge are reported instead of being silently stripped with the block.
        $hardcoded = $this->findEchoedFixedText($markup);

        $this->test('PHP emits no hardcoded visible text', $hardcoded === [],
            'hardcoded: ' . implode(', ', array_map(static fn ($s) => "'$s'", $hardcoded)));

        $this->test('Removal confirmation comes from the language file',
            str_contains($source, "Text::script('COM_J2STORE_CLEANUP_CONFIRM_REMOVE')")
            && str_contains($source, "confirm(Joomla.Text._('COM_J2STORE_CLEANUP_CONFIRM_REMOVE'))"));

        $this->test('No English message is built outside the language file',
            !preg_match("/(?:enqueueMessage|\['messages'\]\[\]\s*=|'reason'\s*=>)\s*\(?\s*'[A-Za-z][^']*\s[^']*'/", $source));
    }

    /**
     * Remove the few fixed strings the page may legitimately show: the company
     * name, the copyright sign, the year range, the GPL notice and separators.
     *
     * The copyright line goes first and as ONE exact string, so the separator between
     * the company name and the licence notice goes with it. The exact wording, not a
     * pattern: a typo in the notice has to fail the checks, and a second piece of fixed
     * text that merely looks similar must not slip through.
     */
    private function dropAllowedText(string $text): string
    {
        $text = str_replace(
            [
                '© 2025-2026 Advans IT Solutions GmbH. Licensed under the GNU General Public License version 3 or later.',
                'Advans IT Solutions GmbH',
                '©',
                '2025-2026',
                '—',
            ],
            ' ',
            $text
        );

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Find visible text emitted as a hardcoded string literal from PHP output.
     *
     * Tokenises the markup and collects the string literals that an `echo`,
     * `print` or `<?= ?>` writes out directly (parenthesis depth 0 — literals
     * passed to Text::…, htmlspecialchars() etc. sit inside parentheses and
     * are therefore ignored, being either localised or non-visible arguments).
     * A literal is reported when, after stripping any HTML, real words remain
     * that are not an allowed fixed string and not a lowercase CSS class or
     * identifier token.
     *
     * @return string[]  The offending literal contents.
     */
    private function findEchoedFixedText(string $markup): array
    {
        $tokens = token_get_all($markup);
        $found  = [];

        $inEcho = false;
        $depth  = 0;

        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text] = $token;

                if ($id === T_ECHO || $id === T_PRINT || $id === T_OPEN_TAG_WITH_ECHO) {
                    $inEcho = true;
                    $depth  = 0;
                    continue;
                }

                if (!$inEcho) {
                    continue;
                }

                if ($id === T_CLOSE_TAG) {
                    $inEcho = false;
                    continue;
                }

                if ($id === T_CONSTANT_ENCAPSED_STRING && $depth === 0) {
                    $value   = $this->literalValue($text);
                    $visible = $this->dropAllowedText(
                        html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    );

                    // Ignore empties, pure CSS-class / identifier tokens (lower
                    // case, digits, hyphens) and anything without a real word.
                    if ($visible !== ''
                        && preg_match('/\p{L}/u', $visible)
                        && !preg_match('/^[a-z0-9\-\s]+$/', $visible)) {
                        $found[$value] = $value;
                    }
                }

                continue;
            }

            if (!$inEcho) {
                continue;
            }

            // Single-character tokens: track parentheses and the statement end.
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            } elseif ($token === ';' && $depth === 0) {
                $inEcho = false;
            }
        }

        return array_values($found);
    }

    /**
     * Decode a single or double quoted PHP string literal to its text value.
     */
    private function literalValue(string $literal): string
    {
        $quote = $literal[0] ?? "'";
        $inner = substr($literal, 1, -1);

        if ($quote === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $inner);
        }

        return stripcslashes($inner);
    }

    /**
     * Results and messages follow the site language. Runs in de-DE, so an
     * English text built into the code would show.
     */
    private function testTextsFollowTheLanguage(): void
    {
        echo "\n--- Texts in de-DE ---\n";

        // Joomla installs an extension's language file only for languages the site
        // has, and the test site has no de-DE pack. Load the de-DE file from the
        // package under test instead, without falling back to en-GB.
        $language = Factory::getContainer()
            ->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)
            ->createLanguage('de-DE');
        $language->load('com_j2store_cleanup', $this->packageLanguageDir('com_j2store_cleanup', 'de-DE'), 'de-DE', true, false);

        $previousApplication = Factory::$application;
        $hasLanguageProperty = property_exists(Factory::class, 'language');
        $previousLanguage    = $hasLanguageProperty ? Factory::$language : null;

        // Text reads the language through Factory; give it the German one.
        Factory::$application = new class ($language) {
            public function __construct(private object $language)
            {
            }

            public function getLanguage(): object
            {
                return $this->language;
            }
        };

        if ($hasLanguageProperty) {
            Factory::$language = $language;
        }

        try {
            $this->test('Precondition: component language loaded in de-DE',
                Text::_('COM_J2STORE_CLEANUP_BUTTON_REMOVE') === 'Ausgewählte Erweiterungen entfernen');

            $patterns = getIssuePatterns();

            $issue = describeIssue(['type' => 'joomla', 'detail' => 'JFactory', 'removedIn' => 6]);
            $this->test('A finding is described in German', $issue === 'JFactory (entfernt in Joomla 6)', $issue);

            $core = classifyExtension((object) ['version' => '4.0.20', 'author' => 'J2Commerce'],
                (object) ['element' => 'com_j2store', 'type' => 'component', 'folder' => '', 'client_id' => 1], $patterns);
            $this->test('The core component is described in German',
                $core['reason'] === 'Kernkomponente (Version 4.0.20)', $core['reason']);

            $missing = classifyExtension((object) ['version' => '1.0', 'author' => 'Test'],
                (object) ['element' => 'plg_nonexistent_xyz', 'type' => 'plugin', 'folder' => 'j2store', 'client_id' => 0], $patterns);
            $this->test('Missing files are described in German',
                $missing['reason'] === 'Dateien nicht auf dem Server gefunden (Test, Version 1.0)', $missing['reason']);
        } finally {
            Factory::$application = $previousApplication;

            if ($hasLanguageProperty) {
                Factory::$language = $previousLanguage;
            }
        }
    }

    /**
     * Directory holding language/<tag>/<extension>.ini from the package under
     * test (/tmp/extension.zip), as a base path for Language::load().
     */
    private function packageLanguageDir(string $extension, string $tag): string
    {
        $dir = $this->tmpDir . '/package-language';
        @mkdir($dir . '/language/' . $tag, 0755, true);

        $zip = new \ZipArchive();

        if ($zip->open('/tmp/extension.zip') === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_ends_with($name, $tag . '/' . $extension . '.ini')) {
                    file_put_contents($dir . '/language/' . $tag . '/' . $extension . '.ini', (string) $zip->getFromIndex($i));
                    break;
                }
            }

            $zip->close();
        }

        return $dir;
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
