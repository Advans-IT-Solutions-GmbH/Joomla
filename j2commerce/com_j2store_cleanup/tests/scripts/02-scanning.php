<?php
/**
 * Scanning tests for J2Store Cleanup.
 *
 * Runs the real scanner functions from j2store_cleanup.php (no copy of them)
 * against small fixture extensions. Joomla is not booted: the few Joomla
 * classes the scanner touches are stubbed below, so the result does not
 * depend on which backward-compatibility plugins the test site has enabled.
 * Whether a legacy API is still usable is decided by the scanner at run time
 * (class alias registered, Factory method present, com_j2store installed);
 * the stubs set up both sides of every such decision.
 *
 * The fixtures mirror the findings the scanner reported on a Joomla 6.2 /
 * J2Commerce 6 site: matches inside strings and comments, `$this->app` in
 * classes that are no plugins, and Factory methods Joomla 6 still has.
 *
 * Runs locally without Docker: php tests/scripts/02-scanning.php
 */

namespace Joomla\CMS {
    // Joomla 6 still has these methods (deprecated, removal announced for 7.0).
    class Factory
    {
        public static function getApplication()
        {
            return null;
        }

        public static function getDbo()
        {
            return null;
        }

        public static function getSession()
        {
            return null;
        }

        public static function getUser()
        {
            return null;
        }

        public static function getDocument()
        {
            return null;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }

        public static function sprintf(string $key, ...$values): string
        {
            return $key . ($values ? ':' . implode('|', $values) : '');
        }
    }
}

namespace {
    define('_JEXEC', true);
    define('JVERSION', '6.2.0');

    $extensionRoot = dirname(__DIR__, 2);
    $fixtureRoot   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'j2cleanup-scan-' . bin2hex(random_bytes(6));
    mkdir($fixtureRoot, 0777, true);
    define('JPATH_SITE', $fixtureRoot);
    define('JPATH_ADMINISTRATOR', $fixtureRoot . DIRECTORY_SEPARATOR . 'administrator');
    define('JPATH_API', $fixtureRoot . DIRECTORY_SEPARATOR . 'api');
    define('JPATH_PLUGINS', $fixtureRoot . DIRECTORY_SEPARATOR . 'plugins');
    define('JPATH_LIBRARIES', $fixtureRoot . DIRECTORY_SEPARATOR . 'libraries');
    define('J2STORE_CLEANUP_FUNCTIONS_ONLY', true);

    require $extensionRoot . DIRECTORY_SEPARATOR . 'administrator'
        . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_j2store_cleanup'
        . DIRECTORY_SEPARATOR . 'j2store_cleanup.php';

    // The backward-compatibility plugin registers JFactory as an alias of
    // Factory; JSession stays unregistered, as on a site with aliases off.
    class_alias(\Joomla\CMS\Factory::class, 'JFactory');

    final class ScanningTest
    {
        private int $passed = 0;
        private int $failed = 0;

        public function __construct(private string $root)
        {
        }

        private function test(string $name, bool $condition, string $detail = ''): void
        {
            echo ($condition ? 'PASS' : 'FAIL') . ": $name" . ($condition || $detail === '' ? '' : " ($detail)") . "\n";
            $condition ? $this->passed++ : $this->failed++;
        }

        /**
         * Create a plugin fixture under plugins/j2store/<element> and classify it.
         */
        private function classify(string $element, array $files): array
        {
            $dir = JPATH_PLUGINS . '/j2store/' . $element;
            foreach ($files as $name => $code) {
                $path = $dir . '/' . $name;
                if (!is_dir(dirname($path))) {
                    mkdir(dirname($path), 0777, true);
                }
                file_put_contents($path, $code);
            }

            $ext = (object) ['type' => 'plugin', 'folder' => 'j2store', 'element' => $element, 'client_id' => 0];

            return classifyExtension((object) ['version' => '1.0', 'author' => 'Test'], $ext, getIssuePatterns());
        }

        private static function details(array $result): string
        {
            return implode(', ', array_map(static fn (array $i): string => $i['detail'], $result['issues']));
        }

        public function run(): int
        {
            echo "=== Scanning Tests (real scanner functions) ===\n\n";

            // --- Strings and comments are not code (privacy plugin, script.php) ---
            $r = $this->classify('strings_and_comments', ['script.php' => <<<'PHP'
<?php
// Only the exact helper names count. HTMLHelper and JHtml from other classes do not.
$pattern = '/(?:HTMLHelper|JHtml)::_\(\s*[\'"]form\.token[\'"]\s*\)/';
$text    = "JFactory::getDbo() is mentioned in a message only";
$doc     = <<<TXT
JSession and Factory::getSession() inside a heredoc
TXT;
/** JRoute::_() in a doc block */
echo $pattern, $text, $doc;
PHP]);
            $this->test('A legacy name inside a string or comment is no finding', $r['status'] === 'compatible', self::details($r));

            // --- A string that contains "/*" must not hide the code after it ---
            $r = $this->classify('comment_opener_in_string', ['a.php' => "<?php\n\$glob = 'cache/*';\n\$s = JSession::getInstance();\n// */\n"]);
            $this->test('Code after a string containing "/*" is still scanned',
                in_array('JSession', array_column($r['issues'], 'detail'), true), self::details($r));

            // --- $this->app in a module dispatcher (J2Commerce core modules) ---
            $r = $this->classify('module_dispatcher', ['src/Dispatcher/Dispatcher.php' => <<<'PHP'
<?php
namespace J2Commerce\Module\Products\Site\Dispatcher;
use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $lang = $this->app->getLanguage();
        return ['itemId' => (int) ($this->app->getMenu()->getActive()?->id ?? 0)];
    }
}
PHP]);
            $this->test('$this->app of a module dispatcher is no finding', $r['status'] === 'compatible', self::details($r));

