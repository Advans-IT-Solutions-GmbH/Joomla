<?php
/**
 * Data Export Tests - validates the actual SQL queries the plugin uses
 * against real J2Commerce tables.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

class DataExportTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $testUserId = 100;
    private string $tp; // table prefix: 'j2store' or 'j2commerce'

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
        $this->tp = getenv('J2COMMERCE_STACK') === 'j6' ? 'j2commerce' : 'j2store';
    }

    private function test($name, $condition, $message = '')
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

    public function run(): bool
    {
        echo "=== Data Export Tests ===\n\n";

        // Test 1: Execute the exact orders export query the plugin uses
        echo "--- Orders Export Query ---\n";
        try {
            $query = $this->db->getQuery(true)
                ->select(['o.*', 'oi.orderitem_name', 'oi.orderitem_sku', 'oi.orderitem_quantity', 'oi.orderitem_price', 'oi.orderitem_finalprice',
                    'inf.billing_first_name', 'inf.billing_last_name'])
                ->from($this->db->quoteName('#__' . $this->tp . '_orders', 'o'))
                ->leftJoin($this->db->quoteName('#__' . $this->tp . '_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->leftJoin($this->db->quoteName('#__' . $this->tp . '_orderinfos', 'inf') . ' ON o.order_id = inf.order_id')
                ->where('o.user_id = ' . $this->testUserId);

            $this->db->setQuery($query);
            $rows = $this->db->loadAssocList();
            $this->test('Orders export query executes without error', true);
            $this->test('Orders export returns rows', count($rows) > 0, 'Got ' . count($rows) . ' rows');

            if (count($rows) > 0) {
                $row = $rows[0];
                // Verify expected columns exist in result
                $this->test('Result has user_email (from orders)', array_key_exists('user_email', $row));
                $this->test('Result has billing_first_name (from orderinfos)', array_key_exists('billing_first_name', $row));
                $this->test('Result has billing_last_name (from orderinfos)', array_key_exists('billing_last_name', $row));
                $this->test('Result has orderitem_name (from orderitems)', array_key_exists('orderitem_name', $row));
                $this->test('Result has orderitem_finalprice (from orderitems)', array_key_exists('orderitem_finalprice', $row));
                $this->test('Result has order_state', array_key_exists('order_state', $row));
                $this->test('Result has currency_code', array_key_exists('currency_code', $row));

                // Verify data integrity
                $this->test('billing_first_name has value', !empty($row['billing_first_name']),
                    'Got: ' . ($row['billing_first_name'] ?? 'NULL'));
                $this->test('orderitem_name has value', !empty($row['orderitem_name']),
                    'Got: ' . ($row['orderitem_name'] ?? 'NULL'));
            }
        } catch (\Exception $e) {
            $this->test('Orders export query executes without error', false, $e->getMessage());
        }

        // Test 2: Execute the addresses export query
        echo "\n--- Addresses Export Query ---\n";
        try {
            $query = $this->db->getQuery(true)
                ->select('*')
                ->from($this->db->quoteName('#__' . $this->tp . '_addresses'))
                ->where('user_id = ' . $this->testUserId);

            $this->db->setQuery($query);
            $addresses = $this->db->loadAssocList();
            $this->test('Addresses export query executes', true);
            $this->test('Addresses export returns rows', count($addresses) >= 2,
                'Expected >= 2, got ' . count($addresses));

            if (count($addresses) > 0) {
                $addr = $addresses[0];
                $this->test('Address has first_name', array_key_exists('first_name', $addr));
                $this->test('Address has last_name', array_key_exists('last_name', $addr));
                $this->test('Address has email', array_key_exists('email', $addr));
                $this->test('Address has address_1', array_key_exists('address_1', $addr));
            }
        } catch (\Exception $e) {
            $this->test('Addresses export query executes', false, $e->getMessage());
        }

        // Test 3: Verify JOIN produces correct order-item associations
        echo "\n--- JOIN Integrity ---\n";
        try {
            $query = $this->db->getQuery(true)
                ->select(['o.order_id', 'COUNT(oi.' . $this->tp . '_orderitem_id) AS item_count'])
                ->from($this->db->quoteName('#__' . $this->tp . '_orders', 'o'))
                ->leftJoin($this->db->quoteName('#__' . $this->tp . '_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->where('o.user_id = ' . $this->testUserId)
                ->group('o.order_id');

            $this->db->setQuery($query);
            $orderCounts = $this->db->loadAssocList();
            $this->test('JOIN groups orders correctly', count($orderCounts) >= 2,
                'Expected >= 2 orders, got ' . count($orderCounts));

            foreach ($orderCounts as $oc) {
                $this->test("Order {$oc['order_id']} has items", (int)$oc['item_count'] > 0,
                    'item_count=' . $oc['item_count']);
            }
        } catch (\Exception $e) {
            $this->test('JOIN integrity check', false, $e->getMessage());
        }

        echo "\n=== Data Export Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new DataExportTest();
exit($test->run() ? 0 : 1);
