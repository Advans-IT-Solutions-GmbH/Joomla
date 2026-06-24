<?php
/**
 * onAfterRender() HTML Injection Tests — real-functional render proof.
 *
 * This is the end-to-end proof that the compare bar + modal markup is actually
 * injected into a rendered HTML <body>. It does NOT skip on a non-HTML document
 * like the legacy asset test did: it builds a REAL Joomla HtmlDocument, attaches
 * it to the real site application, sets a real HTML body, invokes the plugin's
 * onAfterRender() listener exactly as Joomla's onAfterRender event would, and
 * asserts the injected layout markup (bar + modal, rendered from the real tmpl
 * files) appears before </body>.
 *
 * Runs on both stacks:
 *   J5 + J2Store/J2Commerce 4  (group j2store)
 *   J6 + J2Commerce 6          (group j2commerce)
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
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

$group = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';

JLoader::registerNamespace(
    'Advans\Plugin\J2Commerce\ProductCompare',
    '/var/www/html/plugins/' . $group . '/productcompare/src',
    false,
    false,
    'psr4'
);

class RenderInjectionTest
{
    private $passed = 0;
    private $failed = 0;
    private string $group;

    public function __construct()
    {
        $this->group = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';
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
        echo "=== onAfterRender() HTML Injection Tests (group: {$this->group}) ===\n\n";

        // Joomla's Factory::getApplication() is a singleton: the first application
        // created in this process is cached and returned for every later call,
        // regardless of the requested client id. We therefore only use the site
        // application here; the administrator guard (isClient('administrator')) is
        // covered by the plugin-class test (04).
        $this->testInjectsBarAndModal();

        echo "\n=== Render Injection Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function makePlugin(): \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare
    {
        $params = new Registry([
            'max_products'   => 4,
            'show_in_list'   => 1,
            'show_in_detail' => 1,
        ]);

        // Pass the install group as $config['type'] so renderLayout() resolves the
        // real tmpl directory (plugins/{group}/productcompare/tmpl), exactly as the
        // installed plugin does at runtime.
        $plugin = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            new Dispatcher(),
            ['params' => $params, 'type' => $this->group, 'name' => 'productcompare']
        );

        $plugin->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));

        return $plugin;
    }

    private function makeHtmlDocument(): HtmlDocument
    {
        try {
            $doc = Factory::getContainer()->get(DocumentFactoryInterface::class)->createDocument('html');
            if ($doc instanceof HtmlDocument) {
                return $doc;
            }
        } catch (\Throwable $e) {
            // fall through to direct construction
        }

        return new HtmlDocument();
    }

    /**
     * Attach a real HTML document to the application via reflection. The CMS sets
     * its document through the protected loadDocument() path; in a CLI test we set
     * the protected `document` property so $app->getDocument() returns our HTML doc.
     */
    private function attachDocument(object $app, HtmlDocument $doc): void
    {
        $rp = new ReflectionProperty($app, 'document');
        $rp->setAccessible(true);
        $rp->setValue($app, $doc);
    }

    private function testInjectsBarAndModal(): void
    {
        echo "--- Frontend HTML body: bar + modal injected ---\n";

        try {
            $app = bootstrapSiteApplication();
        } catch (\Throwable $e) {
            $this->test('Site application available for render proof', false, $e->getMessage());
            return;
        }

        $doc = $this->makeHtmlDocument();
        $this->attachDocument($app, $doc);
        $this->test('Document type is html', $app->getDocument()->getType() === 'html');

        $plugin = $this->makePlugin();
        if (method_exists($plugin, 'setApplication')) {
            $plugin->setApplication($app);
        }

        $original = '<!DOCTYPE html><html><head><title>T</title></head>'
            . '<body><main><p>Storefront content</p></main></body></html>';
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

        $this->test('Body still well-formed (contains </body>)', strpos($body, '</body>') !== false);
        $this->test('Original storefront content preserved',
            strpos($body, 'Storefront content') !== false);

        // The bar layout (tmpl/bar.php) renders the fixed compare bar container.
        $this->test('Compare BAR markup injected (#j2store-compare-bar)',
            strpos($body, 'id="j2store-compare-bar"') !== false,
            'Compare bar layout was not injected into the body');

        // The modal layout (tmpl/modal.php) renders the comparison modal.
        $this->test('Compare MODAL markup injected (#j2store-compare-modal)',
            strpos($body, 'id="j2store-compare-modal"') !== false,
            'Compare modal layout was not injected into the body');

        // Injection must happen before </body>, not appended after the closing tag.
        $barPos  = strpos($body, 'id="j2store-compare-bar"');
        $bodyEnd = strrpos($body, '</body>');
        $this->test('Injected markup sits before </body>',
            $barPos !== false && $bodyEnd !== false && $barPos < $bodyEnd);

        // Body must have grown by the injected layouts.
        $this->test('Body length increased after injection',
            strlen($body) > strlen($original));
    }
}

$test = new RenderInjectionTest();
exit($test->run() ? 0 : 1);
