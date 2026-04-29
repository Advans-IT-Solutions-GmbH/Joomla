<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Extension
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Component\Privacy\Administrator\Export\Domain;
use Joomla\Component\Privacy\Administrator\Export\Field;
use Joomla\Component\Privacy\Administrator\Export\Item;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Component\Privacy\Administrator\Table\RequestTable;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

class J2Commerce extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * Create a database query object (Joomla 4/5/6 compatible)
     * Joomla 6 deprecates getQuery(true) in favor of createQuery()
     *
     * @return  \Joomla\Database\QueryInterface
     */
    protected function createDbQuery()
    {
        $db = $this->getDatabase();
        
        // Joomla 6+: use createQuery()
        if (method_exists($db, 'createQuery')) {
            return $db->createQuery();
        }
        
        // Joomla 4/5: use getQuery(true)
        return $db->getQuery(true);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Privacy Component Events (Joomla 5 style)
            'onPrivacyExportRequest'   => 'onPrivacyExportRequest',
            'onPrivacyCanRemoveData'   => 'onPrivacyCanRemoveData',
            'onPrivacyRemoveData'      => 'onPrivacyRemoveData',
            // System Events for Checkout
            // onAfterRender removed: frontend rendering is handled via
            // template overrides shipped with the plugin (overrides/com_j2store/).
            // See README — Template Integration section.
            'onAjaxJ2commercePrivacy'  => 'onAjaxJ2commercePrivacy',
        ];
    }

    // =========================================================================
    // PRIVACY EXPORT FUNCTIONALITY
    // =========================================================================

    /**
     * Process privacy export request
     * Supports both Joomla 4 (direct params) and Joomla 5 (event object)
     *
     * @param   ExportRequestEvent|User  $eventOrUser  Event object (J5) or User (J4)
     *
     * @return  array|void
     */
    public function onPrivacyExportRequest($eventOrUser)
    {
        // Joomla 5: Event object
        if ($eventOrUser instanceof ExportRequestEvent) {
            $user = $eventOrUser->getUser();
            if (!$user) {
                return;
            }
            $domains = $this->collectExportDomains($user);
            $eventOrUser->addResult($domains);
            return;
        }

        // Joomla 4: Direct User parameter
        $user = $eventOrUser;
        return $this->collectExportDomains($user);
    }

    /**
     * Collect all export domains for a user
     */
    protected function collectExportDomains(User $user): array
    {
        $domains = [];
        $domains[] = $this->createOrdersDomain($user);
        $domains[] = $this->createAddressesDomain($user);

        if ($this->params->get('include_joomla_data', 1)) {
            $domains[] = $this->createJoomlaUserDomain($user);
            $domains[] = $this->createJoomlaProfileDomain($user);
            $domains[] = $this->createJoomlaActionLogsDomain($user);
        }

        $acymDomain = $this->createAcyMailingDomain($user);
        if ($acymDomain !== null) {
            $domains[] = $acymDomain;
        }

        return $domains;
    }

    // =========================================================================
    // ACYMAILING INTEGRATION
    // =========================================================================

    /**
     * Detect the AcyMailing table prefix used in this Joomla installation.
     * Returns null if AcyMailing is not installed (no acym_configuration table found).
     *
     * Works with all AcyMailing versions (6.x, 7.x, 8.x, 9.x, 10.x) because
     * it only relies on the database tables, not on AcyMailing PHP classes.
     */
    protected function getAcymTablePrefix(): ?string
    {
        $db = $this->getDatabase();

        // AcyMailing always creates a table named <joomla_prefix>acym_configuration
        // Try the standard Joomla table prefix first, then scan for any acym_configuration table.
        $joomlaPrefix = $db->getPrefix();
        $candidate    = $joomlaPrefix . 'acym_configuration';

        try {
            $tables = $db->getTableList();
        } catch (\Exception $e) {
            return null;
        }

        if (in_array($candidate, $tables, true)) {
            return $joomlaPrefix . 'acym_';
        }

        // Fallback: scan all tables for *acym_configuration
        foreach ($tables as $table) {
            if (str_ends_with($table, 'acym_configuration')) {
                return substr($table, 0, -strlen('configuration'));
            }
        }

        return null;
    }

    /**
     * Export AcyMailing subscription data for a user via direct DB queries.
     *
     * Uses raw SQL instead of AcyMailing PHP classes so the plugin works
     * regardless of AcyMailing version, license tier (Starter/Essential/Enterprise),
     * or whether AcyMailing is currently enabled/disabled.
     */
    protected function createAcyMailingDomain(User $user): ?Domain
    {
        $prefix = $this->getAcymTablePrefix();
        if ($prefix === null) {
            return null;
        }

        $db    = $this->getDatabase();
        $email = $user->email;

        // Load subscriber record
        try {
            $query = $db->getQuery(true)
                ->select(['id', 'email', 'name', 'confirmed', 'creation_date'])
                ->from($db->quoteName($prefix . 'user'))
                ->where($db->quoteName('email') . ' = ' . $db->quote($email));
            $acymUser = $db->setQuery($query)->loadObject();
        } catch (\Exception $e) {
            return null;
        }

        if (!$acymUser) {
            return null;
        }

        $domain = $this->createDomain('newsletter_subscriptions', Text::_('PLG_PRIVACY_J2COMMERCE_ACYM_DOMAIN'));

        $domain->addItem($this->createItemFromArray([
            'email'     => $acymUser->email,
            'name'      => $acymUser->name ?? '',
            'confirmed' => $acymUser->confirmed ? 'yes' : 'no',
            'created'   => $acymUser->creation_date ?? '',
        ], 'subscriber'));

        // Load list subscriptions with list names
        try {
            $query = $db->getQuery(true)
                ->select([
                    'uhl.list_id',
                    'uhl.status',
                    'uhl.subscription_date',
                    'uhl.unsubscribe_date',
                    'l.name AS list_name',
                    'l.display_name AS list_display_name',
                ])
                ->from($db->quoteName($prefix . 'user_has_list', 'uhl'))
                ->leftJoin(
                    $db->quoteName($prefix . 'list', 'l') .
                    ' ON ' . $db->quoteName('l.id') . ' = ' . $db->quoteName('uhl.list_id')
                )
                ->where($db->quoteName('uhl.user_id') . ' = ' . (int) $acymUser->id);
            $subscriptions = $db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $subscriptions = [];
        }

        foreach ($subscriptions as $sub) {
            $listName = !empty($sub->list_display_name) ? $sub->list_display_name : ($sub->list_name ?? 'List #' . $sub->list_id);
            $domain->addItem($this->createItemFromArray([
                'list'              => $listName,
                'status'            => (int) $sub->status === 1 ? 'subscribed' : 'unsubscribed',
                'subscription_date' => $sub->subscription_date ?? '',
                'unsubscribe_date'  => $sub->unsubscribe_date ?? '',
            ], 'subscription_' . $sub->list_id));
        }

        // Load custom field values (may contain name, address, phone, etc.)
        try {
            $query = $db->getQuery(true)
                ->select(['uhf.field_id', 'uhf.value', 'f.name AS field_name', 'f.type AS field_type'])
                ->from($db->quoteName($prefix . 'user_has_field', 'uhf'))
                ->leftJoin(
                    $db->quoteName($prefix . 'field', 'f') .
                    ' ON ' . $db->quoteName('f.id') . ' = ' . $db->quoteName('uhf.field_id')
                )
                ->where($db->quoteName('uhf.user_id') . ' = ' . (int) $acymUser->id);
            $fields = $db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $fields = [];
        }

        foreach ($fields as $field) {
            if (empty($field->value)) {
                continue;
            }

            $domain->addItem($this->createItemFromArray([
                'field' => $field->field_name ?? 'field_' . $field->field_id,
                'type'  => $field->field_type ?? '',
                'value' => $field->value,
            ], 'field_' . $field->field_id));
        }

        return $domain;
    }

    /**
     * Remove AcyMailing subscriber data via direct DB queries.
     *
     * Deletes the subscriber record and all list associations.
     * Uses raw SQL for version-independence — no AcyMailing PHP classes required.
     */
    protected function removeAcyMailingData(User $user): void
    {
        $prefix = $this->getAcymTablePrefix();
        if ($prefix === null) {
            return;
        }

        $db    = $this->getDatabase();
        $email = $user->email;

        try {
            $query    = $db->getQuery(true)
                ->select('id')
                ->from($db->quoteName($prefix . 'user'))
                ->where($db->quoteName('email') . ' = ' . $db->quote($email));
            $acymId = (int) $db->setQuery($query)->loadResult();
        } catch (\Exception $e) {
            return;
        }

        if (!$acymId) {
            return;
        }

        try {
            // Tables referencing acym_user.id — delete before the subscriber record (FK constraints)
            $relatedTables = [
                'user_has_list',   // list subscriptions
                'user_has_field',  // custom field values (may contain name, address, etc.)
                'user_stat',       // per-campaign open/click/bounce stats
                'url_click',       // URL click tracking
                'history',         // action log incl. IP address
                'queue',           // pending outbound emails
            ];

            foreach ($relatedTables as $table) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->delete($db->quoteName($prefix . $table))
                        ->where($db->quoteName('user_id') . ' = ' . $acymId)
                )->execute();
            }

            // Delete subscriber record
            $db->setQuery(
                $db->getQuery(true)
                    ->delete($db->quoteName($prefix . 'user'))
                    ->where($db->quoteName('id') . ' = ' . $acymId)
            )->execute();

            $this->logActivity('acymailing_subscriber_deleted', $user->id);
        } catch (\Exception $e) {
            Log::add('AcyMailing subscriber deletion failed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }
    }

    /**
     * Create a new domain object
     */
    protected function createDomain(string $name, string $description = ''): Domain
    {
        $domain = new Domain();
        $domain->name = $name;
        $domain->description = $description;
        return $domain;
    }

    /**
     * Create an item object from an array
     */
    protected function createItemFromArray(array $data, $itemId = null): Item
    {
        $item = new Item();
        $item->id = $itemId;

        foreach ($data as $key => $value) {
            if (\is_object($value)) {
                $value = (array) $value;
            }
            if (\is_array($value)) {
                $value = print_r($value, true);
            }

            $field = new Field();
            $field->name = $key;
            $field->value = $value;
            $item->addField($field);
        }

        return $item;
    }

    protected function createOrdersDomain(User $user): Domain
    {
        $domain = $this->createDomain('j2store_orders', 'J2Store order data');
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['o.*', 'oi.orderitem_name', 'oi.orderitem_sku', 'oi.orderitem_quantity', 'oi.orderitem_price', 'oi.orderitem_finalprice',
                'inf.billing_first_name', 'inf.billing_last_name'])
            ->from($db->quoteName('#__j2store_orders', 'o'))
            ->leftJoin($db->quoteName('#__j2store_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
            ->leftJoin($db->quoteName('#__j2store_orderinfos', 'inf') . ' ON o.order_id = inf.order_id')
            ->where($db->quoteName('o.user_id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER);

        $db->setQuery($query);
        $rows = $db->loadAssocList();

        $orders = [];
        foreach ($rows as $row) {
            $orderId = $row['j2store_order_id'];
            if (!isset($orders[$orderId])) {
                $orders[$orderId] = [
                    'order_id' => $orderId,
                    'order_state' => $row['order_state'],
                    'order_total' => $row['order_total'],
                    'currency_code' => $row['currency_code'],
                    'created_on' => $row['created_on'],
                    'billing_first_name' => $row['billing_first_name'],
                    'billing_last_name' => $row['billing_last_name'],
                    'user_email' => $row['user_email'],
                    'items' => []
                ];
            }
            if ($row['orderitem_name']) {
                $orders[$orderId]['items'][] = [
                    'name' => $row['orderitem_name'],
                    'sku' => $row['orderitem_sku'],
                    'quantity' => $row['orderitem_quantity'],
                    'price' => $row['orderitem_finalprice']
                ];
            }
        }

        foreach ($orders as $order) {
            $domain->addItem($this->createItemFromArray($order, $order['order_id']));
        }

        return $domain;
    }

    protected function createAddressesDomain(User $user): Domain
    {
        $domain = $this->createDomain('j2store_addresses', 'J2Store address data');
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName('#__j2store_addresses'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER);

        $db->setQuery($query);
        $addresses = $db->loadAssocList();

        foreach ($addresses as $address) {
            $domain->addItem($this->createItemFromArray($address, $address['j2store_address_id'] ?? $address['id']));
        }

        return $domain;
    }

    protected function createJoomlaUserDomain(User $user): Domain
    {
        $domain = $this->createDomain('joomla_user', 'Joomla user account data');
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['id', 'name', 'username', 'email', 'registerDate', 'lastvisitDate', 'params'])
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER);

        $db->setQuery($query);
        $userData = $db->loadAssoc();

        if ($userData) {
            $domain->addItem($this->createItemFromArray($userData, $userData['id']));
        }

        return $domain;
    }

    protected function createJoomlaProfileDomain(User $user): Domain
    {
        $domain = $this->createDomain('joomla_user_profiles', 'Joomla user profile data');
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER);

        $db->setQuery($query);
        $profiles = $db->loadAssocList();

        foreach ($profiles as $profile) {
            $domain->addItem($this->createItemFromArray($profile, $profile['user_id'] . '_' . $profile['profile_key']));
        }

        return $domain;
    }

    protected function createJoomlaActionLogsDomain(User $user): Domain
    {
        $domain = $this->createDomain('joomla_action_logs', 'Joomla user activity logs');
        $db = $this->getDatabase();

        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        if (!in_array($prefix . 'action_logs', $tables)) {
            return $domain;
        }

        $query = $this->createDbQuery()
            ->select(['id', 'message_language_key', 'message', 'log_date', 'extension', 'item_id', 'ip_address'])
            ->from($db->quoteName('#__action_logs'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER)
            ->order($db->quoteName('log_date') . ' DESC');

        $db->setQuery($query);
        $logs = $db->loadAssocList();

        foreach ($logs as $log) {
            $domain->addItem($this->createItemFromArray($log, $log['id']));
        }

        return $domain;
    }

    // =========================================================================
    // PRIVACY REMOVAL FUNCTIONALITY
    // =========================================================================

    /**
     * Check if user data can be removed
     * Supports both Joomla 4 and Joomla 5 signatures
     *
     * @param   CanRemoveDataEvent|RequestTable  $eventOrRequest  Event (J5) or RequestTable (J4)
     * @param   User|null                        $user            User object (J4 only)
     *
     * @return  Status|void
     */
    public function onPrivacyCanRemoveData($eventOrRequest, ?User $user = null)
    {
        // Joomla 5: Event object
        if ($eventOrRequest instanceof CanRemoveDataEvent) {
            $user = $eventOrRequest->getUser();
            $status = $this->checkCanRemoveData($user);
            $eventOrRequest->addResult($status);
            return;
        }

        // Joomla 4: Direct parameters
        return $this->checkCanRemoveData($user);
    }

    /**
     * Check if user data can be removed
     */
    protected function checkCanRemoveData(?User $user): Status
    {
        $status = new Status();

        if (!$user) {
            return $status;
        }

        $retentionCheck = $this->checkRetentionPeriod($user->id);

        if (!$retentionCheck['can_delete']) {
            $status->canRemove = false;
            $status->reason = $this->formatRetentionMessage($retentionCheck);
        }

        return $status;
    }

    /**
     * Remove user data
     * Supports both Joomla 4 and Joomla 5 signatures
     *
     * @param   RemoveDataEvent|RequestTable  $eventOrRequest  Event (J5) or RequestTable (J4)
     * @param   User|null                     $user            User object (J4 only)
     *
     * @return  void
     */
    public function onPrivacyRemoveData($eventOrRequest, ?User $user = null): void
    {
        // Joomla 5: Event object
        if ($eventOrRequest instanceof RemoveDataEvent) {
            $user = $eventOrRequest->getUser();
            $this->processDataRemoval($user);
            return;
        }

        // Joomla 4: Direct parameters
        $this->processDataRemoval($user);
    }

    /**
     * Process data removal for user
     */
    /**
     * Process data removal request.
     * 
     * Per Swiss law (OR Art. 958f, MWSTG Art. 70):
     * - Orders within retention period (10 years) are KEPT with full address data
     * - Orders outside retention period are anonymized
     * - Address book entries are always deleted
     * - Cart data is always deleted
     *
     * @param User|null $user User object
     */
    protected function processDataRemoval(?User $user): void
    {
        if (!$user) {
            return;
        }

        // Log the deletion request
        $this->logActivity('data_deletion_requested', $user->id);
        $this->sendAdminNotification('data_deletion', $user);

        // Always delete address book entries (not order-related)
        if ($this->params->get('delete_addresses', 1)) {
            $this->deleteAddresses($user->id);
            $this->logActivity('all_addresses_deleted', $user->id);
        }

        // Always delete cart data
        $this->deleteCartData($user->id);

        // Anonymize only orders OUTSIDE retention period
        // Orders within retention period are kept intact for legal compliance
        if ($this->params->get('anonymize_orders', 1)) {
            $this->anonymizeOrders($user->id);
            $this->logActivity('orders_anonymized', $user->id, 'Orders outside retention period anonymized');
        }

        // Remove AcyMailing subscriber data
        $this->removeAcyMailingData($user);
    }

    /**
     * Delete cart data for user
     *
     * @param int $userId User ID
     */
    protected function deleteCartData(int $userId): void
    {
        $db = $this->getDatabase();

        // Delete cart items
        $query = $this->createDbQuery()
            ->delete($db->quoteName('#__j2store_cartitems'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $userId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        // Delete cart
        $query = $this->createDbQuery()
            ->delete($db->quoteName('#__j2store_carts'))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $userId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    protected function checkRetentionPeriod(int $userId): array
    {
        $retentionYears = (int) $this->params->get('retention_years', 10);
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['o.j2store_order_id', 'o.order_id AS order_number', 'o.created_on', 'o.order_total', 'o.currency_code', 'oi.product_id'])
            ->from($db->quoteName('#__j2store_orders', 'o'))
            ->leftJoin($db->quoteName('#__j2store_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
            ->where($db->quoteName('o.user_id') . ' = :userid')
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);
        $orders = $db->loadObjectList();

        $result = [
            'can_delete' => true,
            'retention_years' => $retentionYears,
            'orders' => [],
            'lifetime_licenses' => [],
            'lifetime_licenses_accounting' => []
        ];

        if (empty($orders)) {
            return $result;
        }

        $now = time();
        $cutoffDate = strtotime("-{$retentionYears} years");
        $processedOrders = [];

        foreach ($orders as $order) {
            if (isset($processedOrders[$order->j2store_order_id])) {
                continue;
            }

            $orderDate = strtotime($order->created_on);
            $isLifetime = $this->isLifetimeLicense($order->product_id);

            if ($isLifetime && $orderDate <= $cutoffDate) {
                $result['can_delete'] = false;
                $result['lifetime_licenses_accounting'][] = [
                    'order_number' => $order->order_number,
                    'order_date' => date('d.m.Y', $orderDate),
                    'order_total' => number_format($order->order_total, 2),
                    'currency' => $order->currency_code,
                    'product_name' => 'Lifetime License'
                ];
                $processedOrders[$order->j2store_order_id] = true;
                continue;
            }

            if ($orderDate > $cutoffDate) {
                $orderAge = ($now - $orderDate) / (365 * 24 * 60 * 60);
                $yearsRemaining = $retentionYears - $orderAge;
                $retentionEnd = date('d.m.Y', strtotime($order->created_on . " +{$retentionYears} years"));

                $result['can_delete'] = false;
                $result['orders'][] = [
                    'order_number' => $order->order_number,
                    'order_date' => date('d.m.Y', $orderDate),
                    'order_total' => number_format($order->order_total, 2),
                    'currency' => $order->currency_code,
                    'years_remaining' => round($yearsRemaining, 1),
                    'retention_end' => $retentionEnd
                ];
                $processedOrders[$order->j2store_order_id] = true;
            }
        }

        return $result;
    }

    protected function isLifetimeLicense(?int $productId): bool
    {
        if ($productId === null) {
            return false;
        }

        $db = $this->getDatabase();

        // product_customfields is an optional table created manually (see post-install step 3)
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        if (!in_array($prefix . 'j2store_product_customfields', $tables)) {
            return false;
        }

        $query = $this->createDbQuery()
            ->select($db->quoteName('field_value'))
            ->from($db->quoteName('#__j2store_product_customfields'))
            ->where($db->quoteName('product_id') . ' = :productid')
            ->where($db->quoteName('field_name') . ' = ' . $db->quote('is_lifetime_license'))
            ->bind(':productid', $productId, ParameterType::INTEGER);

        $db->setQuery($query);
        $fieldValue = $db->loadResult();

        return $fieldValue !== null && strtolower(trim($fieldValue)) === 'yes';
    }

    protected function formatRetentionMessage(array $retentionCheck): string
    {
        $retentionYears = $retentionCheck['retention_years'];
        $supportEmail = $this->params->get('support_email', 'support@example.com');

        $message = "═══════════════════════════════════════════════════════\n";
        $message .= Text::_('PLG_PRIVACY_J2COMMERCE_DELETION_NOT_POSSIBLE') . "\n";
        $message .= "═══════════════════════════════════════════════════════\n\n";

        if (!empty($retentionCheck['lifetime_licenses_accounting'])) {
            $message .= "Lifetime-Lizenzen vorhanden. E-Mail wird für Aktivierung benötigt.\n\n";
            foreach ($retentionCheck['lifetime_licenses_accounting'] as $i => $license) {
                $message .= ($i + 1) . ". {$license['product_name']}\n";
                $message .= "   Bestellung: {$license['order_number']} vom {$license['order_date']}\n";
                $message .= "   Betrag: {$license['order_total']} {$license['currency']}\n\n";
            }
        }

        if (!empty($retentionCheck['orders'])) {
            $message .= "Bestellungen innerhalb der Aufbewahrungsfrist ({$retentionYears} Jahre):\n\n";
            foreach ($retentionCheck['orders'] as $i => $order) {
                $message .= ($i + 1) . ". Bestellung {$order['order_number']}\n";
                $message .= "   Datum: {$order['order_date']}\n";
                $message .= "   Betrag: {$order['order_total']} {$order['currency']}\n";
                $message .= "   Aufbewahrung bis: {$order['retention_end']}\n";
                $message .= "   Verbleibend: {$order['years_remaining']} Jahre\n\n";
            }
        }

        $message .= "Kontakt: {$supportEmail}\n";

        return $message;
    }

    /**
     * Anonymize orders that are OUTSIDE the retention period.
     * Orders within retention period (default 10 years) are kept intact
     * due to Swiss legal requirements (OR Art. 958f, MWSTG Art. 70).
     *
     * @param int $userId User ID
     */
    protected function anonymizeOrders(int $userId): void
    {
        $db = $this->getDatabase();
        $retentionYears = (int) $this->params->get('retention_years', 10);
        
        // Calculate cutoff date - only anonymize orders OLDER than retention period
        $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

        // Anonymize personal data in orders table (user_email, customer_note, ip_address)
        $safeUserId = (int) $userId;
        $safeCutoff = $db->quote($cutoffDate);
        $query = $this->createDbQuery()
            ->update($db->quoteName('#__j2store_orders'))
            ->set([
                $db->quoteName('user_email') . ' = ' . $db->quote('anonymized@example.com'),
                $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                $db->quoteName('ip_address') . ' = ' . $db->quote(''),
            ])
            ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
            ->where($db->quoteName('created_on') . ' < ' . $safeCutoff);

        $db->setQuery($query);
        $db->execute();

        // Anonymize billing/shipping data in orderinfos (joined via order_id)
        $subQuery = $this->createDbQuery()
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName('#__j2store_orders'))
            ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
            ->where($db->quoteName('created_on') . ' < ' . $safeCutoff);

        $query = $this->createDbQuery()
            ->update($db->quoteName('#__j2store_orderinfos'))
            ->set([
                $db->quoteName('billing_first_name') . ' = ' . $db->quote('Anonymized'),
                $db->quoteName('billing_last_name') . ' = ' . $db->quote('User'),
                $db->quoteName('billing_phone_1') . ' = ' . $db->quote(''),
                $db->quoteName('billing_phone_2') . ' = ' . $db->quote(''),
                $db->quoteName('billing_address_1') . ' = ' . $db->quote(''),
                $db->quoteName('billing_address_2') . ' = ' . $db->quote(''),
                $db->quoteName('billing_city') . ' = ' . $db->quote(''),
                $db->quoteName('billing_zip') . ' = ' . $db->quote(''),
                $db->quoteName('billing_company') . ' = ' . $db->quote(''),
                $db->quoteName('billing_tax_number') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_first_name') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_last_name') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_phone_1') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_address_1') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_address_2') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_city') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_zip') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_company') . ' = ' . $db->quote(''),
            ])
            ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');

        $db->setQuery($query);
        $db->execute();
    }

    protected function deleteAddresses(int $userId): void
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->delete($db->quoteName('#__j2store_addresses'))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);

        $db->setQuery($query);
        $db->execute();
    }

    // =========================================================================
    // ADMIN NOTIFICATIONS
    // =========================================================================

    /**
     * Send admin notification email about privacy-related user action
     *
     * @param string $action Action type (address_deleted, data_export, etc.)
     * @param User   $user   User who performed the action
     * @param string $details Additional details
     */
    protected function sendAdminNotification(string $action, User $user, string $details = ''): void
    {
        if (!$this->params->get('admin_notifications', 0)) {
            return;
        }

        $adminEmail = $this->params->get('admin_email', '');
        if (empty($adminEmail)) {
            // Fall back to site admin email
            $adminEmail = $this->getApplication()->get('mailfrom');
        }

        if (empty($adminEmail)) {
            return;
        }

        $subject = Text::_('PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_SUBJECT');
        
        $langKey = 'PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_' . strtoupper($action);
        $body = Text::sprintf($langKey, $user->username, $user->id);
        
        if (!empty($details)) {
            $body .= "\n\n" . $details;
        }

        try {
            $mailer = $this->getApplication()->getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($adminEmail);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $mailer->send();
        } catch (\Exception $e) {
            // Log error but don't fail the operation
            Log::add('Privacy admin notification failed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }
    }

    // =========================================================================
    // ACTIVITY LOGGING
    // =========================================================================

    /**
     * Log privacy-related activity
     *
     * @param string $action  Action type
     * @param int    $userId  User ID
     * @param string $details Additional details
     */
    protected function logActivity(string $action, int $userId, string $details = ''): void
    {
        if (!$this->params->get('activity_logging', 0)) {
            return;
        }

        // Add to Joomla action log
        Log::add(
            sprintf('Privacy action: %s for user %d. %s', $action, $userId, $details),
            Log::INFO,
            'plg_privacy_j2commerce'
        );

        // Also store in database for audit trail
        $db = $this->getDatabase();
        
        // Check if action_logs table exists (Joomla's built-in)
        try {
            $query = $this->createDbQuery()
                ->insert($db->quoteName('#__action_logs'))
                ->columns([
                    $db->quoteName('message_language_key'),
                    $db->quoteName('message'),
                    $db->quoteName('log_date'),
                    $db->quoteName('extension'),
                    $db->quoteName('user_id'),
                    $db->quoteName('item_id'),
                    $db->quoteName('ip_address')
                ])
                ->values(
                    $db->quote('PLG_PRIVACY_J2COMMERCE_LOG_' . strtoupper($action)) . ',' .
                    $db->quote(json_encode(['action' => $action, 'details' => $details])) . ',' .
                    $db->quote(Factory::getDate()->toSql()) . ',' .
                    $db->quote('plg_privacy_j2commerce') . ',' .
                    (int) $userId . ',' .
                    (int) $userId . ',' .
                    $db->quote($this->getApplication()->getInput()->server->get('REMOTE_ADDR', '', 'string'))
                );
            $db->setQuery($query);
            $db->execute();
        } catch (\Exception $e) {
            // Table might not exist or have different structure, just log to file
            Log::add('Could not write to action_logs: ' . $e->getMessage(), Log::DEBUG, 'plg_privacy_j2commerce');
        }
    }
    public function onAjaxJ2commercePrivacy(): array
    {
        $app = $this->getApplication();

        if (!Session::checkToken('get') && !Session::checkToken()) {
            return ['success' => false, 'message' => Text::_('JINVALID_TOKEN')];
        }

        $task = $app->input->get('task', '');
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            return ['success' => false, 'message' => Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST')];
        }

        switch ($task) {
            case 'deleteAddress':
                return $this->deleteUserAddress($app->input->getInt('address_id', 0), $user->id);
            default:
                return ['success' => false, 'message' => 'Invalid task'];
        }
    }

    protected function deleteUserAddress(int $addressId, int $userId): array
    {
        if (!$addressId) {
            return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_INVALID_ADDRESS')];
        }

        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('id')
            ->from($db->quoteName('#__j2store_addresses'))
            ->where($db->quoteName('id') . ' = :addressid')
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':addressid', $addressId, ParameterType::INTEGER)
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        if (!$db->loadResult()) {
            return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_ADDRESS_NOT_FOUND')];
        }

        $query = $this->createDbQuery()
            ->delete($db->quoteName('#__j2store_addresses'))
            ->where($db->quoteName('id') . ' = :addressid')
            ->bind(':addressid', $addressId, ParameterType::INTEGER);

        $db->setQuery($query);

        try {
            $db->execute();
            
            // Log activity and notify admin
            $user = $this->getApplication()->getIdentity();
            $this->logActivity('address_deleted', $userId, 'Address ID: ' . $addressId);
            $this->sendAdminNotification('address_deleted', $user, 'Address ID: ' . $addressId);
            
            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_ERROR')];
        }
    }
}
