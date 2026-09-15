<?php
/**
 * Test 12: Checkout consent logging in #__privacy_consents
 *
 * Runs against the real installed plugins and the real Joomla core table:
 *   - ConsentRepository writes one record per order (logged-in and guest)
 *   - a second write for the same order does not create a duplicate
 *   - status lookup: by user_id for logged-in users, by guest order e-mail for guests,
 *     only valid (state = 1) records, including Joomla's registration consent
 *   - no additional personal data (no e-mail address copied into the consent)
 *   - the bundled system plugin is installed/enabled, decides the checkout step correctly
 *     and records consent when onJ2CommerceAfterSaveOrder is dispatched
 *   - the privacy tab layout links to com_privacy (logged-in) or mailto (guest)
 *
 * Stack-aware: #__j2store_orders (J2Commerce 4) or #__j2commerce_orders (J2Commerce 6).
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy;
use Joomla\CMS\Factory;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use Joomla\Registry\Registry;

if (!class_exists(ConsentRepository::class)) {
    require_once JPATH_PLUGINS . '/privacy/j2commerce/src/Consent/ConsentRepository.php';
}

if (!class_exists(J2CommercePrivacy::class) && is_file(JPATH_PLUGINS . '/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php')) {
    require_once JPATH_PLUGINS . '/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php';
}

if (class_exists(J2CommercePrivacy::class)) {
    /** Test double: replaces only session and request access, keeps the real event handling. */
    class ConsentLoggingTestPlugin extends J2CommercePrivacy
    {
        public bool $consentFlag = true;

        protected function hasCheckoutConsent(): bool
        {
            return $this->consentFlag;
        }

        protected function getClientIp(): string
        {
            return '203.0.113.7';
        }

        protected function getClientUserAgent(): string
        {
            return 'ConsentLoggingTest/1.0';
        }

        protected function getPrivacyParams(): ?Registry
        {
            return new Registry(['show_consent_checkbox' => 1, 'consent_required' => 1]);
        }
    }
}

class ConsentLoggingTest
{
    private const PREFIX       = 'TESTCONSENT-';
    private const GUEST_EMAIL  = 'guest-consent@example.invalid';
    private const USER_ID      = 100;

    private $db;
    private int $passed = 0;
    private int $failed = 0;
    private string $ordersTable;
    private string $pkColumn;

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');

