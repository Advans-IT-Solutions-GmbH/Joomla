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
        $this->testLocalizationGuardCatchesHardcodedText();
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
     * A guard that cannot fail is worth nothing, so the scanner itself is checked here, with
     * the constructs it has to see through and the ones it has to leave alone. Every case is a
     * line someone could plausibly write in this view.
     *
     * The first cases are the reason this self-test exists: a visible literal does not have to
     * stand at parenthesis depth 0. Passed through htmlspecialchars(), through sprintf(), or
     * merely wrapped in parentheses, it reaches the browser just the same, and a scanner that
     * exempts everything inside parentheses would report none of them. Nor does visible text
     * have to be a whole literal: the fixed pieces around an inserted value are their own
     * tokens, and a single plain word is visible text as much as a sentence is.
     */
    private function testLocalizationGuardCatchesHardcodedText(): void
    {
        echo "\n--- Language guard self-test ---\n";

        $visible = [
            'through htmlspecialchars'   => "<?php echo htmlspecialchars('Hardcoded Label'); ?>",
            'through sprintf'            => "<?php echo sprintf('Total: %d items', \$n); ?>",
            'merely in parentheses'      => "<?php echo ('Hardcoded label'); ?>",
            'a single mixed-case word'   => "<?php echo htmlspecialchars('Warning'); ?>",
            'a single lowercase word'    => "<?php echo 'warning'; ?>",
            'a single uppercase word'    => "<?php echo 'WARNING'; ?>",
            'inserted into a translation' => "<?php echo Text::sprintf('COM_X', htmlspecialchars('Visible Thing')); ?>",
            'in front of a value'        => "<?php echo \"Hardcoded {\$label}\"; ?>",
            'behind a value'             => "<?php echo \"{\$count} extensions found\"; ?>",
            'behind a simple variable'   => "<?php echo \"Removed \$name from the site\"; ?>",
            'inside a short array'       => "<?php echo implode(', ', ['Hardcoded Label']); ?>",
            'inside a nested array'      => "<?php echo implode(', ', [\$a, ['Hardcoded Label']]); ?>",
        ];

        foreach ($visible as $label => $snippet) {
            $found = $this->findEchoedFixedText($snippet);
            $this->test('Guard reports a hardcoded text ' . $label, $found !== [],
                'reported: ' . implode(', ', $found));
        }

        $allowed = [
            'a language key'             => "<?php echo Text::_('COM_X_Y'); ?>",
            'a key chosen in place'      => "<?php echo Text::_(\$c ? 'COM_A' : 'COM_B'); ?>",
            'a fully qualified Text::_'  => "<?php echo \\Joomla\\CMS\\Language\\Text::_('COM_X_Y'); ?>",
            'the allowed company name'   => "<?php echo Text::sprintf('COM_X', '<a href=\"https://advans.ch\">Advans IT Solutions GmbH</a>'); ?>",
            'a layout name'             => "<?php echo HTMLHelper::_('form.token'); ?>",
            'an encoding argument'      => "<?php echo htmlspecialchars(\$x, ENT_QUOTES, 'UTF-8'); ?>",
            'CSS classes in a condition' => "<?php echo \$c ? 'badge-danger' : 'badge-warning'; ?>",
            'a field key'               => "<?php echo htmlspecialchars(\$issue['detail']); ?>",
            'a chained field key'       => "<?php echo htmlspecialchars(\$all['issues'][0]['detail']); ?>",
            'a key behind a call'       => "<?php echo htmlspecialchars(getIssue(\$x)['detail']); ?>",
            'a compared value'          => "<?php echo \$issue['type'] === 'j2store' ? 'badge-danger' : 'badge-warning'; ?>",
            'a compared value in front' => "<?php echo 'j2store' === \$issue['type'] ? 'badge-danger' : 'badge-warning'; ?>",
            'a separator without words' => "<?php echo implode(', ', \$parts); ?>",
        ];

        foreach ($allowed as $label => $snippet) {
            $found = $this->findEchoedFixedText($snippet);
            $this->test('Guard stays silent about ' . $label, $found === [],
                'reported: ' . implode(', ', $found));
        }
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
     * `print` or `<?= ?>` writes out, whichever call they pass through. Only one
     * position is exempt: the FIRST argument of a localisation call
     * (`Text::_`, `Text::sprintf`, `Text::plural`, `Text::script`), which is the
     * language key itself and therefore produces translated output.
     *
     * Parenthesis depth alone is not the criterion, and must not be: `echo
     * htmlspecialchars('Hardcoded Label')` puts a visible literal at depth 1, and
     * `echo ('Hardcoded label');` does the same, so exempting everything inside
     * parentheses would let untranslated text pass this very check. The scanner
     * therefore keeps a stack of the open calls and asks which call the literal
     * belongs to, not how deeply it is nested. Further arguments of a localisation
     * call are checked as well, because they are inserted into the translated text
     * and are visible (the footer link survives because its text is an allowed
     * fixed string, not because it is unchecked).
     *
     * Both forms of fixed text count: a whole string literal and the fixed pieces
     * of a string with values inserted, which PHP returns as tokens of their own
     * (`echo "Fixed text $x"`).
     *
     * A literal is reported when, after stripping any HTML, real words remain that
     * are not an allowed fixed string and not an identifier token
     * (see looksLikeIdentifier()). Two positions are recognised by their place in
     * the code rather than by their spelling, because a plain single word really
     * does occur there: a field key inside LOOKUP brackets (`$issue['type']`) and
     * one side of a comparison (`=== 'j2store'`). Neither is ever output. An array
     * with contents is not a lookup, so `implode(', ', ['Fixed text'])` is
     * reported; see opensSubscript().
     *
     * @return string[]  The offending literal contents.
     */
    private function findEchoedFixedText(string $markup): array
    {
        $tokens = $this->meaningfulTokens($markup);
        $found  = [];

        $inEcho = false;

        // Stapel der offenen Klammern. Je Eintrag der Name des Aufrufs, der sie geoeffnet hat,
        // und die Zahl der Kommas auf dieser Ebene, also die Nummer des Arguments.
        $calls = [];

        // Offene eckige Klammern, je Eintrag die Antwort auf die Frage, ob sie einen Zugriff
        // eroeffnet. $issue['type'] ist ein Feldschluessel und wird nie ausgegeben, ['Fester
        // Text'] ist ein Feld mit Inhalt und sehr wohl sichtbar, etwa in
        // implode(', ', ['Fester Text']). Nur die innerste Klammer entscheidet.
        $brackets = [];

        foreach ($tokens as $i => $token) {
            $id   = $token['id'];
            $text = $token['text'];

            if ($id === T_ECHO || $id === T_PRINT || $id === T_OPEN_TAG_WITH_ECHO) {
                $inEcho   = true;
                $calls    = [];
                $brackets = [];
                continue;
            }

            if (!$inEcho) {
                continue;
            }

            if ($id === T_CLOSE_TAG) {
                $inEcho = false;
                continue;
            }

            // Beide Formen fester Text: die vollstaendige Zeichenkette und die festen Stuecke
            // einer Zeichenkette mit eingesetzten Werten. PHP gibt letztere als eigene Token
            // zurueck, ein Waechter, der nur die erste Form kennt, sieht echo "Fester Text $x"
            // nicht.
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $value = $id === T_CONSTANT_ENCAPSED_STRING
                    ? $this->literalValue($text)
                    : stripcslashes($text);

                if (!$this->isLanguageKeyArgument($calls)
                    && !($brackets !== [] && $brackets[count($brackets) - 1])
                    && !$this->isComparisonOperand($tokens, $i)) {
                    $visible = $this->dropAllowedText(
                        html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    );

                    // Ignore empties, identifier tokens and anything without a real word.
                    if ($visible !== ''
                        && preg_match('/\p{L}/u', $visible)
                        && !$this->looksLikeIdentifier($visible)) {
                        $found[$value] = $value;
                    }
                }

                continue;
            }

            // Track the open calls, the argument number, the field keys and the statement end.
            if ($text === '(') {
                $calls[] = ['name' => $this->calleeName($tokens, $i), 'commas' => 0];
            } elseif ($text === ')') {
                array_pop($calls);
            } elseif ($text === '[') {
                $brackets[] = $this->opensSubscript($tokens, $i);
            } elseif ($text === ']') {
                array_pop($brackets);
            } elseif ($text === ',' && $calls !== []) {
                $calls[count($calls) - 1]['commas']++;
            } elseif ($text === ';' && $calls === []) {
                $inEcho = false;
            }
        }

        return array_values($found);
    }

    /**
     * The tokens of the markup without whitespace and comments, each as id plus text, so the
     * scanner can look at the neighbours of a token by index. A single-character token gets the
     * id null.
     *
     * @return array<int, array{id: int|null, text: string}>
     */
    private function meaningfulTokens(string $markup): array
    {
        $out = [];

        foreach (token_get_all($markup) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out[] = ['id' => $token[0], 'text' => $token[1]];

                continue;
            }

            $out[] = ['id' => null, 'text' => $token];
        }

        return $out;
    }

    /**
     * The name in front of the parenthesis at the given index, assembled from the name tokens
     * immediately before it: `Text`, `::`, `_` gives Text::_, and a bare `(` used for grouping
     * gives the empty string.
     *
     * @param  array<int, array{id: int|null, text: string}>  $tokens
     */
    private function calleeName(array $tokens, int $index): string
    {
        $teile = [];

        for ($i = $index - 1; $i >= 0; $i--) {
            $id = $tokens[$i]['id'];

            if (!in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_DOUBLE_COLON,
                T_VARIABLE, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                break;
            }

            $teile[] = $tokens[$i]['text'];
        }

        return implode('', array_reverse($teile));
    }

    /**
     * Does the `[` at the given index open a lookup rather than an array with contents?
     *
     * A lookup follows something that can be indexed: a variable, a closing bracket or
     * parenthesis, a string, or a name (a constant or a property). An array with contents follows
     * none of those, for instance after `(`, after a comma or right behind `echo`. The difference
     * matters because a literal in a lookup is a key and never reaches the page, while
     * `implode(', ', ['Fixed text'])` prints its contents.
     *
     * @param  array<int, array{id: int|null, text: string}>  $tokens
     */
    private function opensSubscript(array $tokens, int $index): bool
    {
        if ($index === 0) {
            return false;
        }

        $vorher = $tokens[$index - 1];

        if (in_array($vorher['id'], [T_VARIABLE, T_STRING, T_CONSTANT_ENCAPSED_STRING,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return true;
        }

        return $vorher['id'] === null && in_array($vorher['text'], [']', ')'], true);
    }

    /**
     * Is the literal at the given index one side of a comparison, as in
     * `$issue['type'] === 'j2store'`? Such a literal is a value being tested, never output, and
     * both orders occur, so the token before and the token after are both consulted.
     *
     * @param  array<int, array{id: int|null, text: string}>  $tokens
     */
    private function isComparisonOperand(array $tokens, int $index): bool
    {
        $vergleiche = ['==', '===', '!=', '!==', '<>', '<=>'];

        foreach ([$index - 1, $index + 1] as $i) {
            if (isset($tokens[$i]) && in_array($tokens[$i]['text'], $vergleiche, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the literal currently being read sit in the one exempt position, the language key
     * of the innermost call?
     *
     * Only the innermost call decides. A literal in `Text::sprintf('KEY', htmlspecialchars('x'))`
     * belongs to htmlspecialchars, not to the translation, and `Text::_($c ? 'A' : 'B')` has
     * both keys in the first argument of Text::_ and is therefore exempt.
     *
     * @param  array<int, array{name: string, commas: int}>  $calls  The open calls, outermost first.
     */
    private function isLanguageKeyArgument(array $calls): bool
    {
        if ($calls === []) {
            return false;
        }

        $innermost = $calls[count($calls) - 1];

        return $innermost['commas'] === 0
            && (bool) preg_match('/(?:^|\\\\)J?Text::(?:_|sprintf|plural|script)$/', $innermost['name']);
    }

    /**
     * Is this text a machine identifier rather than something a reader would read?
     *
     * Machine tokens in this view are CSS classes (`badge-danger`), layout names
     * (`form.token`), language keys (`COM_J2STORE_CLEANUP_X`) and encodings (`UTF-8`). What they
     * have in common is a separator inside one word, and that is the whole rule: one word
     * without spaces that carries a dot, a hyphen or an underscore.
     *
     * Being written in one case is deliberately NOT enough. A lone `warning` or `WARNING` would
     * be visible untranslated text, and the two positions where a single plain word really does
     * occur in this view are recognised by their place in the code instead, not by their
     * spelling: a field key inside square brackets, and one side of a comparison.
     */
    private function looksLikeIdentifier(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_.\-]+$/', $value)
            && (bool) preg_match('/[._\-]/', $value);
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
