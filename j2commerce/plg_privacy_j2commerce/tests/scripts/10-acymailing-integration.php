<?php
/**
 * AcyMailing Integration Tests
 *
 * Validates the AcyMailing schema the plugin relies on, the export queries,
 * the removal of a subscriber through the plugin's privacy removal handler
 * (onPrivacyRemoveData) and that the handler leaves AcyMailing data alone when
 * AcyMailing is not installed.
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
use Joomla\CMS\User\User;

class AcyMailingIntegrationTest
{
    private $db;
    private int $passed = 0;
    private int $failed = 0;

    /** Email of the test subscriber inserted by docker-entrypoint.sh */
    private const TEST_EMAIL = 'acym-test@example.com';

    /** Joomla user ids without J2Commerce data, used for the removal handler */
    private const DELETE_USER_ID = 999101;
    private const KEEP_USER_ID   = 999102;

    /** Tables the plugin clears for a subscriber before deleting the subscriber record */
    private const RELATED_TABLES = [
        'user_has_list',
        'user_has_field',
        'user_stat',
        'url_click',
        'history',
        'queue',
    ];

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
    // Helpers
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

    /**
     * The installed privacy plugin, created the way 05-data-anonymization.php
     * does it. A missing plugin is a failure: the test environment installs it.
     */
    private function createPlugin(): ?object
    {
        $classFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';

        if (!is_file($classFile)) {
            $this->test('privacy plugin is installed', false, "missing $classFile");

            return null;
        }

        try {
            if (!class_exists(\Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class, false)) {
                require_once $classFile;
            }

            $plugin = new \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce(
                new \Joomla\Event\Dispatcher(),
                ['params' => new \Joomla\Registry\Registry([])]
            );
            $plugin->setDatabase(Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class));

            return $plugin;
        } catch (\Throwable $e) {
            $this->test('privacy plugin can be created', false, $e->getMessage());

            return null;
        }
    }

    /**
     * Run the plugin's privacy removal handler for a user. The Joomla 4 call
     * form (request, user) is used: it reaches the same removal code as the
     * Joomla 5/6 event object without needing a privacy request record.
     */
    private function removeThroughPlugin(object $plugin, int $userId, string $email): ?string
    {
        $user           = new User();
        $user->id       = $userId;
        $user->username = 'acym-privacy-test-' . $userId;
        $user->email    = $email;

        try {
            $plugin->onPrivacyRemoveData(null, $user);
        } catch (\Throwable $e) {
            return get_class($e) . ': ' . $e->getMessage();
        }

        return null;
    }

    private function insertSubscriber(string $prefix, string $email, string $name): int
    {
        $this->db->setQuery(
            'INSERT IGNORE INTO ' . $this->db->quoteName($prefix . 'user')
            . ' (email, name, confirmed, creation_date) VALUES ('
            . $this->db->quote($email) . ', ' . $this->db->quote($name) . ', 1, NOW())'
        )->execute();

        return (int) $this->db->setQuery(
            'SELECT id FROM ' . $this->db->quoteName($prefix . 'user')
            . ' WHERE email = ' . $this->db->quote($email)
        )->loadResult();
    }

    private function countRows(string $table, string $column, int $id): int
    {
        return (int) $this->db->setQuery(
            'SELECT COUNT(*) FROM ' . $this->db->quoteName($table)
            . ' WHERE ' . $this->db->quoteName($column) . ' = ' . $id
        )->loadResult();
    }

    private function strictSkip(string $name, string $reason): void
    {
        if (getenv('TEST_STRICT_SKIP') === '1') {
            $this->test($name, false, $reason);
        } else {
            echo "SKIP $name ($reason)\n";
        }
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
            $this->strictSkip('AcyMailing schema available', 'the test environment installs it; getAcymPrefix() returned null');
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
        echo "\n--- Deletion through onPrivacyRemoveData ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            $this->strictSkip('AcyMailing schema available', 'the test environment installs it; getAcymPrefix() returned null');
            return;
        }

        $plugin = $this->createPlugin();
        if ($plugin === null) {
            return;
        }

        // A real AcyMailing installation has every related table. The test
        // schema only has user_has_list, so the others are created here with
        // the column the plugin uses (user_id) and dropped afterwards.
        $created     = [];
        $deleteEmail = 'acym-delete@example.com';
        $subId       = 0;

        try {
            $tables = $this->db->getTableList();

            foreach (self::RELATED_TABLES as $table) {
                if (!in_array($prefix . $table, $tables, true)) {
                    $this->db->setQuery(
                        'CREATE TABLE ' . $this->db->quoteName($prefix . $table)
                        . ' (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL) ENGINE=InnoDB'
                    )->execute();
                    $created[] = $prefix . $table;
                }
            }

            $subId = $this->insertSubscriber($prefix, $deleteEmail, 'Delete Me');
            $this->test('Deletion test subscriber inserted', $subId > 0);

            if ($subId <= 0) {
                return;
            }

            foreach (self::RELATED_TABLES as $table) {
                $sql = $table === 'user_has_list'
                    ? 'INSERT IGNORE INTO ' . $this->db->quoteName($prefix . $table)
                        . " (user_id, list_id, status, subscription_date) VALUES ($subId, 1, 1, NOW())"
                    : 'INSERT INTO ' . $this->db->quoteName($prefix . $table) . " (user_id) VALUES ($subId)";
                $this->db->setQuery($sql)->execute();
                $this->test("$table row inserted", $this->countRows($prefix . $table, 'user_id', $subId) >= 1);
            }

            $error = $this->removeThroughPlugin($plugin, self::DELETE_USER_ID, $deleteEmail);
            $this->test('onPrivacyRemoveData runs without error', $error === null, (string) $error);

            foreach (self::RELATED_TABLES as $table) {
                $remaining = $this->countRows($prefix . $table, 'user_id', $subId);
                $this->test("$table rows deleted by the plugin", $remaining === 0, "Got $remaining remaining");
            }

            $remainingSub = $this->countRows($prefix . 'user', 'id', $subId);
            $this->test('Subscriber record deleted by the plugin', $remainingSub === 0, "Got $remainingSub remaining");

            $otherSub = (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName($prefix . 'user')
                . ' WHERE email = ' . $this->db->quote(self::TEST_EMAIL)
            )->loadResult();
            $this->test('Other subscribers are kept', $otherSub === 1, "Got $otherSub rows for " . self::TEST_EMAIL);
        } catch (\Exception $e) {
            $this->test('Deletion test executes without error', false, $e->getMessage());
        } finally {
            if ($subId > 0) {
                foreach (self::RELATED_TABLES as $table) {
                    try {
                        $this->db->setQuery(
                            'DELETE FROM ' . $this->db->quoteName($prefix . $table) . ' WHERE user_id = ' . $subId
                        )->execute();
                    } catch (\Exception $e) {
                        // table may already be gone
                    }
                }
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE id = ' . $subId)->execute();
            }

            foreach ($created as $table) {
                $this->db->setQuery('DROP TABLE IF EXISTS ' . $this->db->quoteName($table))->execute();
            }
        }
    }

    private function testGracefulSkip(): void
    {
        echo "\n--- Without AcyMailing ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            $this->strictSkip('AcyMailing schema available', 'the test environment installs it; getAcymPrefix() returned null');
            return;
        }

        $plugin = $this->createPlugin();
        if ($plugin === null) {
            return;
        }

        // AcyMailing counts as installed only while <prefix>acym_configuration
        // exists. With that table renamed, the removal handler must not touch
        // the subscriber tables and must not fail.
        $keepEmail = 'acym-keep@example.com';
        $config    = $prefix . 'configuration';
        $parked    = $prefix . 'cfg_parked_by_test';
        $renamed   = false;
        $subId     = 0;

        try {
            $subId = $this->insertSubscriber($prefix, $keepEmail, 'Keep Me');
            $this->test('Subscriber for the no-AcyMailing case inserted', $subId > 0);

            $this->db->setQuery(
                'RENAME TABLE ' . $this->db->quoteName($config) . ' TO ' . $this->db->quoteName($parked)
            )->execute();
            $renamed = true;
            $this->test('AcyMailing no longer detected', $this->getAcymPrefix() === null);

            $error = $this->removeThroughPlugin($plugin, self::KEEP_USER_ID, $keepEmail);
            $this->test('onPrivacyRemoveData runs without error when AcyMailing is missing', $error === null, (string) $error);

            $kept = $this->countRows($prefix . 'user', 'id', $subId);
            $this->test('Subscriber data untouched when AcyMailing is missing', $kept === 1, "Got $kept rows");
        } catch (\Exception $e) {
            $this->test('No-AcyMailing test executes without error', false, $e->getMessage());
        } finally {
            if ($renamed) {
                $this->db->setQuery(
                    'RENAME TABLE ' . $this->db->quoteName($parked) . ' TO ' . $this->db->quoteName($config)
                )->execute();
            }

            if ($subId > 0) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE id = ' . $subId)->execute();
            }
        }

        $this->test('AcyMailing detected again after the test', $this->getAcymPrefix() === $prefix);
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
