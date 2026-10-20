<?php
/**
 * AcyMailing Integration Tests
 *
 * Validates the AcyMailing schema the plugin relies on, the export queries,
 * the removal of a subscriber through the plugin's privacy removal handler
 * (onPrivacyRemoveData) and that the handler leaves AcyMailing data alone when
 * AcyMailing is not installed. The shop is told before a subscriber goes
 * (onJ2CommercePrivacyBeforeNewsletterRemoval), and a failing listener keeps it.
 *
 * Requires AcyMailing schema and test data inserted by docker-entrypoint.sh.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

// Extension namespaces (com_privacy Status) as the application would load them.
JLoader::register('JNamespacePsr4Map', JPATH_LIBRARIES . '/namespacemap.php');
(new JNamespacePsr4Map())->load();

use Joomla\CMS\Application\AdministratorApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Session\Session;
use Joomla\CMS\User\User;
use Joomla\Event\DispatcherInterface;
use Joomla\Session\SessionInterface;
use Joomla\Session\Storage\RuntimeStorage;

/**
 * The administrator application of a com_privacy request, with a guest identity and the plugin
 * language: PluginHelper::importPlugin() reads the view levels of the current user from it, and
 * the plugin enqueues its messages there. The session is held in memory (no cookie, no row).
 */
function bootAdministratorApplication(): AdministratorApplication
{
    $container = Factory::getContainer();
    $container->share(
        'session.acymtest',
        static fn ($c) => new Session(new RuntimeStorage(), $c->get(DispatcherInterface::class)),
        true
    );

    foreach (['session.web', 'session', 'JSession', Session::class, \Joomla\Session\Session::class, SessionInterface::class] as $alias) {
        $container->alias($alias, 'session.acymtest');
    }

    $app      = $container->get(AdministratorApplication::class);
    $language = $container->get(LanguageFactoryInterface::class)->createLanguage('en-GB');
    $language->load('plg_privacy_j2commerce', JPATH_BASE . '/plugins/privacy/j2commerce');
    $app->loadLanguage($language);
    $app->loadIdentity(new User());
    Factory::$application = $app;
    Factory::$language    = $language;

    return $app;
}

