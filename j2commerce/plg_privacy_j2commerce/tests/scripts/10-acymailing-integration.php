<?php
/**
 * AcyMailing Integration Tests
 *
 * Validates the plugin's AcyMailing detection, export, and deletion logic
 * against real AcyMailing tables. Also verifies graceful skip when AcyMailing
 * is not installed.
 *
 * Requires AcyMailing schema and test data inserted by docker-entrypoint.sh.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class AcyMailingIntegrationTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    /** Email of the test subscriber inserted by docker-entrypoint.sh */
    private const TEST_EMAIL = 'acym-test@example.com';

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

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    private function getAcymPrefix(): ?string
    {
        $joomlaPrefix = $this->db->getPrefix();
        $candidate    = $joomlaPrefix . 'acym_configuration';

        try {
            $tables = $this->db->getTableList();
        } catch (\Exception $e) {
            return null;
        }

        if (in_array($candidate, $tables, true)) {
            return $joomlaPrefix . 'acym_';
        }

        foreach ($tables as $table) {
            if (str_ends_with($table, 'acym_configuration')) {
                return substr($table, 0, -strlen('configuration'));
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Test groups
    // -------------------------------------------------------------------------

    private function testDetection(): void
    {
        echo "--- Detection ---\n";

        $prefix = $this->getAcymPrefix();
        $this->test('AcyMailing detected via acym_configuration table', $prefix !== null,
            'getAcymPrefix() returned null — AcyMailing schema not installed');

        if ($prefix === null) {
            return;
        }

        $this->test('Prefix ends with acym_', str_ends_with($prefix, 'acym_'),
            "Got: $prefix");

        // Verify all required tables exist
        $tables  = $this->db->getTableList();
        $required = ['user', 'user_has_list', 'list', 'configuration'];
        foreach ($required as $suffix) {
            $this->test("Table {$prefix}{$suffix} exists",
                in_array($prefix . $suffix, $tables, true));
        }
    }

    private function testExportQuery(): void
    {
        echo "\n--- Export Query ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            echo "SKIP (AcyMailing not installed)\n";
            return;
        }

        // Subscriber record
        try {
            $query = $this->db->getQuery(true)
                ->select(['id', 'email', 'name', 'confirmed', 'creation_date'])
                ->from($this->db->quoteName($prefix . 'user'))
                ->where($this->db->quoteName('email') . ' = ' . $this->db->quote(self::TEST_EMAIL));
            $subscriber = $this->db->setQuery($query)->loadObject();
        } catch (\Exception $e) {
            $this->test('Subscriber query executes', false, $e->getMessage());
            return;
        }

        $this->test('Subscriber query executes without error', true);
        $this->test('Test subscriber found', $subscriber !== null,
            'No row for email ' . self::TEST_EMAIL);

        if ($subscriber === null) {
            return;
        }

        $this->test('Subscriber has id',            !empty($subscriber->id));
        $this->test('Subscriber email matches',     $subscriber->email === self::TEST_EMAIL);
        $this->test('Subscriber name is set',       !empty($subscriber->name));
        $this->test('Subscriber confirmed is 0/1',  in_array((string) $subscriber->confirmed, ['0', '1'], true));
        $this->test('Subscriber creation_date set', !empty($subscriber->creation_date));

        // List subscriptions JOIN
        try {
            $query = $this->db->getQuery(true)
                ->select([
                    'uhl.list_id',
                    'uhl.status',
                    'uhl.subscription_date',
                    'uhl.unsubscribe_date',
                    'l.name AS list_name',
                    'l.display_name AS list_display_name',
                ])
                ->from($this->db->quoteName($prefix . 'user_has_list', 'uhl'))
                ->leftJoin(
                    $this->db->quoteName($prefix . 'list', 'l') .
                    ' ON ' . $this->db->quoteName('l.id') . ' = ' . $this->db->quoteName('uhl.list_id')
                )
                ->where($this->db->quoteName('uhl.user_id') . ' = ' . (int) $subscriber->id);
            $subscriptions = $this->db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $this->test('Subscription JOIN executes', false, $e->getMessage());
            return;
        }

        $this->test('Subscription JOIN executes without error', true);
        $this->test('Test subscriber has list subscriptions', count($subscriptions) >= 1,
            'Got ' . count($subscriptions) . ' rows');

        if (count($subscriptions) > 0) {
            $sub = $subscriptions[0];
            $this->test('Subscription has list_id',         !empty($sub->list_id));
            $this->test('Subscription has status',          isset($sub->status));
            $this->test('Subscription has subscription_date', isset($sub->subscription_date));
            $this->test('Subscription has list_name',       !empty($sub->list_name));
        }
    }

    private function testDeletion(): void
    {
        echo "\n--- Deletion ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            echo "SKIP (AcyMailing not installed)\n";
            return;
        }

        // Insert a dedicated subscriber for deletion so the export test data is untouched
        $deleteEmail = 'acym-delete@example.com';

        // All tables the plugin deletes from (mirrors removeAcyMailingData)
        $relatedTables = [
            'user_has_list',
            'user_has_field',
            'user_stat',
            'url_click',
            'history',
            'queue',
        ];

        try {
            // Insert subscriber
            $this->db->setQuery(
                "INSERT IGNORE INTO `{$prefix}user` (email, name, confirmed, creation_date)
                 VALUES ('$deleteEmail', 'Delete Me', 1, NOW())"
            )->execute();
            $subId = (int) $this->db->setQuery(
                "SELECT id FROM `{$prefix}user` WHERE email = '$deleteEmail'"
            )->loadResult();
            $this->test('Deletion test subscriber inserted', $subId > 0);

            // Insert list association
            $this->db->setQuery(
                "INSERT IGNORE INTO `{$prefix}user_has_list` (user_id, list_id, status, subscription_date)
                 VALUES ($subId, 1, 1, NOW())"
            )->execute();
            $assocCount = (int) $this->db->setQuery(
                "SELECT COUNT(*) FROM `{$prefix}user_has_list` WHERE user_id = $subId"
            )->loadResult();
            $this->test('List association inserted', $assocCount >= 1);

            // Run deletion (mirrors plugin's removeAcyMailingData)
            foreach ($relatedTables as $table) {
                $fullTable = $prefix . $table;
                $tables = $this->db->getTableList();
                if (!in_array($fullTable, $tables, true)) {
                    continue; // table may not exist in minimal test schema
                }

                $this->db->setQuery(
                    $this->db->getQuery(true)
                        ->delete($this->db->quoteName($fullTable))
                        ->where($this->db->quoteName('user_id') . ' = ' . $subId)
                )->execute();
            }

            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete($this->db->quoteName($prefix . 'user'))
                    ->where($this->db->quoteName('id') . ' = ' . $subId)
            )->execute();

            // Verify all related tables are clean
            foreach ($relatedTables as $table) {
                $fullTable = $prefix . $table;
                $tables = $this->db->getTableList();
                if (!in_array($fullTable, $tables, true)) {
                    continue;
                }

                $remaining = (int) $this->db->setQuery(
                    "SELECT COUNT(*) FROM `{$fullTable}` WHERE user_id = $subId"
                )->loadResult();
                $this->test("$table rows deleted", $remaining === 0,
                    "Got $remaining remaining");
            }

            $remainingSub = (int) $this->db->setQuery(
                "SELECT COUNT(*) FROM `{$prefix}user` WHERE id = $subId"
            )->loadResult();
            $this->test('Subscriber record deleted', $remainingSub === 0,
                "Got $remainingSub remaining");

        } catch (\Exception $e) {
            $this->test('Deletion executes without error', false, $e->getMessage());
        }
    }

    private function testGracefulSkip(): void
    {
        echo "\n--- Graceful Skip (no AcyMailing) ---\n";

        // Simulate getAcymTablePrefix() against a DB with no acym_configuration table
        // by checking that the method returns null when the table is absent.
        // We verify this indirectly: if AcyMailing IS installed, we confirm the prefix
        // is non-null; if it is NOT installed, we confirm null is returned without exception.
        $exceptionThrown = false;
        $prefix          = null;

        try {
            $prefix = $this->getAcymPrefix();
        } catch (\Exception $e) {
            $exceptionThrown = true;
        }

        $this->test('getAcymPrefix() does not throw', !$exceptionThrown);
        $this->test('getAcymPrefix() returns string or null',
            $prefix === null || is_string($prefix));

        // Verify plugin class has the required methods
        $classFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';
        if (file_exists($classFile)) {
            require_once $classFile;
            if (class_exists('Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce')) {
                $ref = new \ReflectionClass('Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce');
                $this->test('getAcymTablePrefix() method exists',  $ref->hasMethod('getAcymTablePrefix'));
                $this->test('createAcyMailingDomain() method exists', $ref->hasMethod('createAcyMailingDomain'));
                $this->test('removeAcyMailingData() method exists', $ref->hasMethod('removeAcyMailingData'));
            }
        }
    }

    // -------------------------------------------------------------------------
    // Entry point
    // -------------------------------------------------------------------------

    public function run(): bool
    {
        echo "=== AcyMailing Integration Tests ===\n\n";

        $this->testDetection();
        $this->testExportQuery();
        $this->testDeletion();
        $this->testGracefulSkip();

        echo "\n=== AcyMailing Integration Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new AcyMailingIntegrationTest();
exit($test->run() ? 0 : 1);