        $isJ4              = in_array($this->db->getPrefix() . 'j2store_orders', $this->db->getTableList(), true);
        $this->ordersTable = $isJ4 ? '#__j2store_orders' : '#__j2commerce_orders';
        $this->pkColumn    = $isJ4 ? 'j2store_order_id' : 'j2commerce_order_id';
    }

    private function test(string $name, bool $condition, string $message = ''): bool
    {
        if ($condition) {
            echo "✓ $name... PASS\n";
            $this->passed++;

            return true;
        }

        echo "✗ $name... FAIL" . ($message ? " - $message" : '') . "\n";
        $this->failed++;

        return false;
    }

    private function query()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function cleanup(): void
    {
        $this->db->setQuery(
            $this->query()
                ->delete($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('body') . ' LIKE ' . $this->db->quote('%j2commerce-order:' . self::PREFIX . '%'))
        )->execute();

        $this->db->setQuery(
            $this->query()
                ->delete($this->db->quoteName('#__privacy_consents'))
                ->where($this->db->quoteName('body') . ' = ' . $this->db->quote('consent-logging-test-fixture'))
        )->execute();

        $this->db->setQuery(
            $this->query()
                ->delete($this->db->quoteName($this->ordersTable))
                ->where($this->db->quoteName('order_id') . ' LIKE ' . $this->db->quote(self::PREFIX . '%'))
        )->execute();
    }

    /** Insert a test order by cloning the fixture order of user 100 (keeps all NOT NULL columns valid). */
    private function createOrder(string $orderId, int $userId, string $email): bool
    {
        $query = $this->query()
            ->select('*')
            ->from($this->db->quoteName($this->ordersTable))
            ->where($this->db->quoteName('user_id') . ' = ' . self::USER_ID)
            ->where($this->db->quoteName('order_id') . ' NOT LIKE ' . $this->db->quote(self::PREFIX . '%'));
        $this->db->setQuery($query, 0, 1);
        $row = $this->db->loadAssoc();

        if (!$row) {
            return false;
        }

        unset($row[$this->pkColumn]);
        $row['order_id']   = $orderId;
        $row['user_id']    = $userId;
        $row['user_email'] = $email;
        $row['created_on'] = Factory::getDate()->toSql();

        if (array_key_exists('token', $row)) {
            $row['token'] = 'token-' . strtolower($orderId);
        }

        // Keep possibly unique columns distinct from the cloned fixture row.
        if (array_key_exists('invoice_number', $row)) {
            $row['invoice_number'] = random_int(900000000, 999999999);
        }

        if (array_key_exists('cart_id', $row)) {
            $row['cart_id'] = 0;
        }

        $object = (object) $row;

        return $this->db->insertObject($this->ordersTable, $object);
    }

    private function countOrderConsents(string $orderId): int
    {
        $query = $this->query()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('body') . ' LIKE ' . $this->db->quote('%' . ConsentRepository::orderMarker($orderId) . '%'));
        $this->db->setQuery($query);

        return (int) $this->db->loadResult();
    }

    private function loadConsent(int $id): ?object
    {
        $query = $this->query()
            ->select('*')
            ->from($this->db->quoteName('#__privacy_consents'))
            ->where($this->db->quoteName('id') . ' = ' . $id);
        $this->db->setQuery($query);

        return $this->db->loadObject() ?: null;
    }

    private static function orderIds(array $status): array
    {
        return array_values(array_filter(array_map(static fn ($r) => $r->order_id, $status['records'])));
    }

    public function run(): bool
    {
        echo "=== Checkout Consent Logging Tests ===\n\n";
        echo 'Orders table: ' . $this->ordersTable . "\n\n";

        $language = Factory::getLanguage();
        $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');
        $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy');

        $this->cleanup();

        $orderUser  = self::PREFIX . 'USER-1';
        $orderGuest = self::PREFIX . 'GUEST-1';
        $orderMixed = self::PREFIX . 'USER-SAMEMAIL';

        $this->test('Fixture order for logged-in user created', $this->createOrder($orderUser, self::USER_ID, 'test@example.com'));
        $this->test('Fixture guest order created', $this->createOrder($orderGuest, 0, self::GUEST_EMAIL));
        // Logged-in order that uses the guest e-mail: must never show up in the guest lookup.
        $this->test('Fixture logged-in order with guest e-mail created', $this->createOrder($orderMixed, self::USER_ID, self::GUEST_EMAIL));

        $repository = new ConsentRepository($this->db);

        // ── Core table schema used by the repository ────────────────────────
        echo "\n-- #__privacy_consents schema --\n";
        $columns = array_keys($this->db->getTableColumns('#__privacy_consents'));
        foreach (['id', 'user_id', 'state', 'created', 'subject', 'body', 'remind', 'token'] as $column) {
            $this->test("Core column $column exists", in_array($column, $columns, true));
        }

        // ── Save: logged-in user ────────────────────────────────────────────
        echo "\n-- Save (logged-in) --\n";
        $body   = $repository->buildBody($orderUser, '198.51.100.23', 'Mozilla/5.0 <script>alert(1)</script>');
        $result = $repository->ensureOrderConsent($orderUser, self::USER_ID, $body);
        $this->test('Consent recorded for logged-in order', is_array($result) && $result['created'] === true && $result['id'] > 0);

        $row = $result ? $this->loadConsent($result['id']) : null;
        $this->test('Row stored with user_id of the order', $row && (int) $row->user_id === self::USER_ID);
        $this->test('Row is valid (state 1)', $row && (int) $row->state === 1);
        $this->test('Row uses the plugin subject', $row && $row->subject === ConsentRepository::SUBJECT);
        $this->test('Row has created date', $row && !empty($row->created) && $row->created !== '0000-00-00 00:00:00');
        $this->test('Row remind = 0 and token empty (core defaults)', $row && (int) $row->remind === 0 && $row->token === '');
        $this->test('Body references the order', $row && str_contains($row->body, ConsentRepository::orderMarker($orderUser)));
        $this->test('Body contains IP address like core', $row && str_contains($row->body, '198.51.100.23'));
        $this->test('Body escapes the user agent', $row && !str_contains($row->body, '<script>') && str_contains($row->body, '&lt;script&gt;'));
        $this->test('Body text is translated (no raw language key)', $row && !str_contains($row->body, ConsentRepository::BODY_KEY));

        // ── No duplicates ───────────────────────────────────────────────────
        echo "\n-- Duplicates --\n";
        $again = $repository->ensureOrderConsent($orderUser, self::USER_ID, $body);
        $this->test('Second save returns existing record', is_array($again) && $again['created'] === false && $result && $again['id'] === $result['id']);
        $this->test('Exactly one record for the order', $this->countOrderConsents($orderUser) === 1, 'Got ' . $this->countOrderConsents($orderUser));

        // ── Save: guest ─────────────────────────────────────────────────────
        echo "\n-- Save (guest) --\n";
        $guestBody   = $repository->buildBody($orderGuest, '198.51.100.24', 'GuestAgent/2.0');
        $guestResult = $repository->ensureOrderConsent($orderGuest, 0, $guestBody);
        $guestRow    = $guestResult ? $this->loadConsent($guestResult['id']) : null;
        $this->test('Consent recorded for guest order', is_array($guestResult) && $guestResult['created'] === true);
        $this->test('Guest row has user_id 0', $guestRow && (int) $guestRow->user_id === 0);
        $this->test('Guest e-mail is NOT copied into the consent', $guestRow && !str_contains($guestRow->body, self::GUEST_EMAIL) && !str_contains($guestRow->subject, self::GUEST_EMAIL));
        $repository->ensureOrderConsent($orderGuest, 0, $guestBody);
        $this->test('Guest order has no duplicate', $this->countOrderConsents($orderGuest) === 1);

        // ── Invalid order numbers are rejected ──────────────────────────────
        $bad = self::PREFIX . 'X --> <b>';
        $this->test('Unsafe order number is rejected', $repository->ensureOrderConsent($bad, 0, $repository->buildBody(self::PREFIX . 'X', '', '')) === null);

        // ── Status lookup ───────────────────────────────────────────────────
        echo "\n-- Status lookup --\n";
        $guestStatus = $repository->getStatus(0, self::GUEST_EMAIL);
        $this->test('Guest lookup by order e-mail finds consent', $guestStatus['consented'] && in_array($orderGuest, self::orderIds($guestStatus), true));
        $this->test('Guest lookup ignores logged-in orders with the same e-mail', !in_array($orderMixed, self::orderIds($guestStatus), true) && !in_array($orderUser, self::orderIds($guestStatus), true));
        $this->test('Guest lookup reports latest date', $guestStatus['latest'] !== null);

        $otherGuest = $repository->getStatus(0, 'someone-else@example.invalid');
        $this->test('Guest lookup with other e-mail finds nothing', $otherGuest['consented'] === false && $otherGuest['records'] === []);
        $this->test('Guest lookup without e-mail finds nothing', $repository->getStatus(0, '')['consented'] === false);

        $userStatus = $repository->getStatus(self::USER_ID);
        $this->test('User lookup by user_id finds own order consent', in_array($orderUser, self::orderIds($userStatus), true));
        $this->test('User lookup does not include guest order consent', !in_array($orderGuest, self::orderIds($userStatus), true));

        // Joomla registration consent (core subject) is recognised for the account.
        $core = (object) [
            'user_id' => self::USER_ID,
            'state'   => 1,
            'created' => Factory::getDate('-1 day')->toSql(),
            'subject' => ConsentRepository::CORE_SUBJECT,
            'body'    => 'consent-logging-test-fixture',
            'remind'  => 0,
            'token'   => '',
        ];
        $this->db->insertObject('#__privacy_consents', $core, 'id');
        $userStatus = $repository->getStatus(self::USER_ID);
        $sources    = array_map(static fn ($r) => $r->source, $userStatus['records']);
        $this->test('User lookup includes registration consent as account source', in_array('account', $sources, true));
        $this->test('User lookup includes checkout consent as checkout source', in_array('checkout', $sources, true));

        // Invalidated consent (core state -1) is not a valid consent.
        if ($guestResult) {
            $this->db->setQuery(
                $this->query()->update($this->db->quoteName('#__privacy_consents'))
                    ->set($this->db->quoteName('state') . ' = -1')
                    ->where($this->db->quoteName('id') . ' = ' . (int) $guestResult['id'])
            )->execute();
        }
        $this->test('Invalidated guest consent is not reported', $repository->getStatus(0, self::GUEST_EMAIL)['consented'] === false);
        $this->test('findOrderConsent ignores invalidated record', $repository->findOrderConsent($orderGuest) === null);

        // ── System plugin ───────────────────────────────────────────────────
        echo "\n-- System plugin --\n";
        $query = $this->query()
            ->select($this->db->quoteName(['extension_id', 'enabled']))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerceprivacy'));
        $this->db->setQuery($query);
        $extension = $this->db->loadObject();
        $this->test('System plugin registered', $extension !== null);
        $this->test('System plugin enabled', $extension && (int) $extension->enabled === 1);
        $this->test('System plugin class available', class_exists(J2CommercePrivacy::class));

        if (class_exists(J2CommercePrivacy::class)) {
            $events = J2CommercePrivacy::getSubscribedEvents();
            // J2Commerce 6 CartOrder::saveOrder() dispatches 'onJ2Commerce' . 'AfterSaveOrder'.
            $this->test('Subscribes to onJ2CommerceAfterSaveOrder', isset($events['onJ2CommerceAfterSaveOrder']));
            $this->test('Subscribes to onAfterRoute', isset($events['onAfterRoute']));
            $this->test('Subscribes to onJ2CommerceCheckoutCleanup', isset($events['onJ2CommerceCheckoutCleanup']));

            echo "\n-- Checkout step decision --\n";
            $on       = new Registry(['show_consent_checkbox' => 1, 'consent_required' => 1]);
            $optional = new Registry(['show_consent_checkbox' => 1, 'consent_required' => 0]);
            $off      = new Registry(['show_consent_checkbox' => 0, 'consent_required' => 1]);
            $this->test('Ticked checkbox is recorded', J2CommercePrivacy::decide($on, true, true) === J2CommercePrivacy::DECISION_RECORD);
            $this->test('Required but unticked is rejected', J2CommercePrivacy::decide($on, false, true) === J2CommercePrivacy::DECISION_REJECT);
            $this->test('Not rendered checkbox never blocks checkout', J2CommercePrivacy::decide($on, false, false) === J2CommercePrivacy::DECISION_IGNORE);
            $this->test('Optional unticked is ignored', J2CommercePrivacy::decide($optional, false, true) === J2CommercePrivacy::DECISION_IGNORE);
            $this->test('Disabled checkbox is ignored', J2CommercePrivacy::decide($off, true, true) === J2CommercePrivacy::DECISION_IGNORE);

            echo "\n-- onJ2CommerceAfterSaveOrder dispatch --\n";
            $orderEvent = self::PREFIX . 'EVENT-1';
            $orderNoFlag = self::PREFIX . 'EVENT-2';
            $this->createOrder($orderEvent, 0, self::GUEST_EMAIL);
            $this->createOrder($orderNoFlag, 0, self::GUEST_EMAIL);

            try {
                $plugin = new ConsentLoggingTestPlugin(['name' => 'j2commerceprivacy', 'type' => 'system', 'params' => '{}']);
                $plugin->setDatabase($this->db);

                $dispatcher = new Dispatcher();
                $dispatcher->addSubscriber($plugin);

                $eventClass = 'J2Commerce\\Component\\J2commerce\\Administrator\\Event\\PluginEvent';
                $makeEvent  = static function (object $order) use ($eventClass) {
                    // Same event object J2CommerceHelper::plugin()->event('AfterSaveOrder', [$this]) builds.
                    return class_exists($eventClass)
                        ? new $eventClass('onJ2CommerceAfterSaveOrder', [$order])
                        : new Event('onJ2CommerceAfterSaveOrder', [$order]);
                };

                $plugin->consentFlag = true;
                $savedOrder = (object) ['order_id' => $orderEvent, 'user_id' => 0, 'user_email' => self::GUEST_EMAIL];
                $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent($savedOrder));
                $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent($savedOrder));
                $this->test('Dispatched event records consent once (with session consent)', $this->countOrderConsents($orderEvent) === 1, 'Got ' . $this->countOrderConsents($orderEvent));

                $eventRecord = $repository->findOrderConsent($orderEvent);
                $this->test('Recorded body contains request IP and user agent', $eventRecord && str_contains($eventRecord->body, '203.0.113.7') && str_contains($eventRecord->body, 'ConsentLoggingTest/1.0'));

                $plugin->consentFlag = false;
                $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent((object) ['order_id' => $orderNoFlag, 'user_id' => 0]));
                $this->test('No consent recorded without ticked checkbox', $this->countOrderConsents($orderNoFlag) === 0);

                $guestStatus = $repository->getStatus(0, self::GUEST_EMAIL);
                $this->test('Event-recorded guest consent found by lookup', in_array($orderEvent, self::orderIds($guestStatus), true));
            } catch (\Throwable $e) {
                $this->test('System plugin dispatch runs without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        // ── Privacy tab layout ──────────────────────────────────────────────
        echo "\n-- Privacy tab layout --\n";
        $layout = JPATH_PLUGINS . '/privacy/j2commerce/layouts/privacy_tab.php';
        $this->test('Layout installed', is_file($layout));

        if (is_file($layout)) {
            $render = static function (array $displayData) use ($layout): string {
                ob_start();
                include $layout;

                return (string) ob_get_clean();
            };

            $guestHtml = $render([
                'consented'      => true,
                'records'        => [['date' => '15.09.2026', 'order_id' => $orderGuest, 'source' => 'checkout']],
                'isGuest'        => true,
                'showRequest'    => true,
                'requestUrl'     => '',
                'contactEmail'   => 'privacy@example.invalid',
                'retentionYears' => 10,
            ]);
            $this->test('Guest: mailto request link', str_contains($guestHtml, 'data-privacy-request="mailto"') && str_contains($guestHtml, 'href="mailto:privacy@example.invalid?subject='));
            $this->test('Guest: no com_privacy form link', !str_contains($guestHtml, 'data-privacy-request="form"'));
            $this->test('Guest: consent status and order shown', str_contains($guestHtml, 'data-consent-status="granted"') && str_contains($guestHtml, $orderGuest));

            $userHtml = $render([
                'consented'      => false,
                'records'        => [],
                'isGuest'        => false,
                'showRequest'    => true,
                'requestUrl'     => '/index.php?option=com_privacy&view=request',
                'contactEmail'   => 'privacy@example.invalid',
                'retentionYears' => 10,
            ]);
            $this->test('Logged-in: link to com_privacy request form', str_contains($userHtml, 'data-privacy-request="form"') && str_contains($userHtml, 'option=com_privacy&amp;view=request'));
            $this->test('Logged-in: no consent state rendered as none', str_contains($userHtml, 'data-consent-status="none"'));
            $this->test('Language strings resolved', !str_contains($userHtml . $guestHtml, 'PLG_PRIVACY_J2COMMERCE_'));

            $hiddenHtml = $render(['consented' => false, 'records' => [], 'isGuest' => false, 'showRequest' => false, 'requestUrl' => '/x']);
            $this->test('Request block hidden when export and deletion are disabled', !str_contains($hiddenHtml, 'data-privacy-request'));
        }

        $this->cleanup();

        echo "\n=== Consent Logging Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ConsentLoggingTest();
exit($test->run() ? 0 : 1);
