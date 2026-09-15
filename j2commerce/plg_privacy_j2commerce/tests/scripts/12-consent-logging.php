<?php
/**
 * Test 12: Checkout consent logging in #__privacy_consents
 *
 * Runs against the real installed plugins and the real Joomla core table:
 *   - ConsentRepository writes one record per order (logged-in and guest), no duplicates
 *   - status lookup: logged-in by user_id (checkout + Joomla registration subject only),
 *     guest strictly by one order (token + e-mail), only valid (state = 1) records
 *   - no e-mail address copied into the consent
 *   - system plugin: real handleCheckoutRequest() for the shipping & payment step, the
 *     controller/task variant, confirm and confirmPayment, session state bound to the cart
 *   - onJ2CommerceAfterSaveOrder records consent only for the cart the consent was given for
 *   - the privacy tab layout links to com_privacy (logged-in) or mailto (guest)
 *   - update path: CLI reinstall keeps a disabled system plugin disabled and adds a missing
 *     default_privacy.php only next to an existing MyProfile override of an installed component
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
use Joomla\Input\Input;
use Joomla\Registry\Registry;

if (!class_exists(ConsentRepository::class)) {
    require_once JPATH_PLUGINS . '/privacy/j2commerce/src/Consent/ConsentRepository.php';
}

if (!class_exists(J2CommercePrivacy::class) && is_file(JPATH_PLUGINS . '/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php')) {
    require_once JPATH_PLUGINS . '/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php';
}

/** Minimal session store with the methods the system plugin uses. */
class ConsentTestSession
{
    private array $data = [];

    public function get($name, $default = null)
    {
        return $this->data[$name] ?? $default;
    }

    public function set($name, $value = null)
    {
        $this->data[$name] = $value;
    }

    public function remove($name)
    {
        unset($this->data[$name]);
    }
}

