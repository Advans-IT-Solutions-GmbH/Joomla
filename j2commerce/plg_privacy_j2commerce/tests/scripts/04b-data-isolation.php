<?php
/**
 * Data Isolation Tests
 *
 * Verifies that data-deletion operations are scoped to the target user only:
 * - deleteCartData() must not touch another user's carts/cartitems
 * - deleteUserAddress() must reject deleting another user's address (IDOR guard)
 * - checkRetentionPeriod() return structure is correct for both can_delete states
 *
 * Covers issues #97 and #105 (J6 branch coverage).
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class DataIsolationTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private string $tp; // 'j2store' or 'j2commerce'

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->tp = (getenv('J2COMMERCE_STACK') === 'j6') ? 'j2commerce' : 'j2store';
    }

    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery')
            ? $this->db->createQuery()
            : $this->db->getQuery(true);
    }

    private function test(string $name, bool $ok, string $msg = ''): void
    {
        if ($ok) {
            echo "PASS $name\n";
            $this->passed++;
        } else {
            echo "FAIL $name" . ($msg ? " - $msg" : '') . "\n";
            $this->failed++;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * deleteCartData() must only delete the target user's carts and cartitems,
     * leaving another user's data untouched.
     */
    private function testDeleteCartDataIsolation(): void
    {
        echo "\n--- deleteCartData() isolation ---\n";

        $cartPkCol     = $this->tp . '_cart_id';
        $cartitemPkCol = $this->tp . '_cartitem_id';
        $targetUser    = 9901;
        $bystander     = 9902;

        // Seed: one cart + item for target user, one cart + item for bystander
        $seedCart = function (int $userId) use ($cartPkCol, $cartitemPkCol): array {
            $cart = (object)[
                'user_id'    => $userId,
                'session_id' => 'iso-test-' . $userId . '-' . uniqid(),
                'cart_type'  => 'cart',
                'created_on' => date('Y-m-d H:i:s'),
                'modified_on' => date('Y-m-d H:i:s'),
                'customer_ip' => '127.0.0.1',
                'cart_params' => '{}',
                'cart_browser' => '{}',
                'cart_analytics' => '{}',
            ];
            $this->db->insertObject('#__' . $this->tp . '_carts', $cart, $cartPkCol);
            $cartId = (int) $this->db->insertid();

            $item = (object)[
                'cart_id'     => $cartId,
                'product_id'  => 1,
                'variant_id'  => 1,
                'vendor_id'   => 0,
                'product_type' => 'simple',
                'cartitem_params' => '{}',
                'product_options' => '[]',
                'product_qty' => 1.0,
            ];
            $this->db->insertObject('#__' . $this->tp . '_cartitems', $item, $cartitemPkCol);
            $itemId = (int) $this->db->insertid();

            return [$cartId, $itemId];
        };

        [$targetCartId, $targetItemId] = $seedCart($targetUser);
        [$bystanderCartId, $bystanderItemId] = $seedCart($bystander);

        $this->test('Seeded target cart', $targetCartId > 0);
        $this->test('Seeded bystander cart', $bystanderCartId > 0);

        // Load plugin and call deleteCartData() for target user only
        JLoader::registerNamespace(
            'Advans\\Plugin\\Privacy\\J2Commerce',
            JPATH_BASE . '/plugins/privacy/j2commerce/src',
            false, false, 'psr4'
        );

        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false, $pluginClass);
            // Cleanup
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_cartitems') . ' WHERE cart_id IN (' . $targetCartId . ',' . $bystanderCartId . ')')->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE user_id IN (' . $targetUser . ',' . $bystander . ')')->execute();
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        $method = $rc->getMethod('deleteCartData');
        $method->setAccessible(true);
        $method->invoke($instance, $targetUser);

        // Target user's data must be gone
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->db->quoteName('user_id') . ' = ' . $targetUser);
        $this->test(
            'Target user carts deleted',
            (int) $this->db->setQuery($q)->loadResult() === 0
        );

        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where($this->db->quoteName('cart_id') . ' = ' . $targetCartId);
        $this->test(
            'Target user cart items deleted',
            (int) $this->db->setQuery($q)->loadResult() === 0
        );

        // Bystander's data must be untouched
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->db->quoteName('user_id') . ' = ' . $bystander);
        $this->test(
            'Bystander carts untouched',
            (int) $this->db->setQuery($q)->loadResult() === 1,
            'deleteCartData() must not delete other users\' carts'
        );

        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where($this->db->quoteName('cart_id') . ' = ' . $bystanderCartId);
        $this->test(
            'Bystander cart items untouched',
            (int) $this->db->setQuery($q)->loadResult() === 1,
            'deleteCartData() must not delete other users\' cart items'
        );

        // Cleanup bystander
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_cartitems') . ' WHERE cart_id = ' . $bystanderCartId)->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE ' . $this->tp . '_cart_id = ' . $bystanderCartId)->execute();
    }

    /**
     * deleteUserAddress() must reject deleting an address that belongs to a
     * different user (IDOR guard: atomic DELETE with user_id check).
     */
    private function testDeleteUserAddressIdorGuard(): void
    {
        echo "\n--- deleteUserAddress() IDOR guard ---\n";

        $addrPkCol = $this->tp . '_address_id';
        $owner     = 9903;
        $attacker  = 9904;

        // Insert an address owned by $owner
        $addr = (object)[
            'user_id'    => $owner,
            'first_name' => 'Owner',
            'last_name'  => 'User',
            'address_1'  => 'Private Street 1',
            'city'       => 'Zürich',
            'zip'        => '8001',
            'email'      => 'owner@example.com',
            'type'       => 'billing',
        ];
        $this->db->insertObject('#__' . $this->tp . '_addresses', $addr, $addrPkCol);
        $addrId = (int) $this->db->insertid();
        $this->test('Owner address seeded', $addrId > 0);

        // Load plugin
        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass)) {
            JLoader::registerNamespace(
                'Advans\\Plugin\\Privacy\\J2Commerce',
                JPATH_BASE . '/plugins/privacy/j2commerce/src',
                false, false, 'psr4'
            );
        }

        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false);
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $addrPkCol . ' = ' . $addrId)->execute();
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        $method = $rc->getMethod('deleteUserAddress');
        $method->setAccessible(true);

        // Attacker tries to delete owner's address
        $result = $method->invoke($instance, $addrId, $attacker);

        $this->test(
            'deleteUserAddress() rejects cross-user delete',
            isset($result['success']) && $result['success'] === false,
            'Expected success:false, got: ' . json_encode($result)
        );

        // Address must still exist after the rejected IDOR attempt
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_addresses'))
            ->where($this->db->quoteName($addrPkCol) . ' = ' . $addrId);
        $this->test(
            'Address still exists after rejected IDOR attempt',
            (int) $this->db->setQuery($q)->loadResult() === 1
        );

        // Cleanup: delete the address directly (owner-delete path calls getApplication()
        // which requires a full Joomla app context not available in CLI test scripts)
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') .
            ' WHERE ' . $this->db->quoteName($addrPkCol) . ' = ' . $addrId
        )->execute();
    }

    /**
     * checkRetentionPeriod() must return the correct structure and can_delete
     * value based on order age vs. retention window.
     */
    private function testCheckRetentionPeriod(): void
    {
        echo "\n--- checkRetentionPeriod() return structure ---\n";

        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass)) {
            JLoader::registerNamespace(
                'Advans\\Plugin\\Privacy\\J2Commerce',
                JPATH_BASE . '/plugins/privacy/j2commerce/src',
                false, false, 'psr4'
            );
        }
        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false);
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        // Inject a Registry params object with retention_years = 10
        $paramsProperty = $rc->getProperty('params');
        $paramsProperty->setAccessible(true);
        $params = new \Joomla\Registry\Registry(['retention_years' => 10]);
        $paramsProperty->setValue($instance, $params);

        $method = $rc->getMethod('checkRetentionPeriod');
        $method->setAccessible(true);

        // User 100 has fixture orders (set up by post-install-fixtures.sh):
        // one recent order (within 10 years) → can_delete = false
        $result = $method->invoke($instance, 100);

        $this->test(
            'checkRetentionPeriod() returns array',
            is_array($result)
        );
        $this->test(
            'Result has can_delete key',
            array_key_exists('can_delete', $result)
        );
        $this->test(
            'Result has retention_years key',
            array_key_exists('retention_years', $result),
            'Got keys: ' . implode(', ', array_keys($result))
        );
        $this->test(
            'Result has orders key',
            array_key_exists('orders', $result)
        );
        $this->test(
            'retention_years matches configured value',
            (int) ($result['retention_years'] ?? -1) === 10,
            'Got: ' . ($result['retention_years'] ?? 'missing')
        );

        // User 100 has a recent order → can_delete must be false
        $this->test(
            'User with recent order: can_delete = false',
            $result['can_delete'] === false,
            'Expected false (recent order within retention window), got: ' . var_export($result['can_delete'], true)
        );

        // User 9999 (no orders) → can_delete must be true
        $result2 = $method->invoke($instance, 9999);
        $this->test(
            'User with no orders: can_delete = true',
            $result2['can_delete'] === true,
            'Got: ' . var_export($result2['can_delete'], true)
        );
    }

    // -------------------------------------------------------------------------

    public function run(): bool
    {
        echo "=== Data Isolation Tests ===\n";
        echo "Stack: " . strtoupper($this->tp) . "\n";

        $this->testDeleteCartDataIsolation();
        $this->testDeleteUserAddressIdorGuard();
        $this->testCheckRetentionPeriod();

        echo "\n=== Data Isolation Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new DataIsolationTest();
exit($test->run() ? 0 : 1);
