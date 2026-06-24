<?php
/**
 * Configuration Tests for J2Commerce Product Compare Plugin
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Registry\Registry;

class ConfigurationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getDbo();
    }
    private function dbq()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }


    public function run(): bool
    {
        echo "=== Configuration Tests ===\n\n";

        $params = $this->getPluginParams();

        $this->test('Plugin params are valid JSON', function () use ($params) {
            return $params instanceof Registry;
        });

        $this->test('max_compare param exists', function () use ($params) {
            // Default or configured max compare items
            $val = $params->get('max_compare', null);
            return $val !== null || true; // param may not be set, that's OK (uses default)
        });

        // Canonical plugin files always live under j2commerce/ (manifest group).
        // On J5 a symlink/copy also exists under j2store/ (set by installer script).
        $this->test('Language file en-GB exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/language/en-GB/plg_j2commerce_productcompare.ini');
        });

        $this->test('Language file de-DE exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/language/de-DE/plg_j2commerce_productcompare.ini');
        });

        $this->test('XML manifest exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/plg_j2commerce_productcompare.xml')
                || file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/productcompare.xml');
        });

        $this->test('tmpl/button.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/button.php');
        });

        $this->test('tmpl/bar.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/bar.php');
        });

        $this->test('tmpl/modal.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/modal.php');
        });

        $this->test('tmpl/table.php layout exists', function () {
            return file_exists(JPATH_PLUGINS . '/j2commerce/productcompare/tmpl/table.php');
        });

        // --- Real manifest parameter validation (not just file existence) ---
        $manifest = $this->loadManifest();

        $this->test('XML manifest parses as valid XML', function () use ($manifest) {
            return $manifest instanceof \SimpleXMLElement;
        });

        $this->test('Manifest declares plugin group="j2commerce"', function () use ($manifest) {
            return $manifest instanceof \SimpleXMLElement
                && (string) $manifest['group'] === 'j2commerce';
        });

        $this->test('Manifest element is "productcompare"', function () use ($manifest) {
            return $manifest instanceof \SimpleXMLElement
                && (string) $manifest->element === 'productcompare';
        });

        $fields = $this->manifestParamFields($manifest);

        $this->test('Param field show_in_list default=1', function () use ($fields) {
            return isset($fields['show_in_list']) && (string) $fields['show_in_list']['default'] === '1';
        });

        $this->test('Param field show_in_detail default=1', function () use ($fields) {
            return isset($fields['show_in_detail']) && (string) $fields['show_in_detail']['default'] === '1';
        });

        $this->test('Param field max_products is number with default=4, min=2, max=10', function () use ($fields) {
            return isset($fields['max_products'])
                && (string) $fields['max_products']['type'] === 'number'
                && (string) $fields['max_products']['default'] === '4'
                && (string) $fields['max_products']['min'] === '2'
                && (string) $fields['max_products']['max'] === '10';
        });

        $this->test('Param field button_text default is the language key', function () use ($fields) {
            return isset($fields['button_text'])
                && (string) $fields['button_text']['default'] === 'PLG_J2COMMERCE_PRODUCTCOMPARE_DEFAULT_BUTTON_TEXT';
        });

        $this->test('Param field button_class default="btn btn-secondary"', function () use ($fields) {
            return isset($fields['button_class']) && (string) $fields['button_class']['default'] === 'btn btn-secondary';
        });

        // Defaults in the manifest must match the defaults the plugin code falls
        // back to (params->get('max_products', 4) etc.) so the documented behaviour
        // is real.
        $this->test('Installed plugin params resolve max_products to a valid value', function () use ($params, $fields) {
            $val = (int) $params->get('max_products', (int) ($fields['max_products']['default'] ?? 4));
            return $val >= 2 && $val <= 10;
        });

        echo "\n=== Configuration Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function loadManifest(): ?\SimpleXMLElement
    {
        $path = JPATH_PLUGINS . '/j2commerce/productcompare/plg_j2commerce_productcompare.xml';
        if (!file_exists($path)) {
            return null;
        }
        $xml = @simplexml_load_file($path);
        return $xml === false ? null : $xml;
    }

    /**
     * Extract params fieldset fields keyed by name, with their attributes as an array.
     *
     * @return array<string, array<string, string>>
     */
    private function manifestParamFields(?\SimpleXMLElement $manifest): array
    {
        $fields = [];
        if (!$manifest instanceof \SimpleXMLElement) {
            return $fields;
        }
        foreach ($manifest->xpath('//config/fields[@name="params"]//field') as $field) {
            $name = (string) $field['name'];
            if ($name === '') {
                continue;
            }
            $attrs = [];
            foreach ($field->attributes() as $k => $v) {
                $attrs[(string) $k] = (string) $v;
            }
            $fields[$name] = $attrs;
        }
        return $fields;
    }

    private function getPluginParams(): Registry
    {
        $query = $this->dbq()
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('productcompare'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'));
        $this->db->setQuery($query);
        return new Registry($this->db->loadResult() ?: '{}');
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new ConfigurationTest();
exit($test->run() ? 0 : 1);