            // --- $this->app as an own property (J2Commerce CLI command factory) ---
            $r = $this->classify('own_app_property', ['src/Command/CommandFactory.php' => <<<'PHP'
<?php
class CommandFactory
{
    private $app;
    public function setApplication($app) { $this->app = $app; }
    private function getApplication() { return $this->app; }
}
PHP]);
            $this->test('$this->app of a class that is no plugin is no finding', $r['status'] === 'compatible', self::details($r));

            // --- $this->app in a plugin: deprecated, still filled by Joomla 6 ---
            $r = $this->classify('plugin_app', ['plugin.php' => <<<'PHP'
<?php
use Joomla\CMS\Plugin\CMSPlugin;
class PlgSystemOld extends CMSPlugin
{
    protected $app;
    public function onAfterRoute() { return $this->app->getInput(); }
}
PHP]);
            $this->test('$this->app in a plugin is deprecated, not incompatible', $r['status'] === 'deprecated', $r['status'] . ': ' . self::details($r));

            // --- Factory methods Joomla 6 still has (plg_j2store_advans_layout) ---
            $r = $this->classify('factory_methods', ['plugin.php' => "<?php\nuse Joomla\\CMS\\Factory;\n\$db = Factory::getDbo();\n\$s = \\Joomla\\CMS\\Factory::getSession();\n"]);
            $this->test('Factory::getDbo()/getSession() that still exist are deprecated, not incompatible',
                $r['status'] === 'deprecated', $r['status'] . ': ' . self::details($r));
            $this->test('Deprecated findings are marked as still available',
                $r['issues'] !== [] && array_filter($r['issues'], static fn (array $i): bool => empty($i['available'])) === []);
            $this->test('A deprecated finding names the Joomla version that removes it',
                describeIssue($r['issues'][0] ?? []) === 'COM_J2STORE_CLEANUP_ISSUE_DEPRECATED:Factory::getDbo()|7',
                describeIssue($r['issues'][0] ?? []));

            // --- Class alias provided by the backward-compatibility plugin ---
            $r = $this->classify('alias_available', ['plugin.php' => "<?php\n\$app = JFactory::getApplication();\n"]);
            $this->test('JFactory with the alias registered is deprecated, not incompatible', $r['status'] === 'deprecated', $r['status']);
            $this->test('Its finding says it comes from the compatibility plugin',
                describeIssue($r['issues'][0] ?? []) === 'COM_J2STORE_CLEANUP_ISSUE_COMPAT:JFactory|7',
                describeIssue($r['issues'][0] ?? []));

            // --- Class alias that is not registered: really unavailable ---
            $r = $this->classify('alias_missing', ['plugin.php' => "<?php\n\$s = JSession::checkToken();\n"]);
            $this->test('JSession without a registered alias is incompatible', $r['status'] === 'incompatible', $r['status']);
            $this->test('Its finding says it is not available',
                describeIssue($r['issues'][0] ?? []) === 'COM_J2STORE_CLEANUP_ISSUE_UNAVAILABLE:JSession',
                describeIssue($r['issues'][0] ?? []));

            // --- One unavailable API makes the extension incompatible ---
            $r = $this->classify('mixed', ['a.php' => "<?php\nJFactory::getApplication();\nnew JModelLegacy();\n"]);
            $this->test('Deprecated plus unavailable is incompatible', $r['status'] === 'incompatible', $r['status']);
            $this->test('Both findings are listed', count($r['issues']) === 2, self::details($r));

            // --- J2Store classes on a site without com_j2store (old J2Store modules) ---
            $legacyModule = ['helper.php' => "<?php\n\$config = J2Store::config();\n\$html = J2Html::select();\n"];
            $r = $this->classify('j2store_classes', $legacyModule);
            $this->test('J2Store classes without com_j2store are incompatible', $r['status'] === 'incompatible', $r['status']);
            $this->test('Their finding says J2Store is not installed',
                describeIssue($r['issues'][0] ?? []) === 'COM_J2STORE_CLEANUP_ISSUE_J2STORE_MISSING:J2Store',
                describeIssue($r['issues'][0] ?? []));

            mkdir(JPATH_ADMINISTRATOR . '/components/com_j2store', 0777, true);
            $r = $this->classify('j2store_classes_present', $legacyModule);
            $this->test('J2Store classes with com_j2store installed are no finding', $r['status'] === 'compatible', self::details($r));

            // --- Unchanged behaviour ---
            $r = $this->classify('clean', ['a.php' => "<?php\nuse Joomla\\CMS\\Factory;\n\$app = Factory::getApplication();\n"]);
            $this->test('Clean code is compatible', $r['status'] === 'compatible', self::details($r));

            $r = $this->classify('html_only', ['tmpl.php' => "<h1>JText JFactory</h1>\n<?php echo 1; ?>\n"]);
            $this->test('Legacy names in inline HTML are no finding', $r['status'] === 'compatible', self::details($r));

            $this->test('Nonexistent directory has no findings', scanForIssues('/nonexistent_xyz', getIssuePatterns()) === []);

            $this->test('Deprecated extensions are not offered for removal',
                !isCleanupEligible((object) ['type' => 'plugin', 'folder' => 'j2store', 'element' => 'plugin_app'], ['status' => 'deprecated']));

            echo "\nScanning: {$this->passed} passed, {$this->failed} failed\n";

            return $this->failed === 0 ? 0 : 1;
        }
    }

    $test   = new ScanningTest($fixtureRoot);
    $result = $test->run();

    $remove = static function (string $dir) use (&$remove): void {
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $remove($path) : unlink($path);
        }
        rmdir($dir);
    };
    $remove($fixtureRoot);

    exit($result);
}
