<?php
/**
 * Data Isolation Tests
 *
 * Verifies that data-deletion operations are scoped to the target user only:
 * - deleteCartData() must not touch another user's carts/cartitems
 * - deleteUserAddress() must reject deleting another user's address (IDOR guard)
 * - checkRetentionPeriod() return structure is correct for both can_delete states
 * - guest privacy requests: export and removal by the request e-mail address (no account), an
 *   account with earlier guest orders, another customer's guest order with the same name stays
 *   untouched, case-insensitive matching, the confirmation of a request without login and the
 *   link in the administrator request mail
 *
 * Covers issues #97 and #105 (J6 branch coverage).
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

// Extension namespaces (com_privacy tables and events, plugin helpers) as the application would load them.
JLoader::register('JNamespacePsr4Map', JPATH_LIBRARIES . '/namespacemap.php');
(new JNamespacePsr4Map())->load();

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

foreach ([
    \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class        => JPATH_BASE . '/plugins/privacy/j2commerce/src/Extension/J2Commerce.php',
    \Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy::class => JPATH_BASE . '/plugins/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php',
] as $class => $file) {
    if (!class_exists($class) && is_file($file)) {
        require_once $file;
    }
}

if (class_exists(\Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class)) {
    /** Test double: no application feedback, no lifetime licenses, recorded confirmations. */
    class GuestRequestTestPlugin extends \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce
    {
        public array $confirmed = [];

        protected function feedbackApplication(): ?object
        {
            return null;
        }

        protected function lifetimeOrderIds(array $orderIds): array
        {
            return [];
        }

        /** Server-side attempt counter with a fixed test secret (no application in CLI). */
        protected function confirmAttempts(): ?\Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts
        {
            $file = JPATH_BASE . '/plugins/privacy/j2commerce/src/Security/ConfirmAttempts.php';

            if (!class_exists(\Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts::class) && is_file($file)) {
                require_once $file;
            }

            return new \Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts($this->getDatabase(), 'guest-request-test-secret');
        }

        protected function afterRequestConfirmed(object $request): void
        {
            $this->confirmed[] = (int) $request->id;
        }

        public function call(string $method, ...$args)
        {
            return $this->$method(...$args);
        }
    }
}

if (class_exists(\Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy::class)) {
    /** System plugin with fixed privacy plugin params. */
    class GuestMailTestSystemPlugin extends \Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy
    {
        public ?\Joomla\Registry\Registry $privacyParams = null;

        protected function getPrivacyParams(): ?\Joomla\Registry\Registry
        {
            return $this->privacyParams;
        }
    }
}

/** Mail template that renders its body text with the template data, as send() does. */
class GuestMailTestTemplate extends \Joomla\CMS\Mail\MailTemplate
{
    public function render(string $text): string
    {
        return $this->replaceTags($text, $this->data);
    }
}

/** Session stand-in with get() and set(). */
class GuestTestSession
{
    private array $data = [];

    public function get($name, $default = null)
    {
        return $this->data[$name] ?? $default;
    }

    public function set($name, $value)
    {
        $this->data[$name] = $value;
    }
}

class DataIsolationTest
{
    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private string $tp; // 'j2store' or 'j2commerce'

