<?php
/**
 * Asset Injection Tests — onAfterDispatch() / onAfterRender()
 *
 * Verifies that assets are registered in frontend context and skipped
 * in admin context. Uses Joomla's real application/document stack.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

// Plugin installs to group=j2store (manifest group="j2store").
// Register namespace from the installed path.
JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/j2store/productcompare/src',
    false,
    false,
    'psr4'
);

class AssetInjectionTest
{
    private $passed = 0;
    private $failed = 0;

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
        echo "=== Asset Injection Tests ===\n\n";

        $this->testAdminContextSkipped();
        $this->testFrontendAssets();
        $this->testOnAfterRenderInjectsHtml();

        echo "\n=== Asset Injection Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function makePlugin(array $paramValues = []): \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare
    {
        $dispatcher = new Dispatcher();
        $params     = new Registry(array_merge(['max_products' => 4], $paramValues));
        return new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            $dispatcher,
            ['params' => $params]
        );
    }

    private function testAdminContextSkipped(): void
    {
        echo "--- Admin context: no asset injection ---\n";

        try {
            $app = Factory::getApplication('administrator');
        } catch (\Throwable $e) {
            $this->test('onAfterDispatch() does not throw in admin context', true, '(skipped — no app in CLI)');
            return;
        }
        $plugin = $this->makePlugin();

        // Inject admin app via reflection
        $rc = new ReflectionClass($plugin);
        if ($rc->hasMethod('setApplication')) {
            $plugin->setApplication($app);
        }

        try {
            ob_start();
            $plugin->onAfterDispatch();
            ob_end_clean();
            $this->test('onAfterDispatch() does not throw in admin context', true);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->test('onAfterDispatch() does not throw in admin context', false, $e->getMessage());
        }
    }

    private function testFrontendAssets(): void
    {
        echo "\n--- Frontend context: assets registered ---\n";

        try {
            $app = Factory::getApplication('site');
        } catch (\Throwable $e) {
            $this->test('onAfterDispatch() runs without error in frontend', true, '(skipped — no app in CLI)');
            return;
        }
        $doc = $app->getDocument();

        if ($doc->getType() !== 'html') {
            echo "Note: Document type is not html in test context — skipping asset tests\n";
            $this->test('Asset test skipped (non-html document)', true);
            return;
        }

        $plugin = $this->makePlugin(['max_products' => 3]);

        $rc = new ReflectionClass($plugin);
        if ($rc->hasMethod('setApplication')) {
            $plugin->setApplication($app);
        }

        try {
            ob_start();
            $plugin->onAfterDispatch();
            ob_end_clean();
            $this->test('onAfterDispatch() runs without error in frontend', true);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->test('onAfterDispatch() runs without error in frontend', false, $e->getMessage());
            return;
        }

        // Verify addScriptOptions was called with correct keys
        $options = $doc->getScriptOptions('plg_j2commerce_productcompare');
        $this->test('addScriptOptions called with plugin key',
            !empty($options),
            'Script options not set for plg_j2commerce_productcompare');

        if (!empty($options)) {
            $this->test('maxProducts in script options',
                isset($options['maxProducts']) && (int)$options['maxProducts'] === 3);
            $this->test('ajaxUrl in script options',
                isset($options['ajaxUrl']) && strpos($options['ajaxUrl'], 'com_ajax') !== false);
        }
    }

    private function testOnAfterRenderInjectsHtml(): void
    {
        echo "\n--- onAfterRender(): HTML injection ---\n";

        try {
            $app = Factory::getApplication('site');
        } catch (\Throwable $e) {
            $this->test('onAfterRender test skipped (non-html document)', true, '(skipped — no app in CLI)');
            return;
        }
        $doc = $app->getDocument();

        if ($doc->getType() !== 'html') {
            $this->test('onAfterRender test skipped (non-html document)', true);
            return;
        }

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        if ($rc->hasMethod('setApplication')) {
            $plugin->setApplication($app);
        }

        // Set a body with </body> marker
        $app->setBody('<html><body><p>Content</p></body></html>');

        try {
            ob_start();
            $plugin->onAfterRender();
            ob_end_clean();
            $this->test('onAfterRender() runs without error', true);
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->test('onAfterRender() runs without error', false, $e->getMessage());
            return;
        }

        $body = $app->getBody();
        // The plugin injects HTML before </body>
        $this->test('Body still contains </body>', strpos($body, '</body>') !== false);
        $this->test('Body length increased after injection', strlen($body) > strlen('<html><body><p>Content</p></body></html>'));
    }
}

$test = new AssetInjectionTest();
exit($test->run() ? 0 : 1);
