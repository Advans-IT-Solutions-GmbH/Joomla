<?php
/**
 * GDPR Compliance Tests - validates schema alignment between plugin queries
 * and real J2Commerce tables. Ensures no column mismatch can slip through.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
use Joomla\CMS\Factory;

class GDPRComplianceTest
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
        echo "=== GDPR Compliance Tests ===\n\n";

        // Test 1: Plugin class has required GDPR methods
        echo "--- Method Existence ---\n";
        $classFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';
        $privacyPluginFile = JPATH_BASE . '/administrator/components/com_privacy/src/Plugin/PrivacyPlugin.php';
        if (file_exists($privacyPluginFile)) { require_once $privacyPluginFile; }
        if (file_exists($classFile)) { require_once $classFile; }

        if (class_exists('Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce')) {
            $reflection = new \ReflectionClass('Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce');
            $this->test('onPrivacyExportRequest exists', $reflection->hasMethod('onPrivacyExportRequest'));
            $this->test('onPrivacyCanRemoveData exists', $reflection->hasMethod('onPrivacyCanRemoveData'));
            $this->test('onPrivacyRemoveData exists', $reflection->hasMethod('onPrivacyRemoveData'));
            $this->test('anonymizeOrders exists', $reflection->hasMethod('anonymizeOrders'));
            $this->test('deleteAddresses exists', $reflection->hasMethod('deleteAddresses'));
            $this->test('deleteCartData exists', $reflection->hasMethod('deleteCartData'));
            $this->test('checkRetentionPeriod exists', $reflection->hasMethod('checkRetentionPeriod'));
        }

        // Test 2: Schema validation — every column the plugin references must exist
        echo "\n--- Schema Validation (Plugin vs Real DB) ---\n";

        $isJ6 = $this->isJ6Stack();
        $prefix = $isJ6 ? 'j2commerce' : 'j2store';
        $orderPkCol  = $isJ6 ? 'j2commerce_order_id'  : 'j2store_order_id';
        $addrPkCol   = $isJ6 ? 'j2commerce_address_id' : 'j2store_address_id';
        $cartPkCol   = $isJ6 ? 'j2commerce_cart_id'    : 'j2store_cart_id';

        $orderCols = $this->getTableColumns("#__{$prefix}_orders");
        $itemCols  = $this->getTableColumns("#__{$prefix}_orderitems");
        $infoCols  = $this->getTableColumns("#__{$prefix}_orderinfos");
        $addrCols  = $this->getTableColumns("#__{$prefix}_addresses");
        $cartCols  = $this->getTableColumns("#__{$prefix}_carts");

        // Columns the plugin SELECT queries reference in orders
        foreach ([$orderPkCol, 'order_id', 'user_id', 'user_email', 'order_total',
                   'order_state', 'currency_code', 'created_on', 'customer_note', 'ip_address'] as $col) {
            $this->test("orders.$col exists", in_array($col, $orderCols));
        }

        // Columns in orderitems
        foreach (['order_id', 'orderitem_name', 'orderitem_sku', 'orderitem_quantity',
                   'orderitem_price', 'orderitem_finalprice', 'product_id'] as $col) {
            $this->test("orderitems.$col exists", in_array($col, $itemCols));
        }

        // All PII columns in orderinfos — including previously untested ones
        foreach ([
            'order_id',
            'billing_first_name', 'billing_last_name', 'billing_middle_name',
            'billing_address_1', 'billing_address_2', 'billing_city', 'billing_zip',
            'billing_phone_1', 'billing_phone_2', 'billing_fax',
            'billing_company', 'billing_tax_number',
            'shipping_first_name', 'shipping_last_name', 'shipping_middle_name',
            'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_zip',
            'shipping_phone_1', 'shipping_phone_2', 'shipping_fax',
            'shipping_company', 'shipping_tax_number',
        ] as $col) {
            $this->test("orderinfos.$col exists", in_array($col, $infoCols));
        }

        // Columns in addresses
        foreach ([$addrPkCol, 'user_id', 'first_name', 'last_name', 'email',
                   'address_1', 'city', 'zip', 'type'] as $col) {
            $this->test("addresses.$col exists", in_array($col, $addrCols));
        }

        // Columns in carts
        foreach ([$cartPkCol, 'user_id'] as $col) {
            $this->test("carts.$col exists", in_array($col, $cartCols));
        }

        // Test 3: Verify JOIN key types match
        echo "\n--- JOIN Key Type Validation ---\n";

        $orderIdType     = $this->getColumnType("#__{$prefix}_orders",     'order_id');
        $itemOrderIdType = $this->getColumnType("#__{$prefix}_orderitems",  'order_id');
        $infoOrderIdType = $this->getColumnType("#__{$prefix}_orderinfos",  'order_id');

        $this->test('orders.order_id is varchar',    strpos($orderIdType,     'varchar') !== false, "Got: $orderIdType");
        $this->test('orderitems.order_id is varchar', strpos($itemOrderIdType, 'varchar') !== false, "Got: $itemOrderIdType");
        $this->test('orderinfos.order_id is varchar', strpos($infoOrderIdType, 'varchar') !== false, "Got: $infoOrderIdType");

        // Test 4: Negative tests — columns that must NOT exist
        echo "\n--- Negative Tests (columns that must NOT exist) ---\n";
        $this->test('orderinfos does NOT have billing_email', !in_array('billing_email', $infoCols),
            'billing_email would cause query errors');
        $this->test('orders does NOT have billing_first_name', !in_array('billing_first_name', $orderCols),
            'billing data belongs in orderinfos');

        echo "\n=== GDPR Compliance Test Summary ===\n";
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
        return array_keys($this->db->getTableColumns($table));
    }

    private function getColumnType(string $table, string $column): string
    {
        $columns = $this->db->getTableColumns($table, false);
        return $columns[$column]->Type ?? 'unknown';
    }
}

$test = new GDPRComplianceTest();
exit($test->run() ? 0 : 1);
