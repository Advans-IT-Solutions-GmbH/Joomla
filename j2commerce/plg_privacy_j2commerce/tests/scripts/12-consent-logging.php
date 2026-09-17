<?php
/**
 * Test 12: Checkout consent logging in #__privacy_consents
 *
 * Runs against the real installed plugins and the real Joomla core table:
 *   - ConsentRepository writes one record per order (logged-in and guest), no duplicates
 *   - status lookup: logged-in by user_id (checkout + Joomla registration subject only),
 *     guest strictly by one order (token + e-mail), only valid (state = 1) records
 *   - no e-mail address copied into the consent
 *   - system plugin: the checkbox is rendered through the J2Commerce event
 *     AfterDisplayShippingPayment; handleCheckoutRequest() for the shipping & payment step, the
 *     controller/task variant, confirm and confirmPayment; enforcement only from plugin options
 *   - HTTP (J2Commerce 6): real site requests against this container with a real session and a
 *     real J2Commerce cart (onAfterRoute(), event rendering, currentCartId()), including the
 *     bypass routes: skipped step 4, cart change, template/templateStyle/Itemid request
 *     parameters, and a checkout without any template override; accepted steps must return
 *     J2Commerce JSON without error; privacy policy link escaped once with SEF off and on
 *   - the consent is captured on checkout.confirmPayment (form token for POST, gateway return GET) and
 *     recorded after J2Commerce accepted the order of the consent cart; once per order
 *   - legacy records of an earlier template override are only anonymized (never assigned, not shown);
 *     consent evidence of records outside the retention period and of deleted or anonymized orders is
 *     removed, the evidence-removed body written in the default site language, not the acting person's
 *   - the privacy tab layout links to com_privacy (logged-in) or mailto (guest), one button per
 *     enabled request type (Show Export Data / Show Delete All Data)
 *   - update path: CLI reinstall keeps a disabled system plugin disabled, adds a missing
 *     default_privacy.php only next to an existing MyProfile override of an installed component,
 *     disables an unchanged J2Commerce 6 checkout override shipped by 1.5.5, keeps a changed one
 *     with a warning, and warns about custom checkout overrides without the J2Commerce event
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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\Event\Dispatcher;
use Joomla\Event\Event;
use Joomla\Input\Input;
use Joomla\Registry\Registry;

if (!class_exists(ConsentRepository::class)) {
    require_once JPATH_PLUGINS . '/privacy/j2commerce/src/Consent/ConsentRepository.php';
}

if (!class_exists(\Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::class)) {
    require_once JPATH_PLUGINS . '/privacy/j2commerce/src/Support/J2CommerceStack.php';
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

/** Event with addResult() for stacks without the J2Commerce 6 PluginEvent class. */
class ConsentTestHtmlEvent extends Event
{
    public array $results = [];

    public function addResult($value): void
    {
        $this->results[] = $value;
    }
}

if (class_exists(J2CommercePrivacy::class)) {
    /** Test double for the event dispatch tests: replaces only session and request access. */
    class ConsentLoggingTestPlugin extends J2CommercePrivacy
    {
        public ?int $consentCart = null;

        public array $privacyOptions = ['show_consent_checkbox' => 1, 'consent_required' => 1];

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
            return new Registry($this->privacyOptions);
        }
    }
}

class ConsentLoggingTest
{
    private const PREFIX      = 'TESTCONSENT-';
    private const GUEST_EMAIL = 'guest-consent@example.invalid';
    private const USER_ID     = 100;
    private const FORM_TOKEN  = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';
    private const SITE_URL    = 'http://localhost/index.php';

    /** script.php RETIRED_OVERRIDE_SUFFIX */
    private const RETIRED_SUFFIX = '.plg_privacy_j2commerce-disabled';

    private $db;
    private int $passed = 0;
    private int $failed = 0;
    private string $ordersTable;
    private string $pkColumn;

    /** @var int[] J2Commerce carts created by the HTTP tests */
    private array $httpCarts = [];

    /** Diagnostics of the last HTTP request (status, final URL, body start). */
    private string $lastHttp = '';

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');

        // Same decision as the plugin (installed component, not table presence).
        $isJ4              = \Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack::isJ2Commerce4($this->db);
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
    private function createOrder(string $orderId, int $userId, string $email, ?string $createdOn = null, int $cartId = 0): bool
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
        $row['created_on'] = $createdOn ?? Factory::getDate()->toSql();

        if (array_key_exists('token', $row)) {
            $row['token'] = self::tokenFor($orderId);
        }

        // Keep possibly unique columns distinct from the cloned fixture row.
        if (array_key_exists('invoice_number', $row)) {
            $row['invoice_number'] = random_int(900000000, 999999999);
        }

        if (array_key_exists('cart_id', $row)) {
            $row['cart_id'] = $cartId;
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
            // J2Commerce 6 dispatches 'onJ2Commerce' . 'AfterDisplayShippingPayment'. The consent is
            // recorded when the order is placed, not on AfterSaveOrder (the confirmation step saves
            // an incomplete order already when it is rendered).
            $this->test('Subscribes to onJ2CommerceAfterDisplayShippingPayment', isset($events['onJ2CommerceAfterDisplayShippingPayment']));
            $this->test('Does not record on onJ2CommerceAfterSaveOrder', !isset($events['onJ2CommerceAfterSaveOrder']));
            $this->test('Subscribes to onAfterRoute', isset($events['onAfterRoute']));
            $this->test('Subscribes to onJ2CommerceCheckoutCleanup', isset($events['onJ2CommerceCheckoutCleanup']));

            $this->runCheckboxRenderTests();
            $this->runCheckoutRequestTests();
            $this->runPlacedOrderTests($repository);
            $this->runHttpCheckoutTests();
        }

        $this->runLegacyConsentTests($repository);
        $this->runLayoutTests($orderGuest);

        $this->cleanup();

        $this->runUpdatePathTests($extension);

