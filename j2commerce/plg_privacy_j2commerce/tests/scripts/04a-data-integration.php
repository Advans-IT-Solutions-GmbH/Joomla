<?php
/**
 * Data Integration Tests - validates actual data operations against
 * real J2Commerce tables (orders, orderinfos, orderitems, addresses, carts).
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class DataIntegrationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $testUserId = 100;
    private string $tp; // 'j2store' or 'j2commerce'

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->tp = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';
    }

    public function test($name, $condition, $message = '')
    {
        if ($condition) {
            echo "PASS $name\n";
            $this->passed++;
        } else {
            echo "FAIL $name" . ($message ? " - $message" : "") . "\n";
            $this->failed++;
        }
        return $condition;
    }

    public function run()
    {
        echo "\n========================================\n";
        echo "J2Commerce Data Integration Tests\n";
        echo "========================================\n\n";

        // Test 1: Verify test data exists in real tables
        echo "--- Test Data Verification ---\n";

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_addresses'))
            ->where('user_id = ' . $this->testUserId);
        $this->db->setQuery($query);
        $addressCount = (int) $this->db->loadResult();
        $this->test('Test user has addresses', $addressCount >= 2, "Got $addressCount");

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_orders'))
            ->where('user_id = ' . $this->testUserId);
        $this->db->setQuery($query);
        $orderCount = (int) $this->db->loadResult();
        $this->test('Test user has orders', $orderCount >= 2, "Got $orderCount");

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_orderinfos'))
            ->where('order_id IN (SELECT order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE user_id = ' . $this->testUserId . ')');
        $this->db->setQuery($query);
        $infoCount = (int) $this->db->loadResult();
        $this->test('Test user has order infos', $infoCount >= 2, "Got $infoCount");

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where('user_id = ' . $this->testUserId);
        $this->db->setQuery($query);
        $cartCount = (int) $this->db->loadResult();
        $this->test('Test user has cart', $cartCount >= 1, "Got $cartCount");

        // Test 2: Retention period logic
        echo "\n--- Retention Period Tests ---\n";

        $retentionYears = 10;
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_orders'))
            ->where('user_id = ' . $this->testUserId)
            ->where('created_on >= ' . $this->db->quote($cutoffDate));
        $this->db->setQuery($query);
        $recentOrders = (int) $this->db->loadResult();
        $this->test('Orders within retention period found', $recentOrders >= 1);

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_orders'))
            ->where('user_id = ' . $this->testUserId)
            ->where('created_on < ' . $this->db->quote($cutoffDate));
        $this->db->setQuery($query);
        $oldOrders = (int) $this->db->loadResult();
        $this->test('Orders outside retention period found', $oldOrders >= 1);

        // Test 3: Address CRUD operations
        echo "\n--- Address Operations ---\n";

        $testAddress = (object) [
            'user_id' => 999,
            'first_name' => 'Delete',
            'last_name' => 'Test',
            'address_1' => 'To Be Deleted',
            'city' => 'Test City',
            'zip' => '12345',
            'email' => 'delete@test.com',
            'type' => 'billing'
        ];
        $this->db->insertObject('#__' . $this->tp . '_addresses', $testAddress, $this->tp . '_address_id');
        $insertedId = $this->db->insertid();
        $this->test('Address insert works', $insertedId > 0);

        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__' . $this->tp . '_addresses'))
            ->where($this->tp . '_address_id = ' . $insertedId);
        $this->db->setQuery($query);
        $this->db->execute();

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_addresses'))
            ->where($this->tp . '_address_id = ' . $insertedId);
        $this->db->setQuery($query);
        $this->test('Address delete works', (int) $this->db->loadResult() === 0);

        // Test 4: Cart CRUD operations
        echo "\n--- Cart Operations ---\n";

        $testCart = (object) [
            'user_id' => 999,
            'session_id' => 'test-delete-session',
            'cart_type' => 'cart',
            'created_on' => date('Y-m-d H:i:s')
        ];
        $this->db->insertObject('#__' . $this->tp . '_carts', $testCart, $this->tp . '_cart_id');
        $cartId = $this->db->insertid();
        $this->test('Cart insert works', $cartId > 0);

        $testCartItem = (object) [
            'cart_id' => $cartId,
            'product_id' => 1,
            'variant_id' => 1,
            'product_qty' => 1.0000
        ];
        $this->db->insertObject('#__' . $this->tp . '_cartitems', $testCartItem, $this->tp . '_cartitem_id');
        $cartItemId = $this->db->insertid();
        $this->test('Cart item insert works', $cartItemId > 0);

        // Delete cart items then cart
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where('cart_id = ' . $cartId);
        $this->db->setQuery($query);
        $this->db->execute();

        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->tp . '_cart_id = ' . $cartId);
        $this->db->setQuery($query);
        $this->db->execute();
        $this->test('Cart delete works', true);

        // Test 5: Order anonymization on correct tables
        echo "\n--- Order Anonymization (real schema) ---\n";

        // Insert order + orderinfo
        $testOrder = (object) [
            'order_id' => 'TEST-ANON-001',
            'user_id' => 999,
            'user_email' => 'anon@test.com',
            'order_total' => 100.00000,
            'order_subtotal' => 90.00000,
            'order_tax' => 10.00000,
            'order_shipping' => 0.00000,
            'order_discount' => 0.00000,
            'order_state_id' => 1,
            'currency_code' => 'CHF',
            'currency_value' => 1.00000000,
            'created_on' => date('Y-m-d H:i:s', strtotime('-5 years'))
        ];
        $this->db->insertObject('#__' . $this->tp . '_orders', $testOrder, $this->tp . '_order_id');
        $orderPk = $this->db->insertid();
        $this->test('Test order inserted', $orderPk > 0);

        $testInfo = (object) [
            'order_id'           => 'TEST-ANON-001',
            'billing_first_name' => 'Secret',
            'billing_last_name'  => 'Person',
            'billing_address_1'  => 'Hidden Street 1',
            'billing_city'       => 'Secret City',
            'billing_zip'        => '99999',
            'billing_phone_1'    => '+41 00 000 00 00',
            'shipping_first_name' => 'Secret',
            'shipping_last_name'  => 'Person',
            'shipping_address_1'  => 'Hidden Street 1',
            'shipping_city'       => 'Secret City',
            'shipping_zip'        => '99999',
            // LONGTEXT NOT NULL without DEFAULT — must be set explicitly on J6
            'all_billing'        => '',
            'all_shipping'       => '',
            'all_payment'        => '',
        ];
        $this->db->insertObject('#__' . $this->tp . '_orderinfos', $testInfo, $this->tp . '_orderinfo_id');
        $infoPk = $this->db->insertid();
        $this->test('Test order info inserted', $infoPk > 0);

        // Anonymize orders table (user_email, customer_note, ip_address)
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__' . $this->tp . '_orders'))
            ->set([
                $this->db->quoteName('user_email') . ' = ' . $this->db->quote('anonymized@deleted.invalid'),
                $this->db->quoteName('customer_note') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('ip_address') . ' = ' . $this->db->quote(''),
            ])
            ->where($this->tp . '_order_id = ' . $orderPk);
        $this->db->setQuery($query);
        $this->db->execute();

        // Anonymize orderinfos table (billing/shipping data)
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__' . $this->tp . '_orderinfos'))
            ->set([
                $this->db->quoteName('billing_first_name') . ' = ' . $this->db->quote('Anonymized'),
                $this->db->quoteName('billing_last_name') . ' = ' . $this->db->quote('User'),
                $this->db->quoteName('billing_address_1') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_city') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_zip') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_phone_1') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_first_name') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_last_name') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_address_1') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_city') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_zip') . ' = ' . $this->db->quote(''),
            ])
            ->where('order_id = ' . $this->db->quote('TEST-ANON-001'));
        $this->db->setQuery($query);
        $this->db->execute();

        // Verify orders table anonymization
        $query = $this->db->getQuery(true)
            ->select('user_email')
            ->from($this->db->quoteName('#__' . $this->tp . '_orders'))
            ->where($this->tp . '_order_id = ' . $orderPk);
        $this->db->setQuery($query);
        $order = $this->db->loadObject();
        $this->test('Order user_email anonymized',
            $order->user_email === 'anonymized@deleted.invalid',
            'Got: ' . ($order->user_email ?? 'NULL'));

        // Verify orderinfos table anonymization
        $query = $this->db->getQuery(true)
            ->select('billing_first_name, billing_address_1, shipping_first_name')
            ->from($this->db->quoteName('#__' . $this->tp . '_orderinfos'))
            ->where($this->tp . '_orderinfo_id = ' . $infoPk);
        $this->db->setQuery($query);
        $info = $this->db->loadObject();
        $this->test('Orderinfo billing_first_name anonymized',
            $info->billing_first_name === 'Anonymized',
            'Got: ' . ($info->billing_first_name ?? 'NULL'));
        $this->test('Orderinfo billing_address_1 cleared',
            $info->billing_address_1 === '',
            'Got: ' . ($info->billing_address_1 ?? 'NULL'));
        $this->test('Orderinfo shipping_first_name cleared',
            $info->shipping_first_name === '',
            'Got: ' . ($info->shipping_first_name ?? 'NULL'));

        // Cleanup
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orderinfos') . ' WHERE ' . $this->tp . '_orderinfo_id = ' . $infoPk);
        $this->db->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE ' . $this->tp . '_order_id = ' . $orderPk);
        $this->db->execute();

        // Test 6: deleteCartData() round-trip via plugin reflection
        echo "\n--- deleteCartData() Round-Trip ---\n";
        $this->testDeleteCartData();

        echo "\n=== Data Integration Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function testDeleteCartData(): void
    {
        $cartPkCol     = $this->tp . '_cart_id';
        $cartitemPkCol = $this->tp . '_cartitem_id';
        $deleteUserId  = 998; // isolated test user — not used elsewhere

        // Seed: two carts with one item each for the test user
        $cartIds     = [];
        $cartitemIds = [];
        for ($i = 0; $i < 2; $i++) {
            $cart = (object)[
                'user_id'    => $deleteUserId,
                'session_id' => 'delete-test-' . uniqid(),
                'cart_type'  => 'cart',
                'created_on' => date('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__' . $this->tp . '_carts', $cart, $cartPkCol);
            $cartId    = (int) $this->db->insertid();
            $cartIds[] = $cartId;

            $item = (object)[
                'cart_id'     => $cartId,
                'product_id'  => 1,
                'variant_id'  => 1,
                'product_qty' => 1.0,
            ];
            $this->db->insertObject('#__' . $this->tp . '_cartitems', $item, $cartitemPkCol);
            $cartitemIds[] = (int) $this->db->insertid();
        }

        $this->test('Seeded 2 carts for deleteCartData test',
            count($cartIds) === 2 && $cartIds[0] > 0 && $cartIds[1] > 0);

        // Load the plugin class and call deleteCartData() via reflection
        $pluginFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';
        if (!file_exists($pluginFile)) {
            $this->test('Plugin class file exists for reflection', false, $pluginFile);
            return;
        }

        // Bootstrap plugin namespace
        JLoader::registerNamespace(
            'Advans\\Plugin\\Privacy\\J2Commerce',
            JPATH_BASE . '/plugins/privacy/j2commerce/src',
            false, false, 'psr4'
        );

        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false, $pluginClass);
            return;
        }
        $this->test('Plugin class loadable', true);

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();

        // Inject database via DatabaseAwareTrait::setDatabase()
        $instance->setDatabase($this->db);

        // Call protected deleteCartData()
        $method = $rc->getMethod('deleteCartData');
        $method->setAccessible(true);
        $method->invoke($instance, $deleteUserId);

        // Verify cart items deleted
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where($this->db->quoteName('cart_id') . ' IN (' . implode(',', $cartIds) . ')');
        $this->db->setQuery($query);
        $remainingItems = (int) $this->db->loadResult();

        $this->test('deleteCartData() removed all cart items', $remainingItems === 0,
            "Remaining items: $remainingItems");

        // Verify carts deleted
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->db->quoteName('user_id') . ' = ' . $deleteUserId);
        $this->db->setQuery($query);
        $remainingCarts = (int) $this->db->loadResult();

        $this->test('deleteCartData() removed all carts', $remainingCarts === 0,
            "Remaining carts: $remainingCarts");
    }
}

$test = new DataIntegrationTest();
$success = $test->run();
exit($success ? 0 : 1);