if (class_exists(J2CommercePrivacy::class)) {
    /** Test double: replaces only session and request access, keeps the real event handling. */
    class ConsentLoggingTestPlugin extends J2CommercePrivacy
    {
        public ?int $consentCart = null;

        protected function getSessionConsentCartId(): ?int
        {
            return $this->consentCart;
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
    private const PREFIX      = 'TESTCONSENT-';
    private const GUEST_EMAIL = 'guest-consent@example.invalid';
    private const USER_ID     = 100;
    private const FORM_TOKEN  = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

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
            $row['token'] = self::tokenFor($orderId);
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

    private static function tokenFor(string $orderId): string
    {
        return 'token-' . strtolower($orderId);
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

    private function insertConsentFixture(int $userId, string $subject): void
    {
        $row = (object) [
            'user_id' => $userId,
            'state'   => 1,
            'created' => Factory::getDate('-1 day')->toSql(),
            'subject' => $subject,
            'body'    => 'consent-logging-test-fixture',
            'remind'  => 0,
            'token'   => '',
        ];
        $this->db->insertObject('#__privacy_consents', $row, 'id');
    }

    private static function orderIds(array $status): array
    {
        return array_values(array_filter(array_map(static fn ($r) => $r->order_id, $status['records'])));
    }

    /** Build a request input the way Joomla does (POST/SERVER data come from the superglobals). */
    private function request(array $query, array $post = [], string $method = 'POST', array $server = []): Input
    {
        $_GET     = $query;
        $_POST    = $post;
        $_REQUEST = array_merge($query, $post);
        $_SERVER  = array_merge($_SERVER, ['REQUEST_METHOD' => $method, 'HTTP_X_REQUESTED_WITH' => '', 'HTTP_X_CSRF_TOKEN' => ''], $server);

        return new Input(array_merge($query, $post));
    }

    public function run(): bool
    {
        echo "=== Checkout Consent Logging Tests ===\n\n";
        echo 'Orders table: ' . $this->ordersTable . "\n\n";

        $language = Factory::getLanguage();
        $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');
        $language->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy');

        $this->cleanup();

        $orderUser   = self::PREFIX . 'USER-1';
        $orderGuest  = self::PREFIX . 'GUEST-1';
        $orderGuest2 = self::PREFIX . 'GUEST-2';
        $orderMixed  = self::PREFIX . 'USER-SAMEMAIL';

        $this->test('Fixture order for logged-in user created', $this->createOrder($orderUser, self::USER_ID, 'test@example.com'));
        $this->test('Fixture guest order created', $this->createOrder($orderGuest, 0, self::GUEST_EMAIL));
        $this->test('Second guest order with the same e-mail created', $this->createOrder($orderGuest2, 0, self::GUEST_EMAIL));
        $this->test('Logged-in order with the guest e-mail created', $this->createOrder($orderMixed, self::USER_ID, self::GUEST_EMAIL));

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
        $this->test('Body contains IP address', $row && str_contains($row->body, '198.51.100.23'));
        $this->test('Body escapes the user agent', $row && !str_contains($row->body, '<script>') && str_contains($row->body, '&lt;script&gt;'));
        $this->test('Body text is translated (no raw language key)', $row && !str_contains($row->body, ConsentRepository::BODY_KEY));

        // ── No duplicates ───────────────────────────────────────────────────
        echo "\n-- Duplicates --\n";
        $again = $repository->ensureOrderConsent($orderUser, self::USER_ID, $body);
        $this->test('Second save returns existing record', is_array($again) && $again['created'] === false && $result && $again['id'] === $result['id']);
        $this->test('Exactly one record for the order', $this->countOrderConsents($orderUser) === 1, 'Got ' . $this->countOrderConsents($orderUser));

        // ── Save: guest ─────────────────────────────────────────────────────
        echo "\n-- Save (guest) --\n";
        $guestResult = $repository->ensureOrderConsent($orderGuest, 0, $repository->buildBody($orderGuest, '198.51.100.24', 'GuestAgent/2.0'));
        $guestRow    = $guestResult ? $this->loadConsent($guestResult['id']) : null;
        $this->test('Consent recorded for guest order', is_array($guestResult) && $guestResult['created'] === true);
        $this->test('Guest row has user_id 0', $guestRow && (int) $guestRow->user_id === 0);
        $this->test('Guest e-mail is NOT copied into the consent', $guestRow && !str_contains($guestRow->body, self::GUEST_EMAIL) && !str_contains($guestRow->subject, self::GUEST_EMAIL));
        $repository->ensureOrderConsent($orderGuest, 0, $repository->buildBody($orderGuest, '198.51.100.24', 'GuestAgent/2.0'));
        $this->test('Guest order has no duplicate', $this->countOrderConsents($orderGuest) === 1);
        $repository->ensureOrderConsent($orderGuest2, 0, $repository->buildBody($orderGuest2, '198.51.100.25', 'GuestAgent/2.0'));

        $bad = self::PREFIX . 'X --> <b>';
        $this->test('Unsafe order number is rejected', $repository->ensureOrderConsent($bad, 0, $repository->buildBody(self::PREFIX . 'X', '', '')) === null);

        // ── Status lookup ───────────────────────────────────────────────────
        echo "\n-- Status lookup --\n";
        $hasToken = array_key_exists('token', $this->db->getTableColumns($this->ordersTable));

        if ($hasToken) {
            $guestStatus = $repository->getStatus(0, self::GUEST_EMAIL, self::tokenFor($orderGuest));
            $this->test('Guest token + e-mail shows the consent of that order', self::orderIds($guestStatus) === [$orderGuest]);
            $this->test('Guest token does not reveal other guest orders of the same e-mail', !in_array($orderGuest2, self::orderIds($guestStatus), true));
            $this->test('Guest lookup never includes logged-in orders', !in_array($orderMixed, self::orderIds($guestStatus), true) && !in_array($orderUser, self::orderIds($guestStatus), true));

            $secondStatus = $repository->getStatus(0, self::GUEST_EMAIL, self::tokenFor($orderGuest2));
            $this->test('Token of the second order shows only the second order', self::orderIds($secondStatus) === [$orderGuest2]);

            $this->test('Guest token with another e-mail finds nothing', $repository->getStatus(0, 'someone-else@example.invalid', self::tokenFor($orderGuest))['consented'] === false);
            $this->test('Guest e-mail with an unknown token finds nothing', $repository->getStatus(0, self::GUEST_EMAIL, 'token-unknown')['consented'] === false);
            $this->test('Logged-in order token does not work as guest token', $repository->getStatus(0, self::GUEST_EMAIL, self::tokenFor($orderMixed))['consented'] === false);
        } else {
            $this->test('Orders table without token column: guest lookup returns nothing', $repository->getStatus(0, self::GUEST_EMAIL, 'x')['consented'] === false);
        }

        $this->test('Guest lookup without token finds nothing', $repository->getStatus(0, self::GUEST_EMAIL, '')['consented'] === false);
        $this->test('Guest lookup without e-mail finds nothing', $repository->getStatus(0, '', self::tokenFor($orderGuest))['consented'] === false);

        $userStatus = $repository->getStatus(self::USER_ID);
        $this->test('User lookup by user_id finds own order consent', in_array($orderUser, self::orderIds($userStatus), true));
        $this->test('User lookup does not include guest order consents', !in_array($orderGuest, self::orderIds($userStatus), true));

        $this->insertConsentFixture(self::USER_ID, ConsentRepository::CORE_SUBJECT);
        $this->insertConsentFixture(self::USER_ID, 'PLG_SYSTEM_OTHEREXTENSION_CONSENT_SUBJECT');
        $userStatus = $repository->getStatus(self::USER_ID);
        $subjects   = array_map(static fn ($r) => $r->subject, $userStatus['records']);
        $sources    = array_map(static fn ($r) => $r->source, $userStatus['records']);
        $this->test('Registration consent (core subject) is shown as account source', in_array(ConsentRepository::CORE_SUBJECT, $subjects, true) && in_array('account', $sources, true));
        $this->test('Checkout consent is shown as checkout source', in_array('checkout', $sources, true));
        $this->test('Consents of other extensions are not shown', !in_array('PLG_SYSTEM_OTHEREXTENSION_CONSENT_SUBJECT', $subjects, true));

        if ($guestResult && $hasToken) {
            $this->db->setQuery(
                $this->query()->update($this->db->quoteName('#__privacy_consents'))
                    ->set($this->db->quoteName('state') . ' = -1')
                    ->where($this->db->quoteName('id') . ' = ' . (int) $guestResult['id'])
            )->execute();
            $this->test('Invalidated guest consent is not reported', $repository->getStatus(0, self::GUEST_EMAIL, self::tokenFor($orderGuest))['consented'] === false);
            $this->test('findOrderConsent ignores invalidated record', $repository->findOrderConsent($orderGuest) === null);
        }

        // ── System plugin ───────────────────────────────────────────────────
        echo "\n-- System plugin --\n";
        $query = $this->query()
            ->select($this->db->quoteName(['extension_id', 'enabled']))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('system'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerceprivacy'));
        $this->db->setQuery($query);
        $extension = $this->db->loadObject() ?: null;
        $this->test('System plugin registered', $extension !== null);
        $this->test('System plugin class available', class_exists(J2CommercePrivacy::class));

        if (class_exists(J2CommercePrivacy::class)) {
            $events = J2CommercePrivacy::getSubscribedEvents();
            // J2Commerce 6 CartOrder::saveOrder() dispatches 'onJ2Commerce' . 'AfterSaveOrder'.
            $this->test('Subscribes to onJ2CommerceAfterSaveOrder', isset($events['onJ2CommerceAfterSaveOrder']));
            $this->test('Subscribes to onAfterRoute', isset($events['onAfterRoute']));
            $this->test('Subscribes to onJ2CommerceCheckoutCleanup', isset($events['onJ2CommerceCheckoutCleanup']));

            $this->runCheckoutRequestTests();
            $this->runAfterSaveOrderTests($repository);
        }

        $this->runLayoutTests($orderGuest);

        $this->cleanup();

        $this->runUpdatePathTests($extension);

        echo "\n=== Consent Logging Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function runCheckoutRequestTests(): void
    {
        echo "\n-- Checkout requests (server-side enforcement) --\n";

        $plugin   = new ConsentLoggingTestPlugin(['name' => 'j2commerceprivacy', 'type' => 'system', 'params' => '{}']);
        $required = new Registry(['show_consent_checkbox' => 1, 'consent_required' => 1]);
        $optional = new Registry(['show_consent_checkbox' => 1, 'consent_required' => 0]);
        $hidden   = new Registry(['show_consent_checkbox' => 0, 'consent_required' => 1]);
        $cart     = 11;
        $token    = [self::FORM_TOKEN => '1'];
        $validate = ['option' => 'com_j2commerce', 'task' => 'checkout.shippingPaymentMethodValidate'];
        $confirm  = ['option' => 'com_j2commerce', 'task' => 'checkout.confirm'];
        $payment  = ['option' => 'com_j2commerce', 'task' => 'checkout.confirmPayment'];
        $rendered = static function (ConsentTestSession $session, int $cartId): void {
            $session->set(J2CommercePrivacy::SESSION_RENDERED, ['cart' => $cartId, 'required' => true]);
        };

        // Shipping & payment step
        $session = new ConsentTestSession();
        $rendered($session, $cart);
        $response = $plugin->handleCheckoutRequest($this->request($validate, $token + [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Step: ticked checkbox passes', $response === null);
        $this->test('Step: consent stored for the current cart', ($session->get(J2CommercePrivacy::SESSION_CONSENT)['cart'] ?? null) === $cart);

        $response = $plugin->handleCheckoutRequest($this->request($validate, $token), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Step: unticked required checkbox is rejected', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);
        $this->test('Step: rejection message is translated', isset($response['message']) && !str_contains($response['message'], 'PLG_PRIVACY_J2COMMERCE_'));
        $this->test('Step: rejection removes an earlier consent', $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);

        $variant  = ['option' => 'com_j2commerce', 'controller' => 'checkout', 'task' => 'shippingPaymentMethodValidate'];
        $response = $plugin->handleCheckoutRequest($this->request($variant, $token), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Step: controller=checkout&task=... variant is rejected too', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);

        $response = $plugin->handleCheckoutRequest(
            $this->request($validate, $token + ['j2commerce_privacy_consent_rendered' => '0']),
            $session,
            $required,
            $cart,
            self::FORM_TOKEN
        );
        $this->test('Step: posted marker fields cannot switch enforcement off', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);

        $response = $plugin->handleCheckoutRequest($this->request($validate, [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Step: invalid token stores nothing', $response === null && $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);

        $other = new ConsentTestSession();
        $rendered($other, 99);
        $response = $plugin->handleCheckoutRequest($this->request($validate, $token), $other, $required, $cart, self::FORM_TOKEN);
        $this->test('Step: checkbox rendered for another cart does not block', $response === null);

        $response = $plugin->handleCheckoutRequest($this->request($validate, $token), $session, $optional, $cart, self::FORM_TOKEN);
        $this->test('Step: optional consent is not enforced', $response === null);

        $response = $plugin->handleCheckoutRequest($this->request($validate, $token), $session, $hidden, $cart, self::FORM_TOKEN);
        $this->test('Step: disabled checkbox is not enforced', $response === null);

        $response = $plugin->handleCheckoutRequest($this->request(['option' => 'com_content', 'task' => 'checkout.confirm']), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Other components are ignored', $response === null);

        // Confirmation step
        $session = new ConsentTestSession();
        $rendered($session, $cart);
        $response = $plugin->handleCheckoutRequest($this->request($confirm), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Confirm: refused without consent (skipped step)', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_CONFIRM_ERROR);

        $session->set(J2CommercePrivacy::SESSION_CONSENT, ['cart' => 12, 'at' => time()]);
        $response = $plugin->handleCheckoutRequest($this->request($confirm), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Confirm: consent of another cart does not count', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_CONFIRM_ERROR);

        $session->set(J2CommercePrivacy::SESSION_CONSENT, ['cart' => $cart, 'at' => time()]);
        $response = $plugin->handleCheckoutRequest($this->request($confirm), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('Confirm: allowed with consent for the current cart', $response === null);

        $response = $plugin->handleCheckoutRequest($this->request($confirm), new ConsentTestSession(), $required, $cart, self::FORM_TOKEN);
        $this->test('Confirm: not refused when the checkbox was never rendered', $response === null);

        // Payment submission
        $session = new ConsentTestSession();
        $rendered($session, $cart);
        $response = $plugin->handleCheckoutRequest($this->request($payment, $token, 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('ConfirmPayment (AJAX POST): refused without consent', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_PAYMENT_ERROR);

        $response = $plugin->handleCheckoutRequest($this->request($payment, $token), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('ConfirmPayment (form POST): redirected back without consent', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_REDIRECT);

        $response = $plugin->handleCheckoutRequest($this->request($payment, [], 'GET'), $session, $required, $cart, self::FORM_TOKEN);
        $this->test('ConfirmPayment (gateway return GET): not refused', $response === null);

        $this->test('resolveTask keeps dotted tasks', J2CommercePrivacy::resolveTask($this->request($validate)) === J2CommercePrivacy::TASK_VALIDATE);
    }

    private function runAfterSaveOrderTests(ConsentRepository $repository): void
    {
        echo "\n-- onJ2CommerceAfterSaveOrder dispatch --\n";

        $orderEvent     = self::PREFIX . 'EVENT-1';
        $orderNoFlag    = self::PREFIX . 'EVENT-2';
        $orderWrongCart = self::PREFIX . 'EVENT-3';

        foreach ([$orderEvent, $orderNoFlag, $orderWrongCart] as $orderId) {
            $this->createOrder($orderId, 0, self::GUEST_EMAIL);
        }

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

            $plugin->consentCart = 21;
            $saved = (object) ['order_id' => $orderEvent, 'user_id' => 0, 'cart_id' => 21];
            $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent($saved));
            $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent($saved));
            $this->test('Consent for the order cart is recorded once', $this->countOrderConsents($orderEvent) === 1, 'Got ' . $this->countOrderConsents($orderEvent));

            $record = $repository->findOrderConsent($orderEvent);
            $this->test('Recorded body contains request IP and user agent', $record && str_contains($record->body, '203.0.113.7') && str_contains($record->body, 'ConsentLoggingTest/1.0'));

            $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent((object) ['order_id' => $orderWrongCart, 'user_id' => 0, 'cart_id' => 22]));
            $this->test('Consent of another cart is not applied to the order', $this->countOrderConsents($orderWrongCart) === 0);

            $plugin->consentCart = null;
            $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', $makeEvent((object) ['order_id' => $orderNoFlag, 'user_id' => 0, 'cart_id' => 21]));
            $this->test('No consent recorded without ticked checkbox', $this->countOrderConsents($orderNoFlag) === 0);
        } catch (\Throwable $e) {
            $this->test('System plugin dispatch runs without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function runLayoutTests(string $orderGuest): void
    {
        echo "\n-- Privacy tab layout --\n";
        $layout = JPATH_PLUGINS . '/privacy/j2commerce/layouts/privacy_tab.php';
        $this->test('Layout installed', is_file($layout));

        if (!is_file($layout)) {
            return;
        }

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
        $this->test('Request block hidden when not requested', !str_contains($hiddenHtml, 'data-privacy-request'));

        foreach (['com_j2commerce', 'com_j2store'] as $component) {
            $source = (string) @file_get_contents(JPATH_PLUGINS . '/privacy/j2commerce/overrides/' . $component . '/myprofile/default_privacy.php');
            $this->test(
                "[$component] override puts the template layout path before the plugin layout",
                str_contains($source, 'html/layouts/plg_privacy_j2commerce')
                    && strpos($source, 'html/layouts/plg_privacy_j2commerce') < strpos($source, "/privacy/j2commerce/layouts'")
            );
            $this->test("[$component] override passes the verified guest session as isGuest", str_contains($source, "'isGuest'        => \$_isGuest"));
        }
    }

    private function runUpdatePathTests(?object $extension): void
    {
        echo "\n-- Update path (CLI reinstall of the package) --\n";

        $package = '/tmp/extension.zip';

        if (!$this->test('Package for the update test available', is_file($package), $package . ' missing') || $extension === null) {
            return;
        }

        $query = $this->query()
            ->select('DISTINCT ' . $this->db->quoteName('template'))
            ->from($this->db->quoteName('#__template_styles'))
            ->where($this->db->quoteName('client_id') . ' = 0');
        $this->db->setQuery($query);
        $templates = array_values(array_filter($this->db->loadColumn() ?: []));

        $components = ['com_j2store', 'com_j2commerce'];
        $installed  = array_values(array_filter($components, static fn ($c) => is_dir(JPATH_SITE . '/components/' . $c)));
        $missing    = array_values(array_diff($components, $installed));

        $target = null;

        foreach ($templates as $template) {
            foreach ($installed as $component) {
                if (is_file(JPATH_SITE . "/templates/$template/html/$component/myprofile/default.php")) {
                    $target = [$template, $component];
                    break 2;
                }
            }
        }

        if (!$this->test('Template with deployed MyProfile override found', $target !== null)) {
            return;
        }

        [$template, $component] = $target;
        $htmlBase    = JPATH_SITE . "/templates/$template/html";
        $privacyFile = "$htmlBase/$component/myprofile/default_privacy.php";
        $restore     = [[$privacyFile, is_file($privacyFile) ? file_get_contents($privacyFile) : null]];
        @unlink($privacyFile);

        // A MyProfile override of a component that is not installed must not be completed.
        $negativeFiles = [];

        foreach ($missing as $missingComponent) {
            $dir       = "$htmlBase/$missingComponent/myprofile";
            $restore[] = ["$dir/default.php", is_file("$dir/default.php") ? file_get_contents("$dir/default.php") : null];
            $restore[] = ["$dir/default_privacy.php", is_file("$dir/default_privacy.php") ? file_get_contents("$dir/default_privacy.php") : null];

            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            file_put_contents("$dir/default.php", "<?php\n// consent logging update test\n");
            @unlink("$dir/default_privacy.php");
            $negativeFiles[] = "$dir/default_privacy.php";
        }

        $extensionId = (int) $extension->extension_id;
        $setEnabled  = function (int $enabled) use ($extensionId): void {
            $this->db->setQuery(
                $this->query()
                    ->update($this->db->quoteName('#__extensions'))
                    ->set($this->db->quoteName('enabled') . ' = ' . $enabled)
                    ->where($this->db->quoteName('extension_id') . ' = ' . $extensionId)
            )->execute();
        };

        $setEnabled(0);

        $command = sprintf(
            'HTTP_HOST=localhost %s %s extension:install --path=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(JPATH_BASE . '/cli/joomla.php'),
            escapeshellarg($package)
        );
        exec($command, $output, $exitCode);
        $this->test('CLI update of the package succeeds', $exitCode === 0, 'exit ' . $exitCode . ': ' . implode(' | ', array_slice($output, -5)));

        $this->db->setQuery(
            $this->query()
                ->select($this->db->quoteName('enabled'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('extension_id') . ' = ' . $extensionId)
        );
        $this->test('Update keeps a disabled consent system plugin disabled', (int) $this->db->loadResult() === 0);

        clearstatcache();
        $this->test("Update adds missing default_privacy.php next to the MyProfile override ($template/$component)", is_file($privacyFile));

        foreach ($negativeFiles as $file) {
            $this->test('Update does not add default_privacy.php for a component that is not installed', !is_file($file), $file);
        }

        // Restore the environment for later suites.
        $setEnabled(1);

        foreach ($restore as [$file, $content]) {
            if ($content === null) {
                @unlink($file);
            } else {
                file_put_contents($file, $content);
            }
        }
    }
}

$test = new ConsentLoggingTest();
exit($test->run() ? 0 : 1);
