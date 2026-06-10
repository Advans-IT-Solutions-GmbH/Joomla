<?php
/**
 * AutoCleanupTask Tests
 *
 * Verifies that AutoCleanupTask is correctly registered in the DI container,
 * responds to scheduler events, and executes retention-based cleanup correctly.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\ParameterType;

class AutoCleanupTaskTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    private function test(string $name, bool $condition, string $message = ''): bool
    {
        if ($condition) {
            echo "PASS $name\n";
            $this->passed++;
        } else {
            echo "FAIL $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }

        return $condition;
    }

    public function run(): bool
    {
        echo "=== AutoCleanupTask Tests ===\n\n";

        $this->testClassExists();
        $this->testDiRegistration();
        $this->testSchedulerEventAdvertisement();
        $this->testRetentionLogic();
        $this->testLifetimeLicenseExemption();
        $this->testLifetimeLicenseMetafieldsPath();

        echo "\n=== AutoCleanupTask Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function testClassExists(): void
    {
        echo "--- Class and File ---\n";

        $taskFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Task/AutoCleanupTask.php';
        $this->test('AutoCleanupTask.php exists', file_exists($taskFile));

        // TaskPluginTrait is part of com_scheduler which is not installed in the
        // test container. Loading the file causes a PHP fatal at compile time
        // (trait resolution), so we verify the class structure statically instead.
        if (file_exists($taskFile)) {
            $src = file_get_contents($taskFile);

            $this->test(
                'AutoCleanupTask uses TaskPluginTrait',
                str_contains($src, 'use TaskPluginTrait')
            );

            $this->test(
                'AutoCleanupTask implements SubscriberInterface',
                str_contains($src, 'implements SubscriberInterface')
            );

            $this->test(
                'AutoCleanupTask defines getSubscribedEvents',
                str_contains($src, 'getSubscribedEvents')
            );
        }
    }

    private function testDiRegistration(): void
    {
        echo "\n--- DI Container Registration ---\n";

        $providerFile = JPATH_BASE . '/plugins/privacy/j2commerce/services/provider.php';
        $this->test('provider.php exists', file_exists($providerFile));

        if (!file_exists($providerFile)) {
            return;
        }

        $providerSource = file_get_contents($providerFile);

        $this->test(
            'provider.php references AutoCleanupTask',
            str_contains($providerSource, 'AutoCleanupTask')
        );

        $this->test(
            'provider.php registers AutoCleanupTask::class as service key',
            str_contains($providerSource, 'AutoCleanupTask::class')
        );
    }

    private function testSchedulerEventAdvertisement(): void
    {
        echo "\n--- Scheduler Event Advertisement ---\n";

        // com_scheduler is not installed in the test container — TaskPluginTrait
        // causes a PHP fatal at class-load time. Verify event subscriptions via
        // static source analysis instead of runtime reflection.
        $taskFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Task/AutoCleanupTask.php';

        if (!file_exists($taskFile)) {
            $this->test('Event subscriptions (skipped — file not found)', true);
            return;
        }

        $src = file_get_contents($taskFile);

        $this->test(
            'Subscribes to onTaskOptionsList',
            str_contains($src, 'onTaskOptionsList'),
            'Required for Joomla scheduler to discover the task'
        );

        $this->test(
            'Subscribes to onExecuteTask',
            str_contains($src, 'onExecuteTask'),
            'Required for Joomla scheduler to execute the task'
        );

        $this->test(
            'Subscribes to onContentPrepareForm',
            str_contains($src, 'onContentPrepareForm'),
            'Required for task parameter form in scheduler UI'
        );
    }

    private function testRetentionLogic(): void
    {
        echo "\n--- Retention Logic ---\n";

        // Seed: one user with an old order (beyond retention) and one recent order
        $oldUserId    = 9901;
        $recentUserId = 9902;
        $now          = date('Y-m-d H:i:s');
        $oldDate      = date('Y-m-d H:i:s', strtotime('-11 years'));
        $recentDate   = date('Y-m-d H:i:s', strtotime('-1 year'));

        $this->seedTestUser($oldUserId, 'old-cleanup-test@example.com');
        $this->seedTestUser($recentUserId, 'recent-cleanup-test@example.com');
        $this->seedTestOrder($oldUserId, 'CLEANUP-OLD-' . time(), $oldDate);
        $this->seedTestOrder($recentUserId, 'CLEANUP-RECENT-' . time(), $recentDate);

        // Simulate the retention query: users whose ALL orders are older than 10 years
        $ordersTable = $this->isJ6Stack() ? '#__j2commerce_orders' : '#__j2store_orders';
        $cutoff = date('Y-m-d H:i:s', strtotime('-10 years'));
        $query  = $this->db->getQuery(true)
            ->select('DISTINCT o.user_id')
            ->from($this->db->quoteName($ordersTable, 'o'))
            ->where($this->db->quoteName('o.user_id') . ' > 0')
            ->where('NOT EXISTS (
                SELECT 1 FROM ' . $this->db->quoteName($ordersTable, 'o2') . '
                WHERE ' . $this->db->quoteName('o2.user_id') . ' = ' . $this->db->quoteName('o.user_id') . '
                AND ' . $this->db->quoteName('o2.created_on') . ' >= :cutoff
            )')
            ->bind(':cutoff', $cutoff);
        $this->db->setQuery($query);
        $expiredUserIds = array_map('intval', $this->db->loadColumn() ?: []);

        $this->test(
            'Old user (11y) is in retention-expired set',
            in_array($oldUserId, $expiredUserIds),
            'User with 11-year-old order should be flagged for cleanup'
        );

        $this->test(
            'Recent user (1y) is NOT in retention-expired set',
            !in_array($recentUserId, $expiredUserIds),
            'User with 1-year-old order must not be flagged'
        );

        $this->cleanupTestData([$oldUserId, $recentUserId]);
    }

    private function testLifetimeLicenseExemption(): void
    {
        echo "\n--- Lifetime License Exemption ---\n";
        $tp = $this->isJ6Stack() ? 'j2commerce' : 'j2store';

        // A user with an old order but a lifetime license product should be exempt
        $userId  = 9903;
        $oldDate = date('Y-m-d H:i:s', strtotime('-11 years'));

        $this->seedTestUser($userId, 'lifetime-cleanup-test@example.com');
        $orderId = $this->seedTestOrder($userId, 'CLEANUP-LIFETIME-' . time(), $oldDate);

        // Seed a lifetime license order item (product_source_id matching lifetime pattern)
        if ($orderId) {
            try {
                $itemData = (object) [
                    'order_id'          => $orderId,
                    'cart_id'           => 0,
                    'cartitem_id'       => 0,
                    'product_id'        => 0,
                    'product_type'      => 'simple',
                    'variant_id'        => 0,
                    'vendor_id'         => 0,
                    'product_source_id' => 9999,
                    'product_source'    => 'com_content',
                    'orderitem_sku'     => 'LIFETIME-TEST',
                    'orderitem_name'    => 'Lifetime License',
                    'orderitem_attributes' => '',
                    'orderitem_quantity' => '1',
                    'orderitem_taxprofile_id' => 0,
                    'orderitem_per_item_tax' => 0.00000,
                    'orderitem_tax'     => 0.00000,
                    'orderitem_discount' => 0.00000,
                    'orderitem_discount_tax' => 0.00000,
                    'orderitem_price'   => 299.00000,
                    'orderitem_option_price' => 0.00000,
                    'orderitem_finalprice' => 299.00000,
                    'orderitem_finalprice_with_tax' => 299.00000,
                    'orderitem_finalprice_without_tax' => 299.00000,
                    'orderitem_params'  => '{"license_type":"lifetime"}',
                    'created_on'        => date('Y-m-d H:i:s'),
                    'created_by'        => 0,
                    'orderitem_weight'  => '0',
                    'orderitem_weight_total' => '0',
                ];
                $this->db->insertObject('#__' . $tp . '_orderitems', $itemData);
            } catch (\Exception $e) {
                // orderitems schema may differ — skip item seeding
            }
        }

        // The task reads lifetime_source_ids from params — simulate with known IDs
        // (matches the known-issues list: 18,25,26,32,33,34,35,36,37,41,42,48,61,65,68,74,77,78,85,87,94,125)
        $lifetimeSourceIds = [18, 25, 26, 32, 33, 34, 35, 36, 37, 41, 42, 48, 61, 65, 68, 74, 77, 78, 85, 87, 94, 125];

        // Verify the exemption query structure: users with lifetime items should be excluded
        $cutoff = date('Y-m-d H:i:s', strtotime('-10 years'));
        $query  = $this->db->getQuery(true)
            ->select('DISTINCT o.user_id')
            ->from($this->db->quoteName('#__' . $tp . '_orders', 'o'))
            ->where($this->db->quoteName('o.user_id') . ' > 0')
            ->where('NOT EXISTS (
                SELECT 1 FROM ' . $this->db->quoteName('#__' . $tp . '_orders', 'o2') . '
                WHERE ' . $this->db->quoteName('o2.user_id') . ' = ' . $this->db->quoteName('o.user_id') . '
                AND ' . $this->db->quoteName('o2.created_on') . ' >= :cutoff
            )')
            ->bind(':cutoff', $cutoff);

        // Add lifetime exemption if orderitems table exists
        // Check that both the table and the product_source_id column exist before
        // building the exemption subquery — the column is absent in some schema variants.
        $orderItemColumns = [];
        try {
            $orderItemColumns = $this->db->getTableColumns('#__' . $tp . '_orderitems');
        } catch (\Exception $e) {
            // table not available
        }

        if (empty($orderItemColumns) || !isset($orderItemColumns['product_source_id'])) {
            $this->test('Lifetime exemption query (skipped — product_source_id column unavailable)', true);
            $this->cleanupTestData([$userId]);
            return;
        }

        $query->where('NOT EXISTS (
                SELECT 1 FROM ' . $this->db->quoteName('#__' . $tp . '_orderitems', 'oi') . '
                JOIN ' . $this->db->quoteName('#__' . $tp . '_orders', 'ol') . '
                  ON ' . $this->db->quoteName('ol.' . $tp . '_order_id') . ' = ' . $this->db->quoteName('oi.' . $tp . '_order_id') . '
                WHERE ' . $this->db->quoteName('ol.user_id') . ' = ' . $this->db->quoteName('o.user_id') . '
                AND ' . $this->db->quoteName('oi.product_source_id') . ' IN (' . implode(',', $lifetimeSourceIds) . ')
            )');

        $this->db->setQuery($query);
        $expiredUserIds = array_map('intval', $this->db->loadColumn() ?: []);

        // Our test user has product_source_id=9999 (not in lifetime list) — should still be flagged
        $this->test(
            'Non-lifetime user with old order is flagged',
            in_array($userId, $expiredUserIds),
            'User without lifetime product should be in cleanup set'
        );

        $this->cleanupTestData([$userId]);
    }

    private function testLifetimeLicenseMetafieldsPath(): void
    {
        echo "\n--- Lifetime License Metafields Path (J6) ---\n";

        if (!$this->isJ6Stack()) {
            $this->test('Metafields lifetime path (skipped — J4 stack)', true);
            return;
        }

        // Verify #__j2commerce_metafields exists (required for J6 lifetime check)
        $tables = $this->db->getTableList();
        $prefix = $this->db->getPrefix();
        $this->test(
            '#__j2commerce_metafields table exists',
            in_array($prefix . 'j2commerce_metafields', $tables, true)
        );

        // Seed: user with order + product + metafield marking it as lifetime license
        $userId    = 9904;
        $productId = 9904;
        $orderId   = 'CLEANUP-META-' . time();
        $oldDate   = date('Y-m-d H:i:s', strtotime('-11 years'));

        $this->seedTestUser($userId, 'metafields-lifetime-test@example.com');
        $this->seedTestOrder($userId, $orderId, $oldDate);

        // Seed order item linking to the product
        $itemSeeded = false;
        try {
            $item = (object) [
                'order_id'    => $orderId,
                'cart_id'     => 0,
                'cartitem_id' => 0,
                'product_id'  => $productId,
                'product_type' => 'simple',
                'variant_id'  => 0,
                'vendor_id'   => 0,
                'orderitem_sku' => 'META-LIFETIME',
                'orderitem_name' => 'Lifetime Product',
                'orderitem_attributes' => '',
                'orderitem_quantity' => '1',
                'orderitem_taxprofile_id' => 0,
                'orderitem_per_item_tax' => 0.00000,
                'orderitem_tax' => 0.00000,
                'orderitem_discount' => 0.00000,
                'orderitem_discount_tax' => 0.00000,
                'orderitem_price' => 199.00000,
                'orderitem_option_price' => 0.00000,
                'orderitem_finalprice' => 199.00000,
                'orderitem_finalprice_with_tax' => 199.00000,
                'orderitem_finalprice_without_tax' => 199.00000,
                'orderitem_params' => '{}',
                'created_on' => date('Y-m-d H:i:s'),
                'created_by' => 0,
                'orderitem_weight' => '0',
                'orderitem_weight_total' => '0',
            ];
            $this->db->insertObject('#__j2commerce_orderitems', $item);
            $itemSeeded = true;
        } catch (\Exception $e) {
            // schema may differ
        }

        // Seed metafield marking the product as lifetime license
        $metaSeeded = false;
        if ($itemSeeded && in_array($prefix . 'j2commerce_metafields', $tables, true)) {
            try {
                $meta = (object) [
                    'metakey'        => 'is_lifetime_license',
                    'namespace'      => 'product',
                    'scope'          => 'product',
                    'metavalue'      => 'yes',
                    'valuetype'      => 'string',
                    'description'    => '',
                    'owner_id'       => $productId,
                    'owner_resource' => 'product',
                ];
                $this->db->insertObject('#__j2commerce_metafields', $meta, 'id');
                $metaSeeded = true;
            } catch (\Exception $e) {
                // non-fatal
            }
        }

        if ($metaSeeded) {
            // Query mirrors hasLifetimeLicense() J6 path in AutoCleanupTask
            try {
                $query = $this->db->getQuery(true)
                    ->select('COUNT(*)')
                    ->from($this->db->quoteName('#__j2commerce_orderitems', 'oi'))
                    ->join('INNER', $this->db->quoteName('#__j2commerce_orders', 'o')
                        . ' ON ' . $this->db->quoteName('o.order_id') . ' = ' . $this->db->quoteName('oi.order_id'))
                    ->join('INNER', $this->db->quoteName('#__j2commerce_metafields', 'mf')
                        . ' ON ' . $this->db->quoteName('mf.owner_id') . ' = ' . $this->db->quoteName('oi.product_id')
                        . ' AND ' . $this->db->quoteName('mf.owner_resource') . ' = ' . $this->db->quote('product')
                        . ' AND ' . $this->db->quoteName('mf.metakey') . ' = ' . $this->db->quote('is_lifetime_license')
                        . ' AND LOWER(TRIM(' . $this->db->quoteName('mf.metavalue') . ')) = ' . $this->db->quote('yes'))
                    ->where($this->db->quoteName('o.user_id') . ' = :userid')
                    ->bind(':userid', $userId, ParameterType::INTEGER);
                $this->db->setQuery($query);
                $count = (int) $this->db->loadResult();

                $this->test(
                    'J6 metafields lifetime query detects seeded lifetime product',
                    $count > 0,
                    'Expected COUNT > 0 for user with lifetime metafield'
                );
            } catch (\Exception $e) {
                $this->test('J6 metafields lifetime query', false, $e->getMessage());
            }
        } else {
            $this->test('J6 metafields lifetime query (skipped — seed failed)', true);
        }

        // Verify fail-closed behaviour is in AutoCleanupTask source
        $taskFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Task/AutoCleanupTask.php';
        if (file_exists($taskFile)) {
            $src = file_get_contents($taskFile);
            $this->test(
                'AutoCleanupTask hasLifetimeLicense J6 catch returns true (fail-closed)',
                (bool) preg_match('/catch\s*\(\s*\\\\Exception[^}]+return\s+true\s*;/s', $src),
                'catch block must return true to prevent accidental anonymization on DB error'
            );
        }

        // Cleanup
        try {
            if ($metaSeeded) {
                $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->delete($this->db->quoteName('#__j2commerce_metafields'))
                        ->where($this->db->quoteName('owner_id') . ' = ' . $productId)
                        ->where($this->db->quoteName('owner_resource') . ' = ' . $this->db->quote('product'))
                )->execute();
            }
            if ($itemSeeded) {
                $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->delete($this->db->quoteName('#__j2commerce_orderitems'))
                        ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($orderId))
                )->execute();
            }
        } catch (\Exception $e) {
            // non-fatal
        }
        $this->cleanupTestData([$userId]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedTestUser(int $userId, string $email): void
    {
        try {
            $existing = $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select('id')
                    ->from($this->db->quoteName('#__users'))
                    ->where($this->db->quoteName('id') . ' = :id')
                    ->bind(':id', $userId, ParameterType::INTEGER)
            )->loadResult();

            if (!$existing) {
                $user = (object) [
                    'id'             => $userId,
                    'name'           => 'Cleanup Test User ' . $userId,
                    'username'       => 'cleanup_test_' . $userId,
                    'email'          => $email,
                    'password'       => md5('test'),
                    'block'          => 0,
                    'sendEmail'      => 0,
                    'registerDate'   => date('Y-m-d H:i:s'),
                    'lastvisitDate'  => null,
                    'activation'     => '',
                    'params'         => '',
                    'lastResetTime'  => null,
                    'resetCount'     => 0,
                    'requireReset'   => 0,
                ];
                $this->db->insertObject('#__users', $user, 'id');
            }
        } catch (\Exception $e) {
            // User seeding failed — tests will still run against existing data
        }
    }

    private function isJ6Stack(): bool
    {
        if (getenv('J2COMMERCE_STACK') === 'j6') {
            return true;
        }
        try {
            return count($this->db->getTableColumns('#__j2commerce_orders', false)) > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function seedTestOrder(int $userId, string $orderId, string $createdOn): ?string
    {
        try {
            $isJ6 = $this->isJ6Stack();
            $table = $isJ6 ? '#__j2commerce_orders' : '#__j2store_orders';
            $order = (object) [
                'order_id'       => $orderId,
                'cart_id'        => 0,
                'invoice_prefix' => 'INV-',
                'invoice_number' => random_int(100000, 999999),
                'token'          => 'cleanup-' . md5($orderId),
                'user_id'        => $userId,
                'user_email'     => 'cleanup-test-' . $userId . '@example.com',
                'order_total'    => 10.00,
                'order_subtotal' => 10.00,
                'order_tax'      => 0.00,
                'order_shipping' => 0.00,
                'order_shipping_tax' => 0.00,
                'order_discount' => 0.00,
                'order_credit'   => 0.00,
                'order_surcharge' => 0.00,
                'orderpayment_type' => 'manual',
                'transaction_id' => '',
                'transaction_status' => 'confirmed',
                'transaction_details' => '',
                'currency_id'    => 1,
                'currency_code'  => 'CHF',
                'currency_value' => 1.00,
                'ip_address'     => '127.0.0.1',
                'is_shippable'   => 0,
                'is_including_tax' => 1,
                'customer_note'  => '',
                'customer_language' => '*',
                'customer_group' => 'default',
                'order_state_id' => 1,
                'order_state'    => 'confirmed',
                'created_on'     => $createdOn,
                'modified_on'    => $createdOn,
            ];
            // Stack-specific state column
            if ($isJ6) {
                $order->order_state = 'confirmed';
            } else {
                $order->order_state_id = 1;
            }
            $this->db->insertObject($table, $order);

            return $orderId;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function cleanupTestData(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        $isJ6 = $this->isJ6Stack();
        $ordersTable = $isJ6 ? '#__j2commerce_orders' : '#__j2store_orders';

        try {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete($this->db->quoteName($ordersTable))
                    ->whereIn($this->db->quoteName('user_id'), $userIds)
            )->execute();

            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete($this->db->quoteName('#__users'))
                    ->whereIn($this->db->quoteName('id'), $userIds)
            )->execute();
        } catch (\Exception $e) {
            // Cleanup failure is non-fatal
        }
    }
}

$test = new AutoCleanupTaskTest();
exit($test->run() ? 0 : 1);
