<?php
/**
 * Plugin Class Tests for J2Commerce Product Compare Plugin
 *
 * Instantiates the plugin class and calls methods rather than checking
 * for method names with strpos().
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class PluginClassTest
{
    private $passed = 0;
    private $failed = 0;
    private $classFile = '/var/www/html/plugins/j2store/productcompare/src/Extension/ProductCompare.php';

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
        echo "=== Plugin Class Tests ===\n\n";

        // --- File ---
        echo "--- File ---\n";
        $this->test('Class file exists', file_exists($this->classFile));

        if (!file_exists($this->classFile)) {
            echo "Cannot continue — class file missing\n";
            return false;
        }

        // --- Autoloader: class must be loadable ---
        echo "\n--- Class loading ---\n";
        // Joomla's autoloader only registers plugin namespaces when the plugin
        // is loaded via PluginHelper::importPlugin(). Register the namespace
        // manually so the class can be resolved without a full plugin bootstrap.
        JLoader::registerNamespace(
            'Advans\Plugin\J2Commerce\ProductCompare',
            '/var/www/html/plugins/j2store/productcompare/src',
            false,
            false,
            'psr4'
        );
        $loaded = class_exists('Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare', true);
        $this->test('ProductCompare class autoloads', $loaded);

        if (!$loaded) {
            echo "Cannot continue — class not loadable\n";
            return false;
        }

        $this->testReflection();
        $this->testInstantiation();

        echo "\n=== Plugin Class Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function testReflection(): void
    {
        echo "\n--- Method signatures (reflection) ---\n";

        $rc = new ReflectionClass('Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare');

        foreach ([
            'onAfterDispatch',
            'onAfterRender',
            'onJ2StoreAfterDisplayProduct',
            'onJ2StoreAfterDisplayProductList',
            'onJ2CommerceViewProductHtml',
            'onJ2CommerceViewProductListHtml',
            'onAjaxProductcompare',
        ] as $method) {
            $this->test("$method() exists", $rc->hasMethod($method));
        }

        // Private helpers must exist
        foreach (['renderLayout', 'renderCompareButton', 'getProductsData', 'getProductOptions'] as $m) {
            $this->test("$m() exists", $rc->hasMethod($m));
        }

        // Extends CMSPlugin
        $this->test('Extends CMSPlugin',
            $rc->isSubclassOf('Joomla\CMS\Plugin\CMSPlugin'));

        // autoloadLanguage = true
        $prop = $rc->getProperty('autoloadLanguage');
        $prop->setAccessible(true);
        $instance = $rc->newInstanceWithoutConstructor();
        $this->test('autoloadLanguage is true', (bool) $prop->getValue($instance) === true);
    }

    private function testInstantiation(): void
    {
        echo "\n--- Instantiation ---\n";

        $dispatcher = new \Joomla\Event\Dispatcher();
        $params     = new Registry(['max_products' => 4, 'show_in_list' => 1, 'show_in_detail' => 1]);

        try {
            $plugin = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
                $dispatcher,
                ['params' => $params]
            );
            $this->test('Plugin instantiates without error', true);
        } catch (\Throwable $e) {
            $this->test('Plugin instantiates without error', false, $e->getMessage());
            return;
        }

        // J4: onJ2StoreAfterDisplayProductList with show_in_list=0 → empty string
        $params0 = new Registry(['show_in_list' => 0, 'show_in_detail' => 0]);
        $plugin0 = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            $dispatcher,
            ['params' => $params0]
        );
        $product = (object)['j2store_product_id' => 1];
        $result  = $plugin0->onJ2StoreAfterDisplayProductList($product);
        $this->test('J4 show_in_list=0 → empty string', $result === '');

        $result2 = $plugin0->onJ2StoreAfterDisplayProduct($product, 'detail');
        $this->test('J4 show_in_detail=0 → empty string', $result2 === '');

        // J6: onJ2CommerceViewProductListHtml with show_in_list=0 → no result added
        $eventList = new \Joomla\Event\Event('onJ2CommerceViewProductListHtml', [
            (object)['j2commerce_product_id' => 1],
            'com_j2commerce.category',
        ]);
        $plugin0->onJ2CommerceViewProductListHtml($eventList);
        $this->test('J6 show_in_list=0 → no result added',
            count($eventList->getArgument('result', [])) === 0);

        // J6: onJ2CommerceViewProductHtml with show_in_detail=0 → no result added
        $eventDetail = new \Joomla\Event\Event('onJ2CommerceViewProductHtml', [
            (object)['j2commerce_product_id' => 1],
        ]);
        $plugin0->onJ2CommerceViewProductHtml($eventDetail);
        $this->test('J6 show_in_detail=0 → no result added',
            count($eventDetail->getArgument('result', [])) === 0);
    }
}

$test = new PluginClassTest();
$result = $test->run();
echo $result ? "✅ All plugin class tests passed\n" : "❌ Plugin class tests failed\n";
exit($result ? 0 : 1);
