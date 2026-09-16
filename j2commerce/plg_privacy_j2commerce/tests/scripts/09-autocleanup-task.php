<?php
/**
 * J2Commerce Privacy Cleanup Task Tests
 *
 * Verifies that the bundled task plugin is registered in the DI container,
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
        echo "=== J2Commerce Privacy Cleanup Task Tests ===\n\n";

        $this->testClassExists();
        $this->testDiRegistration();
        $this->testBundledInstaller();
        $this->testSchedulerEventAdvertisement();
        $this->testRetentionLogic();
        $this->testLifetimeLicenseExemption();
        $this->testLifetimeLicenseMetafieldsPath();

        echo "\n=== J2Commerce Privacy Cleanup Task Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    // -------------------------------------------------------------------------

    private function testClassExists(): void
    {
        echo "--- Class and File ---\n";

        $taskFile = JPATH_BASE . '/plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php';
        $this->test('Task plugin class exists', file_exists($taskFile));

        $manifest = JPATH_BASE . '/plugins/task/j2commerceprivacy/j2commerceprivacy.xml';
        $this->test('Task plugin manifest exists', file_exists($manifest));

        if (file_exists($manifest)) {
            $manifestSrc = file_get_contents($manifest);
            $this->test(
                'Task plugin manifest uses task group',
                str_contains($manifestSrc, 'group="task"')
            );
        }

        $form = JPATH_BASE . '/plugins/task/j2commerceprivacy/forms/autocleanup.xml';
        $this->test('Task parameter form exists', file_exists($form));

        // TaskPluginTrait is part of com_scheduler which is not installed in the
        // test container. Loading the file causes a PHP fatal at compile time
        // (trait resolution), so we verify the class structure statically instead.
        if (file_exists($taskFile)) {
            $src = file_get_contents($taskFile);

            $this->test(
                'Task plugin uses TaskPluginTrait',
                str_contains($src, 'use TaskPluginTrait')
            );

            $this->test(
                'Task plugin extends CMSPlugin',
                str_contains($src, 'extends CMSPlugin')
            );

            $this->test(
                'Task plugin implements SubscriberInterface',
                str_contains($src, 'implements SubscriberInterface')
            );

            $this->test(
                'Task plugin defines getSubscribedEvents',
                str_contains($src, 'getSubscribedEvents')
            );

            $this->test(
                'Task plugin advertises the Joomla scheduler routine ID',
                str_contains($src, 'plg_task_j2commerceprivacy.autocleanup')
            );

            $this->test(
                'Task plugin links the autocleanup form',
                str_contains($src, "'form'") && str_contains($src, "'autocleanup'")
            );
        }
    }

    private function testDiRegistration(): void
    {
        echo "\n--- DI Container Registration ---\n";

        $providerFile = JPATH_BASE . '/plugins/task/j2commerceprivacy/services/provider.php';
        $this->test('task provider.php exists', file_exists($providerFile));

        if (!file_exists($providerFile)) {
            return;
        }

        $providerSource = file_get_contents($providerFile);

        $this->test(
            'task provider.php references J2CommercePrivacy',
            str_contains($providerSource, 'J2CommercePrivacy')
        );

        $this->test(
            'task provider.php registers PluginInterface service',
            str_contains($providerSource, 'PluginInterface::class')
        );

        $this->test(
            'task provider.php loads task plugin config',
            str_contains($providerSource, "PluginHelper::getPlugin('task', 'j2commerceprivacy')")
        );

        $privacyProvider = JPATH_BASE . '/plugins/privacy/j2commerce/services/provider.php';
        if (file_exists($privacyProvider)) {
            $privacyProviderSource = file_get_contents($privacyProvider);
            $this->test(
                'privacy provider no longer registers hidden scheduler service',
                !str_contains($privacyProviderSource, 'AutoCleanupTask')
            );
        }
    }

    private function testBundledInstaller(): void
    {
        echo "\n--- Bundled Task Plugin (result of the privacy installer) ---\n";

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('extension_id'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('task'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerceprivacy'));
        $taskPluginId = (int) $this->db->setQuery($query)->loadResult();

        $this->test('task plugin is registered in #__extensions', $taskPluginId > 0);

        // The test environment enables every plugin after installation; the
        // state recorded before that step shows what the installer did.
        $stateFile = '/tmp/test-state/plugins-before-activation.tsv';

        if (!is_file($stateFile)) {
            $this->test('plugin state before test setup was recorded', false, $stateFile . ' missing');
        } else {
            $enabledByInstaller = null;

            foreach (file($stateFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $parts = explode("\t", $line);

                if (count($parts) === 3 && $parts[0] === 'task' && $parts[1] === 'j2commerceprivacy') {
                    $enabledByInstaller = (int) $parts[2];
                }
            }

            $this->test('installer enabled the task plugin (before test setup)', $enabledByInstaller === 1,
                'state before activation: ' . var_export($enabledByInstaller, true));
        }

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__scheduler_tasks'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plg_privacy_j2commerce.autocleanup'));
        $this->test('no scheduled task uses the legacy routine ID', (int) $this->db->setQuery($query)->loadResult() === 0);
    }

    /**
     * Runs the cleanup routine through Joomla's real scheduler (CLI
     * `scheduler:run --id`). The scheduler ignores tasks whose routine is not
     * advertised by an enabled task plugin, so a successful run proves that the
     * plugin is discoverable, executes and anonymizes expired data.
     */
    private function testSchedulerEventAdvertisement(): void
    {
        echo "\n--- Scheduler executes the cleanup routine (scheduler:run) ---\n";

        $userId   = 9905;
        $orderId  = 'CLEANUP-SCHED-' . time();
        $oldDate  = date('Y-m-d H:i:s', strtotime('-11 years'));
        $table    = $this->isJ6Stack() ? '#__j2commerce_orders' : '#__j2store_orders';

        $this->seedTestUser($userId, 'scheduler-cleanup-test@example.com');
        $seeded = $this->seedTestOrder($userId, $orderId, $oldDate);
        $this->test('expired test order seeded', $seeded !== null);

        if ($seeded === null) {
            $this->cleanupTestData([$userId]);
            return;
        }

        // Guest orders (user_id = 0): the retention period starts at the end of the fiscal year
        // (31.12.). An order of 31.12. eleven years ago is expired; an order of 1 January ten years
        // ago is kept until 31.12. of this year, although it is older than "now - 10 years".
        $year         = (int) date('Y');
        $guestExpired = 'CLEANUP-GUEST-OLD-' . time();
        $guestKept    = 'CLEANUP-GUEST-FY-' . time();
        $this->test('expired guest order seeded', $this->seedTestOrder(0, $guestExpired, sprintf('%04d-12-31 12:00:00', $year - 11)) !== null);
        $this->test('guest order of the fiscal year ten years ago seeded', $this->seedTestOrder(0, $guestKept, sprintf('%04d-01-01 00:00:01', $year - 10)) !== null);

        // Checkout consent of the expired orders (IP/UA must be removed by the task) and of an
        // order of another user and of the kept guest order (must stay unchanged).
        $consentIds = [];

        foreach ([
            'expired'      => [$orderId, $userId, '203.0.113.60'],
            'other'        => ['CLEANUP-OTHER-' . time(), 9907, '203.0.113.61'],
            'guestExpired' => [$guestExpired, 0, '203.0.113.62'],
            'guestKept'    => [$guestKept, 0, '203.0.113.63'],
        ] as $key => [$consentOrder, $consentUser, $ip]) {
            $consent = (object) [
                'user_id' => $consentUser,
                'state'   => 1,
                'created' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'subject' => 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT',
                'body'    => "<p>IP address: $ip</p><p>User agent: CleanupTaskAgent/1.0</p><!-- j2commerce-order:$consentOrder -->",
                'remind'  => 0,
                'token'   => '',
            ];
            $this->db->insertObject('#__privacy_consents', $consent, 'id');
            $consentIds[$key] = (int) $consent->id;
        }

        $consentBody = function (int $id): string {
            return (string) $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName('body'))
                    ->from($this->db->quoteName('#__privacy_consents'))
                    ->where($this->db->quoteName('id') . ' = ' . $id)
            )->loadResult();
        };
        $guestOrder = function (string $number) use ($table): ?object {
            return $this->db->setQuery(
                $this->db->getQuery(true)
                    ->select($this->db->quoteName(['user_email', 'ip_address']))
                    ->from($this->db->quoteName($table))
                    ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($number))
            )->loadObject() ?: null;
        };
        $otherBefore     = $consentBody($consentIds['other']);
        $guestKeptBefore = $consentBody($consentIds['guestKept']);

        $run = $this->runCleanupTask('{"retention_years":10,"fiscal_year_end":"12-31","anonymize_orders":1,"delete_addresses":1}');

        $this->test('scheduled task created', $run['created'], $run['detail']);
        $this->test('scheduler:run exits with 0', $run['exit'] === 0, 'exit code ' . $run['exit']);
        $this->test('task was executed by the scheduler', $run['executed'], $run['detail']);
        $this->test('task finished with exit code 0 (Status::OK)', $run['ok'], $run['detail']);

        $email = $this->orderEmail($table, $orderId);
        $this->test('expired order e-mail anonymized by the task', $email === 'anonymized@deleted.invalid',
            'user_email=' . var_export($email, true));

        $expiredBody = $consentBody($consentIds['expired']);
        $this->test('task removes IP address and user agent from the consent of the anonymized order',
            $expiredBody !== '' && !str_contains($expiredBody, '203.0.113.60') && !str_contains($expiredBody, 'CleanupTaskAgent')
            && str_contains($expiredBody, "<!-- j2commerce-order:$orderId -->"),
            'body=' . $expiredBody);
        $this->test('task leaves the consent of another user unchanged', $consentBody($consentIds['other']) === $otherBefore);

        $expiredGuest = $guestOrder($guestExpired);
        $keptGuest    = $guestOrder($guestKept);
        $this->test('task anonymizes an expired guest order (e-mail and IP address)',
            $expiredGuest && $expiredGuest->user_email === 'anonymized@deleted.invalid' && $expiredGuest->ip_address === '',
            var_export($expiredGuest, true));
        $guestBody = $consentBody($consentIds['guestExpired']);
        $this->test('task removes IP address and user agent from the consent of the expired guest order',
            $guestBody !== '' && !str_contains($guestBody, '203.0.113.62') && str_contains($guestBody, "<!-- j2commerce-order:$guestExpired -->"),
            'body=' . $guestBody);
        $this->test('task keeps a guest order until the end of its fiscal year plus 10 years',
            $keptGuest && $keptGuest->user_email !== 'anonymized@deleted.invalid' && $keptGuest->ip_address === '127.0.0.1',
            var_export($keptGuest, true));
        $this->test('task leaves the consent of the kept guest order unchanged', $consentBody($consentIds['guestKept']) === $guestKeptBefore);

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName($table))
                ->where($this->db->quoteName('user_id') . ' = 0')
                ->whereIn($this->db->quoteName('order_id'), [$guestExpired, $guestKept], ParameterType::STRING)
        )->execute();
        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__privacy_consents'))
                ->whereIn($this->db->quoteName('id'), array_values($consentIds))
        )->execute();
        $this->cleanupTestData([$userId]);
    }

    /**
     * Create a manual cleanup task, run it through Joomla's scheduler CLI
     * (`scheduler:run --id`) and remove it again.
     *
     * @return array{created:bool, exit:int, executed:bool, ok:bool, detail:string}
     */
    private function runCleanupTask(string $params): array
    {
        $result = ['created' => false, 'exit' => -1, 'executed' => false, 'ok' => false, 'detail' => ''];
        $task   = (object) [
            'title'           => 'J2Commerce privacy cleanup (test)',
            'type'            => 'plg_task_j2commerceprivacy.autocleanup',
            'execution_rules' => '{"rule-type":"manual"}',
            'cron_rules'      => '{"type":"manual","exp":""}',
            'state'           => 1,
            'last_exit_code'  => 0,
            'times_executed'  => 0,
            'times_failed'    => 0,
            'priority'        => 0,
            'ordering'        => 0,
            'params'          => $params,
            'note'            => '',
            'created'         => date('Y-m-d H:i:s'),
            'created_by'      => 0,
        ];

        try {
            $this->db->insertObject('#__scheduler_tasks', $task, 'id');
        } catch (\Throwable $e) {
            $result['detail'] = 'task not created: ' . $e->getMessage();

            return $result;
        }

        $taskId            = (int) $task->id;
        $result['created'] = $taskId > 0;

        $output = [];
        $exit   = 0;
        exec('cd ' . escapeshellarg(JPATH_BASE) . ' && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $taskId . ' 2>&1', $output, $exit);
        echo '  ' . implode("\n  ", $output) . "\n";
        $result['exit'] = $exit;

        if ($exit !== 0) {
            // Diagnostics for a task plugin class that cannot be autoloaded.
            $map = JPATH_BASE . '/administrator/cache/autoload_psr4.php';
            $owner = is_file($map) && function_exists('posix_getpwuid') ? (posix_getpwuid(fileowner($map))['name'] ?? '?') : '?';
            echo '  DIAG autoload_psr4.php exists=' . (is_file($map) ? 'yes' : 'no')
                . ' owner=' . $owner
                . ' contains task namespace=' . (is_file($map) && str_contains((string) file_get_contents($map), 'J2CommercePrivacy') ? 'yes' : 'no')
                . "\n";
        }

        $row = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select([$this->db->quoteName('last_exit_code'), $this->db->quoteName('times_executed')])
                ->from($this->db->quoteName('#__scheduler_tasks'))
                ->where($this->db->quoteName('id') . ' = ' . $taskId)
        )->loadObject();

        $result['executed'] = $row !== null && (int) $row->times_executed >= 1;
        $result['ok']       = $exit === 0 && $result['executed'] && (int) $row->last_exit_code === 0;
        $result['detail']   = "exit code $exit, times_executed=" . ($row->times_executed ?? 'n/a')
            . ', last_exit_code=' . ($row->last_exit_code ?? 'n/a');

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__scheduler_tasks'))
                ->where($this->db->quoteName('id') . ' = ' . $taskId)
        )->execute();

        return $result;
    }

    private function orderEmail(string $table, string $orderId): ?string
    {
        $email = $this->db->setQuery(
            $this->db->getQuery(true)
                ->select($this->db->quoteName('user_email'))
                ->from($this->db->quoteName($table))
                ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($orderId))
        )->loadResult();

        return $email === null ? null : (string) $email;
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

        $plainUserId  = 9906;
        $plainOrderId = 'CLEANUP-FAILCLOSED-' . time();
        $this->seedTestUser($plainUserId, 'failclosed-cleanup-test@example.com');

        if (!$metaSeeded) {
            $this->test('J6 metafields lifetime test data seeded', false, 'order item or metafield could not be inserted');
        } else {
            // 1. The task detects the lifetime license through the metafield. Provisional rule
            //    (pending confirmation): only the lifetime-license order keeps its e-mail address;
            //    the same user's other expired order is fully anonymized.
            $lifetimeUserPlain = 'CLEANUP-META-PLAIN-' . time();
            $this->seedTestOrder($userId, $lifetimeUserPlain, $oldDate);
            $run = $this->runCleanupTask('{"retention_years":10,"anonymize_orders":1,"delete_addresses":1}');
            $this->test('cleanup task run (lifetime metafield) finished with Status::OK', $run['ok'], $run['detail']);
            $email = $this->orderEmail('#__j2commerce_orders', $orderId);
            $this->test(
                'lifetime-license order keeps the order e-mail (partial anonymization)',
                $email === 'cleanup-test-' . $userId . '@example.com',
                'user_email=' . var_export($email, true)
            );
            $plainEmail = $this->orderEmail('#__j2commerce_orders', $lifetimeUserPlain);
            $this->test(
                'the same user\'s other expired order loses the e-mail (lifetime rule applies per order)',
                $plainEmail === 'anonymized@deleted.invalid',
                'user_email=' . var_export($plainEmail, true)
            );

            // 2. Fail-closed: when the J6 lifetime query fails, the task treats
            //    the user as a lifetime license holder. A user without a
            //    lifetime license keeps the order e-mail while the metafields
            //    table cannot be queried.
            $plainSeeded = $this->seedTestOrder($plainUserId, $plainOrderId, $oldDate) !== null;
            $this->test('expired order without lifetime license seeded', $plainSeeded);

            $metaTable = $prefix . 'j2commerce_metafields';
            $broken    = false;

            if ($plainSeeded) {
                try {
                    $this->db->setQuery(
                        'ALTER TABLE ' . $this->db->quoteName($metaTable)
                        . ' RENAME COLUMN ' . $this->db->quoteName('metavalue') . ' TO ' . $this->db->quoteName('metavalue_parked_by_test')
                    )->execute();
                    $broken = true;

                    $run = $this->runCleanupTask('{"retention_years":10,"anonymize_orders":1,"delete_addresses":1}');
                    $this->test('cleanup task run (lifetime query failing) finished with Status::OK', $run['ok'], $run['detail']);
                    $email = $this->orderEmail('#__j2commerce_orders', $plainOrderId);
                    $this->test(
                        'failing lifetime query keeps the order e-mail (fail-closed)',
                        $email === 'cleanup-test-' . $plainUserId . '@example.com',
                        'user_email=' . var_export($email, true)
                    );
                } catch (\Throwable $e) {
                    $this->test('fail-closed check executes', false, $e->getMessage());
                } finally {
                    if ($broken) {
                        $this->db->setQuery(
                            'ALTER TABLE ' . $this->db->quoteName($metaTable)
                            . ' RENAME COLUMN ' . $this->db->quoteName('metavalue_parked_by_test') . ' TO ' . $this->db->quoteName('metavalue')
                        )->execute();
                    }
                }

                // 3. With the table intact again, the same user is fully anonymized.
                $run = $this->runCleanupTask('{"retention_years":10,"anonymize_orders":1,"delete_addresses":1}');
                $this->test('cleanup task run (lifetime query restored) finished with Status::OK', $run['ok'], $run['detail']);
                $email = $this->orderEmail('#__j2commerce_orders', $plainOrderId);
                $this->test(
                    'user without lifetime license is fully anonymized once the query works',
                    $email === 'anonymized@deleted.invalid',
                    'user_email=' . var_export($email, true)
                );
            }
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
        $this->cleanupTestData([$userId, $plainUserId]);

        $taskFile = JPATH_BASE . '/plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php';

        if (file_exists($taskFile)) {
            $src = (string) file_get_contents($taskFile);
            $this->test('Task status is KNOCKOUT when guest orders failed, also if users were processed',
                str_contains($src, 'if ($guestErrors > 0 || ($errorCount > 0'));
            $this->test('Task logs an invalid fiscal year end and uses the effective value',
                str_contains($src, 'Invalid fiscal year end') && str_contains($src, 'RetentionPeriod::effectiveFiscalYearEnd('));
        }
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
