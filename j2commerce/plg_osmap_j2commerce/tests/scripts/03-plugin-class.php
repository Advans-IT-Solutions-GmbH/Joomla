<?php
/**
 * Plugin Class Tests for OSMap J2Commerce Plugin
 *
 * Verifies the plugin class loads correctly, extends the right base,
 * and returns the expected component element.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
// Bootstrap DB connection (sufficient for CLI; getApplication('site') fails in CLI context)
Factory::getDbo();

use Alledia\OSMap\Plugin\Base;

class PluginClassTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): bool
    {
        echo "=== Plugin Class Tests ===\n\n";

        $this->test('Plugin file is loadable', function () {
            require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';
            return class_exists('PlgOsmapJ2commerce');
        });

        $this->test('Plugin extends Alledia\\OSMap\\Plugin\\Base', function () {
            return is_subclass_of('PlgOsmapJ2commerce', Base::class);
        });

        $this->test('getComponentElement() returns com_j2store', function () {
            $dispatcher = new \Joomla\Event\Dispatcher();
            $plugin = new PlgOsmapJ2commerce($dispatcher, [
                'name'   => 'j2commerce',
                'type'   => 'osmap',
                'params' => '{}',
            ]);
            return $plugin->getComponentElement() === 'com_j2store';
        });

        $this->test('getTree() method exists with correct signature', function () {
            $ref    = new ReflectionMethod('PlgOsmapJ2commerce', 'getTree');
            $params = $ref->getParameters();
            return count($params) >= 3
                && $params[0]->getType()?->getName() === 'Alledia\OSMap\Sitemap\Collector'
                && $params[1]->getType()?->getName() === 'Alledia\OSMap\Sitemap\Item'
                && $params[2]->getType()?->getName() === 'Joomla\Registry\Registry';
        });

        echo "\n=== Plugin Class Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new PluginClassTest();
exit($test->run() ? 0 : 1);