class AcyMailingIntegrationTest
{
    private $db;
    private AdministratorApplication $app;
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
        $this->db  = Factory::getContainer()->get('DatabaseDriver');
        $this->app = bootAdministratorApplication();
    }

    /** Messages enqueued since the last call, as "type: text" (the queue is cleared). */
    private function takeMessages(): array
    {
        return array_map(
            static fn (array $message): string => $message['type'] . ': ' . $message['message'],
            $this->app->getMessageQueue(true)
        );
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

    /**
     * Owner decision 2026-10-19 («Abmeldung bleibt nach Löschbegehren»): before a subscriber is
     * removed, the plugin fires onJ2CommercePrivacyBeforeNewsletterRemoval with the subscriber's
     * e-mail, while the subscriber still exists, so a shop plugin can keep a suppression record. If
     * a listener throws, the subscriber and its unsubscribed list row are kept and the removal
     * request itself still completes.
     */
    private function testUnsubscribeOutlivesRemoval(): void
    {
        echo "\n--- The unsubscribe outlives the removal request ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            $this->strictSkip('AcyMailing schema available', 'the test environment installs it; getAcymPrefix() returned null');
            return;
        }

        $plugin = $this->createPlugin();
        if ($plugin === null) {
            return;
        }

        $class = \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class;
        $name  = $class::EVENT_BEFORE_NEWSLETTER_REMOVAL;
        $this->test('Event name is onJ2CommercePrivacyBeforeNewsletterRemoval', $name === 'onJ2CommercePrivacyBeforeNewsletterRemoval');

        $this->test('Administrator application with an identity registered', Factory::getApplication() === $this->app && $this->app->getIdentity() !== null);
        $dispatcher = (new \ReflectionMethod($plugin, 'newsletterDispatcher'))->invoke($plugin);
        $this->test('Listener test uses the application dispatcher', $dispatcher === $this->app->getDispatcher());
        $strings    = $this->app->getLanguage();
        $doneText   = $strings->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_NOTHING_RETAINED');
        $keptText   = $strings->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_NOTHING_RETAINED_NEWSLETTER_KEPT');
        $this->test('Plugin language loaded for the removal messages', !str_starts_with($doneText, 'PLG_') && !str_starts_with($keptText, 'PLG_'));
        $email      = 'acym-suppress@example.com';
        $count      = function () use ($prefix, $email): int {
            return (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE email = ' . $this->db->quote($email)
            )->loadResult();
        };
        $unsubscribed = function (int $subId) use ($prefix): int {
            return (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName($prefix . 'user_has_list') . ' WHERE user_id = ' . $subId . ' AND status = 0'
            )->loadResult();
        };
        $insert = function () use ($prefix, $email): int {
            $subId = $this->insertSubscriber($prefix, $email, 'Unsubscribed Earlier');
            $this->db->setQuery(
                'INSERT IGNORE INTO ' . $this->db->quoteName($prefix . 'user_has_list')
                . " (user_id, list_id, status, subscription_date) VALUES ($subId, 1, 0, NOW())"
            )->execute();

            return $subId;
        };
        $cleanup = function () use ($prefix, $email): void {
            $ids = $this->db->setQuery(
                'SELECT id FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE email = ' . $this->db->quote($email)
            )->loadColumn() ?: [];

            foreach (array_map('intval', $ids) as $id) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user_has_list') . ' WHERE user_id = ' . $id)->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE id = ' . $id)->execute();
            }
        };

        // 1. A listener that succeeds: it is called once, with the e-mail, while the subscriber exists.
        $seen     = [];
        $listener = function ($event) use (&$seen, $count): void {
            $seen[] = [(string) $event->getArgument('email', ''), $count()];
        };
        $dispatcher->addListener($name, $listener);

        try {
            $cleanup();
            $subId = $insert();
            $this->test('Unsubscribed subscriber inserted', $subId > 0 && $unsubscribed($subId) === 1);
            $this->takeMessages();
            $error = $this->removeThroughPlugin($plugin, self::DELETE_USER_ID, $email);
            $this->test('Removal runs without error', $error === null, (string) $error);
            $this->test('Listener called once with the subscriber e-mail, before the subscriber was deleted', $seen === [[$email, 1]], json_encode($seen));
            $this->test('Subscriber deleted after the listener succeeded', $count() === 0);
            $messages = $this->takeMessages();
            $this->test('Administrator message names the newsletter data as removed', $messages === ['message: ' . $doneText], json_encode($messages));
        } catch (\Throwable $e) {
            $this->test('Successful-listener case executes without error', false, $e->getMessage());
        } finally {
            $dispatcher->removeListener($name, $listener);
            $cleanup();
        }

        // 2. A listener that throws: the subscriber and its unsubscribed row stay, the request completes.
        $failing = function (): void {
            throw new \RuntimeException('suppression record not stored');
        };
        $dispatcher->addListener($name, $failing);

        try {
            $subId = $insert();
            $this->takeMessages();
            $error = $this->removeThroughPlugin($plugin, self::DELETE_USER_ID, $email);
            $this->test('Removal request still completes when the listener throws', $error === null, (string) $error);
            $this->test('Subscriber kept when the listener throws', $count() === 1);
            $this->test('Unsubscribed list row kept when the listener throws', $unsubscribed($subId) === 1);
            $messages = $this->takeMessages();
            $this->test('Administrator gets the warning and a removal message that names the subscriber as kept',
                $messages === ['warning: ' . $strings->_('PLG_PRIVACY_J2COMMERCE_ACYM_REMOVAL_KEPT'), 'warning: ' . $keptText], json_encode($messages));
            $this->takeMessages();
            $error = $this->removeThroughPlugin($plugin, self::KEEP_USER_ID, 'acym-no-subscriber@example.com');
            $messages = $this->takeMessages();
            $this->test('Next removal request without a subscriber: the kept state is not carried over', $error === null && $messages === ['message: ' . $doneText], json_encode($messages));
        } catch (\Throwable $e) {
            $this->test('Failing-listener case executes without error', false, $e->getMessage());
        } finally {
            $dispatcher->removeListener($name, $failing);
            $cleanup();
        }
    }

    /**
     * Joomla's privacy user plugin pseudonymises name, username and e-mail of the User object of
     * the request in onPrivacyRemoveData() and can run before this plugin (same ordering, lower
     * extension ID). com_privacy dispatches onPrivacyCanRemoveData() to all plugins first.
     * Both cases are run in that order: the subscriber is found (request e-mail, or the account
     * e-mail captured in onPrivacyCanRemoveData()) and the administrator notification gets the
     * original username.
     */
    private function testRemovalAfterPseudonymisation(): void
    {
        echo "\n--- Removal request after the core user plugin ---\n";

        $prefix = $this->getAcymPrefix();
        if ($prefix === null) {
            $this->strictSkip('AcyMailing schema available', 'the test environment installs it; getAcymPrefix() returned null');
            return;
        }

        $classFile = JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php';
        $class     = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';

        if (!class_exists($class) && is_file($classFile)) {
            require_once $classFile;
        }

        if (!$this->test('Plugin class loadable for the removal flow', class_exists($class))
            || !$this->test('com_privacy Status class loadable', class_exists('Joomla\\Component\\Privacy\\Administrator\\Removal\\Status'))) {
            return;
        }

        foreach (['request e-mail' => true, 'account e-mail captured before the removal' => false] as $label => $withRequestEmail) {
            $userId   = $withRequestEmail ? 9907 : 9908;
            $email    = 'acym-pseudonymised-' . $userId . '@example.com';
            $username = 'acym-original-' . $userId;

            try {
                $subId = $this->insertSubscriber($prefix, $email, 'Pseudo Test');
                $this->db->setQuery(
                    'INSERT IGNORE INTO ' . $this->db->quoteName($prefix . 'user_has_list')
                    . " (user_id, list_id, status, subscription_date) VALUES ($subId, 1, 1, NOW())"
                )->execute();
                $this->test("[$label] subscriber inserted", $subId > 0);

                $plugin = new class (['params' => new \Joomla\Registry\Registry(['anonymize_orders' => 0, 'delete_addresses' => 0])]) extends \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce {
                    public array $notifiedUsernames = [];

                    protected function sendAdminNotification(string $action, User $user, string $details = '', ?string $username = null): void
                    {
                        $this->notifiedUsernames[] = $username ?? $user->username;
                    }
                };
                $plugin->setDatabase($this->db);

                // One User object for both events, as RemoveModel::removeDataForRequest() does.
                $user           = new User();
                $user->id       = $userId;
                $user->name     = 'Original Name';
                $user->username = $username;
                $user->email    = $email;

                $status = $plugin->onPrivacyCanRemoveData(null, $user);
                $this->test("[$label] removal request allowed", $status->canRemove === true);

                // plg_privacy_user::onPrivacyRemoveData() runs first and binds pseudonymised values.
                $user->name     = 'User ID ' . $userId;
                $user->username = bin2hex(random_bytes(12));
                $user->email    = 'UserID' . $userId . 'removed@email.invalid';

                if ($withRequestEmail) {
                    $method = new \ReflectionMethod($plugin, 'processDataRemoval');
                    $method->setAccessible(true);
                    $method->invoke($plugin, $user, $email);
                } else {
                    $plugin->onPrivacyRemoveData(null, $user);
                }

                $this->test("[$label] subscriber removed although the account is pseudonymised",
                    $this->countRows($prefix . 'user', 'id', $subId) === 0 && $this->countRows($prefix . 'user_has_list', 'user_id', $subId) === 0);
                $this->test("[$label] administrator notification uses the original username",
                    $plugin->notifiedUsernames === [$username], json_encode($plugin->notifiedUsernames));

                // The capture is removed after the request: a second removal uses the current values.
                $plugin->notifiedUsernames = [];
                $plugin->onPrivacyRemoveData(null, $user);
                $this->test("[$label] capture is not kept after the request",
                    $plugin->notifiedUsernames === [$user->username], json_encode($plugin->notifiedUsernames));
            } catch (\Throwable $e) {
                $this->test("[$label] removal flow runs without error", false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            } finally {
                $this->db->setQuery(
                    'DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE email = ' . $this->db->quote($email)
                )->execute();
            }
        }

        $this->test('AcyMailing detected again after the test', $this->getAcymPrefix() === $prefix);
    }
    /**
     * A privacy request with a request address and a different account address, both subscribed:
     * the export contains both subscribers with their own item IDs.
     */
    private function testExportBothAddresses(): void
    {
        echo "\n--- Export: request and account address both subscribed ---\n";

        $prefix = $this->getAcymPrefix();
        $plugin = $this->createPlugin();

        if ($prefix === null || $plugin === null) {
            $this->strictSkip('export of two subscribers', 'AcyMailing tables or plugin missing');

            return;
        }

        $requestEmail = 'acym-request-' . random_int(1000, 9999) . '@example.com';
        $accountEmail = 'acym-account-' . random_int(1000, 9999) . '@example.com';
        $ids          = [];

        try {
            $ids[] = $requestId = $this->insertSubscriber($prefix, $requestEmail, 'Request Address');
            $ids[] = $accountId = $this->insertSubscriber($prefix, $accountEmail, 'Account Address');

            $method = new \ReflectionMethod($plugin, 'createAcyMailingDomain');
            $method->setAccessible(true);
            $domain = $method->invoke($plugin, null, [$requestEmail, $accountEmail]);
            $itemIds = $domain ? array_map(static fn ($item): string => (string) $item->id, $domain->getItems()) : [];

            $this->test('export contains the subscriber of the request address', in_array('subscriber_' . $requestId . '_subscriber', $itemIds, true), json_encode($itemIds));
            $this->test('export contains the subscriber of the account address', in_array('subscriber_' . $accountId . '_subscriber', $itemIds, true), json_encode($itemIds));
            $this->test('item IDs are unique', count($itemIds) === count(array_unique($itemIds)), json_encode($itemIds));

            $single  = $method->invoke($plugin, null, [$requestEmail]);
            $singleIds = $single ? array_map(static fn ($item): string => (string) $item->id, $single->getItems()) : [];
            $this->test('one subscriber keeps the item ID "subscriber"', in_array('subscriber', $singleIds, true), json_encode($singleIds));
        } catch (\Throwable $e) {
            $this->test('export of two subscribers ran without error', false, $e->getMessage());
        } finally {
            foreach ($ids as $id) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE id = ' . (int) $id)->execute();
            }
        }
    }

    /**
     * Addresses equal only under the column collation (accents, sharp s) belong to different
     * people: a request of A neither exports nor deletes the subscriber B. The same address in
     * other letter case still matches.
     */
    private function testCollationPairs(): void
    {
        echo "\n--- Export and removal: addresses equal only under the column collation ---\n";

        $prefix = $this->getAcymPrefix();
        $plugin = $this->createPlugin();

        if ($prefix === null || $plugin === null) {
            $this->strictSkip('collation pairs', 'AcyMailing tables or plugin missing');

            return;
        }

        $suffix = (string) random_int(10000, 99999);
        $pairs  = [
            'muller'  => ['acym-muller-' . $suffix . '@example.com', 'acym-müller-' . $suffix . '@example.com'],
            'strasse' => ['acym-strasse-' . $suffix . '@example.com', "acym-stra\u{00DF}e-" . $suffix . '@example.com'],
            'resume'  => ['acym-resume-' . $suffix . '@example.com', 'acym-résumé-' . $suffix . '@example.com'],
        ];
        $ids    = [];
        $export = new \ReflectionMethod($plugin, 'createAcyMailingDomain');
        $remove = new \ReflectionMethod($plugin, 'removeAcyMailingData');
        $export->setAccessible(true);
        $remove->setAccessible(true);

        try {
            foreach ($pairs as $name => [$a, $b]) {
                $ids[] = $subB = $this->insertSubscriber($prefix, $b, 'Subscriber B');
                $this->test("[$name] subscriber B inserted", $subB > 0);

                $this->test("[$name] export of A finds no subscriber", $export->invoke($plugin, null, [$a]) === null);
                $remove->invoke($plugin, null, [$a]);
                $this->test("[$name] removal of A keeps subscriber B", $this->countRows($prefix . 'user', 'id', $subB) === 1);

                $domain = $export->invoke($plugin, null, [str_replace('@example.com', '@EXAMPLE.COM', $b)]);
                $this->test("[$name] export of B in other letter case finds subscriber B", $domain !== null);
            }
        } catch (\Throwable $e) {
            $this->test('collation pairs ran without error', false, $e->getMessage());
        } finally {
            foreach ($ids as $id) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName($prefix . 'user') . ' WHERE id = ' . (int) $id)->execute();
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
        $this->testUnsubscribeOutlivesRemoval();
        $this->testRemovalAfterPseudonymisation();
        $this->testExportBothAddresses();
        $this->testCollationPairs();
        $this->testGracefulSkip();

        echo "\n=== AcyMailing Integration Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new AcyMailingIntegrationTest();
exit($test->run() ? 0 : 1);
