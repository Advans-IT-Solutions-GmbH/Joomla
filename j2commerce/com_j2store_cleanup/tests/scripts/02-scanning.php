<?php
/**
 * Scanning Tests for J2Store Cleanup
 * Tests the version-aware file scanning logic
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

class ScanningTest
{
    private $passed = 0;
    private $failed = 0;
    private $tmpDir;

    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir() . '/j2cleanup_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    public function __destruct()
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir($dir)
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function makeDir(string $name): string
    {
        $dir = $this->tmpDir . '/' . $name;
        mkdir($dir, 0755, true);
        return $dir;
    }

    private function test(string $name, bool $condition): void
    {
        echo 'Test: ' . $name . '... ' . ($condition ? 'PASS' : 'FAIL') . "\n";
        $condition ? $this->passed++ : $this->failed++;
    }

    /**
     * Replicate scanForIssues from j2store_cleanup.php
     */
    private function scanForIssues(string $path, array $patterns): array
    {
        if (!is_dir($path)) return [];
        $issues = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') continue;
            $content = @file_get_contents($file->getPathname());
            if ($content === false) continue;
            $stripped = preg_replace('#/\*.*?\*/#s', '', $content);
            $stripped = preg_replace('#//.*$#m', '', $stripped);
            foreach ($patterns['joomla'] as $pattern => $label) {
                if (preg_match($pattern, $stripped)) {
                    $issues[] = ['type' => 'joomla', 'detail' => $label];
                }
            }
            foreach ($patterns['j2store'] as $pattern => $label) {
                if (preg_match($pattern, $stripped)) {
                    $issues[] = ['type' => 'j2store', 'detail' => $label];
                }
            }
        }
        $seen = [];
        $unique = [];
        foreach ($issues as $issue) {
            $key = $issue['type'] . ':' . $issue['detail'];
            if (!isset($seen[$key])) { $seen[$key] = true; $unique[] = $issue; }
        }
        return $unique;
    }

    private function getPatternsForVersion(int $major): array
    {
        $byVersion = [
            4 => [
                '/\bJPlugin\b/'              => 'JPlugin (removed in Joomla 4)',
                '/\bJModel(Legacy)?\b/'      => 'JModel (removed in Joomla 4)',
                '/\bJTable\b/'               => 'JTable (removed in Joomla 4)',
                '/\bJView(Legacy)?\b/'       => 'JView (removed in Joomla 4)',
                '/\bJController(Legacy)?\b/' => 'JController (removed in Joomla 4)',
                '/\bJForm\b/'               => 'JForm (removed in Joomla 4)',
            ],
            6 => [
                '/\bJFactory\b/'             => 'JFactory (removed in Joomla 6)',
                '/\bJText\b/'               => 'JText (removed in Joomla 6)',
                '/\bJHtml\b/'               => 'JHtml (removed in Joomla 6)',
                '/\bJRoute\b/'              => 'JRoute (removed in Joomla 6)',
                '/\bJUri\b/'                => 'JUri (removed in Joomla 6)',
                '/\bJSession\b/'            => 'JSession (removed in Joomla 6)',
                '/Factory::getUser\s*\(/'   => 'Factory::getUser() (removed in Joomla 6)',
                '/Factory::getDbo\s*\(/'    => 'Factory::getDbo() (removed in Joomla 6)',
                '/Factory::getSession\s*\(/' => 'Factory::getSession() (removed in Joomla 6)',
                '/Factory::getDocument\s*\(/' => 'Factory::getDocument() (removed in Joomla 6)',
                '/\$this->app\b(?!lication)/' => '$this->app property (removed in Joomla 6)',
            ],
        ];
        $p = [];
        foreach ($byVersion as $removedIn => $patterns) {
            if ($major >= $removedIn) $p = array_merge($p, $patterns);
        }
        return ['joomla' => $p, 'j2store' => []];
    }

    public function run(): bool
    {
        echo "=== Scanning Tests (Version-Aware Detection) ===\n\n";

        // --- Joomla 4: only J3 legacy classes flagged ---
        echo "--- Joomla 4 ---\n";
        $p4 = $this->getPatternsForVersion(4);

        $d = $this->makeDir('j4_legacy');
        file_put_contents($d . '/a.php', "<?php\n\$m = new JModelLegacy();\n");
        $this->test('J4: JModelLegacy flagged', count($this->scanForIssues($d, $p4)) > 0);

        $d = $this->makeDir('j4_jfactory');
        file_put_contents($d . '/a.php', "<?php\n\$app = JFactory::getApplication();\n");
        $this->test('J4: JFactory NOT flagged', count($this->scanForIssues($d, $p4)) === 0);

        $d = $this->makeDir('j4_getuser');
        file_put_contents($d . '/a.php', "<?php\nFactory::getUser();\n");
        $this->test('J4: Factory::getUser() NOT flagged', count($this->scanForIssues($d, $p4)) === 0);

        // --- Joomla 5: same as J4 (B/C plugin still active) ---
        echo "\n--- Joomla 5 ---\n";
        $p5 = $this->getPatternsForVersion(5);

        $d = $this->makeDir('j5_legacy');
        file_put_contents($d . '/a.php', "<?php\n\$t = new JTable();\n");
        $this->test('J5: JTable flagged', count($this->scanForIssues($d, $p5)) > 0);

        $d = $this->makeDir('j5_jfactory');
        file_put_contents($d . '/a.php', "<?php\nJFactory::getApplication();\n");
        $this->test('J5: JFactory NOT flagged (B/C plugin)', count($this->scanForIssues($d, $p5)) === 0);

        $d = $this->makeDir('j5_getdbo');
        file_put_contents($d . '/a.php', "<?php\nFactory::getDbo();\n");
        $this->test('J5: Factory::getDbo() NOT flagged', count($this->scanForIssues($d, $p5)) === 0);

        // --- Joomla 6: everything flagged ---
        echo "\n--- Joomla 6 ---\n";
        $p6 = $this->getPatternsForVersion(6);

        $d = $this->makeDir('j6_jfactory');
        file_put_contents($d . '/a.php', "<?php\nJFactory::getApplication();\n");
        $this->test('J6: JFactory flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_jtext');
        file_put_contents($d . '/a.php', "<?php\necho JText::_('KEY');\n");
        $this->test('J6: JText flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_jroute');
        file_put_contents($d . '/a.php', "<?php\nJRoute::_('index.php');\n");
        $this->test('J6: JRoute flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_getuser');
        file_put_contents($d . '/a.php', "<?php\nFactory::getUser();\n");
        $this->test('J6: Factory::getUser() flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_getdbo');
        file_put_contents($d . '/a.php', "<?php\nFactory::getDbo();\n");
        $this->test('J6: Factory::getDbo() flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_getsession');
        file_put_contents($d . '/a.php', "<?php\nFactory::getSession();\n");
        $this->test('J6: Factory::getSession() flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_getdocument');
        file_put_contents($d . '/a.php', "<?php\nFactory::getDocument();\n");
        $this->test('J6: Factory::getDocument() flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_thisapp');
        file_put_contents($d . '/a.php', "<?php\n\$this->app->getInput();\n");
        $this->test('J6: $this->app flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_jlegacy');
        file_put_contents($d . '/a.php', "<?php\nnew JPlugin();\n");
        $this->test('J6: JPlugin also flagged (J4 removal still applies)', count($this->scanForIssues($d, $p6)) > 0);

        // --- Clean code: no issues on any version ---
        echo "\n--- Clean modern code ---\n";
        $d = $this->makeDir('clean');
        file_put_contents($d . '/a.php', "<?php\nuse Joomla\\CMS\\Factory;\n\$app = Factory::getApplication();\n\$user = \$app->getIdentity();\n\$db = Factory::getContainer()->get('DatabaseDriver');\n");
        $this->test('Clean code: no issues on J6', count($this->scanForIssues($d, $p6)) === 0);
        $this->test('Clean code: no issues on J5', count($this->scanForIssues($d, $p5)) === 0);
        $this->test('Clean code: no issues on J4', count($this->scanForIssues($d, $p4)) === 0);

        // --- $this->app pattern (J6 removal) ---
        echo "\n--- \$this->app pattern ---\n";
        $d = $this->makeDir('j6_thisapp_getdocument');
        file_put_contents($d . '/a.php', "<?php\n\$this->app->getDocument();\n");
        $this->test('J6: $this->app->getDocument() flagged', count($this->scanForIssues($d, $p6)) > 0);

        $d = $this->makeDir('j6_thisapp_getinput');
        file_put_contents($d . '/a.php', "<?php\n\$this->app->getInput();\n");
        $this->test('J6: $this->app->getInput() flagged', count($this->scanForIssues($d, $p6)) > 0);

        // $this->application (with 'lication' suffix) must NOT be flagged — it's a valid J5 pattern
        $d = $this->makeDir('j5_thisapplication');
        file_put_contents($d . '/a.php', "<?php\n\$this->application->getDocument();\n");
        $this->test('J5: $this->application NOT flagged', count($this->scanForIssues($d, $p6)) === 0);

        // --- F0F not flagged (J2Commerce ships it) ---
        echo "\n--- J2Commerce F0F ---\n";
        $d = $this->makeDir('fof');
        file_put_contents($d . '/a.php', "<?php\nF0FModel::getTmpInstance('Products', 'J2StoreModel');\nF0FTable::getInstance('Order', 'J2StoreTable');\n");
        $this->test('F0FModel NOT flagged on J6', count($this->scanForIssues($d, $p6)) === 0);

        // --- Comments ignored ---
        echo "\n--- Comment stripping ---\n";
        $d = $this->makeDir('comments');
        file_put_contents($d . '/a.php', "<?php\n// JFactory::getApplication();\n/* JText::_('test'); */\n\$x = 1;\n");
        $this->test('Commented code not flagged', count($this->scanForIssues($d, $p6)) === 0);

        // --- Non-PHP files ignored ---
        echo "\n--- Non-PHP files ---\n";
        $d = $this->makeDir('nonphp');
        file_put_contents($d . '/t.html', 'JFactory JText');
        file_put_contents($d . '/c.xml', '<x>JFactory</x>');
        $this->test('Non-PHP files ignored', count($this->scanForIssues($d, $p6)) === 0);

        // --- Edge cases ---
        echo "\n--- Edge cases ---\n";
        $d = $this->makeDir('empty');
        $this->test('Empty dir: no issues', count($this->scanForIssues($d, $p6)) === 0);
        $this->test('Nonexistent dir: no issues', count($this->scanForIssues('/nonexistent', $p6)) === 0);

        // --- Deduplication ---
        echo "\n--- Deduplication ---\n";
        $d = $this->makeDir('dedup');
        file_put_contents($d . '/a.php', "<?php\nJFactory::getApplication();\n");
        file_put_contents($d . '/b.php', "<?php\nJFactory::getDbo();\n");
        $issues = $this->scanForIssues($d, $p6);
        $jfactoryCount = count(array_filter($issues, fn($i) => strpos($i['detail'], 'JFactory') !== false));
        $this->test('JFactory deduplicated across files', $jfactoryCount === 1);

        echo "\n=== Scanning Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ScanningTest();
exit($test->run() ? 0 : 1);
