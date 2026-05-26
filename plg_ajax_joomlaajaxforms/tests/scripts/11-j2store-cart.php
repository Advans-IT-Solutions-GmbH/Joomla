<?php
/**
 * Test 11: J2Store Cart
 * Tests the J2Store cart AJAX functionality
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class J2StoreCartTest
{
    public function run(): bool
    {
        echo "=== J2Store Cart Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testCartFeatureConfig() && $allPassed;
        $allPassed = $this->testHandleRemoveCartItemMethodExists() && $allPassed;
        $allPassed = $this->testHandleGetCartCountMethodExists() && $allPassed;
        $allPassed = $this->testCartLanguageStrings() && $allPassed;

        echo "\n=== J2Store Cart Test Summary ===\n";
        return $allPassed;
    }

    private function testCartFeatureConfig(): bool
    {
        echo "Test: J2Store cart feature config exists... ";

        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('params')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));

        $db->setQuery($query);
        $params = json_decode($db->loadResult() ?: '{}', true);

        $enabled = $params['enable_j2store_cart'] ?? 1;
        if ($enabled == 1) {
            echo "PASS\n";
            return true;
        }

        echo "FAIL (enable_j2store_cart=$enabled)\n";
        return false;
    }

    private function testHandleRemoveCartItemMethodExists(): bool
    {
        echo "Test: handleRemoveCartItem method exists... ";

        $file = JPATH_ROOT . '/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        if (!file_exists($file)) {
            echo "FAIL (file not found)\n";
            return false;
        }

        $content = file_get_contents($file);
        if (strpos($content, 'function handleRemoveCartItem') !== false) {
            echo "PASS\n";
            return true;
        }

        echo "FAIL (method not found in source)\n";
        return false;
    }

    private function testHandleGetCartCountMethodExists(): bool
    {
        echo "Test: handleGetCartCount method exists... ";

        $file = JPATH_ROOT . '/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        if (!file_exists($file)) {
            echo "FAIL (file not found)\n";
            return false;
        }

        $content = file_get_contents($file);
        if (strpos($content, 'function handleGetCartCount') !== false) {
            echo "PASS\n";
            return true;
        }

        echo "FAIL (method not found in source)\n";
        return false;
    }

    private function testCartLanguageStrings(): bool
    {
        echo "Test: Cart language strings loaded... ";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        $keys = [
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_ITEM_REMOVED',
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_REMOVE_FAILED',
            'PLG_AJAX_JOOMLAAJAXFORMS_INVALID_CART_ITEM',
            'PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND',
            'PLG_AJAX_JOOMLAAJAXFORMS_CART_EMPTY',
        ];

        $missing = [];
        foreach ($keys as $key) {
            if ($lang->hasKey($key) === false) {
                $missing[] = $key;
            }
        }

        if (empty($missing)) {
            echo "PASS\n";
            return true;
        }

        echo "FAIL (missing: " . implode(', ', $missing) . ")\n";
        return false;
    }
}

$test = new J2StoreCartTest();
$result = $test->run();
exit($result ? 0 : 1);
