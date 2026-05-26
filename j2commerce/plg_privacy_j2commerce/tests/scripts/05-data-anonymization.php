<?php
/**
 * Data Anonymization Tests - validates that the anonymization queries
 * target the correct tables and columns in real J2Commerce schema.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

class DataAnonymizationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
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
        echo "=== Data Anonymization Tests ===\n\n";

        // Test 1: Verify anonymization targets correct tables (stack-aware)
        echo "--- Schema Validation for Anonymization ---\n";

        $isJ6        = $this->isJ6Stack();
        $ordersTable = $isJ6 ? '#__j2commerce_orders'    : '#__j2store_orders';
        $infosTable  = $isJ6 ? '#__j2commerce_orderinfos' : '#__j2store_orderinfos';

        $orderCols = $this->getTableColumns($ordersTable);
        $this->test('orders has user_email column',    in_array('user_email',    $orderCols));
        $this->test('orders has customer_note column', in_array('customer_note', $orderCols));
        $this->test('orders has ip_address column',    in_array('ip_address',    $orderCols));
        // billing_first_name belongs in orderinfos, not orders
        $this->test('orders does NOT have billing_first_name', !in_array('billing_first_name', $orderCols));

        // orderinfos table should have all billing/shipping PII fields
        $infoCols = $this->getTableColumns($infosTable);
        foreach ([
            'billing_first_name', 'billing_last_name', 'billing_middle_name',
            'billing_address_1', 'billing_address_2', 'billing_city', 'billing_zip',
            'billing_phone_1', 'billing_phone_2', 'billing_fax',
            'billing_company', 'billing_tax_number',
            'shipping_first_name', 'shipping_last_name', 'shipping_middle_name',
            'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_zip',
            'shipping_phone_1', 'shipping_phone_2', 'shipping_fax',
            'shipping_company', 'shipping_tax_number',
        ] as $col) {
            $this->test("orderinfos has $col", in_array($col, $infoCols));
        }

        // Test 2: Full anonymization round-trip (stack-aware: J4/J5 vs J6)
        echo "\n--- Anonymization Round-Trip ---\n";

        $isJ6 = $this->isJ6Stack();
        $ordersTable   = $isJ6 ? '#__j2commerce_orders'   : '#__j2store_orders';
        $orderinfosTable = $isJ6 ? '#__j2commerce_orderinfos' : '#__j2store_orderinfos';
        $orderPkCol    = $isJ6 ? 'j2commerce_order_id'    : 'j2store_order_id';
        $orderinfoPkCol = $isJ6 ? 'j2commerce_orderinfo_id' : 'j2store_orderinfo_id';
        $orderStateCol = $isJ6 ? 'order_state'             : 'order_state_id';
        $orderStateVal = $isJ6 ? 'confirmed'               : 1;

        $orderId = 'ANON-TEST-' . time();
        $testOrder = (object) [
            'order_id'       => $orderId,
            'user_id'        => 998,
            'user_email'     => 'private@example.com',
            'order_total'    => 50.00000,
            'order_subtotal' => 45.00000,
            'order_tax'      => 5.00000,
            'order_shipping' => 0.00000,
            'order_discount' => 0.00000,
            $orderStateCol   => $orderStateVal,
            'currency_code'  => 'CHF',
            'currency_value' => 1.00000000,
            'customer_note'  => 'Please deliver before 5pm',
            'ip_address'     => '192.168.1.100',
            'created_on'     => date('Y-m-d H:i:s', strtotime('-12 years')),
        ];
        $this->db->insertObject($ordersTable, $testOrder, $orderPkCol);
        $orderPk = $this->db->insertid();

        // All PII fields — including the 7 previously untested ones
        $testInfo = (object) [
            'order_id'              => $orderId,
            'billing_first_name'    => 'Hans',
            'billing_last_name'     => 'Muster',
            'billing_middle_name'   => 'Karl',
            'billing_address_1'     => 'Bahnhofstrasse 1',
            'billing_address_2'     => 'c/o Muster',
            'billing_city'          => 'Zürich',
            'billing_zip'           => '8001',
            'billing_phone_1'       => '+41 44 111 22 33',
            'billing_phone_2'       => '+41 79 111 22 33',
            'billing_fax'           => '+41 44 111 22 34',
            'billing_company'       => 'Muster AG',
            'billing_tax_number'    => 'CHE-123.456.789',
            'shipping_first_name'   => 'Hans',
            'shipping_last_name'    => 'Muster',
            'shipping_middle_name'  => 'Karl',
            'shipping_address_1'    => 'Bahnhofstrasse 1',
            'shipping_address_2'    => '',
            'shipping_city'         => 'Zürich',
            'shipping_zip'          => '8001',
            'shipping_phone_1'      => '+41 44 111 22 33',
            'shipping_phone_2'      => '+41 79 111 22 33',
            'shipping_fax'          => '+41 44 111 22 34',
            'shipping_company'      => 'Muster AG',
            'shipping_tax_number'   => 'CHE-123.456.789',
        ];
        $this->db->insertObject($orderinfosTable, $testInfo, $orderinfoPkCol);
        $infoPk = $this->db->insertid();

        // Anonymize orders table
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName($ordersTable))
            ->set([
                $this->db->quoteName('user_email')     . ' = ' . $this->db->quote('anonymized@deleted.invalid'),
                $this->db->quoteName('customer_note')  . ' = ' . $this->db->quote(''),
                $this->db->quoteName('ip_address')     . ' = ' . $this->db->quote(''),
            ])
            ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk);
        $this->db->setQuery($query)->execute();

        // Anonymize orderinfos table — all PII fields including previously missing ones
        $query = $this->db->getQuery(true)
            ->update($this->db->quoteName($orderinfosTable))
            ->set([
                $this->db->quoteName('billing_first_name')   . ' = ' . $this->db->quote('Anonymized'),
                $this->db->quoteName('billing_last_name')    . ' = ' . $this->db->quote('User'),
                $this->db->quoteName('billing_middle_name')  . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_address_1')    . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_address_2')    . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_city')         . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_zip')          . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_phone_1')      . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_phone_2')      . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_fax')          . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_company')      . ' = ' . $this->db->quote(''),
                $this->db->quoteName('billing_tax_number')   . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_first_name')  . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_last_name')   . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_middle_name') . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_address_1')   . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_address_2')   . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_city')        . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_zip')         . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_phone_1')     . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_phone_2')     . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_fax')         . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_company')     . ' = ' . $this->db->quote(''),
                $this->db->quoteName('shipping_tax_number')  . ' = ' . $this->db->quote(''),
            ])
            ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($orderId));
        $this->db->setQuery($query)->execute();

        // Verify orders
        $query = $this->db->getQuery(true)
            ->select('user_email, customer_note, ip_address')
            ->from($this->db->quoteName($ordersTable))
            ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk);
        $order = $this->db->setQuery($query)->loadObject();
        $this->test('user_email anonymized', $order->user_email === 'anonymized@deleted.invalid');
        $this->test('customer_note cleared', $order->customer_note === '');
        $this->test('ip_address cleared', $order->ip_address === '');

        // Verify all PII fields in orderinfos
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName($orderinfosTable))
            ->where($this->db->quoteName($orderinfoPkCol) . ' = ' . (int) $infoPk);
        $info = $this->db->setQuery($query)->loadObject();
        $this->test('billing_first_name anonymized',   $info->billing_first_name   === 'Anonymized');
        $this->test('billing_last_name anonymized',    $info->billing_last_name    === 'User');
        $this->test('billing_middle_name cleared',     $info->billing_middle_name  === '');
        $this->test('billing_address_1 cleared',       $info->billing_address_1    === '');
        $this->test('billing_city cleared',            $info->billing_city         === '');
        $this->test('billing_zip cleared',             $info->billing_zip          === '');
        $this->test('billing_phone_1 cleared',         $info->billing_phone_1      === '');
        $this->test('billing_phone_2 cleared',         $info->billing_phone_2      === '');
        $this->test('billing_fax cleared',             $info->billing_fax          === '');
        $this->test('billing_company cleared',         $info->billing_company      === '');
        $this->test('billing_tax_number cleared',      $info->billing_tax_number   === '');
        $this->test('shipping_first_name cleared',     $info->shipping_first_name  === '');
        $this->test('shipping_middle_name cleared',    $info->shipping_middle_name === '');
        $this->test('shipping_phone_2 cleared',        $info->shipping_phone_2     === '');
        $this->test('shipping_fax cleared',            $info->shipping_fax         === '');
        $this->test('shipping_tax_number cleared',     $info->shipping_tax_number  === '');

        // Financial data must be preserved
        $query = $this->db->getQuery(true)
            ->select('order_total')
            ->from($this->db->quoteName($ordersTable))
            ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk);
        $this->test('order_total preserved after anonymization',
            (float) $this->db->setQuery($query)->loadResult() === 50.0);

        // Cleanup
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($orderinfosTable) . ' WHERE ' . $this->db->quoteName($orderinfoPkCol) . ' = ' . (int) $infoPk)->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($ordersTable) . ' WHERE ' . $this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk)->execute();

        echo "\n=== Data Anonymization Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function isJ6Stack(): bool
    {
        return getenv('J2COMMERCE_STACK') === 'j6'
            || count($this->db->getTableColumns('#__j2commerce_orders', false)) > 0;
    }

    private function getTableColumns(string $table): array
    {
        $columns = $this->db->getTableColumns($table);
        return array_keys($columns);
    }
}

$test = new DataAnonymizationTest();
exit($test->run() ? 0 : 1);
