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

// Extension namespaces (com_privacy Status, plugin helpers) as the application would load them.
JLoader::register('JNamespacePsr4Map', JPATH_LIBRARIES . '/namespacemap.php');
(new JNamespacePsr4Map())->load();

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

$pluginClassFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';

if (!class_exists(\Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class) && is_file($pluginClassFile)) {
    require_once $pluginClassFile;
}

if (class_exists(\Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class)) {
    /** Test double: fixed lifetime-license orders, stub application and mail result. */
    class AnonymizationTestPlugin extends \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce
    {
        public array $lifetime = [];
        public ?object $stubApp = null;
        public ?RecordingMailerFactory $mailerFactory = null;
        public string $mailState = 'sent';
        public bool $realMail = false;

        protected function lifetimeOrderIds(array $orderIds): array
        {
            return array_values(array_intersect(array_map('strval', $orderIds), $this->lifetime));
        }

        protected function feedbackApplication(): ?object
        {
            return $this->stubApp;
        }

        protected function sendCustomerRetentionNotice($app, array $retained, array $lifetime, string $customerEmail, string $languageTag): string
        {
            if ($this->realMail) {
                return parent::sendCustomerRetentionNotice($app, $retained, $lifetime, $customerEmail, $languageTag);
            }

            FeedbackTestApp::$log[] = ['mail', $customerEmail, $languageTag];

            return $this->mailState;
        }

        protected function createMailer()
        {
            if ($this->mailerFactory !== null) {
                return $this->mailerFactory->createMailer();
            }

            return parent::createMailer();
        }

        public function call(string $method, ...$args)
        {
            return $this->$method(...$args);
        }
    }
}

/** Mail doubles: application with configuration and container, factory and recording mailer. */
class RecordingMailer
{
    public array $to = [];
    public string $subject = '';
    public string $body = '';
    public bool $result = true;

    public function addRecipient($recipient)
    {
        $this->to[] = $recipient;

        return $this;
    }

    public function setSubject($subject)
    {
        $this->subject = (string) $subject;

        return $this;
    }

    public function setBody($body)
    {
        $this->body = (string) $body;

        return $this;
    }

    public function send()
    {
        return $this->result;
    }
}

class RecordingMailerFactory
{
    public ?RecordingMailer $last = null;
    public bool $result = true;

    public function createMailer()
    {
        $this->last         = new RecordingMailer();
        $this->last->result = $this->result;

        return $this->last;
    }
}

class MailTestApp
{
    public $container;
    public int $mailonline = 0;

    public function get($key, $default = null)
    {
        return ['mailonline' => $this->mailonline, 'sitename' => 'Test Shop', 'mailfrom' => 'shop@example.invalid'][$key] ?? $default;
    }

    public function getContainer()
    {
        return $this->container;
    }
}

/** Administrator application stub that records enqueued messages. */
class FeedbackTestApp
{
    public static array $log = [];

    public function isClient($client): bool
    {
        return $client === 'administrator';
    }

    public function enqueueMessage($message, $type = 'message'): void
    {
        self::$log[] = ['message', $type, (string) $message];
    }

    public function get($key, $default = null)
    {
        return $default;
    }
}

class DataAnonymizationTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
    }

    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery')
            ? $this->db->createQuery()
            : $this->db->getQuery(true);
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
        $orderId = 'ANON-TEST-' . time();
        $testOrder = (object) [
            'order_id'       => $orderId,
            'cart_id'        => 0,
            'invoice_prefix' => 'INV-',
            'invoice_number' => 5001,
            'token'          => 'anon-token',
            'user_id'        => 998,
            'user_email'     => 'private@example.com',
            'order_total'    => 50.00000,
            'order_subtotal' => 45.00000,
            'order_tax'      => 5.00000,
            'order_shipping' => 0.00000,
            'order_shipping_tax' => 0.00000,
            'order_discount' => 0.00000,
            'order_credit'   => 0.00000,
            'order_surcharge' => 0.00000,
            'orderpayment_type' => 'manual',
            'transaction_id' => '',
            'transaction_status' => 'confirmed',
            'transaction_details' => '',
            'currency_id'    => 1,
            'order_state_id' => 1,
            'order_state'    => 'confirmed',
            'currency_code'  => 'CHF',
            'currency_value' => 1.00000000,
            'customer_note'  => 'Please deliver before 5pm',
            'ip_address'     => '192.168.1.100',
            'is_shippable'   => 0,
            'is_including_tax' => 1,
            'customer_language' => '*',
            'customer_group' => 'default',
            'created_on'     => date('Y-m-d H:i:s', strtotime('-12 years')),
            'modified_on'    => date('Y-m-d H:i:s', strtotime('-12 years')),
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
            // LONGTEXT NOT NULL without DEFAULT on J6 — must be set explicitly
            'all_billing'           => '',
            'all_shipping'          => '',
            'all_payment'           => '',
        ];
        $this->db->insertObject($orderinfosTable, $testInfo, $orderinfoPkCol);
        $infoPk = $this->db->insertid();

        // Migrated site: the #__j2store_* source tables stay after the official migration and
        // com_j2store is disabled. The enabled component decides which tables are used.
        $stack = $this->prepareMigratedStack($isJ6, $ordersTable, $testOrder, $orderPkCol);
        register_shutdown_function(fn () => $this->cleanupMigratedStack($stack));

        try {

        // Checkout consent records: one for the expired order (IP/UA must be removed), one for a
        // recent order of the same user and one of another user (both must stay unchanged).
        $recentOrderId = 'ANON-RECENT-' . time();
        $recentOrder   = clone $testOrder;
        unset($recentOrder->$orderPkCol); // insertObject() stored the primary key in $testOrder
        $recentOrder->order_id       = $recentOrderId;
        $recentOrder->invoice_number = 5002;
        $recentOrder->created_on     = date('Y-m-d H:i:s', strtotime('-1 year'));
        $recentOrder->modified_on    = $recentOrder->created_on;
        $this->db->insertObject($ordersTable, $recentOrder, $orderPkCol);
        $recentPk = $this->db->insertid();

        // Placed on 1 January ten years ago: older than "now - 10 years", but the retention period
        // starts at the end of that fiscal year (OR Art. 958f), so the order is kept until 31.12. of this year.
        $fiscalOrder = clone $testOrder;
        unset($fiscalOrder->$orderPkCol);
        $fiscalOrder->order_id       = 'ANON-FY-' . time();
        $fiscalOrder->invoice_number = 5003;
        $fiscalOrder->created_on     = sprintf('%04d-01-01 00:00:01', (int) date('Y') - 10);
        $fiscalOrder->modified_on    = $fiscalOrder->created_on;
        $this->db->insertObject($ordersTable, $fiscalOrder, $orderPkCol);
        $fiscalPk = $this->db->insertid();

        // Expired order with a lifetime license (provisional rule, pending confirmation): anonymized
        // like any other order, but the order e-mail address is kept for license reactivation.
        $lifetimeOrder = clone $testOrder;
        unset($lifetimeOrder->$orderPkCol);
        $lifetimeOrder->order_id       = 'ANON-LT-' . time();
        $lifetimeOrder->invoice_number = 5004;
        $this->db->insertObject($ordersTable, $lifetimeOrder, $orderPkCol);
        $lifetimePk   = $this->db->insertid();
        $lifetimeInfo = clone $testInfo;
        unset($lifetimeInfo->$orderinfoPkCol);
        $lifetimeInfo->order_id = $lifetimeOrder->order_id;
        $this->db->insertObject($orderinfosTable, $lifetimeInfo, $orderinfoPkCol);
        $lifetimeInfoPk = $this->db->insertid();

        $consentIds = [];
        $consents   = [
            'expired' => [$orderId, 998, '203.0.113.50', 'AnonTestAgent/1.0'],
            'recent'  => [$recentOrderId, 998, '203.0.113.51', 'AnonTestAgent/1.1'],
            'other'   => ['ANON-OTHER-' . time(), 997, '203.0.113.52', 'AnonTestAgent/1.2'],
        ];

        foreach ($consents as $key => [$consentOrder, $consentUser, $ip, $agent]) {
            $row = (object) [
                'user_id' => $consentUser,
                'state'   => 1,
                'created' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'subject' => 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT',
                'body'    => "<p>Order $consentOrder</p><p>IP address: $ip</p><p>User agent: $agent</p><!-- j2commerce-order:$consentOrder -->",
                'remind'  => 0,
                'token'   => '',
            ];
            $this->db->insertObject('#__privacy_consents', $row, 'id');
            $consentIds[$key] = (int) $row->id;
        }

        $loadConsent = function (int $id): ?object {
            $query = $this->createDbQuery()
                ->select('*')
                ->from($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('id') . ' = ' . $id);

            return $this->db->setQuery($query)->loadObject() ?: null;
        };
        $consentsBefore = array_map($loadConsent, $consentIds);

        // Anonymize via the real plugin method — not a hand-rolled SQL copy.
        // Load the plugin class file directly (the Joomla autoloader does not
        // register plugin namespaces until the plugin is installed and enabled).
        $pluginClassFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';
        $pluginAvailable = file_exists($pluginClassFile);

        if (!$pluginAvailable) {
            // The test environment installs the plugin; a missing class is a failure.
            $this->test('plugin class available for anonymization round-trip', false, "not installed at $pluginClassFile");
        } else {
            $anonymized = false;
            try {
                $db         = Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
                $plugin     = new AnonymizationTestPlugin(
                    ['params' => new \Joomla\Registry\Registry(['admin_notifications' => 1, 'admin_email' => 'admin@example.invalid'])]
                );
                $plugin->setDatabase($db);
                $plugin->lifetime = [$lifetimeOrder->order_id];

                // Public entry point of a privacy removal request. The Joomla 4
                // call form (request, user) reaches the same removal code as the
                // Joomla 5/6 event object without a privacy request record.
                $user           = new \Joomla\CMS\User\User();
                $user->id       = 998;
                $user->username = 'anonymization-test-998';
                $user->email    = 'private@example.com';

                $plugin->onPrivacyRemoveData(null, $user);
                $anonymized = true;
                $this->test('onPrivacyRemoveData() ran through the plugin with admin notifications enabled', true);
            } catch (\Throwable $e) {
                $this->test('onPrivacyRemoveData() ran through the plugin with admin notifications enabled', false, $e->getMessage());
            }

            if ($anonymized) {
                // Verify orders table
                $query = $this->createDbQuery()
                    ->select('user_email, customer_note, ip_address')
                    ->from($this->db->quoteName($ordersTable))
                    ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk);
                $order = $this->db->setQuery($query)->loadObject();
                $this->test('user_email anonymized', $order->user_email === 'anonymized@deleted.invalid');
                $this->test('customer_note cleared', $order->customer_note === '');
                $this->test('ip_address cleared',    $order->ip_address   === '');

                // Verify all PII fields in orderinfos
                $query = $this->createDbQuery()
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
                $query = $this->createDbQuery()
                    ->select('order_total')
                    ->from($this->db->quoteName($ordersTable))
                    ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk);
                $this->test('order_total preserved after anonymization',
                    (float) $this->db->setQuery($query)->loadResult() === 50.0);

                $query = $this->createDbQuery()
                    ->select('user_email, ip_address')
                    ->from($this->db->quoteName($ordersTable))
                    ->where($this->db->quoteName($orderPkCol) . ' = ' . (int) $fiscalPk);
                $fiscal = $this->db->setQuery($query)->loadObject();
                $this->test('order of 1 January ten years ago kept (retention from the end of the fiscal year)',
                    $fiscal && $fiscal->user_email === 'private@example.com' && $fiscal->ip_address === '192.168.1.100',
                    var_export($fiscal, true));

                $query = $this->createDbQuery()
                    ->select('o.user_email, o.ip_address, oi.billing_first_name, oi.billing_address_1')
                    ->from($this->db->quoteName($ordersTable, 'o'))
                    ->join('LEFT', $this->db->quoteName($orderinfosTable, 'oi') . ' ON oi.order_id = o.order_id')
                    ->where('o.' . $this->db->quoteName($orderPkCol) . ' = ' . (int) $lifetimePk);
                $lt = $this->db->setQuery($query)->loadObject();
                $this->test('lifetime-license order: e-mail address kept', $lt && $lt->user_email === 'private@example.com', var_export($lt, true));
                $this->test('lifetime-license order: IP address and billing data anonymized',
                    $lt && $lt->ip_address === '' && $lt->billing_first_name === 'Anonymized' && $lt->billing_address_1 === '', var_export($lt, true));

                $check = $plugin->call('checkRetentionPeriod', 998);
                $this->test('lifetime-license order does not block the removal request (can_delete = true)', $check['can_delete'] === true);
                $this->test('expired lifetime-license order is reported (e-mail kept)',
                    in_array($lifetimeOrder->order_id, array_column($check['lifetime_expired'], 'order_number'), true));
                $this->test('recent orders are listed as retained, the lifetime order is not',
                    in_array($recentOrderId, array_column($check['orders'], 'order_number'), true)
                    && !in_array($lifetimeOrder->order_id, array_column($check['orders'], 'order_number'), true));

                $statusClass = 'Joomla\\Component\\Privacy\\Administrator\\Removal\\Status';

                if ($this->test('com_privacy Status class loadable', class_exists($statusClass))) {
                    $user     = new \Joomla\CMS\User\User();
                    $user->id = 998;
                    $status   = $plugin->call('checkCanRemoveData', $user);
                    $this->test('checkCanRemoveData allows the removal request', $status->canRemove === true);
                }

                $this->testRemovalFeedback($plugin);

                if ($isJ6 && $stack['copy']) {
                    $copyEmail = fn (): ?string => $this->db->setQuery(
                        'SELECT user_email FROM ' . $this->db->quoteName('#__j2store_orders') . ' WHERE order_id = ' . $this->db->quote($orderId)
                    )->loadResult();
                    // The removal request above runs on every data set.
                    $this->test('migrated site: the removal request anonymized the J2Commerce 6 order and the #__j2store_orders copy',
                        $order->user_email === 'anonymized@deleted.invalid' && $copyEmail() === 'anonymized@deleted.invalid', var_export($copyEmail(), true));
                    $sets = $plugin->call('forEachDataSet', fn (bool $isJ4): bool => $isJ4);
                    $this->test('migrated site: both data sets are processed, J2Commerce 6 first', $sets === [false, true], json_encode($sets));
                    $this->test('migrated site: outside forEachDataSet() the active set is J2Commerce 6', $plugin->call('isJ2Commerce4') === false);
                }

                // Consent records of the anonymized order lose IP address and user agent only.
                echo "\n--- Consent evidence of anonymized orders ---\n";
                $expired = $loadConsent($consentIds['expired']);
                $before  = $consentsBefore['expired'];
                $this->test('consent of the anonymized order still exists', $expired !== null);
                $this->test('consent IP address removed', $expired && !str_contains($expired->body, '203.0.113.50'));
                $this->test('consent user agent removed', $expired && !str_contains($expired->body, 'AnonTestAgent/1.0'));
                $this->test('consent keeps order reference and removal marker',
                    $expired && str_contains($expired->body, "<!-- j2commerce-order:$orderId -->")
                    && str_contains($expired->body, '<!-- j2commerce-evidence-removed -->'));
                $this->test('consent body text translated (no raw key)', $expired && !str_contains($expired->body, 'PLG_SYSTEM_J2COMMERCEPRIVACY_'));
                $this->test('consent user_id, state, created and subject unchanged',
                    $expired && $before
                    && (int) $expired->user_id === (int) $before->user_id
                    && (int) $expired->state === (int) $before->state
                    && $expired->created === $before->created
                    && $expired->subject === $before->subject);

                foreach (['recent' => 'recent order of the same user (within retention)', 'other' => 'order of another user'] as $key => $label) {
                    $after = $loadConsent($consentIds[$key]);
                    $this->test("consent of the $label unchanged", $after && $after->body === $consentsBefore[$key]->body);
                }

                // A second anonymization run does not rewrite the record again.
                $bodyAfterFirstRun = $expired->body ?? '';
                $plugin->call('anonymizeOrders', 998);
                $again = $loadConsent($consentIds['expired']);
                $this->test('second anonymization leaves the consent unchanged', $again && $again->body === $bodyAfterFirstRun);
            }
        }

        // Cleanup
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE ' . $this->db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $consentIds)) . ')')->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($ordersTable) . ' WHERE ' . $this->db->quoteName($orderPkCol) . ' IN (' . (int) $recentPk . ',' . (int) $fiscalPk . ',' . (int) $lifetimePk . ')')->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($orderinfosTable) . ' WHERE ' . $this->db->quoteName($orderinfoPkCol) . ' = ' . (int) $lifetimeInfoPk)->execute();

        $this->cleanupMigratedStack($stack);
        $this->testRetentionPeriod();
        } finally {
            // Also after a fatal error: later suites must not run on a fake migrated site.
            $this->cleanupMigratedStack($stack);
        }
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($orderinfosTable) . ' WHERE ' . $this->db->quoteName($orderinfoPkCol) . ' = ' . (int) $infoPk)->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($ordersTable) . ' WHERE ' . $this->db->quoteName($orderPkCol) . ' = ' . (int) $orderPk)->execute();

        echo "\n=== Data Anonymization Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * J2Commerce 6 stack: create the J2Store tables a migration leaves behind (if missing), a copy of
     * the test order in #__j2store_orders and a disabled com_j2store component, then check the
     * detection. J2Store stack: the enabled com_j2store is detected.
     *
     * @return  array{tables: string[], extension: int, copy: bool}
     */
    private function prepareMigratedStack(bool $isJ6, string $ordersTable, object $testOrder, string $orderPkCol): array
    {
        $state = ['tables' => [], 'extension' => 0, 'restore' => [], 'copy' => false, 'done' => false];
        $file  = JPATH_BASE . '/plugins/privacy/j2commerce/src/Support/J2CommerceStack.php';
        $class = \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::class;

        if (!class_exists($class) && is_file($file)) {
            require_once $file;
        }

        if (!$this->test('J2CommerceStack class available', class_exists($class), $file)) {
            return $state;
        }

        if (!$isJ6) {
            $class::reset();
            $this->test('J2Store stack detected through the enabled com_j2store', $class::isJ2Commerce4($this->db) === true);

            // An enabled com_j2commerce without its tables is skipped.
            if (!in_array($this->db->getPrefix() . 'j2commerce_orders', $this->db->getTableList(), true)) {
                $fake = $this->insertComponent('com_j2commerce', 1);
                $class::reset();
                $this->test('enabled com_j2commerce without tables is skipped: J2Store stays active', $class::isJ2Commerce4($this->db) === true);

                if ($fake > 0) {
                    $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__extensions') . ' WHERE extension_id = ' . $fake)->execute();
                }

                $class::reset();
            }

            return $state;
        }

        echo "\n--- Migrated site: J2Store tables present, com_j2store disabled ---\n";
        $prefix = $this->db->getPrefix();
        $tables = $this->db->getTableList();

        try {
            // Enabled com_j2store without its tables is skipped (checked before the tables exist).
            if (!in_array($prefix . 'j2store_orders', $tables, true)) {
                $rows = $this->db->setQuery(
                    'SELECT extension_id, enabled FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'component' AND element = 'com_j2store'"
                )->loadObjectList();

                foreach ($rows as $row) {
                    $state['restore'][(int) $row->extension_id] = (int) $row->enabled;
                }

                if ($rows === []) {
                    $state['extension'] = $this->insertComponent('com_j2store', 1);
                } else {
                    $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 1 WHERE type = 'component' AND element = 'com_j2store'")->execute();
                }

                \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
                $this->test('enabled com_j2store without tables is skipped: J2Commerce 6 stays active',
                    \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::isJ2Commerce4($this->db) === false);
                $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 0 WHERE type = 'component' AND element = 'com_j2store'")->execute();
            }

            foreach (['orders', 'orderinfos', 'orderitems', 'carts', 'cartitems', 'addresses'] as $name) {
                if (!in_array($prefix . 'j2store_' . $name, $tables, true) && in_array($prefix . 'j2commerce_' . $name, $tables, true)) {
                    $this->db->setQuery('CREATE TABLE ' . $this->db->quoteName($prefix . 'j2store_' . $name) . ' LIKE ' . $this->db->quoteName($prefix . 'j2commerce_' . $name))->execute();
                    $state['tables'][] = $prefix . 'j2store_' . $name;
                    // J2Store names its key columns j2store_*_id.
                    foreach (array_keys($this->db->getTableColumns($prefix . 'j2store_' . $name, false)) as $column) {
                        if (str_starts_with($column, 'j2commerce_')) {
                            $this->db->setQuery(
                                'ALTER TABLE ' . $this->db->quoteName($prefix . 'j2store_' . $name) . ' RENAME COLUMN '
                                . $this->db->quoteName($column) . ' TO ' . $this->db->quoteName('j2store_' . substr($column, strlen('j2commerce_')))
                            )->execute();
                        }
                    }                }
            }

            $copy = clone $testOrder;
            unset($copy->$orderPkCol);
            $this->db->insertObject('#__j2store_orders', $copy);
            $state['copy'] = true;

            $existing = (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'component' AND element = 'com_j2store'"
            )->loadResult();

            if ($existing > 0 && $state['extension'] > 0) {
                // Row inserted above for the check without tables; it is disabled now.
            } elseif ($existing > 0) {
                // Existing row: disable explicitly, restore afterwards.
                $rows = $this->db->setQuery(
                    'SELECT extension_id, enabled FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'component' AND element = 'com_j2store'"
                )->loadObjectList();

                foreach ($rows as $row) {
                    $state['restore'][(int) $row->extension_id] = (int) $row->enabled;
                }

                $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 0 WHERE type = 'component' AND element = 'com_j2store'")->execute();
            } else {
                $extension = (object) [
                    'package_id' => 0, 'name' => 'com_j2store', 'type' => 'component', 'element' => 'com_j2store',
                    'changelogurl' => '', 'folder' => '', 'client_id' => 1, 'enabled' => 0, 'access' => 1, 'protected' => 0,
                    'locked' => 0, 'manifest_cache' => '{}', 'params' => '{}', 'custom_data' => '', 'ordering' => 0,
                    'state' => 0, 'note' => '',
                ];
                $this->db->insertObject('#__extensions', $extension, 'extension_id');
                $state['extension'] = (int) $extension->extension_id;
            }

            $enabled = (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'component' AND element = 'com_j2store' AND enabled = 1"
            )->loadResult();
            $this->test('com_j2store is disabled for the test', $enabled === 0);

            \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
            $this->test('both table sets present, com_j2store disabled: J2Commerce 6 tables are used',
                \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::isJ2Commerce4($this->db) === false);

            // Only enabled components count: com_j2commerce disabled and com_j2store enabled with
            // tables -> J2Store is the active shop (removal and cleanup still cover both sets).
            $j6Enabled = $this->db->setQuery(
                'SELECT extension_id, enabled FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'component' AND element = 'com_j2commerce'"
            )->loadObjectList();

            foreach ($j6Enabled as $row) {
                $state['restore'][(int) $row->extension_id] = $state['restore'][(int) $row->extension_id] ?? (int) $row->enabled;
            }

            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 0 WHERE type = 'component' AND element = 'com_j2commerce'")->execute();
            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 1 WHERE type = 'component' AND element = 'com_j2store'")->execute();
            \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
            $this->test('com_j2commerce disabled, com_j2store enabled with tables: J2Store is the active shop',
                \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::isJ2Commerce4($this->db) === true);
            $this->test('data sets are still both listed, the active J2Store set first',
                \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::dataSets($this->db) === [true, false]);

            foreach ($j6Enabled as $row) {
                $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . ' SET enabled = ' . (int) $row->enabled . ' WHERE extension_id = ' . (int) $row->extension_id)->execute();
            }

            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . " SET enabled = 0 WHERE type = 'component' AND element = 'com_j2store'")->execute();
            \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
            $this->test('both data sets are listed, J2Commerce 6 first',
                \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::dataSets($this->db) === [false, true]);
        } catch (\Throwable $e) {
            $this->test('migrated-site setup', false, $e->getMessage());
        }

        return $state;
    }

    /** Insert a minimal component row; returns its ID. */
    private function insertComponent(string $element, int $enabled): int
    {
        $extension = (object) [
            'package_id' => 0, 'name' => $element, 'type' => 'component', 'element' => $element,
            'changelogurl' => '', 'folder' => '', 'client_id' => 1, 'enabled' => $enabled, 'access' => 1, 'protected' => 0,
            'locked' => 0, 'manifest_cache' => '{}', 'params' => '{}', 'custom_data' => '', 'ordering' => 0,
            'state' => 0, 'note' => '',
        ];
        $this->db->insertObject('#__extensions', $extension, 'extension_id');

        return (int) $extension->extension_id;
    }

    private function cleanupMigratedStack(array &$state): void
    {
        if (!empty($state['done'])) {
            return;
        }

        $state['done'] = true;

        foreach ($state['restore'] ?? [] as $extensionId => $enabled) {
            $this->db->setQuery('UPDATE ' . $this->db->quoteName('#__extensions') . ' SET enabled = ' . (int) $enabled . ' WHERE extension_id = ' . (int) $extensionId)->execute();
        }

        foreach ($state['tables'] as $table) {
            $this->db->setQuery('DROP TABLE IF EXISTS ' . $this->db->quoteName($table))->execute();
        }

        if ($state['copy'] && !in_array($this->db->getPrefix() . 'j2store_orders', $state['tables'], true)) {
            $this->db->setQuery("DELETE FROM " . $this->db->quoteName('#__j2store_orders') . " WHERE order_id LIKE 'ANON-TEST-%'")->execute();
        }

        if ($state['extension'] > 0) {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__extensions') . ' WHERE extension_id = ' . (int) $state['extension'])->execute();
        }

        if (class_exists(\Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::class)) {
            \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::reset();
        }
    }

    /**
     * Retention period from the end of the fiscal year (OR Art. 958f), fixed dates.
     */
    private function testRetentionPeriod(): void
    {
        echo "\n--- Retention period from the end of the fiscal year ---\n";

        $file  = JPATH_BASE . '/plugins/privacy/j2commerce/src/Retention/RetentionPeriod.php';
        $class = \Advans\Plugin\Privacy\J2Commerce\Retention\RetentionPeriod::class;

        if (!class_exists($class) && is_file($file)) {
            require_once $file;
        }

        if (!$this->test('RetentionPeriod class available', class_exists($class), $file)) {
            return;
        }

        $utc    = new \DateTimeZone('UTC');
        $zurich = new \DateTimeZone('Europe/Zurich');
        $now    = new \DateTimeImmutable('2026-09-16 10:00:00', $utc);
        $day    = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d');

        $this->test('UTC, 10 years, fiscal year end 31.12.: cutoff 2015-12-31 23:59:59', $class::cutoff(10, '12-31', $now) === '2015-12-31 23:59:59');
        $this->test('UTC, 10 years, fiscal year end 30.06.: cutoff 2016-06-30 23:59:59', $class::cutoff(10, '06-30', $now) === '2016-06-30 23:59:59');
        $this->test('Zurich, 31.12.: cutoff is the local year end in UTC (2015-12-31 22:59:59)', $class::cutoff(10, '12-31', $now, $zurich) === '2015-12-31 22:59:59');
        $this->test('Zurich, 30.06. (summer time): cutoff 2016-06-30 21:59:59 UTC', $class::cutoff(10, '06-30', $now, $zurich) === '2016-06-30 21:59:59');
        $this->test('Order of 15.03.2016 is kept until 31.12.2026', $class::retentionEnd('2016-03-15 08:00:00', 10)->format('Y-m-d H:i:s') === '2026-12-31 23:59:59');
        $this->test('Zurich: order of 01.01.2017 00:30 CET (stored 2016-12-31 23:30 UTC) belongs to 2017, kept until 31.12.2027',
            $day($class::retentionEnd('2016-12-31 23:30:00', 10, '12-31', $zurich)) === '2027-12-31');
        $this->test('Zurich: order of 31.12.2016 23:30 CET (stored 22:30 UTC) is kept until 31.12.2026',
            $day($class::retentionEnd('2016-12-31 22:30:00', 10, '12-31', $zurich)) === '2026-12-31');
        $this->test('Zurich, year end 30.06.: order of 01.07.2016 00:30 CEST (stored 2016-06-30 22:30 UTC) kept until 30.06.2027',
            $day($class::retentionEnd('2016-06-30 22:30:00', 10, '06-30', $zurich)) === '2027-06-30');
        $this->test('Zurich, year end 30.06.: order of 30.06.2016 23:30 CEST (stored 21:30 UTC) kept until 30.06.2026',
            $day($class::retentionEnd('2016-06-30 21:30:00', 10, '06-30', $zurich)) === '2026-06-30');
        $this->test('Order of 15.03.2016 is not expired on 16.09.2026', !$class::isExpired('2016-03-15 08:00:00', 10, '12-31', $now));
        $this->test('Order of 15.03.2016 is expired on 01.01.2027', $class::isExpired('2016-03-15 08:00:00', 10, '12-31', new \DateTimeImmutable('2027-01-01 00:00:00', $utc)));
        $this->test('Order of 01.07.2016 with fiscal year end 30.06. is kept until 30.06.2027', $class::retentionEnd('2016-07-01 00:00:00', 10, '06-30')->format('Y-m-d') === '2027-06-30');
        $this->test('Fiscal year end 02-29 is valid and means the last day of February',
            $class::isValidFiscalYearEnd('02-29') && $day($class::retentionEnd('2017-02-28 12:00:00', 10, '02-29')) === '2027-02-28'
            && $day($class::retentionEnd('2015-03-01 12:00:00', 1, '02-29')) === '2017-02-28');
        $this->test('Non-existent days (02-30, 04-31, 13-01) are invalid and count as 12-31',
            !$class::isValidFiscalYearEnd('02-30') && !$class::isValidFiscalYearEnd('04-31') && !$class::isValidFiscalYearEnd('13-01')
            && $class::effectiveFiscalYearEnd('04-31') === '12-31');
        $this->test('Unknown time zone falls back to UTC', $class::timeZone('Mars/Olympus')->getName() === 'UTC');

        $rule = JPATH_BASE . '/plugins/privacy/j2commerce/src/Rule/FiscalyearendRule.php';
        $ruleClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Rule\\FiscalyearendRule';

        if (!class_exists($ruleClass) && is_file($rule)) {
            require_once $rule;
        }

        if ($this->test('Form rule fiscalyearend available', class_exists($ruleClass))) {
            $field = new \SimpleXMLElement('<field name="fiscal_year_end" validate="fiscalyearend"/>');
            $ruleObject = new $ruleClass();
            $this->test('Form rule accepts 12-31, 06-30, 02-29 and empty', $ruleObject->test($field, '12-31') && $ruleObject->test($field, '06-30') && $ruleObject->test($field, '02-29') && $ruleObject->test($field, ''));
            $this->test('Form rule rejects 04-31, 02-30, 12/31 and 1231', !$ruleObject->test($field, '04-31') && !$ruleObject->test($field, '02-30') && !$ruleObject->test($field, '12/31') && !$ruleObject->test($field, '1231'));

            foreach (['/plugins/privacy/j2commerce/j2commerce.xml', '/plugins/task/j2commerceprivacy/forms/autocleanup.xml'] as $xml) {
                $source = (string) @file_get_contents(JPATH_BASE . $xml);
                $this->test("$xml validates fiscal_year_end on the server", str_contains($source, 'validate="fiscalyearend"') && str_contains($source, 'addruleprefix="Advans\\Plugin\\Privacy\\J2Commerce\\Rule"'));
            }
        }

        $mismatch = 0;
        $first    = '';
        $zones    = [$utc, $zurich, new \DateTimeZone('America/New_York'), new \DateTimeZone('Pacific/Auckland')];

        // Fixed seed: a failure can be reproduced.
        mt_srand(20260916);

        for ($i = 0; $i < 2000; $i++) {
            $order = (new \DateTimeImmutable('2010-01-01', $utc))->modify('+' . mt_rand(0, 6000) . ' days +' . mt_rand(0, 86399) . ' seconds')->format('Y-m-d H:i:s');
            $at    = (new \DateTimeImmutable('2024-01-01', $utc))->modify('+' . mt_rand(0, 1500) . ' days +' . mt_rand(0, 86399) . ' seconds');
            $years = mt_rand(0, 12);
            $fy    = ['12-31', '06-30', '03-31', '02-29'][$i % 4];
            $zone  = $zones[intdiv($i, 4) % 4];

            if ($class::isExpired($order, $years, $fy, $at, $zone) !== ($order <= $class::cutoff($years, $fy, $at, $zone))) {
                $mismatch++;
                $first = $first ?: sprintf('order %s, now %s, %d years, fiscal year end %s, zone %s', $order, $at->format('Y-m-d H:i:s'), $years, $fy, $zone->getName());
            }
        }
        $this->test('Cutoff query and per-order check agree (2000 random cases, 4 time zones)', $mismatch === 0, "$mismatch mismatches, first: $first");
    }

    /**
     * Feedback after a removal request: the customer e-mail is attempted first, the administrator
     * message reflects its result; the customer text is in the customer's language.
     */
    private function testRemovalFeedback(AnonymizationTestPlugin $plugin): void
    {
        echo "\n--- Removal request feedback ---\n";

        Factory::getLanguage()->load('plg_privacy_j2commerce', JPATH_BASE . '/plugins/privacy/j2commerce');
        $plugin->stubApp = new FeedbackTestApp();
        $retained        = [['order_number' => 'FB-1', 'order_date' => '01.02.2024', 'retention_end' => '31.12.2034', 'lifetime' => true]];
        $email           = 'customer@example.invalid';

        $cases = [
            'sent'    => ['message', 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED'],
            'invalid' => ['warning', 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED_NO_ADDRESS'],
            'failed'  => ['warning', 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED_MAIL_FAILED'],
        ];

        foreach ($cases as $state => [$type, $key]) {
            FeedbackTestApp::$log = [];
            $plugin->mailState    = $state;
            $plugin->call('reportRetainedOrders', $retained, [], $email, 'de-DE');
            $log      = FeedbackTestApp::$log;
            $expected = htmlspecialchars(Text::sprintf($key, $email), ENT_QUOTES, 'UTF-8');

            $this->test("mail '$state': e-mail attempted before the administrator message",
                ($log[0][0] ?? '') === 'mail' && ($log[1][0] ?? '') === 'message', json_encode($log));
            $this->test("mail '$state': administrator message type $type with the matching text",
                ($log[1][1] ?? '') === $type && str_contains($log[1][2] ?? '', $expected) && !str_contains($log[1][2] ?? '', 'PLG_PRIVACY_J2COMMERCE_'),
                json_encode($log));
        }

        FeedbackTestApp::$log = [];
        $plugin->call('reportRetainedOrders', [], [], $email, 'de-DE');
        $this->test('nothing retained: no e-mail, success message',
            count(FeedbackTestApp::$log) === 1 && FeedbackTestApp::$log[0][1] === 'message'
            && str_contains(FeedbackTestApp::$log[0][2], Text::_('PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_NOTHING_RETAINED')));

        $german = $plugin->call('formatRetainedOrders', $retained, [['order_number' => 'FB-2', 'order_date' => '01.02.2010']], $plugin->call('pluginLanguage', 'de-DE'));
        $this->test('customer text in German (customer language) incl. lifetime-license notes',
            str_contains($german, 'Aufbewahrungsfrist') && str_contains($german, 'Unbefristete Lizenz') && str_contains($german, 'FB-2')
            && !str_contains($german, 'PLG_PRIVACY_J2COMMERCE_'), $german);
        $this->test('customer language falls back to a valid site language tag',
            (bool) preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', (string) $plugin->call('customerLanguage', 998)));
        $plugin->stubApp = null;

        // The real sendCustomerRetentionNotice(): address check, customer language, mail result.
        $plugin->realMail = true;
        $factory          = new RecordingMailerFactory();
        $plugin->mailerFactory = $factory;
        $app              = new MailTestApp();
        $lifetime         = [['order_number' => 'FB-2', 'order_date' => '01.02.2010']];

        $this->test('real mail path: invalid request address is reported as invalid, no mailer created',
            $plugin->call('sendCustomerRetentionNotice', $app, $retained, $lifetime, 'not-an-address', 'de-DE') === 'invalid' && $factory->last === null);

        $state  = $plugin->call('sendCustomerRetentionNotice', $app, $retained, $lifetime, $email, 'de-DE');
        $mailer = $factory->last;
        $this->test('real mail path: sent to the request address', $state === 'sent' && $mailer && $mailer->to === [$email], $state);
        $this->test('real mail path: subject and body in the customer language (German)',
            $mailer && str_contains($mailer->subject, 'Test Shop') && str_contains($mailer->subject, 'Anfrage')
            && str_contains($mailer->body, 'Aufbewahrungsfrist') && str_contains($mailer->body, 'Unbefristete Lizenz')
            && str_contains($mailer->body, 'FB-1') && str_contains($mailer->body, 'FB-2') && !str_contains($mailer->body, 'PLG_PRIVACY_J2COMMERCE_'),
            $mailer ? $mailer->subject . ' | ' . mb_substr($mailer->body, 0, 300) : 'no mailer');

        $factory->result = false;
        $this->test('real mail path: send() returning false is reported as failed',
            $plugin->call('sendCustomerRetentionNotice', $app, $retained, $lifetime, $email, 'de-DE') === 'failed');

        // Joomla's own mailer with "Send Mail" off (mailonline = 0) throws MailDisabledException.
        $joomlaApp            = new MailTestApp();
        $joomlaApp->container = Factory::getContainer();
        $previous             = Factory::$application;
        Factory::$application = $joomlaApp;
        $plugin->mailerFactory = null;

        try {
            $this->test('real Joomla mailer with mail disabled is reported as failed',
                $plugin->call('sendCustomerRetentionNotice', $joomlaApp, $retained, $lifetime, $email, 'de-DE') === 'failed');
        } finally {
            Factory::$application = $previous;
            $plugin->realMail     = false;
            $plugin->mailerFactory = null;
        }
    }

    /** Same decision as the plugin (installed component, not table presence). */
    private function isJ6Stack(): bool
    {
        $class = \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::class;
        $file  = JPATH_BASE . '/plugins/privacy/j2commerce/src/Support/J2CommerceStack.php';

        if (!class_exists($class) && is_file($file)) {
            require_once $file;
        }

        return class_exists($class) ? !$class::isJ2Commerce4($this->db) : getenv('J2COMMERCE_STACK') === 'j6';
    }

    private function getTableColumns(string $table): array
    {
        $columns = $this->db->getTableColumns($table);
        return array_keys($columns);
    }
}

$test = new DataAnonymizationTest();
exit($test->run() ? 0 : 1);
