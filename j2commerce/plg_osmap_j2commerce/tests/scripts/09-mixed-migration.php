<?php
/**
 * Mixed migration state (J2Store and J2Commerce 6 installed at the same time).
 *
 * Documented behaviour (README, "Migrating from J2Store to J2Commerce"):
 *   - While com_j2store and com_j2commerce are both enabled, the plugin serves
 *     com_j2store; OSMap does not hand com_j2commerce menu items to it.
 *   - After com_j2store is disabled, the plugin serves com_j2commerce.
 *   - Rendering a menu item of the component whose tables are missing does not
 *     throw (the plugin logs and skips instead of breaking the sitemap).
 *
 * The component that is not installed on this stack is registered as a
 * temporary #__extensions row. All rows and states are restored at the end.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

require_once __DIR__ . '/_osmap_bootstrap.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

osmap_ensure_classes();

spl_autoload_register(function (string $class): void {
    $prefix = 'Advans\\Plugin\\Osmap\\J2Commerce\\';
    $base   = JPATH_PLUGINS . '/osmap/j2commerce/src/';
    if (str_starts_with($class, $prefix)) {
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

require_once JPATH_PLUGINS . '/osmap/j2commerce/j2commerce.php';

class MixedCollector extends \Alledia\OSMap\Sitemap\Collector
{
    public array $nodes = [];
    public function __construct() {}
    public function printNode($node): bool
    {
        $this->nodes[] = (object) $node;
        return true;
    }
}

class MixedMigrationTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;

    /** @var array<string, array{id:int, enabled:int, created:bool}> */
    private array $components = [];

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "PASS $name\n";
                $this->passed++;
            } else {
                echo "FAIL $name\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "FAIL $name — " . get_class($e) . ': ' . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    private function plugin(): \PlgOsmapJ2commerce
    {
        $plugin = new \PlgOsmapJ2commerce(['params' => new Registry([])]);
        $plugin->setDatabase($this->db);

        return $plugin;
    }

    private function ensureComponentsEnabled(): void
    {
        foreach (['com_j2store', 'com_j2commerce'] as $element) {
            $row = $this->db->setQuery(
                $this->query()
                    ->select([$this->db->quoteName('extension_id'), $this->db->quoteName('enabled')])
                    ->from($this->db->quoteName('#__extensions'))
                    ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
                    ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
            )->loadObject();

            if ($row) {
                $this->components[$element] = ['id' => (int) $row->extension_id, 'enabled' => (int) $row->enabled, 'created' => false];
                $this->setEnabled($element, 1);
                continue;
            }

            $extension = (object) [
                'name'           => $element,
                'type'           => 'component',
                'element'        => $element,
                'folder'         => '',
                'client_id'      => 1,
                'enabled'        => 1,
                'access'         => 1,
                'protected'      => 0,
                'locked'         => 0,
                'manifest_cache' => '{}',
                'params'         => '{}',
                'custom_data'    => '',
                'ordering'       => 0,
                'state'          => 0,
                'note'           => 'temporary row of 09-mixed-migration.php',
            ];
            $this->db->insertObject('#__extensions', $extension, 'extension_id');
            $this->components[$element] = ['id' => (int) $extension->extension_id, 'enabled' => 0, 'created' => true];
            echo "INFO registered temporary $element row #{$extension->extension_id}\n";
        }
    }

    private function setEnabled(string $element, int $enabled): void
    {
        $this->db->setQuery(
            $this->query()
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('enabled') . ' = ' . $enabled)
                ->where($this->db->quoteName('extension_id') . ' = ' . $this->components[$element]['id'])
        )->execute();
    }

    private function restore(): void
    {
        foreach ($this->components as $element => $info) {
            if ($info['created']) {
                $this->db->setQuery(
                    $this->query()
                        ->delete($this->db->quoteName('#__extensions'))
                        ->where($this->db->quoteName('extension_id') . ' = ' . $info['id'])
                )->execute();
                continue;
            }

            $this->setEnabled($element, $info['enabled']);
        }
    }

    public function run(): bool
    {
        echo "=== Mixed Migration State Tests ===\n\n";

        try {
            $this->ensureComponentsEnabled();

            $this->test('both components enabled → plugin serves com_j2store', function () {
                return $this->plugin()->getComponentElement() === 'com_j2store';
            });

            $this->test('rendering a com_j2commerce item in the mixed state does not throw', function () {
                $parent            = osmap_make_item([]);
                $parent->id        = 9001;
                $parent->path      = 'shop';
                $parent->component = 'com_j2commerce';
                $parent->link      = 'index.php?option=com_j2commerce&view=products';
                $this->plugin()->getTree(new MixedCollector(), $parent, new Registry([]));
                return true;
            });

            $this->setEnabled('com_j2store', 0);

            $this->test('com_j2store disabled → plugin serves com_j2commerce', function () {
                return $this->plugin()->getComponentElement() === 'com_j2commerce';
            });

            $this->test('rendering a com_j2store item after disabling it does not throw', function () {
                $parent            = osmap_make_item([]);
                $parent->id        = 9001;
                $parent->path      = 'shop';
                $parent->component = 'com_j2store';
                $parent->link      = 'index.php?option=com_j2store&view=products';
                $this->plugin()->getTree(new MixedCollector(), $parent, new Registry([]));
                return true;
            });
        } finally {
            $this->restore();
        }

        $this->test('component rows and states restored', function () {
            foreach ($this->components as $element => $info) {
                $row = $this->db->setQuery(
                    $this->query()
                        ->select($this->db->quoteName('enabled'))
                        ->from($this->db->quoteName('#__extensions'))
                        ->where($this->db->quoteName('extension_id') . ' = ' . $info['id'])
                )->loadResult();

                if ($info['created'] ? $row !== null : (int) $row !== $info['enabled']) {
                    return false;
                }
            }

            return true;
        });

        echo "\n=== Mixed Migration State Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new MixedMigrationTest();
exit($test->run() ? 0 : 1);
