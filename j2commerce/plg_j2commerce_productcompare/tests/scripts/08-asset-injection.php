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
require_once __DIR__ . '/bootstrap-app.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Document\FactoryInterface as DocumentFactoryInterface;
use Joomla\CMS\Document\HtmlDocument;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

// Plugin installs to group=j2store (manifest group="j2store").
// Register namespace from the installed path.
JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/' . (getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store') . '/productcompare/src',
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

        // Note: tests use the frontend site application. We deliberately do not
        // create an administrator application in this process because Joomla's
        // Factory::getApplication() is a singleton — the first application created
        // is cached and returned for every subsequent call regardless of the id.
        // The administrator guard (isClient('administrator')) is covered by 04.
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
        $group      = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';

        return new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            $dispatcher,
            ['params' => $params, 'type' => $group, 'name' => 'productcompare']
        );
    }

    private function makeHtmlDocument(): HtmlDocument
    {
        try {
            $doc = Factory::getContainer()->get(DocumentFactoryInterface::class)->createDocument('html');
            if ($doc instanceof HtmlDocument) {
                return $doc;
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return new HtmlDocument();
    }

    /**
     * Force a real HTML document onto the application so the asset/render paths
     * (which require $doc->getType() === 'html') are actually exercised instead
     * of skipped. The CMS sets its document through the protected loadDocument()
     * path; in a CLI test we set the protected `document` property by reflection.
     */
    private function attachHtmlDocument(object $app): HtmlDocument
    {
        $doc = $this->makeHtmlDocument();
        $rp  = new ReflectionProperty($app, 'document');
        $rp->setAccessible(true);
        $rp->setValue($app, $doc);

        return $doc;
    }

    private function testFrontendAssets(): void
    {
        echo "\n--- Frontend context: assets registered ---\n";

        try {
            $app = bootstrapSiteApplication();
        } catch (\Throwable $e) {
            $this->test('onAfterDispatch() runs without error in frontend', false, $e->getMessage());
            return;
        }

        // Drive a real HTML document so the WebAssetManager + script-options path
        // actually runs (the legacy version skipped here on a non-HTML CLI doc).
        $doc = $this->attachHtmlDocument($app);
        $this->test('Frontend document type is html', $doc->getType() === 'html');

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
            $app = bootstrapSiteApplication();
        } catch (\Throwable $e) {
            $this->test('onAfterRender() runs without error', false, $e->getMessage());
            return;
        }

        // Drive a real HTML document so the injection path runs (see 09 for the
        // dedicated end-to-end render-injection proof).
        $doc = $this->attachHtmlDocument($app);
        $this->test('Render document type is html', $doc->getType() === 'html');

        $plugin = $this->makePlugin();
        $rc     = new ReflectionClass($plugin);
        if ($rc->hasMethod('setApplication')) {
            $plugin->setApplication($app);
        }

        // Set a body with </body> marker
        $original = '<html><body><p>Content</p></body></html>';
        $app->setBody($original);

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
        // The plugin injects the compare bar + modal HTML before </body>.
        $this->test('Body still contains </body>', strpos($body, '</body>') !== false);
        $this->test('Compare bar markup injected', strpos($body, 'j2store-compare-bar') !== false);
        $this->test('Compare modal markup injected', strpos($body, 'j2store-compare-modal') !== false);
        $this->test('Body length increased after injection', strlen($body) > strlen($original));
    }
}

$test = new AssetInjectionTest();
exit($test->run() ? 0 : 1);