        echo "\n=== Consent Logging Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function runCheckboxRenderTests(): void
    {
        echo "\n-- Checkbox through AfterDisplayShippingPayment --\n";

        $required = J2CommercePrivacy::renderConsentCheckbox(new Registry(['show_consent_checkbox' => 1, 'consent_required' => 1]));
        $this->test('Checkbox has id and name j2commerce_privacy_consent',
            str_contains($required, 'id="j2commerce_privacy_consent"') && str_contains($required, 'name="j2commerce_privacy_consent"'));
        $this->test('Required checkbox is marked required', str_contains($required, ' required') && str_contains($required, 'text-danger'));
        $this->test('Checkbox HTML contains no script', !str_contains(strtolower($required), '<script'));
        $this->test('Default consent text is translated', !str_contains($required, 'PLG_PRIVACY_J2COMMERCE_') && !str_contains($required, '{privacy_policy}'));

        $optional = J2CommercePrivacy::renderConsentCheckbox(new Registry(['consent_required' => 0, 'consent_text' => 'Custom {privacy_policy} text']));
        $this->test('Optional checkbox is not marked required', !str_contains($optional, ' required'));
        $this->test('Custom consent text with placeholder is used', str_contains($optional, 'Custom ') && !str_contains($optional, '{privacy_policy}'));

        $plugin = new ConsentLoggingTestPlugin(['name' => 'j2commerceprivacy', 'type' => 'system', 'params' => '{}']);

        $dispatcher = new Dispatcher();
        $dispatcher->addSubscriber($plugin);

        $eventClass = 'J2Commerce\\Component\\J2commerce\\Administrator\\Event\\PluginEvent';
        $makeEvent  = static fn () => class_exists($eventClass)
            ? new $eventClass('onJ2CommerceAfterDisplayShippingPayment', [null])
            : new ConsentTestHtmlEvent('onJ2CommerceAfterDisplayShippingPayment', [null]);
        $resultHtml = static function ($event): string {
            $results = $event instanceof ConsentTestHtmlEvent ? $event->results : (array) $event->getArgument('result', []);

            return implode('', array_filter($results, 'is_string'));
        };

        $event = $makeEvent();
        $dispatcher->dispatch('onJ2CommerceAfterDisplayShippingPayment', $event);
        $this->test('Event listener adds the checkbox to the J2Commerce event result', str_contains($resultHtml($event), 'id="j2commerce_privacy_consent"'));

        $plugin->privacyOptions = ['show_consent_checkbox' => 0];
        $event                  = $makeEvent();
        $dispatcher->dispatch('onJ2CommerceAfterDisplayShippingPayment', $event);
        $this->test('No checkbox when "Show Consent Checkbox" is off', $resultHtml($event) === '');
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
        $call     = fn (Input $input, ConsentTestSession $session, Registry $params, int $cartId) =>
            $plugin->handleCheckoutRequest($input, $session, $params, $cartId, self::FORM_TOKEN);

        // Shipping & payment step
        $session  = new ConsentTestSession();
        $response = $call($this->request($validate, $token), $session, $required, $cart);
        $this->test('Step: unticked required checkbox is rejected', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);
        $this->test('Step: rejection message is translated', isset($response['message']) && !str_contains($response['message'], 'PLG_PRIVACY_J2COMMERCE_'));

        $response = $call($this->request($validate, $token + [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $required, $cart);
        $this->test('Step: ticked checkbox passes', $response === null);
        $this->test('Step: consent stored for the current cart', ($session->get(J2CommercePrivacy::SESSION_CONSENT)['cart'] ?? null) === $cart);

        $response = $call($this->request($validate, $token), $session, $required, $cart);
        $this->test('Step: a later unticked submit is rejected and removes the consent',
            ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR && $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);

        $variant  = ['option' => 'com_j2commerce', 'controller' => 'checkout', 'task' => 'shippingPaymentMethodValidate'];
        $response = $call($this->request($variant, $token), $session, $required, $cart);
        $this->test('Step: controller=checkout&task=... variant is rejected too', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);

        $response = $call($this->request($validate + ['template' => 'cassiopeia', 'templateStyle' => '1', 'Itemid' => '999'], $token), $session, $required, $cart);
        $this->test('Step: template/templateStyle/Itemid request parameters do not switch enforcement off', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR);

        $response = $call($this->request($validate, [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $required, $cart);
        $this->test('Step: invalid token stores nothing', $response === null && $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);

        // Without a cart (ID 0) the consent cannot be bound to a purchase.
        $session  = new ConsentTestSession();
        $response = $call($this->request($validate, $token + [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $required, 0);
        $this->test('Step: no cart (ID 0) with required consent is refused and stores nothing',
            ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_STEP_ERROR && $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);
        $response = $call($this->request($validate, $token + [J2CommercePrivacy::CONSENT_FIELD => '1']), $session, $optional, 0);
        $this->test('Step: no cart (ID 0) with optional consent passes and stores nothing',
            $response === null && $session->get(J2CommercePrivacy::SESSION_CONSENT) === null);
        $session->set(J2CommercePrivacy::SESSION_CONSENT, ['cart' => 0, 'at' => time()]);
        $response = $call($this->request($confirm), $session, $required, 0);
        $this->test('Confirm: a consent bound to cart ID 0 does not count', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_CONFIRM_ERROR);

        $response = $call($this->request($validate, $token), $session, $optional, $cart);
        $this->test('Step: optional consent is not enforced', $response === null);

        $response = $call($this->request($validate, $token), $session, $hidden, $cart);
        $this->test('Step: disabled checkbox is not enforced', $response === null);

        $response = $call($this->request(['option' => 'com_content', 'task' => 'checkout.confirm']), $session, $required, $cart);
        $this->test('Other components are ignored', $response === null);

        // Confirmation step
        $session  = new ConsentTestSession();
        $response = $call($this->request($confirm), $session, $required, $cart);
        $this->test('Confirm: refused without consent (step 4 skipped)', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_CONFIRM_ERROR);

        $session->set(J2CommercePrivacy::SESSION_CONSENT, ['cart' => $cart, 'at' => time()]);
        $response = $call($this->request($confirm), $session, $required, 12);
        $this->test('Confirm: refused after a cart change (consent of the previous cart)', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_CONFIRM_ERROR);

        $response = $call($this->request($confirm), $session, $required, $cart);
        $this->test('Confirm: allowed with consent for the current cart', $response === null);

        $response = $call($this->request($confirm), new ConsentTestSession(), $optional, $cart);
        $this->test('Confirm: not refused when consent is optional', $response === null);

        // Payment submission
        $session  = new ConsentTestSession();
        $response = $call($this->request($payment, $token, 'POST', ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']), $session, $required, $cart);
        $this->test('ConfirmPayment (AJAX POST): refused without consent', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_PAYMENT_ERROR);

        $response = $call($this->request($payment, $token), $session, $required, $cart);
        $this->test('ConfirmPayment (form POST): redirected back without consent', ($response['type'] ?? '') === J2CommercePrivacy::RESPONSE_REDIRECT);

        $response = $call($this->request($payment, [], 'GET'), $session, $required, $cart);
        $this->test('ConfirmPayment (gateway return GET): not refused', $response === null);

        $this->test('resolveTask keeps dotted tasks', J2CommercePrivacy::resolveTask($this->request($validate)) === J2CommercePrivacy::TASK_VALIDATE);
    }

    private function runPlacedOrderTests(ConsentRepository $repository): void
    {
        echo "\n-- Consent recorded after J2Commerce accepted the order --\n";

        if ($this->ordersTable !== '#__j2commerce_orders') {
            echo "  (J2Commerce 6 only; the system plugin does not record consent on J2Store)\n";

            return;
        }

        $accepted   = self::PREFIX . 'PLACED-1';
        $otherCart  = self::PREFIX . 'PLACED-2';
        $incomplete = self::PREFIX . 'PLACED-3';
        $offsite    = self::PREFIX . 'PLACED-4';

        $this->createOrder($accepted, 0, self::GUEST_EMAIL, null, 21);
        $this->createOrder($otherCart, 0, self::GUEST_EMAIL, null, 22);
        $this->createOrder($incomplete, 0, self::GUEST_EMAIL, null, 21);
        $this->createOrder($offsite, 0, self::GUEST_EMAIL, null, 23);
        $this->setOrderState($accepted, 1);
        $this->setOrderState($otherCart, 1);
        $this->setOrderState($incomplete, J2CommercePrivacy::ORDER_STATE_INCOMPLETE);
        $this->setOrderState($offsite, J2CommercePrivacy::ORDER_STATE_INCOMPLETE);

        $token   = [self::FORM_TOKEN => '1'];
        $payment = ['option' => 'com_j2commerce', 'task' => 'checkout.confirmPayment'];
        $confirm = ['option' => 'com_j2commerce', 'task' => 'checkout.confirm'];

        try {
            $plugin = new ConsentLoggingTestPlugin(['name' => 'j2commerceprivacy', 'type' => 'system', 'params' => '{}']);
            $plugin->setDatabase($this->db);
            $capture = fn (Input $input, ?int $cart, string $order): ?array => $plugin->capturePlacement($input, $cart, $order, self::FORM_TOKEN);

            // Capture (before J2Commerce's controller): only confirmPayment with a ticked consent.
            $this->test('Capture: confirmation step is not captured', $capture($this->request($confirm, $token), 21, $accepted) === null);
            $this->test('Capture: POST without a valid form token is not captured', $capture($this->request($payment, []), 21, $accepted) === null);
            $this->test('Capture: no ticked consent is not captured', $capture($this->request($payment, $token), null, $accepted) === null);
            $this->test('Capture: consent bound to cart ID 0 is not captured', $capture($this->request($payment, $token), 0, $accepted) === null);
            $this->test('Capture: empty order number (no order in the user state) is not captured', $capture($this->request($payment, $token), 21, '') === null);

            $posted = $capture($this->request($payment, $token), 21, $accepted);
            $this->test('Capture: POST with form token captures order, cart and request data',
                $posted !== null && $posted['order_id'] === $accepted && $posted['cart_id'] === 21 && $posted['ip'] === '203.0.113.7' && $posted['user_agent'] === 'ConsentLoggingTest/1.0',
                json_encode($posted));
            $returned = $capture($this->request($payment, [], 'GET'), 23, $offsite);
            $this->test('Capture: GET return from an off-site gateway is captured', $returned !== null && $returned['order_id'] === $offsite, json_encode($returned));

            // Recording (after the controller): order must be accepted and from the consent cart.
            $this->test('Record: incomplete order (state 5, e.g. rejected by AfterOrderValidate) gets no record',
                $plugin->recordAcceptedOrderConsent(['order_id' => $incomplete, 'cart_id' => 21, 'ip' => '203.0.113.7', 'user_agent' => 'x']) === null
                && $this->countOrderConsents($incomplete) === 0);
            $this->test('Record: order of another cart gets no record',
                $plugin->recordAcceptedOrderConsent(['order_id' => $otherCart, 'cart_id' => 21, 'ip' => '203.0.113.7', 'user_agent' => 'x']) === null
                && $this->countOrderConsents($otherCart) === 0);
            $this->test('Record: unknown order number gets no record',
                $plugin->recordAcceptedOrderConsent(['order_id' => self::PREFIX . 'MISSING', 'cart_id' => 21, 'ip' => '', 'user_agent' => '']) === null);

            $first  = $plugin->recordAcceptedOrderConsent($posted);
            $second = $plugin->recordAcceptedOrderConsent($posted);
            $this->test('Record: accepted order gets exactly one record (double submit)',
                ($first['created'] ?? null) === true && ($second['created'] ?? null) === false && $this->countOrderConsents($accepted) === 1,
                'count ' . $this->countOrderConsents($accepted));
            $record = $repository->findOrderConsent($accepted);
            $this->test('Record: body holds the captured IP address and user agent',
                $record && str_contains($record->body, '203.0.113.7') && str_contains($record->body, 'ConsentLoggingTest/1.0'));

            // Off-site gateway: the order is still incomplete on the outbound POST and accepted on return.
            $this->test('Record: off-site order still incomplete gets no record', $plugin->recordAcceptedOrderConsent($returned) === null);
            $this->setOrderState($offsite, 4);
            $this->test('Record: off-site order accepted on return gets its record',
                ($plugin->recordAcceptedOrderConsent($returned)['created'] ?? null) === true && $this->countOrderConsents($offsite) === 1);

            // Parallel requests: a second row inserted by a concurrent request is removed again.
            $duplicate = (object) ['user_id' => 0, 'state' => 1, 'created' => Factory::getDate()->toSql(), 'subject' => ConsentRepository::SUBJECT,
                'body' => $repository->buildBody($accepted, '203.0.113.8', 'Parallel/1.0'), 'remind' => 0, 'token' => ''];
            $this->db->insertObject('#__privacy_consents', $duplicate, 'id');
            $kept = $repository->removeDuplicateConsents($accepted);
            $this->test('Duplicate consent rows of one order are reduced to the oldest',
                $kept === (int) ($first['id'] ?? 0) && $this->countOrderConsents($accepted) === 1);

            // The AfterSaveOrder event no longer writes anything.
            $dispatcher = new Dispatcher();
            $dispatcher->addSubscriber($plugin);
            $dispatcher->dispatch('onJ2CommerceAfterSaveOrder', new Event('onJ2CommerceAfterSaveOrder', [(object) ['order_id' => $incomplete, 'user_id' => 0, 'cart_id' => 21]]));
            $this->test('onJ2CommerceAfterSaveOrder records nothing', $this->countOrderConsents($incomplete) === 0);

            // Source of the order number: J2Commerce 6 stores it in the user state.
            $controller = JPATH_SITE . '/components/com_j2commerce/src/Controller/CheckoutController.php';

            if (is_file($controller)) {
                $source = (string) file_get_contents($controller);
                $this->test('J2Commerce keeps the order number in the user state j2commerce.order_id',
                    str_contains($source, "setUserState('j2commerce.order_id'") && str_contains($source, "getUserState('j2commerce.order_id'"));
                $this->test('J2Commerce marks an unplaced order with order_state_id 5', (bool) preg_match('/order_state_id\s*\?\?\s*0\)\s*===\s*5/', $source));
            }
        } catch (\Throwable $e) {
            $this->test('Placed-order recording runs without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    private function setOrderState(string $orderId, int $state): void
    {
        $this->db->setQuery(
            $this->query()
                ->update($this->db->quoteName($this->ordersTable))
                ->set($this->db->quoteName('order_state_id') . ' = ' . $state)
                ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($orderId))
        )->execute();
    }

    /**
     * Records of an earlier template override (subject PLG_PRIVACY_J2COMMERCE, e-mail address,
     * IP address and user agent in the body, no order reference) are only anonymized.
     */
    private function runLegacyConsentTests(ConsentRepository $repository): void
    {
        echo "\n-- Legacy consent records (earlier template override) --\n";

        $userOrder = self::PREFIX . 'LEGACY-USER';
        $created   = Factory::getDate('-3 days')->toSql();
        $this->createOrder($userOrder, self::USER_ID, 'test@example.com', $created);

        $body = static fn (string $email, string $ip, string $ua): string =>
            '<p>Einwilligung zur Datenschutzerklärung während des J2Commerce-Checkouts. E-Mail: <strong>' . htmlspecialchars($email) . '</strong></p>'
            . '<p>IP-Adresse: <strong>' . $ip . '</strong></p><p>User-Agent:<br/>' . htmlspecialchars($ua) . '</p>';
        $ids    = [];
        $insert = function (string $key, int $userId, string $when, string $text, string $subject = ConsentRepository::LEGACY_SUBJECT) use (&$ids): void {
            $row = (object) ['user_id' => $userId, 'state' => 1, 'created' => $when, 'subject' => $subject, 'body' => $text, 'remind' => 0, 'token' => ''];
            $this->db->insertObject('#__privacy_consents', $row, 'id');
            $ids[$key] = (int) $row->id;
        };

        // Profile pattern: created exactly at the order time (written afterwards by the old template).
        $insert('profile', self::USER_ID, $created, $body('test@example.com', '198.51.100.40', 'LegacyAgent/1.0'));
        $insert('checkout', self::USER_ID, Factory::getDate('-3 days +2 minutes')->toSql(), $body('test@example.com', '198.51.100.41', 'LegacyAgent/1.1'));
        $insert('guestOwn', 0, Factory::getDate('-4 days')->toSql(), $body('legacy-own@example.invalid', '198.51.100.42', 'LegacyAgent/1.2'));
        $insert('guestOther', 0, Factory::getDate('-4 days')->toSql(), $body('legacy-other@example.invalid', '198.51.100.43', 'LegacyAgent/1.3'));
        $insert('oldBody', 102, Factory::getDate('-5 days')->toSql(), 'Consent given during J2Commerce checkout');

        $load = fn (string $key): ?object => $this->loadConsent($ids[$key]);
        $isAnonymized = function (?object $row, array $gone): bool {
            if (!$row || $row->subject !== ConsentRepository::LEGACY_DONE_SUBJECT
                || !str_contains($row->body, ConsentRepository::LEGACY_MARKER)
                || !str_contains($row->body, ConsentRepository::EVIDENCE_REMOVED_MARKER)
                || str_contains($row->body, 'PLG_SYSTEM_J2COMMERCEPRIVACY_')
                || str_contains($row->body, 'j2commerce-order:')
            ) {
                return false;
            }

            foreach ($gone as $text) {
                if (str_contains($row->body, $text)) {
                    return false;
                }
            }

            return true;
        };

        $language   = Factory::getLanguage();
        $tag        = method_exists($language, 'getTag') ? (string) $language->getTag() : 'en-GB';
        $legacyRoot = sys_get_temp_dir() . '/privacy-legacy-language-' . uniqid('', true);
        $legacyDir  = $legacyRoot . '/' . $tag;
        $legacyFile = $legacyDir . '/plg_system_j2commerceprivacy.ini';
        $loadedOld  = false;
        $siteTag    = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $siteLang   = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($siteTag);
        $siteLang->load('plg_system_j2commerceprivacy', JPATH_ADMINISTRATOR, $siteTag)
            || $siteLang->load('plg_system_j2commerceprivacy', JPATH_PLUGINS . '/system/j2commerceprivacy', $siteTag);
        $expectedBodyPrefix = (string) $siteLang->_(ConsentRepository::LEGACY_BODY_KEY);

        try {
            if (@mkdir($legacyDir, 0755, true) && @file_put_contents(
                $legacyFile,
                "PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT=\"Legacy fixture without new key\"\n"
            ) !== false) {
                $loadedOld = (bool) $language->load('plg_system_j2commerceprivacy', $legacyRoot, $tag, true);
            }

            $this->test(
                'Update simulation loads an older system-plugin language without the legacy body key',
                $loadedOld && !$language->hasKey(ConsentRepository::LEGACY_BODY_KEY),
                "loaded=$loadedOld tag=$tag"
            );

            // Removal request of user 100 (account e-mail legacy-own@...): own records and guest
            // records with that e-mail address, nothing else.
            $scoped = $repository->anonymizeLegacyConsents(self::USER_ID, ['legacy-own@example.invalid', '']);
            $this->test('Removal request anonymizes the user\'s records and guest records with the user\'s e-mail', $scoped === 3, "changed $scoped");
            $this->test('Guest record with another e-mail is not touched by that request', $load('guestOther')->subject === ConsentRepository::LEGACY_SUBJECT);
            $this->test('Record of another user is not touched by that request', $load('oldBody')->subject === ConsentRepository::LEGACY_SUBJECT);

            $insert(
                'rawKey',
                0,
                Factory::getDate('-4 days')->toSql(),
                ConsentRepository::LEGACY_BODY_KEY . ConsentRepository::LEGACY_MARKER . ConsentRepository::EVIDENCE_REMOVED_MARKER,
                ConsentRepository::LEGACY_DONE_SUBJECT
            );
            $all = $repository->anonymizeLegacyConsents();
            $this->test('Cleanup anonymizes the remaining legacy records and repairs already key-based legacy bodies', $all === 3, "changed $all");

            foreach (['profile' => ['test@example.com', '198.51.100.40', 'LegacyAgent'], 'checkout' => ['198.51.100.41'], 'guestOwn' => ['legacy-own@', '198.51.100.42'], 'guestOther' => ['legacy-other@', '198.51.100.43'], 'oldBody' => ['Consent given during']] as $key => $gone) {
                $row = $load($key);
                $this->test("[$key] anonymized, never assigned to an order, neutral text", $isAnonymized($row, $gone), $row->body ?? '');
                $this->test("[$key] created and invalidated for com_privacy lists", $row && (int) $row->state === -1);
                $this->test("[$key] body uses the default site language", $row && str_starts_with((string) $row->body, $expectedBodyPrefix), $row->body ?? '');
            }
            $rawKey = $load('rawKey');
            $this->test('[rawKey] already anonymized key-based legacy body is repaired', $isAnonymized($rawKey, [ConsentRepository::LEGACY_BODY_KEY]), $rawKey->body ?? '');
            $this->test('[rawKey] repaired legacy row is invalidated for com_privacy lists', $rawKey && (int) $rawKey->state === -1);

            $this->test('Legacy anonymization never creates a checkout consent for the order', $this->countOrderConsents($userOrder) === 0);
            $this->test('Second run changes nothing', $repository->anonymizeLegacyConsents() === 0);

            $status = $repository->getStatus(self::USER_ID);
            $shown  = array_map(static fn ($r) => (int) $r->id, $status['records']);
            $this->test('Legacy records are not shown and not counted as consent', !array_intersect($shown, array_values($ids)));
        } catch (\Throwable $e) {
            $this->test('Legacy anonymization runs without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            @unlink($legacyFile);
            @rmdir($legacyDir);
            @rmdir($legacyRoot);
            $this->db->setQuery(
                $this->query()
                    ->delete($this->db->quoteName('#__privacy_consents'))
                    ->where($this->db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')')
            )->execute();
        }

        $this->runStaleEvidenceTests($repository);
    }

    /**
     * IP address and user agent go when the order no longer needs them: record outside the
     * retention period, order deleted, order already anonymized.
     */
    private function runStaleEvidenceTests(ConsentRepository $repository): void
    {
        echo "\n-- Consent evidence without a current order --\n";

        $live       = self::PREFIX . 'STALE-LIVE';
        $anonymized = self::PREFIX . 'STALE-ANON';
        $old        = self::PREFIX . 'STALE-OLD';
        $deleted    = self::PREFIX . 'STALE-GONE';

        $this->createOrder($live, self::USER_ID, 'test@example.com');
        $this->createOrder($anonymized, self::USER_ID, 'anonymized@deleted.invalid');
        $this->createOrder($old, self::USER_ID, 'test@example.com');
        $this->db->setQuery(
            $this->query()
                ->update($this->db->quoteName($this->ordersTable))
                ->set($this->db->quoteName('ip_address') . ' = ' . $this->db->quote(''))
                ->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($anonymized))
        )->execute();
        $infos = str_replace('_orders', '_orderinfos', $this->ordersTable);
        $info  = ['order_id' => $anonymized, 'billing_first_name' => 'Anonymized', 'billing_last_name' => 'User'];

        // Every other column of the order info table: empty value (the anonymized state).
        foreach ($this->db->getTableColumns($infos, false) as $name => $column) {
            if (!array_key_exists($name, $info) && ($column->Key ?? '') !== 'PRI' && strtoupper((string) ($column->Null ?? 'YES')) === 'NO' && $column->Default === null) {
                $type         = (string) $column->Type;
                $info[$name] = preg_match('/int|decimal|float|double/i', $type) ? 0 : (preg_match('/date|time/i', $type) ? Factory::getDate()->toSql() : '');
            }
        }

        $info = (object) $info;

        try {
            $this->db->insertObject($infos, $info);
        } catch (\Throwable $e) {
            $this->test('order info of the anonymized order seeded', false, $e->getMessage());
        }

        $ids = [];

        foreach ([$live => 'now', $anonymized => 'now', $old => '-20 years', $deleted => 'now'] as $order => $when) {
            $result = $repository->ensureOrderConsent($order, self::USER_ID, $repository->buildBody($order, '198.51.100.60', 'StaleAgent/1.0'));
            $ids[$order] = (int) ($result['id'] ?? 0);

            if ($when !== 'now') {
                $this->db->setQuery(
                    $this->query()->update($this->db->quoteName('#__privacy_consents'))
                        ->set($this->db->quoteName('created') . ' = ' . $this->db->quote(Factory::getDate($when)->toSql()))
                        ->where($this->db->quoteName('id') . ' = ' . $ids[$order])
                )->execute();
            }
        }

        try {
            $changed = $repository->removeStaleEvidence(Factory::getDate('-10 years')->toSql(), [$this->ordersTable]);
            $body    = fn (string $order): string => (string) ($this->loadConsent($ids[$order])->body ?? '');

            $this->test('Evidence removed from 3 records (outside retention, anonymized order, deleted order)', $changed === 3, "changed $changed");
            $this->test('Consent of a current order keeps IP address and user agent', str_contains($body($live), '198.51.100.60'));

            foreach ([$anonymized => 'already anonymized order', $old => 'record outside the retention period', $deleted => 'deleted order'] as $order => $label) {
                $this->test("Consent of a $label loses IP address and user agent",
                    !str_contains($body($order), '198.51.100.60') && !str_contains($body($order), 'StaleAgent') && str_contains($body($order), ConsentRepository::orderMarker($order)));
            }

            $this->test('Second run changes nothing', $repository->removeStaleEvidence(Factory::getDate('-10 years')->toSql(), [$this->ordersTable]) === 0);

            // The evidence-removed body is written while an administrator or the cleanup task
            // processes the order, so it must use the website's default site language, not the
            // language of the acting person (the current CLI language here). Force a site language
            // that differs from the current one and assert the body follows the site language.
            $current     = method_exists(Factory::getLanguage(), 'getTag') ? (string) Factory::getLanguage()->getTag() : 'en-GB';
            $phrases     = [
                'de-DE' => 'nach Ablauf ihrer Aufbewahrungsfrist',
                'en-GB' => 'after its retention period',
                'fr-FR' => 'après sa durée de conservation',
            ];
            $siteTestTag = $current === 'de-DE' ? 'en-GB' : 'de-DE';
            $langParams   = ComponentHelper::getParams('com_languages');
            $previousSite = (string) $langParams->get('site', 'en-GB');
            $langParams->set('site', $siteTestTag);

            try {
                $evidenceBody = $repository->buildEvidenceRemovedBody($live);
                $usesSite     = str_contains($evidenceBody, $phrases[$siteTestTag]);
                $notCurrent   = !isset($phrases[$current]) || !str_contains($evidenceBody, $phrases[$current]);
                $this->test(
                    'Evidence-removed body uses the default site language, not the acting person\'s language',
                    $usesSite && $notCurrent,
                    "site=$siteTestTag current=$current body=$evidenceBody"
                );
            } finally {
                $langParams->set('site', $previousSite);
            }
        } catch (\Throwable $e) {
            $this->test('Stale evidence cleanup runs without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $this->db->setQuery(
                $this->query()->delete($this->db->quoteName($infos))->where($this->db->quoteName('order_id') . ' = ' . $this->db->quote($anonymized))
            )->execute();
        }
    }
    /**
     * Real site requests against this container: real Joomla session (database handler), real
     * J2Commerce cart lookup, the real onAfterRoute() of the enabled system plugin and the real
     * AfterDisplayShippingPayment event of the rendered step.
     */
    private function runHttpCheckoutTests(): void
    {
        echo "\n-- HTTP checkout requests (real session, J2Commerce 6) --\n";

        if (!is_dir(JPATH_SITE . '/components/com_j2commerce')) {
            echo "  (J2Commerce 6 is not installed on this stack; HTTP checkout tests apply to J2Commerce 6 only)\n";

            return;
        }

        if (!$this->test('PHP curl extension available for HTTP tests', function_exists('curl_init'))) {
            return;
        }

        $message   = htmlspecialchars(Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_REQUIRED_ERROR'), ENT_QUOTES, 'UTF-8');
        $validate  = ['option' => 'com_j2commerce', 'task' => 'checkout.shippingPaymentMethodValidate'];
        $render    = ['option' => 'com_j2commerce', 'task' => 'checkout.shippingPaymentMethod'];
        $confirm   = ['option' => 'com_j2commerce', 'task' => 'checkout.confirm'];
        $payment   = ['option' => 'com_j2commerce', 'task' => 'checkout.confirmPayment'];
        $ticked    = [J2CommercePrivacy::CONSENT_FIELD => '1', 'payment_plugin' => 'payment_cash'];
        $stepError = static function (string $body): bool {
            $json = json_decode($body, true);

            return is_array($json) && isset($json['error'][J2CommercePrivacy::CONSENT_FIELD]);
        };
        // An accepted step is the J2Commerce JSON response without any error (the test cart is
        // empty, so no shipping or payment method is required). HTML, error pages, token errors or
        // an empty body do not count as accepted.
        $stepAccepted = static function (string $body): bool {
            $json = json_decode($body, true);

            return is_array($json) && !array_key_exists('error', $json);
        };

        try {
            // Flow A: render step 4 (checkbox through the event), tick, render again
            echo "  Flow A: render, tick, re-render\n";
            $a = $this->startSiteSession();

            if ($this->test('Flow A: site session and form token obtained', $a !== null, $this->lastHttp)) {
                $a['cart'] = $this->createSessionCart($a['session']);

                $body = $this->post($a, $render);
                $this->test('Flow A: step 4 contains the consent checkbox', str_contains($body, 'id="j2commerce_privacy_consent"'), $this->lastHttp);

                $body = $this->post($a, $validate);
                $this->test('Flow A: unticked step is rejected by onAfterRoute (JSON field error)', $stepError($body), $this->lastHttp);

                $body = $this->post($a, $validate + $ticked);
                $this->test('Flow A: ticked step is accepted (JSON without error)', $stepAccepted($body), $this->lastHttp);

                $body = $this->post($a, $confirm);
                $this->test('Flow A: confirm after ticking is not refused (consent bound to the current cart)', !str_contains($body, $message), $this->lastHttp);

                $this->post($a, $render);
                $body = $this->post($a, $confirm);
                $this->test('Flow A: re-rendering step 4 discards the earlier consent', str_contains($body, $message), $this->lastHttp);
            }

            // Flow B: skip step 4 and try to switch the check off through request parameters
            echo "  Flow B: skipped step 4 and request parameters\n";
            $b = $this->startSiteSession();

            if ($this->test('Flow B: site session and form token obtained', $b !== null, $this->lastHttp)) {
                $b['cart'] = $this->createSessionCart($b['session']);

                $body = $this->post($b, $validate + ['payment_plugin' => 'payment_cash']);
                $this->test('Flow B: validate without render and without tick is rejected', $stepError($body), $this->lastHttp);

                $body = $this->post($b, ['option' => 'com_j2commerce', 'controller' => 'checkout', 'task' => 'shippingPaymentMethodValidate']);
                $this->test('Flow B: controller=checkout variant is rejected', $stepError($body), $this->lastHttp);

                $body = $this->post($b, $confirm);
                $this->test('Flow B: confirm without consent is refused', str_contains($body, $message), $this->lastHttp);

                foreach ([['template' => 'cassiopeia'], ['template' => 'atum'], ['templateStyle' => '999999'], ['Itemid' => '999999']] as $switch) {
                    $label = http_build_query($switch);
                    $body  = $this->post($b, $confirm + $switch);
                    $this->test("Flow B: confirm with $label is still refused", str_contains($body, $message), $this->lastHttp);
                }

                $body = $this->post($b, $payment);
                $json = json_decode($body, true);
                $this->test('Flow B: confirmPayment (AJAX) without consent is refused', is_array($json) && ($json['success'] ?? null) === false, $this->lastHttp);
            }

            // Flow C: tick, then the session switches to another cart
            echo "  Flow C: cart change\n";
            $c = $this->startSiteSession();

            if ($this->test('Flow C: site session and form token obtained', $c !== null, $this->lastHttp)) {
                $c['cart'] = $this->createSessionCart($c['session']);
                $body = $this->post($c, $validate + $ticked);
                $this->test('Flow C: ticked step is accepted (JSON without error)', $stepAccepted($body), $this->lastHttp);

                $body = $this->post($c, $confirm);
                $this->test('Flow C: confirm with consent for the current cart is not refused', !str_contains($body, $message), $this->lastHttp);

                $this->db->setQuery(
                    $this->query()
                        ->update($this->db->quoteName('#__j2commerce_carts'))
                        ->set($this->db->quoteName('session_id') . ' = ' . $this->db->quote('moved-' . $c['cart']))
                        ->where($this->db->quoteName('j2commerce_cart_id') . ' = ' . (int) $c['cart'])
                )->execute();
                $newCart = $this->createSessionCart($c['session']);

                $body = $this->post($c, $confirm);
                $this->test("Flow C: confirm after the cart changed ({$c['cart']} -> $newCart) is refused", str_contains($body, $message), $this->lastHttp);
            }

            // Flow D: no template override (child or parent), the J2Commerce core template renders the checkbox
            echo "  Flow D: J2Commerce core template without any override\n";
            $moved = $this->moveCheckoutOverridesAside();

            try {
                $d = $this->startSiteSession();

                if ($this->test('Flow D: site session and form token obtained', $d !== null, $this->lastHttp)) {
                    $d['cart'] = $this->createSessionCart($d['session']);

                    $body = $this->post($d, $render);
                    $this->test('Flow D: core template step 4 contains the consent checkbox (event)', str_contains($body, 'id="j2commerce_privacy_consent"'), $this->lastHttp);

                    $body = $this->post($d, $validate);
                    $this->test('Flow D: unticked step is rejected without override', $stepError($body), $this->lastHttp);

                    $body = $this->post($d, $confirm);
                    $this->test('Flow D: confirm without ticked checkbox is refused', str_contains($body, $message), $this->lastHttp);

                    $body = $this->post($d, $validate + $ticked);
                    $this->test('Flow D: ticked step is accepted without override (JSON without error)', $stepAccepted($body), $this->lastHttp);

                    $body = $this->post($d, $confirm);
                    $this->test('Flow D: confirm after ticking is not refused (consent stored without override)', !str_contains($body, $message), $this->lastHttp);
                }
            } finally {
                foreach ($moved as $original => $aside) {
                    @rename($aside, $original);
                }
            }

            $this->runPrivacyLinkHttpTests($render);
        } catch (\Throwable $e) {
            $this->test('HTTP checkout tests run without error', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            if ($this->httpCarts !== []) {
                $this->db->setQuery(
                    $this->query()
                        ->delete($this->db->quoteName('#__j2commerce_carts'))
                        ->where($this->db->quoteName('j2commerce_cart_id') . ' IN (' . implode(',', array_map('intval', $this->httpCarts)) . ')')
                )->execute();
            }
        }
    }

    /**
     * Flow E: privacy policy link of the rendered step 4 with "Privacy Policy Article" set, with SEF
     * URLs off and on. The href must be escaped exactly once (no "&amp;amp;", no "amp;" parameters).
     * configuration.php, the plugin params and the test article are restored afterwards.
     */
    private function runPrivacyLinkHttpTests(array $render): void
    {
        echo "  Flow E: privacy policy link (SEF off and on)\n";

        $configFile = JPATH_BASE . '/configuration.php';
        $config     = (string) @file_get_contents($configFile);

        $this->db->setQuery(
            $this->query()
                ->select($this->db->quoteName(['extension_id', 'params']))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
                ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('privacy'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('j2commerce'))
        );
        $plugin = $this->db->loadObject();

        if (!$this->test('Flow E: configuration.php and privacy plugin row readable', $config !== '' && $plugin !== null)) {
            return;
        }

        $setParams = function (string $params) use ($plugin): void {
            $this->db->setQuery(
                $this->query()
                    ->update($this->db->quoteName('#__extensions'))
                    ->set($this->db->quoteName('params') . ' = ' . $this->db->quote($params))
                    ->where($this->db->quoteName('extension_id') . ' = ' . (int) $plugin->extension_id)
            )->execute();
        };

        // A real article, so the SEF router builds a real article URL.
        $now     = Factory::getDate()->toSql();
        $article = (object) [
            'title' => 'Consent link test', 'alias' => 'consent-link-test-' . random_int(1000, 9999),
            'introtext' => '', 'fulltext' => '', 'state' => 1, 'catid' => 2, 'created' => $now,
            'created_by' => self::USER_ID, 'created_by_alias' => '', 'modified' => $now, 'modified_by' => self::USER_ID,
            'publish_up' => $now, 'images' => '{}', 'urls' => '{}', 'attribs' => '{}', 'version' => 1,
            'ordering' => 0, 'metakey' => '', 'metadesc' => '', 'access' => 1, 'hits' => 0,
            'metadata' => '{}', 'featured' => 0, 'language' => '*', 'note' => '',
        ];
        $articleId = 0;

        try {
            $this->db->insertObject('#__content', $article, 'id');
            $articleId = (int) ($article->id ?? $this->db->insertid());
        } catch (\Throwable $e) {
            $this->test('Flow E: test article created', false, $e->getMessage());

            return;
        }

        $params = new Registry($plugin->params);
        $params->set('show_consent_checkbox', 1);
        $params->set('privacy_article', $articleId);
        $params->set('consent_text', 'I accept the {privacy_policy}.');
        $setParams($params->toString());

        try {
            foreach (['SEF off' => '0', 'SEF on' => '1'] as $label => $sef) {
                $updated = preg_replace('/public \$sef\s*=\s*[^;]+;/', "public \$sef = '$sef';", $config, 1, $count);

                if (!$this->test("Flow E ($label): SEF setting written", $count === 1 && @file_put_contents($configFile, $updated) !== false)) {
                    continue;
                }

                // Apache OPcache revalidates configuration.php after revalidate_freq (2 s in the official image).
                sleep(3);

                $session = $this->startSiteSession();

                if (!$this->test("Flow E ($label): site session and form token obtained", $session !== null, $this->lastHttp)) {
                    continue;
                }

                $this->createSessionCart($session['session']);
                $body = $this->post($session, $render);
                $href = preg_match('/for="' . J2CommercePrivacy::CONSENT_FIELD . '">.*?<a href="([^"]*)"/s', $body, $match) ? $match[1] : null;

                if (!$this->test("Flow E ($label): consent text links the privacy article", $href !== null, $this->lastHttp)) {
                    continue;
                }

                $url = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $diag = 'href="' . $href . '"';

                $this->test("Flow E ($label): href is escaped once (no &amp;amp;)", !str_contains($href, '&amp;amp;') && !str_contains($url, '&amp;'), $diag);
                $this->test("Flow E ($label): no query parameter starts with amp;", !preg_grep('/^amp;/', array_keys($query)), $diag);

                if ($sef === '0') {
                    $this->test(
                        'Flow E (SEF off): href opens the article (view=article, id)',
                        ($query['option'] ?? '') === 'com_content' && ($query['view'] ?? '') === 'article' && (int) ($query['id'] ?? 0) === $articleId
                            && str_contains($href, '&amp;view=article'),
                        $diag
                    );
                } else {
                    $this->test('Flow E (SEF on): href is a SEF URL', !isset($query['option']) && str_contains($url, $article->alias), $diag);
                }
            }
        } finally {
            file_put_contents($configFile, $config);
            $setParams((string) $plugin->params);
            $this->db->setQuery(
                $this->query()
                    ->delete($this->db->quoteName('#__content'))
                    ->where($this->db->quoteName('id') . ' = ' . $articleId)
            )->execute();
            sleep(3);
        }
    }

    /**
     * Rename every J2Commerce 6 checkout override of step 4 in the site templates
     * (checkout/, checkout/bootstrap5/, checkout/uikit/).
     *
     * @return  array<string, string>  original path => temporary path
     */
    private function moveCheckoutOverridesAside(): array
    {
        $moved = [];
        $files = array_merge(
            glob(JPATH_SITE . '/templates/*/html/com_j2commerce/checkout/default_shipping_payment.php') ?: [],
            glob(JPATH_SITE . '/templates/*/html/com_j2commerce/checkout/bootstrap5/default_shipping_payment.php') ?: [],
            glob(JPATH_SITE . '/templates/*/html/com_j2commerce/checkout/uikit/default_shipping_payment.php') ?: []
        );

        foreach ($files as $file) {
            $aside = $file . '.consent-test-aside';

            if (@rename($file, $aside)) {
                $moved[$file] = $aside;
            }
        }

        return $moved;
    }

    /**
     * Open a guest site session and read its form token from the login form. Redirects are
     * followed (Joomla 6.1 strict routing redirects non-SEF GET URLs).
     *
     * @return  array{jar: string, token: string, session: string}|null
     */
    private function startSiteSession(): ?array
    {
        $jar  = tempnam(sys_get_temp_dir(), 'consent-cookies-');
        $body = $this->httpRequest($jar, 'GET', ['option' => 'com_users', 'view' => 'login']);

        if (!preg_match('/name="([a-f0-9]{32})"\s+value="1"/', $body, $match)) {
            $this->lastHttp .= ' | no form token in response';

            return null;
        }

        $session = '';

        foreach (file($jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line  = preg_replace('/^#HttpOnly_/', '', $line);
            $parts = explode("\t", $line);

            if (count($parts) === 7 && preg_match('/^[a-f0-9]{32}$/', $parts[5])) {
                $session = $parts[6];
            }
        }

        if ($session === '') {
            $this->lastHttp .= ' | no session cookie in cookie jar';

            return null;
        }

        return ['jar' => $jar, 'token' => $match[1], 'session' => $session];
    }

    private function createSessionCart(string $sessionId): int
    {
        $cart = (object) [
            'user_id'        => 0,
            'session_id'     => $sessionId,
            'cart_type'      => 'cart',
            'created_on'     => Factory::getDate()->toSql(),
            'modified_on'    => Factory::getDate()->toSql(),
            'customer_ip'    => '127.0.0.1',
            'cart_params'    => '{}',
            'cart_browser'   => '{}',
            'cart_analytics' => '{}',
        ];
        $this->db->insertObject('#__j2commerce_carts', $cart, 'j2commerce_cart_id');
        $id = (int) ($cart->j2commerce_cart_id ?? $this->db->insertid());
        $this->httpCarts[] = $id;

        return $id;
    }

    /** POST a checkout request of a site session (with its form token) as the checkout script does. */
    private function post(array $session, array $params): string
    {
        return $this->httpRequest($session['jar'], 'POST', $params + [$session['token'] => '1'], ['X-Requested-With: XMLHttpRequest']);
    }

    private function httpRequest(string $jar, string $method, array $params, array $headers = []): string
    {
        $url = self::SITE_URL . ($method === 'GET' ? '?' . http_build_query($params) : '');
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            // GET pages may be redirected (strict routing); checkout POSTs must not be followed.
            CURLOPT_FOLLOWLOCATION => $method === 'GET',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_USERAGENT      => 'ConsentLoggingHttpTest/1.0',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $body     = (string) curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $redirect = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $error    = curl_error($ch);
        unset($ch); // writes the cookie jar

        $this->lastHttp = sprintf(
            'HTTP %d %s %s%s%s | %s',
            $status,
            $method,
            $finalUrl,
            $redirect !== '' ? ' -> ' . $redirect : '',
            $error !== '' ? ' | curl: ' . $error : '',
            mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 160)
        );

        return $body;
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

        // "Show Export Data" / "Show Delete All Data": one button per enabled request type.
        $types = static function (string $html, string $kind): array {
            preg_match_all('/data-privacy-request="' . $kind . '" data-request-type="([a-z]+)"/', $html, $m);

            return $m[1];
        };
        $userData  = ['consented' => false, 'records' => [], 'isGuest' => false, 'showRequest' => true, 'requestUrl' => '/index.php?option=com_privacy&view=request', 'contactEmail' => 'privacy@example.invalid'];
        $guestData = ['isGuest' => true, 'requestUrl' => ''] + $userData;

        $this->test('Both options on: export and remove buttons (logged-in)', $types($userHtml, 'form') === ['export', 'remove']);
        $this->test('Both options on: export and remove mailto links (guest)', $types($guestHtml, 'mailto') === ['export', 'remove']);
        $this->test('Export only (logged-in)', $types($render($userData + ['showExport' => true, 'showDelete' => false]), 'form') === ['export']);
        $this->test('Delete only (logged-in)', $types($render($userData + ['showExport' => false, 'showDelete' => true]), 'form') === ['remove']);

        $guestExport = $render($guestData + ['showExport' => true, 'showDelete' => false]);
        $this->test('Export only (guest): mailto with the export subject',
            $types($guestExport, 'mailto') === ['export']
                && str_contains($guestExport, 'subject=' . rawurlencode(Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_EXPORT_TITLE'))));

        $guestDelete = $render($guestData + ['showExport' => false, 'showDelete' => true]);
        $this->test('Delete only (guest): mailto with the deletion subject',
            $types($guestDelete, 'mailto') === ['remove']
                && str_contains($guestDelete, 'subject=' . rawurlencode(Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_DELETE_TITLE'))));

        $none = $render($userData + ['showExport' => false, 'showDelete' => false]) . $render($guestData + ['showExport' => false, 'showDelete' => false]);
        $this->test('Both options off: no request section', !str_contains($none, 'data-privacy-request') && !str_contains($none, 'j2commerce-privacy-request'));
        $this->test('Both options off: consent status still shown', str_contains($none, 'data-consent-status="none"'));

        foreach (['com_j2commerce', 'com_j2store'] as $component) {
            $source = (string) @file_get_contents(JPATH_PLUGINS . '/privacy/j2commerce/overrides/' . $component . '/myprofile/default_privacy.php');
            $this->test(
                "[$component] override puts the template layout path before the plugin layout",
                str_contains($source, 'html/layouts/plg_privacy_j2commerce')
                    && strpos($source, 'html/layouts/plg_privacy_j2commerce') < strpos($source, "/privacy/j2commerce/layouts'")
            );
            $this->test("[$component] override passes the verified guest session as isGuest", str_contains($source, "'isGuest'        => \$_isGuest"));
            $this->test(
                "[$component] override passes the export/delete options to the layout",
                str_contains($source, "'showExport'     => (bool) \$_params->get('show_export_data', 1)")
                    && str_contains($source, "'showDelete'     => (bool) \$_params->get('show_delete_all', 1)")
            );
            $this->test("[$component] override returns early when the privacy section is off", str_contains($source, "get('show_privacy_section', 1)"));
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

        // J2Commerce 6 checkout overrides in the same template (installer checks of the update):
        // flat file = unchanged copy shipped by 1.5.5, bootstrap5 = custom override without the
        // event, uikit = custom override with the event call.
        $j6        = is_dir(JPATH_SITE . '/components/com_j2commerce');
        $checkout  = "$htmlBase/com_j2commerce/checkout";
        $legacy    = "$checkout/default_shipping_payment.php";
        $retired   = $legacy . self::RETIRED_SUFFIX;
        $noEvent   = "$checkout/bootstrap5/default_shipping_payment.php";
        $withEvent = "$checkout/uikit/default_shipping_payment.php";
        $fixture   = (string) @file_get_contents(__DIR__ . '/fixture-checkout-override-1.5.5.php');
        $relative  = static fn (string $file): string => substr($file, strlen(JPATH_SITE . '/templates/'));

        if ($j6) {
            foreach ([$legacy, $retired, $noEvent, $withEvent] as $file) {
                $restore[] = [$file, is_file($file) ? file_get_contents($file) : null];
                @mkdir(dirname($file), 0755, true);
            }

            $this->test('Fixture of the checkout override shipped by 1.5.5 available', $fixture !== '');
            file_put_contents($legacy, $fixture);
            @unlink($retired);
            file_put_contents($noEvent, "<?php\n// custom step 4 override without the consent event\n");
            file_put_contents($withEvent, "<?php\n// custom step 4 override\necho J2CommerceHelper::plugin()->eventWithHtml('AfterDisplayShippingPayment', [\$this->order]);\n");
        }

        $setEnabled(0);

        [$exitCode, $output, $readable] = $this->runCliUpdate($package);
        $this->test('CLI update of the package succeeds', $exitCode === 0, 'exit ' . $exitCode . ': ' . mb_substr($readable, -400));

        if ($j6) {
            // The console cuts long words (paths) at its line length: compare without any whitespace.
            // By reference: $output is replaced by the second update below.
            $has       = static function (string $needle) use (&$output): bool {
                return str_contains($output, preg_replace('/\s+/', '', $needle));
            };
            $version   = $this->getJ2CommerceVersion();
            $hasEvent  = $version !== '' && version_compare($version, '6.3.4', '>=');
            $outdated  = $this->messagePrefix('PLG_PRIVACY_J2COMMERCE_WARN_CHECKOUT_OVERRIDE_OUTDATED');
            $bundled   = $this->messagePrefix('PLG_PRIVACY_J2COMMERCE_WARN_CHECKOUT_OVERRIDE_BUNDLED');
            $diag      = mb_substr($readable, 0, 1500);

            clearstatcache();
            $this->test('Update warns about a custom checkout override without the event (bootstrap5)',
                $has($outdated) && $has($relative($noEvent)), $diag);
            // Meaningful only because the same warning is present in this output.
            $this->test('Update does not name a custom checkout override with the event (uikit)',
                $has($outdated) && $has($relative($noEvent)) && !$has($relative($withEvent)), $diag);
            $this->test('No untranslated installer message', $output !== '' && !$has('PLG_PRIVACY_J2COMMERCE_'), $diag);

            if ($hasEvent) {
                $this->test("Update disables the unchanged 1.5.5 checkout override (J2Commerce $version)",
                    !is_file($legacy) && is_file($retired) && file_get_contents($retired) === $fixture, $diag);
                $this->test('Update reports the disabled checkout override',
                    $has($this->messagePrefix('PLG_PRIVACY_J2COMMERCE_CHECKOUT_OVERRIDE_RETIRED')) && $has($relative($legacy)), $diag);
            } else {
                $this->test("Update keeps the 1.5.5 checkout override while J2Commerce ($version) lacks the event", is_file($legacy), $diag);
                $this->test('Update warns that J2Commerce is too old',
                    $has($this->messagePrefix('PLG_PRIVACY_J2COMMERCE_WARN_J2COMMERCE_TOO_OLD')), $diag);
            }

            // A changed copy of the shipped override may contain shop changes: kept, with a warning.
            $changed = $fixture . "\n<?php // changed by the shop\n";
            file_put_contents($legacy, $changed);
            @unlink($retired);
            file_put_contents($noEvent, "<?php\n// custom override\necho J2CommerceHelper::plugin()->eventWithHtml('AfterDisplayShippingPayment', [\$this->order]);\n");

            [$exitCode, $output, $readable] = $this->runCliUpdate($package);
            $diag = mb_substr($readable, 0, 1500);
            clearstatcache();
            $this->test('Second CLI update succeeds', $exitCode === 0, $diag);
            $this->test('Update keeps a changed copy of the shipped checkout override',
                is_file($legacy) && file_get_contents($legacy) === $changed && !is_file($retired), $diag);
            $this->test('Update warns about the changed copy of the shipped checkout override',
                $has($bundled) && $has($relative($legacy)), $diag);
            // Meaningful only because this output contains the installer warnings (bundled copy).
            $this->test('No outdated-override warning once every override fires the event',
                $has($bundled) && !$has($outdated), $diag);
        }

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

        // Remove folders created for the test (rmdir fails on non-empty folders).
        foreach (["$checkout/bootstrap5", "$checkout/uikit", $checkout] as $dir) {
            @rmdir($dir);
        }
    }

    /**
     * Reinstall the package through the Joomla CLI. The console prints the enqueued installer
     * messages. A wide terminal is requested, but Symfony's block output still wraps (and cuts long
     * words) at its maximum line length, so the output is returned without any whitespace for
     * comparisons, plus a readable copy for diagnostics.
     *
     * @return  array{0: int, 1: string, 2: string}
     */
    private function runCliUpdate(string $package): array
    {
        $command = sprintf(
            'COLUMNS=10000 HTTP_HOST=localhost %s %s extension:install --path=%s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg(JPATH_BASE . '/cli/joomla.php'),
            escapeshellarg($package)
        );
        $output = [];
        exec($command, $output, $exitCode);

        $readable = trim(preg_replace('/\s+/', ' ', implode(' ', $output)));

        return [$exitCode, preg_replace('/\s+/', '', $readable), $readable];
    }

    /** Longest literal part of a translated installer message (placeholders removed, whitespace collapsed). */
    private function messagePrefix(string $key): string
    {
        $parts = preg_split('/%(\d+\$)?s/', Text::_($key));
        usort($parts, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return trim(preg_replace('/\s+/', ' ', $parts[0]));
    }

    private function getJ2CommerceVersion(): string
    {
        $this->db->setQuery(
            $this->query()
                ->select($this->db->quoteName('manifest_cache'))
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_j2commerce'))
        );
        $cache = json_decode((string) $this->db->loadResult(), true);

        return is_array($cache) ? (string) ($cache['version'] ?? '') : '';
    }
}

$test = new ConsentLoggingTest();
exit($test->run() ? 0 : 1);