    public function __construct()
    {
        $this->db = Factory::getContainer()->get(DatabaseInterface::class);
        $this->tp = (getenv('J2COMMERCE_STACK') === 'j6') ? 'j2commerce' : 'j2store';
    }

    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery')
            ? $this->db->createQuery()
            : $this->db->getQuery(true);
    }

    private function test(string $name, bool $ok, string $msg = ''): void
    {
        if ($ok) {
            echo "PASS $name\n";
            $this->passed++;
        } else {
            echo "FAIL $name" . ($msg ? " - $msg" : '') . "\n";
            $this->failed++;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * deleteCartData() must only delete the target user's carts and cartitems,
     * leaving another user's data untouched.
     */
    private function testDeleteCartDataIsolation(): void
    {
        echo "\n--- deleteCartData() isolation ---\n";

        $cartPkCol     = $this->tp . '_cart_id';
        $cartitemPkCol = $this->tp . '_cartitem_id';
        $targetUser    = 9901;
        $bystander     = 9902;

        // Seed: one cart + item for target user, one cart + item for bystander
        $seedCart = function (int $userId) use ($cartPkCol, $cartitemPkCol): array {
            $cart = (object)[
                'user_id'    => $userId,
                'session_id' => 'iso-test-' . $userId . '-' . uniqid(),
                'cart_type'  => 'cart',
                'created_on' => date('Y-m-d H:i:s'),
                'modified_on' => date('Y-m-d H:i:s'),
                'customer_ip' => '127.0.0.1',
                'cart_params' => '{}',
                'cart_browser' => '{}',
                'cart_analytics' => '{}',
            ];
            $this->db->insertObject('#__' . $this->tp . '_carts', $cart, $cartPkCol);
            $cartId = (int) $this->db->insertid();

            $item = (object)[
                'cart_id'     => $cartId,
                'product_id'  => 1,
                'variant_id'  => 1,
                'vendor_id'   => 0,
                'product_type' => 'simple',
                'cartitem_params' => '{}',
                'product_options' => '[]',
                'product_qty' => 1.0,
            ];
            $this->db->insertObject('#__' . $this->tp . '_cartitems', $item, $cartitemPkCol);
            $itemId = (int) $this->db->insertid();

            return [$cartId, $itemId];
        };

        [$targetCartId, $targetItemId] = $seedCart($targetUser);
        [$bystanderCartId, $bystanderItemId] = $seedCart($bystander);

        $this->test('Seeded target cart', $targetCartId > 0);
        $this->test('Seeded bystander cart', $bystanderCartId > 0);

        // Load plugin and call deleteCartData() for target user only
        JLoader::registerNamespace(
            'Advans\\Plugin\\Privacy\\J2Commerce',
            JPATH_BASE . '/plugins/privacy/j2commerce/src',
            false, false, 'psr4'
        );

        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false, $pluginClass);
            // Cleanup
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_cartitems') . ' WHERE cart_id IN (' . $targetCartId . ',' . $bystanderCartId . ')')->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE user_id IN (' . $targetUser . ',' . $bystander . ')')->execute();
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        $method = $rc->getMethod('deleteCartData');
        $method->setAccessible(true);
        $method->invoke($instance, $targetUser);

        // Target user's data must be gone
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->db->quoteName('user_id') . ' = ' . $targetUser);
        $this->test(
            'Target user carts deleted',
            (int) $this->db->setQuery($q)->loadResult() === 0
        );

        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where($this->db->quoteName('cart_id') . ' = ' . $targetCartId);
        $this->test(
            'Target user cart items deleted',
            (int) $this->db->setQuery($q)->loadResult() === 0
        );

        // Bystander's data must be untouched
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_carts'))
            ->where($this->db->quoteName('user_id') . ' = ' . $bystander);
        $this->test(
            'Bystander carts untouched',
            (int) $this->db->setQuery($q)->loadResult() === 1,
            'deleteCartData() must not delete other users\' carts'
        );

        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_cartitems'))
            ->where($this->db->quoteName('cart_id') . ' = ' . $bystanderCartId);
        $this->test(
            'Bystander cart items untouched',
            (int) $this->db->setQuery($q)->loadResult() === 1,
            'deleteCartData() must not delete other users\' cart items'
        );

        // Cleanup bystander
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_cartitems') . ' WHERE cart_id = ' . $bystanderCartId)->execute();
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE ' . $this->tp . '_cart_id = ' . $bystanderCartId)->execute();
    }

    /**
     * deleteUserAddress() must reject deleting an address that belongs to a
     * different user (IDOR guard: atomic DELETE with user_id check).
     */
    private function testDeleteUserAddressIdorGuard(): void
    {
        echo "\n--- deleteUserAddress() IDOR guard ---\n";

        $addrPkCol = $this->tp . '_address_id';
        $owner     = 9903;
        $attacker  = 9904;

        // Insert an address owned by $owner
        $addr = (object)[
            'user_id'    => $owner,
            'first_name' => 'Owner',
            'last_name'  => 'User',
            'address_1'  => 'Private Street 1',
            'city'       => 'Zürich',
            'zip'        => '8001',
            'email'      => 'owner@example.com',
            'type'       => 'billing',
        ];
        $this->db->insertObject('#__' . $this->tp . '_addresses', $addr, $addrPkCol);
        $addrId = (int) $this->db->insertid();
        $this->test('Owner address seeded', $addrId > 0);

        // Load plugin
        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass)) {
            JLoader::registerNamespace(
                'Advans\\Plugin\\Privacy\\J2Commerce',
                JPATH_BASE . '/plugins/privacy/j2commerce/src',
                false, false, 'psr4'
            );
        }

        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false);
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $addrPkCol . ' = ' . $addrId)->execute();
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        $method = $rc->getMethod('deleteUserAddress');
        $method->setAccessible(true);

        // Attacker tries to delete owner's address
        $result = $method->invoke($instance, $addrId, $attacker);

        $this->test(
            'deleteUserAddress() rejects cross-user delete',
            isset($result['success']) && $result['success'] === false,
            'Expected success:false, got: ' . json_encode($result)
        );

        // Address must still exist after the rejected IDOR attempt
        $q = $this->createDbQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__' . $this->tp . '_addresses'))
            ->where($this->db->quoteName($addrPkCol) . ' = ' . $addrId);
        $this->test(
            'Address still exists after rejected IDOR attempt',
            (int) $this->db->setQuery($q)->loadResult() === 1
        );

        // Cleanup: delete the address directly (owner-delete path calls getApplication()
        // which requires a full Joomla app context not available in CLI test scripts)
        $this->db->setQuery(
            'DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') .
            ' WHERE ' . $this->db->quoteName($addrPkCol) . ' = ' . $addrId
        )->execute();
    }

    /**
     * checkRetentionPeriod() must return the correct structure and can_delete
     * value based on order age vs. retention window.
     */
    private function testCheckRetentionPeriod(): void
    {
        echo "\n--- checkRetentionPeriod() return structure ---\n";

        $pluginClass = 'Advans\\Plugin\\Privacy\\J2Commerce\\Extension\\J2Commerce';
        if (!class_exists($pluginClass)) {
            JLoader::registerNamespace(
                'Advans\\Plugin\\Privacy\\J2Commerce',
                JPATH_BASE . '/plugins/privacy/j2commerce/src',
                false, false, 'psr4'
            );
        }
        if (!class_exists($pluginClass, true)) {
            $this->test('Plugin class loadable', false);
            return;
        }

        $rc       = new ReflectionClass($pluginClass);
        $instance = $rc->newInstanceWithoutConstructor();
        $instance->setDatabase($this->db);

        // Inject a Registry params object with retention_years = 10
        $paramsProperty = $rc->getProperty('params');
        $paramsProperty->setAccessible(true);
        $params = new \Joomla\Registry\Registry(['retention_years' => 10]);
        $paramsProperty->setValue($instance, $params);

        $method = $rc->getMethod('checkRetentionPeriod');
        $method->setAccessible(true);

        // User 100 has fixture orders (set up by post-install-fixtures.sh):
        // one recent order (within 10 years) → can_delete = false
        $result = $method->invoke($instance, 100);

        $this->test(
            'checkRetentionPeriod() returns array',
            is_array($result)
        );
        $this->test(
            'Result has can_delete key',
            array_key_exists('can_delete', $result)
        );
        $this->test(
            'Result has retention_years key',
            array_key_exists('retention_years', $result),
            'Got keys: ' . implode(', ', array_keys($result))
        );
        $this->test(
            'Result has orders key',
            array_key_exists('orders', $result)
        );
        $this->test(
            'retention_years matches configured value',
            (int) ($result['retention_years'] ?? -1) === 10,
            'Got: ' . ($result['retention_years'] ?? 'missing')
        );

        // User 100 has a recent order: the removal request is carried out (can_delete = true), the
        // order is listed as kept until the end of its retention period.
        $this->test(
            'User with recent order: removal allowed, order listed as retained',
            $result['can_delete'] === true && count($result['orders'] ?? []) > 0,
            'can_delete=' . var_export($result['can_delete'], true) . ', retained orders=' . count($result['orders'] ?? [])
        );
        $first = $result['orders'][0] ?? [];
        $this->test(
            'Retained order ends on a fiscal year end (31.12.)',
            isset($first['retention_end']) && str_starts_with((string) $first['retention_end'], '31.12.'),
            'retention_end=' . ($first['retention_end'] ?? 'missing')
        );

        // User 9999 (no orders) → can_delete must be true
        $result2 = $method->invoke($instance, 9999);
        $this->test(
            'User with no orders: can_delete = true',
            $result2['can_delete'] === true,
            'Got: ' . var_export($result2['can_delete'], true)
        );
    }

    // -------------------------------------------------------------------------
    // Guest privacy requests
    // -------------------------------------------------------------------------

    /** Insert an order with its orderinfo; returns the order number. */
    private function insertGuestTestOrder(string $orderId, int $userId, string $email, string $createdOn, string $firstName): string
    {
        $isJ6   = $this->tp === 'j2commerce';
        $order  = (object) [
            'order_id'            => $orderId,
            'cart_id'             => 0,
            'invoice_prefix'      => 'INV-',
            'invoice_number'      => random_int(100000, 999999),
            'token'               => 'guest-token-' . $orderId,
            'user_id'             => $userId,
            'user_email'          => $email,
            'order_total'         => 20.00000,
            'order_subtotal'      => 20.00000,
            'order_tax'           => 0.00000,
            'order_shipping'      => 0.00000,
            'order_shipping_tax'  => 0.00000,
            'order_discount'      => 0.00000,
            'order_credit'        => 0.00000,
            'order_surcharge'     => 0.00000,
            'orderpayment_type'   => 'manual',
            'transaction_id'      => '',
            'transaction_status'  => 'confirmed',
            'transaction_details' => '',
            'currency_id'         => 1,
            'order_state_id'      => 1,
            'order_state'         => 'confirmed',
            'currency_code'       => 'CHF',
            'currency_value'      => 1.00000000,
            'customer_note'       => 'Note of ' . $firstName,
            'ip_address'          => '198.51.100.7',
            'is_shippable'        => 0,
            'is_including_tax'    => 1,
            'customer_language'   => '*',
            'customer_group'      => 'default',
            'created_on'          => $createdOn,
            'modified_on'         => $createdOn,
        ];
        $this->db->insertObject('#__' . $this->tp . '_orders', $order, $isJ6 ? 'j2commerce_order_id' : 'j2store_order_id');

        $info = (object) [
            'order_id'             => $orderId,
            'billing_first_name'   => $firstName,
            'billing_last_name'    => 'Muster',
            'billing_middle_name'  => '',
            'billing_address_1'    => 'Bahnhofstrasse 1',
            'billing_address_2'    => '',
            'billing_city'         => 'Zürich',
            'billing_zip'          => '8001',
            'billing_phone_1'      => '+41 44 000 00 00',
            'billing_phone_2'      => '',
            'billing_fax'          => '',
            'billing_company'      => '',
            'billing_tax_number'   => '',
            'shipping_first_name'  => $firstName,
            'shipping_last_name'   => 'Muster',
            'shipping_middle_name' => '',
            'shipping_address_1'   => 'Bahnhofstrasse 1',
            'shipping_address_2'   => '',
            'shipping_city'        => 'Zürich',
            'shipping_zip'         => '8001',
            'shipping_phone_1'     => '',
            'shipping_phone_2'     => '',
            'shipping_fax'         => '',
            'shipping_company'     => '',
            'shipping_tax_number'  => '',
            'all_billing'          => '',
            'all_shipping'         => '',
            'all_payment'          => '',
        ];
        $this->db->insertObject('#__' . $this->tp . '_orderinfos', $info, $isJ6 ? 'j2commerce_orderinfo_id' : 'j2store_orderinfo_id');

        return $orderId;
    }

    /** user_email, ip_address and billing first name of an order (null if missing). */
    private function loadGuestTestOrder(string $orderId): ?object
    {
        return $this->db->setQuery(
            'SELECT o.user_email, o.ip_address, o.customer_note, i.billing_first_name, i.billing_address_1'
            . ' FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders', 'o')
            . ' LEFT JOIN ' . $this->db->quoteName('#__' . $this->tp . '_orderinfos', 'i') . ' ON i.order_id = o.order_id'
            . ' WHERE o.order_id = ' . $this->db->quote($orderId)
        )->loadObject() ?: null;
    }

    /** Insert a checkout consent record of an order; returns its ID. */
    private function insertGuestTestConsent(string $orderId, string $ip, int $state = 1): int
    {
        $row = (object) [
            'user_id' => 0,
            'state'   => $state,
            'created' => gmdate('Y-m-d H:i:s', strtotime('-1 day')),
            'subject' => 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT',
            'body'    => "<p>Order $orderId</p><p>IP address: $ip</p><p>User agent: GuestTestAgent/1.0</p><!-- j2commerce-order:$orderId -->",
            'remind'  => 0,
            'token'   => '',
        ];
        $this->db->insertObject('#__privacy_consents', $row, 'id');

        return (int) $row->id;
    }

    /** Insert a legacy consent record (earlier template override) mentioning $email; returns its ID. */
    private function insertGuestTestLegacyConsent(string $email): int
    {
        $row = (object) [
            'user_id' => 0,
            'state'   => 1,
            'created' => gmdate('Y-m-d H:i:s', strtotime('-2 days')),
            'subject' => 'PLG_PRIVACY_J2COMMERCE',
            'body'    => 'Consent given by ' . $email . ' (IP 198.51.100.9)',
            'remind'  => 0,
            'token'   => '',
        ];
        $this->db->insertObject('#__privacy_consents', $row, 'id');

        return (int) $row->id;
    }

    /** com_privacy request record as the events carry it (only the e-mail address is read). */
    private function guestTestRequest(string $email): object
    {
        $class   = \Joomla\Component\Privacy\Administrator\Table\RequestTable::class;
        $request = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        $request->email        = $email;
        $request->request_type = 'remove';
        $request->status       = 1;

        return $request;
    }

    /**
     * Item IDs of a named domain of an export (domains of all data sets with the same base name).
     *
     * @return string[]
     */
    private function guestTestDomainItems(array $domains, string $name): array
    {
        $ids = [];

        foreach ($domains as $domain) {
            if ($domain->name === $name || str_ends_with((string) $domain->name, '_' . $name)) {
                foreach ($domain->getItems() as $item) {
                    $ids[] = (string) $item->id;
                }
            }
        }

        return $ids;
    }

    /** Values of a field over all items of the named domain. */
    private function guestTestDomainValues(array $domains, string $name, string $field): array
    {
        $values = [];

        foreach ($domains as $domain) {
            if ($domain->name !== $name) {
                continue;
            }

            foreach ($domain->getItems() as $item) {
                foreach ($item->getFields() as $itemField) {
                    if ($itemField->name === $field) {
                        $values[] = (string) $itemField->value;
                    }
                }
            }
        }

        return $values;
    }

    /**
     * Export and removal of a guest customer (no Joomla account) by the request e-mail address,
     * and of an account with earlier guest orders. Orders of other customers, also with the same
     * name or with the same address under another account, stay untouched.
     */
    private function testGuestRequests(): void
    {
        echo "\n--- Guest privacy requests: export and removal by e-mail address ---\n";

        if (!class_exists('GuestRequestTestPlugin')) {
            $this->test('Plugin class loadable for guest requests', false);
            return;
        }

        $suffix    = (string) time() . random_int(100, 999);
        $expired   = gmdate('Y-m-d H:i:s', strtotime('-12 years'));
        $recent    = gmdate('Y-m-d H:i:s', strtotime('-1 year'));
        $orders    = [];
        $consents  = [];
        $legacy    = [];
        $addresses = [];
        $cartId    = 0;

        // Guest customer: stored in mixed case, requested in lower case.
        $orders['guest_expired']   = $this->insertGuestTestOrder('GUEST-EXP-' . $suffix, 0, 'Guest.Buyer@Example.COM', $expired, 'Hans');
        $orders['guest_recent']    = $this->insertGuestTestOrder('GUEST-REC-' . $suffix, 0, 'guest.buyer@example.com', $recent, 'Hans');
        // Another guest with the same name but another address.
        $orders['other_guest']     = $this->insertGuestTestOrder('GUEST-OTH-' . $suffix, 0, 'other.buyer@example.com', $expired, 'Hans');
        // An account order that carries the guest's address: belongs to that account, not to the guest.
        $orders['other_account']   = $this->insertGuestTestOrder('GUEST-ACC-' . $suffix, 9911, 'guest.buyer@example.com', $expired, 'Hans');
        // Account 9912 with an earlier guest order under its address.
        $orders['account_guest']   = $this->insertGuestTestOrder('GUEST-AGO-' . $suffix, 0, 'Account.Holder@example.com', $expired, 'Anna');
        $orders['account_recent']  = $this->insertGuestTestOrder('GUEST-AOR-' . $suffix, 9912, 'account.holder@example.com', $recent, 'Anna');
        // Two orders with the same order number: an expired guest order and an order of account 9913.
        $orders['shared']          = $this->insertGuestTestOrder('GUEST-SHR-' . $suffix, 0, 'guest.buyer@example.com', $expired, 'Hans');
        try {
            $this->insertGuestTestOrder('GUEST-SHR-' . $suffix, 9913, 'shared.account@example.com', $expired, 'Hans');
            $sharedPossible = true;
        } catch (\Throwable $e) {
            // The schema enforces unique order numbers: the case cannot occur.
            $sharedPossible = false;
        }

        $consents['guest_expired'] = $this->insertGuestTestConsent($orders['guest_expired'], '203.0.113.60');
        $consents['guest_recent']  = $this->insertGuestTestConsent($orders['guest_recent'], '203.0.113.61');
        $consents['other_guest']   = $this->insertGuestTestConsent($orders['other_guest'], '203.0.113.62');
        // An invalidated record (state -1) of a guest order: still personal data of the guest.
        $consents['guest_invalid'] = $this->insertGuestTestConsent($orders['guest_recent'], '203.0.113.63', -1);
        $legacy['guest']           = $this->insertGuestTestLegacyConsent('guest.buyer@example.com');
        $legacy['other']           = $this->insertGuestTestLegacyConsent('other.buyer@example.com');
        // Contains the guest address only as part of a longer address: another customer.
        $legacy['similar']         = $this->insertGuestTestLegacyConsent('myguest.buyer@example.com');
        // Another address with an apostrophe, stored HTML-escaped: o'guest.buyer@example.com.
        $legacy['escaped']         = $this->insertGuestTestLegacyConsent('o&#039;guest.buyer@example.com');
        // Another address with a non-ASCII letter before the guest address.
        $legacy['unicode']         = $this->insertGuestTestLegacyConsent('éguest.buyer@example.com');
        // Other addresses with a symbol or punctuation prefix (valid address characters).
        $legacy['emoji']           = $this->insertGuestTestLegacyConsent('💡guest.buyer@example.com');
        $legacy['punctuation']     = $this->insertGuestTestLegacyConsent('!guest.buyer@example.com');
        // The guest address itself in markup and brackets, other letter case.
        $legacy['wrapped']         = $this->insertGuestTestLegacyConsent('(<strong>Guest.Buyer@Example.com</strong>).');

        foreach (['guest' => 'Guest.Buyer@example.com', 'other' => 'other.buyer@example.com'] as $key => $email) {
            $address = (object) [
                'user_id'    => 0,
                'first_name' => 'Hans',
                'last_name'  => 'Muster',
                'address_1'  => 'Bahnhofstrasse 1',
                'city'       => 'Zürich',
                'zip'        => '8001',
                'email'      => $email,
                'type'       => 'billing',
            ];
            $this->db->insertObject('#__' . $this->tp . '_addresses', $address, $this->tp . '_address_id');
            $addresses[$key] = (int) $this->db->insertid();
        }

        $cart = (object) [
            'user_id'        => 0,
            'session_id'     => 'guest-request-test-' . $suffix,
            'cart_type'      => 'cart',
            'created_on'     => gmdate('Y-m-d H:i:s'),
            'modified_on'    => gmdate('Y-m-d H:i:s'),
            'customer_ip'    => '127.0.0.1',
            'cart_params'    => '{}',
            'cart_browser'   => '{}',
            'cart_analytics' => '{}',
        ];
        $this->db->insertObject('#__' . $this->tp . '_carts', $cart, $this->tp . '_cart_id');
        $cartId = (int) $this->db->insertid();

        try {
            $plugin = new GuestRequestTestPlugin(['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry(['retention_years' => 10])]);
            $plugin->setDatabase($this->db);

            // --- Export of a guest customer through the com_privacy event (user = null) ---
            $event = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', [
                'subject' => $this->guestTestRequest('  GUEST.buyer@example.com '),
                'user'    => null,
            ]);
            $plugin->onPrivacyExportRequest($event);
            $domains = array_merge(...array_values(array_filter($event->getArgument('result', []) ?? [], 'is_array')));
            $orderIds = $this->guestTestDomainValues($domains, 'orders', 'order_id');
            $exported = array_map(fn ($id) => (string) $this->db->setQuery(
                'SELECT order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE ' . $this->tp . '_order_id = ' . (int) $id
            )->loadResult(), $orderIds);
            sort($exported);
            $expected = [$orders['guest_expired'], $orders['guest_recent'], $orders['shared']];
            sort($expected);

            $this->test('Guest export (no account): both guest orders of the address, matched case-insensitively', $exported === $expected, json_encode($exported));
            if ($sharedPossible) {
                // The guest's order shares its number with an order of account 9913: billing names
                // and items are joined only by that number and are not exported.
                $sharedPk = (string) $this->db->setQuery(
                    'SELECT ' . $this->tp . '_order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders')
                    . ' WHERE order_id = ' . $this->db->quote($orders['shared']) . ' AND user_id = 0'
                )->loadResult();
                $sharedFields = [];

                foreach ($domains as $domain) {
                    foreach ($domain->name === 'orders' ? $domain->getItems() : [] as $item) {
                        if ((string) $item->id === $sharedPk) {
                            foreach ($item->getFields() as $field) {
                                $sharedFields[$field->name] = (string) $field->value;
                            }
                        }
                    }
                }

                $this->test('Guest export: an order number shared with another account exports no joined billing or shipping data',
                    ($sharedFields['billing_first_name'] ?? null) === '' && ($sharedFields['user_email'] ?? '') === 'guest.buyer@example.com'
                    && !isset($sharedFields['billing_address_1']) && !isset($sharedFields['shipping_city']),
                    json_encode($sharedFields));
            }

            // Complete order info (billing and shipping data) of the guest's own order.
            $expiredPk     = (string) $this->db->setQuery(
                'SELECT ' . $this->tp . '_order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders')
                . ' WHERE order_id = ' . $this->db->quote($orders['guest_expired'])
            )->loadResult();
            $expiredFields = [];

            foreach ($domains as $domain) {
                foreach ($domain->name === 'orders' ? $domain->getItems() : [] as $item) {
                    if ((string) $item->id === $expiredPk) {
                        foreach ($item->getFields() as $field) {
                            $expiredFields[$field->name] = (string) $field->value;
                        }
                    }
                }
            }

            $this->test('Guest export: billing address, phone and all shipping fields of the order info are exported',
                ($expiredFields['billing_address_1'] ?? '') === 'Bahnhofstrasse 1' && ($expiredFields['billing_phone_1'] ?? '') === '+41 44 000 00 00'
                && ($expiredFields['billing_zip'] ?? '') === '8001' && ($expiredFields['shipping_first_name'] ?? '') === 'Hans'
                && ($expiredFields['shipping_address_1'] ?? '') === 'Bahnhofstrasse 1' && ($expiredFields['shipping_city'] ?? '') === 'Zürich'
                && !isset($expiredFields[$this->tp . '_orderinfo_id']) && ($expiredFields['order_id'] ?? '') === $expiredPk,
                json_encode($expiredFields, JSON_UNESCAPED_UNICODE));

            $this->test('Guest export: no order of another guest with the same name or of an account with the same address',
                !in_array($orders['other_guest'], $exported, true) && !in_array($orders['other_account'], $exported, true));
            $this->test('Guest export: the guest address is exported, the other guest address is not',
                in_array((string) $addresses['guest'], $this->guestTestDomainItems($domains, 'addresses'), true)
                && !in_array((string) $addresses['other'], $this->guestTestDomainItems($domains, 'addresses'), true));
            $consentItems = $this->guestTestDomainItems($domains, 'checkout_consents');

            if ($this->tp !== 'j2commerce') {
                // Consents are recorded for J2Commerce 6 orders only; a J2Store order number could
                // be another customer's J2Commerce 6 order, so J2Store orders export no consent.
                $this->test('Guest export (J2Store): no checkout consents looked up through J2Store order numbers', $consentItems === [], json_encode($consentItems));
            } else {
                $this->test('Guest export: checkout consents of the guest orders in every state (incl. IP address) are exported, not those of others',
                    in_array((string) $consents['guest_expired'], $consentItems, true) && in_array((string) $consents['guest_recent'], $consentItems, true)
                    && in_array((string) $consents['guest_invalid'], $consentItems, true)
                    && in_array('-1', $this->guestTestDomainValues($domains, 'checkout_consents', 'state'), true)
                    && !in_array((string) $consents['other_guest'], $consentItems, true)
                    && in_array(true, array_map(fn ($v) => str_contains($v, '203.0.113.60'), $this->guestTestDomainValues($domains, 'checkout_consents', 'consent')), true),
                    json_encode($consentItems));
            }

            $legacyItems = $this->guestTestDomainItems($domains, 'legacy_consents');
            sort($legacyItems);
            $legacyWanted = [(string) $legacy['guest'], (string) $legacy['wrapped']];
            sort($legacyWanted);
            $this->test('Guest export: legacy consent records naming the guest address are exported, records of other addresses are not',
                $legacyItems === $legacyWanted, json_encode($legacyItems));

            $this->test('Guest export: no Joomla account data domains without an account',
                $this->guestTestDomainItems($domains, 'joomla_user') === [] && !in_array('joomla_user', array_map(fn ($d) => $d->name, $domains), true));

            $empty = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', [
                'subject' => $this->guestTestRequest('not-an-address'),
                'user'    => null,
            ]);
            $plugin->onPrivacyExportRequest($empty);
            $this->test('Export without account and without a valid address adds nothing', ($empty->getArgument('result', []) ?? []) === []);

            // --- Retention list of the guest ---
            $check    = $plugin->call('checkRetentionPeriod', 0, ['guest.buyer@example.com']);
            $retained = array_column($check['orders'], 'order_number');
            $this->test('Guest retention check lists the recent guest order only', $retained === [$orders['guest_recent']], json_encode($retained));
            $this->test('No account and no address: nothing is matched', $plugin->call('checkRetentionPeriod', 0, [])['orders'] === []);

            // --- Removal of the guest through the com_privacy event (user = null) ---
            $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest('GUEST.BUYER@example.com'),
                'user'    => null,
            ]));

            $guestExpired = $this->loadGuestTestOrder($orders['guest_expired']);
            $this->test('Guest removal: the expired guest order is anonymized (e-mail, IP, note, billing data)',
                $guestExpired && $guestExpired->user_email === 'anonymized@deleted.invalid' && $guestExpired->ip_address === ''
                && $guestExpired->customer_note === '' && $guestExpired->billing_first_name === 'Anonymized' && $guestExpired->billing_address_1 === '',
                json_encode($guestExpired));
            $guestRecent = $this->loadGuestTestOrder($orders['guest_recent']);
            $this->test('Guest removal: the guest order within the retention period is kept intact',
                $guestRecent && $guestRecent->user_email === 'guest.buyer@example.com' && $guestRecent->billing_first_name === 'Hans', json_encode($guestRecent));

            foreach (['other_guest' => 'other guest with the same name', 'other_account' => 'account order with the same address'] as $key => $label) {
                $row = $this->loadGuestTestOrder($orders[$key]);
                $this->test("Guest removal: $label untouched",
                    $row && $row->user_email !== 'anonymized@deleted.invalid' && $row->ip_address === '198.51.100.7' && $row->billing_first_name === 'Hans', json_encode($row));
            }

            $consentBody = fn (int $id): string => (string) $this->db->setQuery('SELECT body FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id = ' . $id)->loadResult();
            if ($this->tp === 'j2commerce') {
                $this->test('Guest removal: consent of the anonymized guest order loses the IP address', !str_contains($consentBody($consents['guest_expired']), '203.0.113.60'));
            } else {
                $this->test('Guest removal (J2Store): consent records are J2Commerce 6 records and stay unchanged', str_contains($consentBody($consents['guest_expired']), '203.0.113.60'));
            }

            // An order number that also belongs to another account's order: the guest's order row
            // is anonymized by primary key, the other account's row and the shared order info stay.
            $sharedNo = 'GUEST-SHR-' . $suffix;

            if (!$sharedPossible) {
                $this->test('Shared order number: order numbers are unique in this schema', true);
            } else {
                $this->db->setQuery(
                    'SELECT ' . $this->tp . '_order_id AS pk, user_id, user_email, ip_address FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders')
                    . ' WHERE order_id = ' . $this->db->quote($sharedNo) . ' ORDER BY user_id'
                );
                $sharedRows = $this->db->loadObjectList();
                $sharedInfo = $this->db->setQuery(
                    'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__' . $this->tp . '_orderinfos')
                    . ' WHERE order_id = ' . $this->db->quote($sharedNo) . ' AND billing_first_name = ' . $this->db->quote('Hans')
                )->loadResult();
                $this->test('Shared order number: the guest order row is anonymized',
                    count($sharedRows) === 2 && (int) $sharedRows[0]->user_id === 0 && $sharedRows[0]->user_email === 'anonymized@deleted.invalid' && $sharedRows[0]->ip_address === '',
                    json_encode($sharedRows));
                $this->test('Shared order number: the order of the other account is untouched',
                    count($sharedRows) === 2 && (int) $sharedRows[1]->user_id === 9913 && $sharedRows[1]->user_email === 'shared.account@example.com' && $sharedRows[1]->ip_address === '198.51.100.7',
                    json_encode($sharedRows));
                $this->test('Shared order number: the order info rows are left alone', (int) $sharedInfo === 2, (string) $sharedInfo);
            }

            $this->test('Guest removal: consent of the retained guest order unchanged', str_contains($consentBody($consents['guest_recent']), '203.0.113.61'));
            $this->test('Guest removal: consent of the other guest unchanged', str_contains($consentBody($consents['other_guest']), '203.0.113.62'));

            $legacySubject = fn (int $id): string => (string) $this->db->setQuery('SELECT subject FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id = ' . $id)->loadResult();
            $this->test('Guest removal: legacy consent record of the guest address anonymized', $legacySubject($legacy['guest']) === 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY');
            $this->test('Guest removal: legacy consent record of another guest untouched', $legacySubject($legacy['other']) === 'PLG_PRIVACY_J2COMMERCE');
            $this->test('Guest removal: legacy record of a longer address that contains the guest address untouched', $legacySubject($legacy['similar']) === 'PLG_PRIVACY_J2COMMERCE');
            $this->test('Guest removal: legacy record of an HTML-escaped address with an apostrophe before the guest address untouched', $legacySubject($legacy['escaped']) === 'PLG_PRIVACY_J2COMMERCE');
            $this->test('Guest removal: legacy record of an address with a non-ASCII letter before the guest address untouched', $legacySubject($legacy['unicode']) === 'PLG_PRIVACY_J2COMMERCE');
            $this->test('Guest removal: legacy records of addresses with a symbol or punctuation prefix untouched',
                $legacySubject($legacy['emoji']) === 'PLG_PRIVACY_J2COMMERCE' && $legacySubject($legacy['punctuation']) === 'PLG_PRIVACY_J2COMMERCE');
            $this->test('Guest removal: legacy record with the guest address in markup, brackets and other letter case anonymized',
                $legacySubject($legacy['wrapped']) === 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY');

            // An address with an apostrophe stored raw and with two different entities: the
            // preselection must not depend on the representation of the local part.
            $legacy['apos_raw']     = $this->insertGuestTestLegacyConsent("o'brien.guest@example.com");
            $legacy['apos_html5']   = $this->insertGuestTestLegacyConsent('O&apos;Brien.Guest@Example.com');
            $legacy['apos_numeric'] = $this->insertGuestTestLegacyConsent('o&#039;brien.guest@example.com');
            $repository = new \Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository($this->db);
            $found      = array_map('intval', array_column($repository->findLegacyGuestConsents(["o'brien.guest@example.com"]), 'id'));
            sort($found);
            $wantedIds  = [$legacy['apos_raw'], $legacy['apos_html5'], $legacy['apos_numeric']];
            sort($wantedIds);
            $this->test('Legacy export: an apostrophe address is found raw, as &apos; and as &#039;', $found === $wantedIds, json_encode($found));

            // A domain written with entities in an otherwise plain body.
            $legacy['encoded_domain'] = $this->insertGuestTestLegacyConsent('encoded.guest@ex&#97;mple.com');
            $encodedFound             = array_map('intval', array_column($repository->findLegacyGuestConsents(['encoded.guest@example.com']), 'id'));
            $this->test('Legacy export: an address with an entity-encoded domain is found', $encodedFound === [$legacy['encoded_domain']], json_encode($encodedFound));
            $this->test('Legacy removal: the same three records are anonymized',
                $repository->anonymizeLegacyConsents(0, ["O'Brien.Guest@example.com"]) === 3
                && $legacySubject($legacy['apos_raw']) === 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY'
                && $legacySubject($legacy['apos_html5']) === 'PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY');

            $addressCount = fn (int $id): int => (int) $this->db->setQuery(
                'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $this->tp . '_address_id = ' . $id
            )->loadResult();
            $this->test('Guest removal: the guest address is deleted', $addressCount($addresses['guest']) === 0);
            $this->test('Guest removal: the address of the other guest is kept', $addressCount($addresses['other']) === 1);
            $this->test('Guest removal: guest carts (user_id 0) are not touched',
                (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE ' . $this->tp . '_cart_id = ' . $cartId)->loadResult() === 1);

            // --- Account with an earlier guest order ---
            $account           = new \Joomla\CMS\User\User();
            $account->id       = 9912;
            $account->username = 'guest-request-account-9912';
            $account->email    = 'account.holder@example.com';

            $accountDomains = $plugin->call('collectExportDomains', $account, 'account.holder@example.com');
            $accountOrders  = array_map(fn ($id) => (string) $this->db->setQuery(
                'SELECT order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE ' . $this->tp . '_order_id = ' . (int) $id
            )->loadResult(), $this->guestTestDomainValues($accountDomains, 'orders', 'order_id'));
            sort($accountOrders);
            $expectedAccount = [$orders['account_guest'], $orders['account_recent']];
            sort($expectedAccount);
            $this->test('Account export: the account order and the earlier guest order under the account address', $accountOrders === $expectedAccount, json_encode($accountOrders));

            $plugin->onPrivacyCanRemoveData(null, $account);
            $plugin->onPrivacyRemoveData(null, $account);

            $accountGuest = $this->loadGuestTestOrder($orders['account_guest']);
            $this->test('Account removal: the earlier expired guest order is anonymized',
                $accountGuest && $accountGuest->user_email === 'anonymized@deleted.invalid' && $accountGuest->billing_first_name === 'Anonymized', json_encode($accountGuest));
            $accountRecent = $this->loadGuestTestOrder($orders['account_recent']);
            $this->test('Account removal: the recent account order is kept', $accountRecent && $accountRecent->billing_first_name === 'Anna', json_encode($accountRecent));
            $other = $this->loadGuestTestOrder($orders['other_account']);
            $this->test('Account removal: the order of account 9911 stays untouched', $other && $other->billing_first_name === 'Hans' && $other->user_email === 'guest.buyer@example.com');

            // Migrated site: the report covers the orders of every data set, each order number once.
            $multi = new class (['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry([])]) extends GuestRequestTestPlugin {
                public array $sets = [];
                private int $calls = 0;

                protected function forEachDataSet(callable $callback): array
                {
                    return [$callback(false), $callback(true)];
                }

                protected function checkRetentionPeriod(int $userId, array $emails = []): array
                {
                    return $this->sets[$this->calls++];
                }
            };
            $order       = fn (string $number): array => ['order_number' => $number, 'order_date' => '01.01.2026', 'retention_end' => '31.12.2036', 'lifetime' => false];
            $multi->sets = [
                ['orders' => [$order('SET6-A'), $order('BOTH-C')], 'lifetime_expired' => [['order_number' => 'CROSS-X', 'order_date' => '01.01.2010']]],
                ['orders' => [$order('BOTH-C'), $order('SET4-B'), $order('CROSS-X')], 'lifetime_expired' => [['order_number' => 'SET4-L', 'order_date' => '01.01.2010']]],
            ];
            $merged = $multi->call('retentionOverDataSets', 0, ['guest.buyer@example.com']);
            $this->test('Retention report over all data sets: orders of both sets, a copied order once, also across the kept and lifetime lists (kept wins)',
                array_column($merged['orders'], 'order_number') === ['SET6-A', 'BOTH-C', 'SET4-B', 'CROSS-X']
                && array_column($merged['lifetime_expired'], 'order_number') === ['SET4-L'] && $merged['can_delete'] === true,
                json_encode($merged));

            // Customer language: newest order, looked up before the anonymization replaces the
            // e-mail the guest orders are found by (an older lifetime order keeps it).
            $orders['lang_new'] = $this->insertGuestTestOrder('GUEST-LGN-' . $suffix, 0, 'lang.guest@example.com', gmdate('Y-m-d H:i:s', strtotime('-11 years')), 'Lena');
            $orders['lang_old'] = $this->insertGuestTestOrder('GUEST-LGO-' . $suffix, 0, 'lang.guest@example.com', gmdate('Y-m-d H:i:s', strtotime('-13 years')), 'Lena');

            foreach (['lang_new' => 'fr-FR', 'lang_old' => 'de-DE'] as $key => $tag) {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' SET customer_language = ' . $this->db->quote($tag)
                    . ' WHERE order_id = ' . $this->db->quote($orders[$key])
                )->execute();
            }

            $langPlugin = new class (['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry(['retention_years' => 10])]) extends GuestRequestTestPlugin {
                public array $lifetime = [];
                public array $reported = [];

                protected function lifetimeOrderIds(array $orderIds): array
                {
                    return array_values(array_intersect(array_map('strval', $orderIds), $this->lifetime));
                }

                protected function reportRetainedOrders(array $retained, array $lifetime, string $customerEmail, string $languageTag): void
                {
                    $this->reported[] = [$languageTag, array_column($lifetime, 'order_number')];
                }
            };
            $langPlugin->setDatabase($this->db);
            $langPlugin->lifetime = [$orders['lang_old']];
            $langPlugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest('lang.guest@example.com'),
                'user'    => null,
            ]));
            $this->test('Customer e-mail language: the newest order (looked up before the anonymization), not the older lifetime order',
                ($langPlugin->reported[0][0] ?? '') === 'fr-FR' && ($langPlugin->reported[0][1] ?? []) === [$orders['lang_old']],
                json_encode($langPlugin->reported));

            // Customer e-mail text names only what the request removed.
            $guestFlag = new ReflectionProperty(\Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class, 'guestRequest');
            $guestFlag->setAccessible(true);
            $keys = [];

            foreach ([[true, 1], [true, 0], [false, 1], [false, 0]] as [$guest, $deleteAddresses]) {
                $mailPlugin = new GuestRequestTestPlugin(['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry(['delete_addresses' => $deleteAddresses])]);
                $guestFlag->setValue($mailPlugin, $guest);
                $keys[] = $mailPlugin->call('customerNoticeBodyKey');
            }

            $this->test('Customer e-mail text follows the request type and the option Delete Addresses', $keys === [
                'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_GUEST',
                'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_GUEST_ADDRESSES_KEPT',
                'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY',
                'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_ADDRESSES_KEPT',
            ], json_encode($keys));

            foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
                $ini    = JPATH_BASE . '/plugins/privacy/j2commerce/language/' . $tag . '/plg_privacy_j2commerce.ini';
                $values = is_file($ini) ? (parse_ini_file($ini, false, INI_SCANNER_RAW) ?: []) : [];
                $kept   = [(string) ($values['PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_GUEST_ADDRESSES_KEPT'] ?? ''), (string) ($values['PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_ADDRESSES_KEPT'] ?? '')];
                $this->test("$tag: texts for kept addresses exist and do not mention the saved addresses as deleted",
                    $kept[0] !== '' && $kept[1] !== ''
                    && !preg_match('/(saved addresses|gespeicherten Adressen|adresses enregistrées)[^.]*(were deleted|wurden gelöscht|ont été supprimées)/iu', implode(' ', $kept)));
            }

            // Matching rules
            $this->test('requestEmails(): trimmed, lower case, placeholder and invalid values dropped, no duplicates',
                $plugin::requestEmails([' A@Example.com ', 'a@example.com', 'anonymized@deleted.invalid', 'no-address', '', '(a@example.com', 'a@example.com.', 'a@localhost']) === ['a@example.com']);
        } catch (\Throwable $e) {
            $this->test('Guest request tests ran without error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $orderList = implode(',', array_map([$this->db, 'quote'], array_values($orders)));

            if ($orderList !== '') {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orderinfos') . ' WHERE order_id IN (' . $orderList . ')')->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE order_id IN (' . $orderList . ')')->execute();
            }

            $consentList = implode(',', array_map('intval', array_merge(array_values($consents), array_values($legacy))));

            if ($consentList !== '') {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id IN (' . $consentList . ')')->execute();
            }

            foreach ($addresses as $id) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $this->tp . '_address_id = ' . (int) $id)->execute();
            }

            if ($cartId > 0) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_carts') . ' WHERE ' . $this->tp . '_cart_id = ' . $cartId)->execute();
            }
        }
    }

    /**
     * Confirmation of a privacy request without login: valid token, wrong, used and expired
     * token, a request that is not pending, another address.
     */
    private function testRequestConfirmation(): void
    {
        echo "\n--- Confirmation of privacy requests without login ---\n";

        if (!class_exists('GuestRequestTestPlugin')) {
            $this->test('Plugin class loadable for request confirmation', false);
            return;
        }

        $ids    = [];
        $insert = function (string $email, int $status, string $token, string $createdAt) use (&$ids): int {
            $row = (object) [
                'email'                    => $email,
                'requested_at'             => gmdate('Y-m-d H:i:s'),
                'status'                   => $status,
                'request_type'             => 'remove',
                'confirm_token'            => $token === '' ? '' : \Joomla\CMS\User\UserHelper::hashPassword($token),
                'confirm_token_created_at' => $createdAt,
            ];
            $this->db->insertObject('#__privacy_requests', $row, 'id');
            $ids[] = (int) $row->id;

            return (int) $row->id;
        };
        $status = fn (int $id): object => $this->db->setQuery(
            'SELECT status, confirm_token FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . $id
        )->loadObject();

        $now     = gmdate('Y-m-d H:i:s');
        $token   = md5('guest-confirm-' . random_int(1, PHP_INT_MAX));
        $other   = md5('guest-other-' . random_int(1, PHP_INT_MAX));
        $old     = md5('guest-old-' . random_int(1, PHP_INT_MAX));
        $done    = md5('guest-done-' . random_int(1, PHP_INT_MAX));

        try {
            $plugin = new GuestRequestTestPlugin(['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry([])]);
            $plugin->setDatabase($this->db);

            $pending  = $insert('Confirm.Guest@example.com', 0, $token, $now);
            $wrongTok = $insert('wrong.token@example.com', 0, $other, $now);
            $expired  = $insert('expired.guest@example.com', 0, $old, gmdate('Y-m-d H:i:s', strtotime('-2 days')));
            $expired2 = $insert('expired.other@example.com', 0, $old, gmdate('Y-m-d H:i:s', strtotime('-2 days')));
            $notOpen  = $insert('done.guest@example.com', 1, $done, $now);

            $this->test('Wrong token: refused', $plugin->confirmPrivacyRequest('wrong.token@example.com', md5('nope')) === 'failed');
            $this->test('Wrong token: request stays pending', (int) $status($wrongTok)->status === 0);
            $this->test('Valid token of another address: refused', $plugin->confirmPrivacyRequest('wrong.token@example.com', $token) === 'failed');
            $this->test('Unfilled placeholder {TOKEN}: refused', $plugin->confirmPrivacyRequest('confirm.guest@example.com', '{TOKEN}') === 'failed');
            $this->test('Request that is not pending (status 1): refused', $plugin->confirmPrivacyRequest('done.guest@example.com', $done) === 'failed');
            $this->test('No confirmation side effects for refused attempts', $plugin->confirmed === []);

            $this->test('Valid token, address in other case: confirmed', $plugin->confirmPrivacyRequest(' confirm.GUEST@example.com', $token) === 'confirmed');
            $row = $status($pending);
            $this->test('Confirmed request has status 1 and no token left', (int) $row->status === 1 && (string) $row->confirm_token === '', json_encode($row));
            $this->test('Confirmation notifies like com_privacy (super users, action log)', $plugin->confirmed === [$pending], json_encode($plugin->confirmed));
            $this->test('Used token: refused the second time', $plugin->confirmPrivacyRequest('confirm.guest@example.com', $token) === 'failed');

            $this->test('Expired token with a wrong value: refused, request untouched',
                $plugin->confirmPrivacyRequest('expired.other@example.com', md5('nope')) === 'failed' && (int) $status($expired2)->status === 0);
            $this->test('Expired token: reported as expired', $plugin->confirmPrivacyRequest('expired.guest@example.com', $old) === 'expired');
            $this->test('Expired token: request invalidated (status -1) as in com_privacy', (int) $status($expired)->status === -1);

            $class   = \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class;
            $session = new GuestTestSession();
            $start   = 1_700_000_000;

            for ($i = 0; $i < $class::CONFIRM_MAX_FAILURES - 1; $i++) {
                $class::registerConfirmFailure($session, $start + $i);
            }

            $this->test('Attempt limit: not reached below the maximum', !$class::confirmAttemptsExceeded($session, $start + 10));
            $class::registerConfirmFailure($session, $start + 10);
            $this->test('Attempt limit: reached at the maximum within the window', $class::confirmAttemptsExceeded($session, $start + 20));
            $this->test('Attempt limit: released after the window', !$class::confirmAttemptsExceeded($session, $start + $class::CONFIRM_ATTEMPT_WINDOW + 20));
            $this->test('cleanConfirmToken() keeps a Joomla token and drops placeholders',
                $class::cleanConfirmToken($token) === $token && $class::cleanConfirmToken('{TOKEN}') === '' && $class::cleanConfirmToken('abc') === '');
        } catch (\Throwable $e) {
            $this->test('Request confirmation tests ran without error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            if ($ids !== []) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->execute();
            }
        }
    }

    /**
     * The mail com_privacy sends for a request an administrator created links to the
     * confirmation page of the privacy plugin, including the token.
     */
    private function testAdminRequestMailLink(): void
    {
        echo "\n--- Administrator request mail links to the confirmation page ---\n";

        if (!class_exists('GuestMailTestSystemPlugin')) {
            $this->test('System plugin class loadable for the mail link', false);
            return;
        }

        $privacy = \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class;
        $system  = \Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy::class;
        $this->test('Confirmation route is the same in both plugins', $privacy::CONFIRM_ROUTE === $system::GUEST_CONFIRM_ROUTE);

        $token = md5('mail-token');
        $body  = "1. Visit the following URL: {TOKENURL}\n2. URL: {FORMURL}\nToken: {TOKEN}";

        // Template data in the order of com_privacy's RequestModel::notifyUserAdminCreatedRequest().
        $makeTemplate = function (string $templateId) use ($token): GuestMailTestTemplate {
            $mailer   = (new ReflectionClass(\Joomla\CMS\Mail\Mail::class))->newInstanceWithoutConstructor();
            $template = new GuestMailTestTemplate($templateId, 'en-GB', $mailer);
            $template->addTemplateData([
                'sitename' => 'Test Shop',
                'url'      => 'http://localhost/',
                'tokenurl' => 'http://localhost/index.php?option=com_privacy&view=confirm&confirm_token=' . $token,
                'formurl'  => 'http://localhost/index.php?option=com_privacy&view=confirm',
                'token'    => $token,
            ]);

            return $template;
        };

        try {
            $plugin = new GuestMailTestSystemPlugin(['name' => 'j2commerceprivacy', 'type' => 'system', 'params' => new \Joomla\Registry\Registry([])]);
            $plugin->privacyParams = new \Joomla\Registry\Registry([]);

            foreach (['com_privacy.notification.admin.export', 'com_privacy.notification.admin.remove'] as $templateId) {
                $template = $makeTemplate($templateId);
                $plugin->onMailBeforeRendering(new \Joomla\CMS\Event\Mail\BeforeRenderingMailTemplateEvent('onMailBeforeRendering', [
                    'templateId' => $templateId,
                    'subject'    => $template,
                ]));
                $text = $template->render($body);
                $this->test("$templateId: the token link opens the confirmation page with the token",
                    str_contains($text, $system::GUEST_CONFIRM_ROUTE . '&confirm_token=' . $token . "\n"), $text);
                $this->test("$templateId: the form link opens the confirmation page",
                    str_contains($text, 'URL: ' . rtrim(\Joomla\CMS\Uri\Uri::root(), '/') . '/' . $system::GUEST_CONFIRM_ROUTE . "\n"), $text);
                $this->test("$templateId: no com_privacy confirm link and no unfilled tag left",
                    !str_contains($text, 'view=confirm') && !str_contains($text, '{TOKEN}'), $text);
            }

            $template = $makeTemplate('com_privacy.notification.export');
            $plugin->onMailBeforeRendering(new \Joomla\CMS\Event\Mail\BeforeRenderingMailTemplateEvent('onMailBeforeRendering', [
                'templateId' => 'com_privacy.notification.export',
                'subject'    => $template,
            ]));
            $this->test('Mail of a request the user created himself: unchanged', str_contains($template->render($body), 'view=confirm&confirm_token=' . $token));

            $plugin->privacyParams = new \Joomla\Registry\Registry(['guest_confirmation' => 0]);
            $template              = $makeTemplate('com_privacy.notification.admin.remove');
            $plugin->onMailBeforeRendering(new \Joomla\CMS\Event\Mail\BeforeRenderingMailTemplateEvent('onMailBeforeRendering', [
                'templateId' => 'com_privacy.notification.admin.remove',
                'subject'    => $template,
            ]));
            $this->test('Guest Request Confirmation off: the mail keeps the com_privacy links', str_contains($template->render($body), 'view=confirm&confirm_token=' . $token));

            $plugin->privacyParams = null;
            $template              = $makeTemplate('com_privacy.notification.admin.remove');
            $plugin->onMailBeforeRendering(new \Joomla\CMS\Event\Mail\BeforeRenderingMailTemplateEvent('onMailBeforeRendering', [
                'templateId' => 'com_privacy.notification.admin.remove',
                'subject'    => $template,
            ]));
            $this->test('Privacy plugin disabled: the mail keeps the com_privacy links', str_contains($template->render($body), 'view=confirm&confirm_token=' . $token));
        } catch (\Throwable $e) {
            $this->test('Mail link tests ran without error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /** Last HTTP exchange of the confirmation page tests (for failure messages). */
    private string $lastHttp = '';

    /**
     * Request against the test site. $query goes into the URL, $post into the body (POST).
     */
    private function guestHttp(string $jar, string $method, array $query, array $post = []): string
    {
        $url = 'http://localhost/index.php?' . http_build_query($query);
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_FOLLOWLOCATION => $method === 'GET',
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_USERAGENT      => 'GuestConfirmHttpTest/1.0',
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        unset($ch); // writes the cookie jar

        $this->lastHttp = sprintf('HTTP %d %s %s | %s', $status, $method, $final, mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($body))), 0, 200));

        return $body;
    }

    /** Joomla form token of the confirmation form in $body ('' if none). */
    private function guestFormToken(string $body): string
    {
        return preg_match('/<form[^>]*>.*?name="([a-f0-9]{32})"\s+value="1"/s', $body, $m) ? $m[1] : '';
    }

    /**
     * The confirmation page through the real com_ajax endpoint as a guest: GET form, POST without
     * form token, wrong code, valid code, attempt limit, option off, and the event result of the
     * JSON address task.
     */
    private function testConfirmationPageHttp(): void
    {
        echo "\n--- Confirmation page over HTTP (com_ajax, guest session) ---\n";

        // test() returns nothing: check the condition itself.
        $this->test('PHP curl extension available for HTTP tests', function_exists('curl_init'));

        if (!function_exists('curl_init')) {
            return;
        }

        // Counters of earlier suites or runs must not decide here (the table check is in testConfirmAttemptLimits()).
        try {
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->execute();
        } catch (\Throwable $e) {
            // Reported by testConfirmAttemptLimits().
        }

        $class = \Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce::class;
        parse_str((string) parse_url($class::CONFIRM_ROUTE, PHP_URL_QUERY), $route);

        $email   = 'http.confirm.' . random_int(1000, 9999) . '@example.com';
        $token   = md5('http-confirm-' . random_int(1, PHP_INT_MAX));
        $request = (object) [
            'email'                    => $email,
            'requested_at'             => gmdate('Y-m-d H:i:s'),
            'status'                   => 0,
            'request_type'             => 'export',
            'confirm_token'            => \Joomla\CMS\User\UserHelper::hashPassword($token),
            'confirm_token_created_at' => gmdate('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__privacy_requests', $request, 'id');
        $requestId = (int) $request->id;
        $status    = fn (): int => (int) $this->db->setQuery('SELECT status FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . $requestId)->loadResult();
        $jars      = [];
        $params    = null;
        $pluginId  = 0;

        try {
            // GET as a guest: the form, the code prefilled from the link, a form token.
            $jars[] = $jar = tempnam(sys_get_temp_dir(), 'guest-confirm-');
            $page   = $this->guestHttp($jar, 'GET', $route + ['confirm_token' => $token]);
            $form   = $this->guestFormToken($page);
            $this->test('GET as guest: confirmation form with e-mail field, prefilled code and form token',
                str_contains($page, 'j2commerce-privacy-confirm') && str_contains($page, 'name="email"')
                && str_contains($page, 'value="' . $token . '"') && $form !== '', $this->lastHttp);
            $this->test('GET as guest: no redirect to the login page', !str_contains($page, 'com_users') || str_contains($page, 'j2commerce-privacy-confirm'), $this->lastHttp);

            // POST from a new session (no earlier GET, e.g. after the session expired): the page
            // answers itself with the form, no redirect to the home page.
            $jars[] = $fresh = tempnam(sys_get_temp_dir(), 'guest-confirm-');
            $answer = $this->guestHttp($fresh, 'POST', $route, ['email' => $email, 'confirm_token' => $token, md5('stale-token') => '1']);
            $this->test('POST from a new session: confirmation form with an error, no redirect, request still pending',
                str_contains($answer, 'j2commerce-privacy-confirm') && str_contains($answer, 'alert-danger') && str_contains($answer, 'name="email"') && $status() === 0,
                $this->lastHttp);

            // POST without form token: refused, nothing changes.
            $answer = $this->guestHttp($jar, 'POST', $route, ['email' => $email, 'confirm_token' => $token]);
            $this->test('POST without form token: refused, request still pending', str_contains($answer, 'alert-danger') && $status() === 0, $this->lastHttp);

            // POST with form token and a wrong code: generic failure, nothing changes.
            $answer = $this->guestHttp($jar, 'POST', $route, ['email' => $email, 'confirm_token' => md5('wrong'), $form => '1']);
            $this->test('POST with a wrong code: generic failure, request still pending',
                str_contains($answer, 'alert-danger') && str_contains($answer, 'name="email"') && $status() === 0, $this->lastHttp);

            // POST with form token and the valid code (address in other letter case): confirmed.
            $answer = $this->guestHttp($jar, 'POST', $route, ['email' => strtoupper($email), 'confirm_token' => $token, $form => '1']);
            $this->test('POST with the valid code: confirmed (status 1), success message', str_contains($answer, 'alert-success') && $status() === 1, $this->lastHttp);

            // Attempt limit in a new session.
            $jars[] = $jar2 = tempnam(sys_get_temp_dir(), 'guest-confirm-');
            $form2  = $this->guestFormToken($this->guestHttp($jar2, 'GET', $route));

            for ($i = 0; $i < $class::CONFIRM_MAX_FAILURES; $i++) {
                $this->guestHttp($jar2, 'POST', $route, ['email' => $email, 'confirm_token' => md5('wrong' . $i), $form2 => '1']);
            }

            $answer = $this->guestHttp($jar2, 'POST', $route, ['email' => $email, 'confirm_token' => md5('wrong-last'), $form2 => '1']);
            $this->test('Attempt limit: after the maximum of failed attempts the page answers with the same failure form', str_contains($answer, 'alert-danger') && str_contains($answer, 'name="email"'), $this->lastHttp);

            // Per address across sessions over HTTP: every failed attempt in a new session, then the
            // right code in yet another session is refused with the same failure form.
            $limitEmail = 'http.limit.' . random_int(1000, 9999) . '@example.com';
            $limitToken = md5('http-limit-' . random_int(1, PHP_INT_MAX));
            $limitRow   = (object) [
                'email' => $limitEmail, 'requested_at' => gmdate('Y-m-d H:i:s'), 'status' => 0, 'request_type' => 'export',
                'confirm_token' => \Joomla\CMS\User\UserHelper::hashPassword($limitToken), 'confirm_token_created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__privacy_requests', $limitRow, 'id');
            $limitStatus = fn (): int => (int) $this->db->setQuery('SELECT status FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . (int) $limitRow->id)->loadResult();

            try {
                $limitClass = \Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts::class;

                for ($i = 0; $i < $limitClass::MAX_EMAIL_FAILURES; $i++) {
                    $jars[]  = $sessionJar = tempnam(sys_get_temp_dir(), 'guest-confirm-');
                    $sessionForm = $this->guestFormToken($this->guestHttp($sessionJar, 'GET', $route));
                    $this->guestHttp($sessionJar, 'POST', $route, ['email' => $limitEmail, 'confirm_token' => md5('http-wrong' . $i), $sessionForm => '1']);
                }

                $jars[]      = $sessionJar = tempnam(sys_get_temp_dir(), 'guest-confirm-');
                $sessionForm = $this->guestFormToken($this->guestHttp($sessionJar, 'GET', $route));
                $answer      = $this->guestHttp($sessionJar, 'POST', $route, ['email' => $limitEmail, 'confirm_token' => $limitToken, $sessionForm => '1']);
                $this->test('HTTP, fresh sessions: after the maximum of failures for an address its right code is refused with the same failure form',
                    str_contains($answer, 'alert-danger') && str_contains($answer, 'name="email"') && $limitStatus() === 0, $this->lastHttp);
            } finally {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . (int) $limitRow->id)->execute();
            }

            // JSON address task as a guest: the plugin answer reaches the response through the event.
            $json = json_decode($this->guestHttp($jar, 'GET', ['option' => 'com_ajax', 'plugin' => 'j2commercePrivacy', 'group' => 'privacy', 'format' => 'json', 'task' => 'deleteAddress', 'address_id' => 1]), true);
            $this->test('JSON address task: the plugin answer is in data (refused without form token and login)',
                is_array($json) && isset($json['data'][0]['success']) && $json['data'][0]['success'] === false, $this->lastHttp);

            // Option "Guest Request Confirmation" off: the page is not available.
            $pluginId = (int) $this->db->setQuery(
                'SELECT extension_id FROM ' . $this->db->quoteName('#__extensions') . " WHERE type = 'plugin' AND folder = 'privacy' AND element = 'j2commerce'"
            )->loadResult();
            $params = (string) $this->db->setQuery('SELECT params FROM ' . $this->db->quoteName('#__extensions') . ' WHERE extension_id = ' . $pluginId)->loadResult();
            $off    = json_decode($params ?: '{}', true) ?: [];
            $off['guest_confirmation'] = 0;
            $this->db->setQuery(
                'UPDATE ' . $this->db->quoteName('#__extensions') . ' SET params = ' . $this->db->quote(json_encode($off)) . ' WHERE extension_id = ' . $pluginId
            )->execute();
            $this->clearSiteCache();

            $answer = $this->guestHttp($jar, 'GET', $route + ['confirm_token' => $token]);
            $this->test('Option off: the page answers that it is not available, no form', str_contains($answer, 'alert-warning') && !str_contains($answer, 'name="email"'), $this->lastHttp);
        } catch (\Throwable $e) {
            $this->test('Confirmation page HTTP tests ran without error', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            if ($pluginId > 0 && $params !== null) {
                $this->db->setQuery(
                    'UPDATE ' . $this->db->quoteName('#__extensions') . ' SET params = ' . $this->db->quote($params) . ' WHERE extension_id = ' . $pluginId
                )->execute();
                $this->clearSiteCache();
            }

            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . $requestId)->execute();
            // Super user notification and action log entry of the confirmation.
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__messages') . ' WHERE ' . $this->db->quoteName('message') . ' LIKE ' . $this->db->quote('%' . $email . '%'))->execute();
            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__action_logs') . ' WHERE ' . $this->db->quoteName('message') . ' LIKE ' . $this->db->quote('%' . $email . '%'))->execute();

            foreach ($jars as $file) {
                @unlink($file);
            }
        }
    }

    /** Remove Joomla's file cache so the site reads changed plugin params. */
    private function clearSiteCache(): void
    {
        foreach (['/cache', '/administrator/cache'] as $dir) {
            foreach (glob(JPATH_BASE . $dir . '/*', GLOB_ONLYDIR) ?: [] as $group) {
                foreach (glob($group . '/*') ?: [] as $file) {
                    @unlink($file);
                }
            }
        }
    }

    /**
     * Addresses that the column collation (utf8mb4_unicode_ci / general_ci) treats as equal but
     * that belong to different people: muller/müller, strasse/straße, resume/résumé. The request
     * of A must never reach the data of B (export, retention, removal, consents, confirmation),
     * while the same address in other letter case still matches.
     */
    private function testCollationPairs(): void
    {
        echo "\n--- Addresses equal only under the column collation (accents, sharp s) ---\n";

        if (!class_exists('GuestRequestTestPlugin')) {
            $this->test('Plugin class loadable for the collation pairs', false);
            return;
        }

        $suffix   = (string) time() . random_int(100, 999);
        $expired  = gmdate('Y-m-d H:i:s', strtotime('-12 years'));
        $recent   = gmdate('Y-m-d H:i:s', strtotime('-1 year'));
        $pairs    = [
            'muller'  => ['durchtest-muller-' . $suffix . '@example.com', 'durchtest-müller-' . $suffix . '@example.com'],
            'strasse' => ['strasse-' . $suffix . '@example.com', "stra\u{00DF}e-" . $suffix . '@example.com'],
            'resume'  => ['resume-' . $suffix . '@example.com', 'résumé-' . $suffix . '@example.com'],
        ];
        $orders    = [];
        $legacy    = [];
        $consents  = [];
        $addresses = [];
        $requests  = [];
        $logIds    = [];

        // Upper case only where it cannot change letters (mb_strtoupper('\u{00DF}') is 'SS').
        $upperDomain = static fn (string $email): string => str_replace('@example.com', '@EXAMPLE.COM', $email);

        try {
            foreach ($pairs as $name => [$a, $b]) {
                $orders[$name . '_a_recent']  = $this->insertGuestTestOrder('COLL-' . strtoupper($name) . '-AR-' . $suffix, 0, $a, $recent, 'Anna');
                $orders[$name . '_b_recent']  = $this->insertGuestTestOrder('COLL-' . strtoupper($name) . '-BR-' . $suffix, 0, $b, $recent, 'Berta');
                $orders[$name . '_b_expired'] = $this->insertGuestTestOrder('COLL-' . strtoupper($name) . '-BE-' . $suffix, 0, $b, $expired, 'Berta');
                $consents[$name]              = $this->insertGuestTestConsent($orders[$name . '_b_recent'], 'evidence-sentinel-b');
                $legacy[$name]                = $this->insertGuestTestLegacyConsent($b);

                $address = (object) [
                    'user_id' => 0, 'first_name' => 'Berta', 'last_name' => 'Muster', 'address_1' => 'Seeweg 2',
                    'city' => 'Bern', 'zip' => '3000', 'email' => $b, 'type' => 'billing',
                ];
                $this->db->insertObject('#__' . $this->tp . '_addresses', $address, $this->tp . '_address_id');
                $addresses[$name] = (int) $this->db->insertid();
            }

            // Same address, other letter case: must still match.
            $caseAddress            = 'Case.Kunde-' . $suffix . '@Example.COM';
            $orders['case_expired'] = $this->insertGuestTestOrder('COLL-CASE-E-' . $suffix, 0, $caseAddress, $expired, 'Cora');
            // Non-ASCII upper case stored, lower case requested: still the same address.
            $umlautAddress            = 'ÜBER.KUNDE-' . $suffix . '@EXAMPLE.COM';
            $orders['umlaut_expired'] = $this->insertGuestTestOrder('COLL-UML-E-' . $suffix, 0, $umlautAddress, $expired, 'Ulla');
            // A case pair newer than the database case tables (Georgian Mtavruli, Unicode 11).
            $georgianAddress            = "\u{1C90}-georgian-" . $suffix . '@example.com';
            $orders['georgian_expired'] = $this->insertGuestTestOrder('COLL-GEO-E-' . $suffix, 0, $georgianAddress, $expired, 'Gela');
            // Imported with tabs around it: PHP trim() removes them, SQL TRIM() does not.
            $orders['tab_expired'] = $this->insertGuestTestOrder('COLL-TAB-E-' . $suffix, 0, "\tTab.Kunde-" . $suffix . "@example.com\t", $expired, 'Tina');

            $plugin = new GuestRequestTestPlugin(['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry(['activity_logging' => 1])]);
            $plugin->setDatabase($this->db);
            $numberOf = fn (string $pk): string => (string) $this->db->setQuery(
                'SELECT order_id FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE ' . $this->tp . '_order_id = ' . (int) $pk
            )->loadResult();

            foreach ($pairs as $name => [$a, $b]) {
                // Export of A.
                $event = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $this->guestTestRequest($a), 'user' => null]);
                $plugin->onPrivacyExportRequest($event);
                $domains  = array_merge(...array_values(array_filter($event->getArgument('result', []) ?? [], 'is_array')));
                $exported = array_map($numberOf, $this->guestTestDomainValues($domains, 'orders', 'order_id'));
                $this->test("[$name] export of A: only A's order, none of B",
                    $exported === [$orders[$name . '_a_recent']], json_encode($exported));
                $this->test("[$name] export of A: B's address, legacy record and checkout consent are not exported",
                    !in_array((string) $addresses[$name], $this->guestTestDomainItems($domains, 'addresses'), true)
                    && !in_array((string) $legacy[$name], $this->guestTestDomainItems($domains, 'legacy_consents'), true)
                    && !in_array((string) $consents[$name], $this->guestTestDomainItems($domains, 'checkout_consents'), true));

                // Retention of A.
                $retained = array_column($plugin->call('checkRetentionPeriod', 0, [$a])['orders'], 'order_number');
                $this->test("[$name] retention of A: only A's recent order", $retained === [$orders[$name . '_a_recent']], json_encode($retained));

                // Removal of A (request ID named in the User Actions Log).
                $request     = $this->guestTestRequest($a);
                $request->id = 990000 + count($logIds);
                $logIds[]    = $request->id;
                $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', ['subject' => $request, 'user' => null]));

                $bExpired = $this->loadGuestTestOrder($orders[$name . '_b_expired']);
                $this->test("[$name] removal of A: B's expired order is not anonymized",
                    $bExpired && $bExpired->user_email === $b && $bExpired->billing_first_name === 'Berta', json_encode($bExpired, JSON_UNESCAPED_UNICODE));
                $addressLeft = (int) $this->db->setQuery(
                    'SELECT COUNT(*) FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $this->tp . '_address_id = ' . $addresses[$name]
                )->loadResult();
                $this->test("[$name] removal of A: B's address is kept", $addressLeft === 1);
                $legacySubject = (string) $this->db->setQuery('SELECT subject FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id = ' . $legacy[$name])->loadResult();
                $this->test("[$name] removal of A: B's legacy consent record untouched", $legacySubject === 'PLG_PRIVACY_J2COMMERCE');
                $consentBody = (string) $this->db->setQuery('SELECT body FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id = ' . $consents[$name])->loadResult();
                $this->test("[$name] removal of A: B's checkout consent keeps its evidence", str_contains($consentBody, 'evidence-sentinel-b'));

                // Confirmation: B's code with A's address is refused.
                $token = md5('collation-' . $name . random_int(1, PHP_INT_MAX));
                $row   = (object) [
                    'email' => $b, 'requested_at' => gmdate('Y-m-d H:i:s'), 'status' => 0, 'request_type' => 'export',
                    'confirm_token' => \Joomla\CMS\User\UserHelper::hashPassword($token), 'confirm_token_created_at' => gmdate('Y-m-d H:i:s'),
                ];
                $this->db->insertObject('#__privacy_requests', $row, 'id');
                $requests[] = (int) $row->id;
                $state      = fn (): int => (int) $this->db->setQuery('SELECT status FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . (int) $row->id)->loadResult();
                $this->test("[$name] confirmation with A's address and B's code is refused", $plugin->confirmPrivacyRequest($a, $token) === 'failed' && $state() === 0);
                $this->test("[$name] confirmation with B's address in upper case and B's code is accepted",
                    $plugin->confirmPrivacyRequest($upperDomain($b), $token) === 'confirmed' && $state() === 1);
            }

            // Same address in other letter case still matches.
            $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest(strtolower($caseAddress)),
                'user'    => null,
            ]));
            $caseOrder = $this->loadGuestTestOrder($orders['case_expired']);
            $this->test('Other letter case of the same address: the expired guest order is anonymized',
                $caseOrder && $caseOrder->user_email === 'anonymized@deleted.invalid', json_encode($caseOrder));

            // A request without the umlaut does not reach the order, the request with it does.
            $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest('uber.kunde-' . $suffix . '@example.com'),
                'user'    => null,
            ]));
            $umlautOrder = $this->loadGuestTestOrder($orders['umlaut_expired']);
            $this->test('Stored ÜBER.KUNDE@: a request for uber.kunde@ does not reach it',
                $umlautOrder && $umlautOrder->user_email === $umlautAddress, json_encode($umlautOrder, JSON_UNESCAPED_UNICODE));
            $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest('über.kunde-' . $suffix . '@example.com'),
                'user'    => null,
            ]));
            $umlautOrder = $this->loadGuestTestOrder($orders['umlaut_expired']);
            $this->test('Stored ÜBER.KUNDE@ (non-ASCII upper case): the request for über.kunde@ anonymizes it',
                $umlautOrder && $umlautOrder->user_email === 'anonymized@deleted.invalid', json_encode($umlautOrder, JSON_UNESCAPED_UNICODE));

            $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                'subject' => $this->guestTestRequest('tab.kunde-' . $suffix . '@example.com'),
                'user'    => null,
            ]));
            $tabOrder = $this->loadGuestTestOrder($orders['tab_expired']);
            $this->test('Stored address with tabs around it: the request without them anonymizes it',
                $tabOrder && $tabOrder->user_email === 'anonymized@deleted.invalid', json_encode($tabOrder));

            if (mb_strtolower("\u{1C90}", 'UTF-8') === "\u{10D0}") {
                $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', [
                    'subject' => $this->guestTestRequest("\u{10D0}-georgian-" . $suffix . '@example.com'),
                    'user'    => null,
                ]));
                $georgianOrder = $this->loadGuestTestOrder($orders['georgian_expired']);
                $this->test('Stored Georgian upper case U+1C90 (Unicode 11 case pair): the lower-case request anonymizes it',
                    $georgianOrder && $georgianOrder->user_email === 'anonymized@deleted.invalid', json_encode($georgianOrder, JSON_UNESCAPED_UNICODE));
            } else {
                $this->test('PHP knows the Unicode 11 case pair U+1C90/U+10D0', false, 'mbstring Unicode data too old');
            }

            // Action log: language key with the request ID and counts, no English text.
            $entry = $this->db->setQuery(
                'SELECT message_language_key, message FROM ' . $this->db->quoteName('#__action_logs')
                . ' WHERE ' . $this->db->quoteName('extension') . ' = ' . $this->db->quote('plg_privacy_j2commerce')
                . ' AND ' . $this->db->quoteName('message') . ' LIKE ' . $this->db->quote('%"requestid":"' . $logIds[0] . '"%')
                . ' AND ' . $this->db->quoteName('message_language_key') . ' = ' . $this->db->quote('PLG_PRIVACY_J2COMMERCE_LOG_DATA_DELETION_REQUESTED')
            )->loadObject();
            $message = $entry ? (json_decode((string) $entry->message, true) ?: []) : [];
            $this->test('Action log of the removal: language key with request ID and counts, no English details',
                ($message['requestid'] ?? '') === (string) $logIds[0] && ($message['retained'] ?? '') === '1' && ($message['details'] ?? 'x') === '',
                json_encode($message));

            foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
                $ini    = JPATH_BASE . '/plugins/privacy/j2commerce/language/' . $tag . '/plg_privacy_j2commerce.ini';
                $values = is_file($ini) ? (parse_ini_file($ini, false, INI_SCANNER_RAW) ?: []) : [];
                $this->test("$tag: the removal log text names the request, kept and lifetime orders",
                    str_contains((string) ($values['PLG_PRIVACY_J2COMMERCE_LOG_DATA_DELETION_REQUESTED'] ?? ''), '{requestid}')
                    && str_contains((string) ($values['PLG_PRIVACY_J2COMMERCE_LOG_DATA_DELETION_REQUESTED'] ?? ''), '{retained}')
                    && str_contains((string) ($values['PLG_PRIVACY_J2COMMERCE_LOG_DATA_DELETION_REQUESTED'] ?? ''), '{lifetime}'));
            }

            // Joomla finds accounts with the column collation: an account of B for a request of A
            // must neither be removed nor exported by this plugin.
            [$a, $b]        = $pairs['muller'];
            $account        = new \Joomla\CMS\User\User();
            $account->id    = 9931;
            $account->email = $b;
            $orders['account_b'] = $this->insertGuestTestOrder('COLL-ACC-' . $suffix, 9931, $b, $recent, 'Berta');

            $status = $plugin->call('checkCanRemoveData', $account, $a);
            $this->test('Account of B found for a removal request of A: the request is refused with a reason',
                $status->canRemove === false && str_contains((string) $status->reason, $b));
            $this->test('Account of B for a removal request of B in other letter case: allowed',
                $plugin->call('checkCanRemoveData', $account, $upperDomain($b))->canRemove === true);

            $event = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $this->guestTestRequest($a), 'user' => $account]);
            $plugin->onPrivacyExportRequest($event);
            $domains  = array_merge(...array_values(array_filter($event->getArgument('result', []) ?? [], 'is_array')));
            $exported = array_map($numberOf, $this->guestTestDomainValues($domains, 'orders', 'order_id'));
            $this->test('Account of B found for an export request of A: neither B\'s account orders nor account data are exported',
                !in_array($orders['account_b'], $exported, true) && !in_array('joomla_user', array_map(fn ($d) => $d->name, $domains), true),
                json_encode($exported));
        } catch (\Throwable $e) {
            $this->test('Collation pair tests ran without error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            $orderList = implode(',', array_map([$this->db, 'quote'], array_values($orders)));

            if ($orderList !== '') {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orderinfos') . ' WHERE order_id IN (' . $orderList . ')')->execute();
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_orders') . ' WHERE order_id IN (' . $orderList . ')')->execute();
            }

            $consentList = implode(',', array_map('intval', array_merge(array_values($consents), array_values($legacy))));

            if ($consentList !== '') {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_consents') . ' WHERE id IN (' . $consentList . ')')->execute();
            }

            foreach ($addresses as $id) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__' . $this->tp . '_addresses') . ' WHERE ' . $this->tp . '_address_id = ' . (int) $id)->execute();
            }

            if ($requests !== []) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id IN (' . implode(',', $requests) . ')')->execute();
            }

            foreach (array_merge($logIds, ['-']) as $id) {
                $this->db->setQuery(
                    'DELETE FROM ' . $this->db->quoteName('#__action_logs') . ' WHERE ' . $this->db->quoteName('extension') . ' = ' . $this->db->quote('plg_privacy_j2commerce')
                    . ' AND ' . $this->db->quoteName('message') . ' LIKE ' . $this->db->quote('%"requestid":"' . $id . '"%')
                )->execute();
            }
        }
    }

    /**
     * Attempt limits of the confirmation page stored server-side: per e-mail address across fresh
     * sessions, per client IP address, the window ends, and a used-up limit of one address does
     * not block the right code of another. Only hashes are stored.
     */
    private function testConfirmAttemptLimits(): void
    {
        echo "\n--- Confirmation attempt limits across sessions (per address, per IP) ---\n";

        $class = \Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts::class;
        $table = $this->db->getPrefix() . 'privacy_j2commerce_attempts';
        $this->test('Attempt table exists after installation', in_array($table, $this->db->getTableList(), true));

        if (!class_exists('GuestRequestTestPlugin') || !in_array($table, $this->db->getTableList(), true)) {
            return;
        }

        $plugin = new GuestRequestTestPlugin(['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry([])]);
        $plugin->setDatabase($this->db);
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->execute();

        $suffix   = (string) random_int(10000, 99999);
        $ids      = [];
        $insert   = function (string $email, string $token) use (&$ids): int {
            $row = (object) [
                'email' => $email, 'requested_at' => gmdate('Y-m-d H:i:s'), 'status' => 0, 'request_type' => 'export',
                'confirm_token' => \Joomla\CMS\User\UserHelper::hashPassword($token), 'confirm_token_created_at' => gmdate('Y-m-d H:i:s'),
            ];
            $this->db->insertObject('#__privacy_requests', $row, 'id');
            $ids[] = (int) $row->id;

            return (int) $row->id;
        };
        $status = fn (int $id): int => (int) $this->db->setQuery('SELECT status FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id = ' . $id)->loadResult();
        $fresh  = fn (): GuestTestSession => new GuestTestSession();
        $t0     = time();

        try {
            $x = 'limit-x-' . $suffix . '@example.com';
            $y = 'limit-y-' . $suffix . '@example.com';
            $z = 'limit-z-' . $suffix . '@example.com';
            $tx = md5('limit-x' . $suffix);
            $ty = md5('limit-y' . $suffix);
            $tz = md5('limit-z' . $suffix);
            $idX = $insert($x, $tx);
            $idY = $insert($y, $ty);
            $idZ = $insert($z, $tz);

            // Per address: every attempt in a fresh session (the session limit never applies).
            for ($i = 1; $i <= $class::MAX_EMAIL_FAILURES; $i++) {
                $plugin->attemptConfirmation($x, md5('wrong' . $i), $fresh(), 'client-a', $t0 + $i);
            }

            $this->test('Per address: after the maximum of failures in fresh sessions the right code is refused (other IP too)',
                $plugin->attemptConfirmation($x, $tx, $fresh(), 'client-b', $t0 + 10) === 'failed' && $status($idX) === 0);

            // The limit of one address does not block another address from the same client.
            $this->test('Another address with its right code from the same client is confirmed',
                $plugin->attemptConfirmation($y, $ty, $fresh(), 'client-a', $t0 + 11) === 'confirmed' && $status($idY) === 1);

            // Per IP: failures for many addresses from one client.
            for ($i = 1; $i <= $class::MAX_IP_FAILURES; $i++) {
                $plugin->attemptConfirmation('flood-' . $i . '-' . $suffix . '@example.com', md5('flood' . $i), $fresh(), 'client-c', $t0 + 20);
            }

            $this->test('Per IP: after the maximum of failures from one client its right code for a third address is refused',
                $plugin->attemptConfirmation($z, $tz, $fresh(), 'client-c', $t0 + 21) === 'failed' && $status($idZ) === 0);
            // Once the IP limit is used up, new addresses from that client create no rows.
            $rowCount = fn (): int => (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->loadResult();
            $before   = $rowCount();

            for ($i = 1; $i <= 5; $i++) {
                $plugin->attemptConfirmation('after-limit-' . $i . '-' . $suffix . '@example.com', md5('after' . $i), $fresh(), 'client-c', $t0 + 21);
            }

            $this->test('Per IP: after the limit, attempts with new addresses from that client add no rows', $rowCount() === $before, $before . ' -> ' . $rowCount());
            $this->test('Per IP: the same address and code from another client is confirmed',
                $plugin->attemptConfirmation($z, $tz, $fresh(), 'client-d', $t0 + 22) === 'confirmed' && $status($idZ) === 1);

            // The window ends: the blocked address gets through with its right code again.
            $this->test('After the window the right code of the blocked address is confirmed',
                $plugin->attemptConfirmation($x, $tx, $fresh(), 'client-b', $t0 + $class::WINDOW + 30) === 'confirmed' && $status($idX) === 1);

            // Without the server-side counter the page confirms nothing (fail closed).
            $w       = 'limit-w-' . $suffix . '@example.com';
            $tw      = md5('limit-w' . $suffix);
            $idW     = $insert($w, $tw);
            $noLimit = new class (['name' => 'j2commerce', 'type' => 'privacy', 'params' => new \Joomla\Registry\Registry([])]) extends GuestRequestTestPlugin {
                protected function confirmAttempts(): ?\Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts
                {
                    return null;
                }
            };
            $noLimit->setDatabase($this->db);
            $this->test('Without the attempt counter the right code is refused (fail closed)',
                $noLimit->attemptConfirmation($w, $tw, $fresh(), 'client-e', $t0 + 50) === 'failed' && $status($idW) === 0);

            // Only hashes are stored.
            $rows =$this->db->setQuery('SELECT scope, subject_hash FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->loadObjectList();
            $raw  = array_filter($rows, static fn ($row): bool => !preg_match('/^[0-9a-f]{64}$/', (string) $row->subject_hash));
            $this->test('Attempt records hold only 64-character hashes, no address or client value',
                $rows !== [] && $raw === [] && !in_array('client-a', array_column($rows, 'subject_hash'), true), json_encode($rows));

            $limiter = new $class($this->db, 'guest-request-test-secret');

            // Parallel requests: all of them reserve before any has finished its code check.
            $parallel = ['email' => 'parallel-' . $suffix . '@example.com', 'ip' => 'client-p'];
            $admitted = 0;

            for ($i = 0; $i < $class::MAX_EMAIL_FAILURES + 3; $i++) {
                $admitted += $limiter->reserve($parallel, $t0 + 40) ? 1 : 0;
            }

            $this->test('Parallel requests: exactly the limit of attempts is admitted', $admitted === $class::MAX_EMAIL_FAILURES, (string) $admitted);
            // A window starts with its first counted attempt and is never reused by a later one:
            // an attempt at the start, more at its end, the next window after it.
            $windowed = ['email' => 'windowed-' . $suffix . '@example.com', 'ip' => 'client-w'];
            $limiter->reserve($windowed, $t0 + 100);

            for ($i = 1; $i < $class::MAX_EMAIL_FAILURES; $i++) {
                $limiter->reserve($windowed, $t0 + 100 + $class::WINDOW - 1);
            }

            $this->test('Window: the limit holds until the end of the window that started with the first attempt',
                $limiter->reserve($windowed, $t0 + 100 + $class::WINDOW - 1) === false);
            $this->test('Window: after it a new window starts with one attempt',
                $limiter->reserve($windowed, $t0 + 100 + $class::WINDOW + 1) === true
                && $limiter->exceeds($windowed, $t0 + 100 + $class::WINDOW + 1) === false);

            $this->test('Hashes depend on the site secret', $limiter->hash('ip', 'client-a') !== (new $class($this->db, 'other-secret'))->hash('ip', 'client-a'));
            $limiter->purgeExpired($t0 + 3 * $class::WINDOW);
            $left = (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->loadResult();
            $this->test('Records of ended windows are purged', $left === 0, (string) $left);
        } catch (\Throwable $e) {
            $this->test('Attempt limit tests ran without error', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        } finally {
            if ($ids !== []) {
                $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_requests') . ' WHERE id IN (' . implode(',', $ids) . ')')->execute();
            }

            $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__privacy_j2commerce_attempts'))->execute();
        }
    }

    // -------------------------------------------------------------------------

    public function run(): bool
    {
        echo "=== Data Isolation Tests ===\n";
        echo "Stack: " . strtoupper($this->tp) . "\n";

        $this->testDeleteCartDataIsolation();
        $this->testDeleteUserAddressIdorGuard();
        $this->testCheckRetentionPeriod();
        $this->testGuestRequests();
        $this->testCollationPairs();
        $this->testRequestConfirmation();
        $this->testAdminRequestMailLink();
        $this->testConfirmationPageHttp();
        $this->testConfirmAttemptLimits();

        echo "\n=== Data Isolation Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new DataIsolationTest();
exit($test->run() ? 0 : 1);
