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
            'onJ2CommerceAfterProductListItemDisplay',
            'onJ2CommerceAfterProductDisplay',
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

        // getSubscribedEvents() returns the correct J6 per-item hooks
        $events = \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare::getSubscribedEvents();
        $this->test('getSubscribedEvents() contains AfterProductListItemDisplay hook',
            isset($events['onJ2CommerceAfterProductListItemDisplay']));
        $this->test('getSubscribedEvents() contains AfterProductDisplay hook',
            isset($events['onJ2CommerceAfterProductDisplay']));
        $this->test('getSubscribedEvents() does not contain wrong ViewProductListHtml event',
            !isset($events['onJ2CommerceViewProductListHtml']));
        $this->test('getSubscribedEvents() does not contain wrong ViewProductHtml event',
            !isset($events['onJ2CommerceViewProductHtml']));

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

        // J6: onJ2CommerceAfterProductListItemDisplay with show_in_list=0 → no result added
        $eventList = new \Joomla\Event\Event('onJ2CommerceAfterProductListItemDisplay', [
            (object)['j2commerce_product_id' => 1],
            'com_j2commerce.category',
        ]);
        $plugin0->onJ2CommerceAfterProductListItemDisplay($eventList);
        $this->test('J6 show_in_list=0 → no result added',
            count($eventList->getArgument('result', [])) === 0);

        // J6: onJ2CommerceAfterProductDisplay with show_in_detail=0 → no result added
        $eventDetail = new \Joomla\Event\Event('onJ2CommerceAfterProductDisplay', [
            (object)['j2commerce_product_id' => 1],
        ]);
        $plugin0->onJ2CommerceAfterProductDisplay($eventDetail);
        $this->test('J6 show_in_detail=0 → no result added',
            count($eventDetail->getArgument('result', [])) === 0);

        // J6: AfterProductDisplay — product found regardless of argument position.
        // The handler scans all args for the first object with j2commerce_product_id,
        // so it works whether the product is at $args[0] (current public source) or
        // $args[2] ([$result, $view, $product] signature reported in review).
        $params1 = new Registry(['show_in_list' => 1, 'show_in_detail' => 1]);
        $plugin1 = new \Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare(
            $dispatcher,
            ['params' => $params1]
        );

        // Product at $args[0]
        $ev0 = new \Joomla\Event\Event('onJ2CommerceAfterProductDisplay', [
            (object)['j2commerce_product_id' => 42],
            (object)['view' => 'product'],
        ]);
        $plugin1->onJ2CommerceAfterProductDisplay($ev0);
        $this->test('AfterProductDisplay: product at args[0] → result added',
            count($ev0->getArgument('result', [])) > 0);

        // Product at $args[2] ([$result, $view, $product] signature)
        $ev2 = new \Joomla\Event\Event('onJ2CommerceAfterProductDisplay', [
            '',
            (object)['view' => 'product'],
            (object)['j2commerce_product_id' => 43],
        ]);
        $plugin1->onJ2CommerceAfterProductDisplay($ev2);
        $this->test('AfterProductDisplay: product at args[2] → result added',
            count($ev2->getArgument('result', [])) > 0);
    }
}

$test = new PluginClassTest();
$result = $test->run();
echo $result ? "✅ All plugin class tests passed\n" : "❌ Plugin class tests failed\n";
exit($result ? 0 : 1);
