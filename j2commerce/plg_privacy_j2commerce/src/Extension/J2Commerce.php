<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Extension
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\J2Commerce\Extension;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Advans\Plugin\Privacy\J2Commerce\Retention\LifetimeLicenses;
use Advans\Plugin\Privacy\J2Commerce\Retention\RetentionPeriod;
use Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
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
use Joomla\Utilities\IpHelper;

class J2Commerce extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * Username and e-mail of the accounts of the current removal request, captured in
     * onPrivacyCanRemoveData(): com_privacy dispatches that event to all privacy plugins before
     * any onPrivacyRemoveData(), and Joomla's privacy user plugin pseudonymises the shared User
     * object in onPrivacyRemoveData(), possibly before this plugin runs.
     *
     * @var array<int, array{username: string, email: string}>
     */
    private array $accountsBeforeRemoval = [];

    /** Data set used by isJ2Commerce4() inside forEachDataSet() (null: active set). */
    private ?bool $dataSetOverride = null;

    /**
     * Returns true if J2Commerce 4.x is installed (#__j2store_* tables).
     * Returns false for J2Commerce 6.x (#__j2commerce_* tables).
     */
    protected function isJ2Commerce4(): bool
    {
        if ($this->dataSetOverride !== null) {
            return $this->dataSetOverride;
        }

        // The installed component decides; migrated sites keep the #__j2store_* tables.
        self::loadHelperClasses();

        return J2CommerceStack::isJ2Commerce4($this->getDatabase());
    }

    /**
     * Run $callback once per existing J2Commerce data set (J2Commerce 6 and J2Store copies left by
     * a migration), with isJ2Commerce4() answering for that set.
     *
     * @return  list<mixed>  Results per set, the active set first
     */
    protected function forEachDataSet(callable $callback): array
    {
        self::loadHelperClasses();
        $results = [];

        foreach (J2CommerceStack::dataSets($this->getDatabase()) as $isJ4) {
            $previous              = $this->dataSetOverride;
            $this->dataSetOverride = $isJ4;

            try {
                $results[] = $callback($isJ4);
            } finally {
                $this->dataSetOverride = $previous;
            }
        }

        return $results;
    }

    /**
     * Cutoff of the retention period: orders with created_on <= this value (UTC) are outside it.
     * The period starts at the end of the fiscal year of the order (OR Art. 958f).
     */
    protected function retentionCutoff(): string
    {
        self::loadHelperClasses();

        return RetentionPeriod::cutoff(
            (int) $this->params->get('retention_years', 10),
            (string) $this->params->get('fiscal_year_end', RetentionPeriod::DEFAULT_FISCAL_YEAR_END),
            null,
            $this->siteTimeZone()
        );
    }

    /**
     * Site time zone (Global Configuration "Website Time Zone"); the fiscal year is determined in it.
     */
    protected function siteTimeZone(): \DateTimeZone
    {
        self::loadHelperClasses();

        try {
            $offset = (string) ($this->getApplication() ?? Factory::getApplication())->get('offset', 'UTC');
        } catch (\Throwable $e) {
            $offset = 'UTC';
        }

        return RetentionPeriod::timeZone($offset);
    }

    /**
     * Order numbers with a lifetime license among $orderIds; on errors all of them (fail-closed).
     *
     * @param   string[]  $orderIds
     *
     * @return  string[]
     */
    protected function lifetimeOrderIds(array $orderIds): array
    {
        self::loadHelperClasses();

        try {
            return LifetimeLicenses::orderIds($this->getDatabase(), $this->isJ2Commerce4(), $orderIds);
        } catch (\Throwable $e) {
            Log::add('Lifetime license lookup failed, keeping the order e-mail addresses: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');

            return array_values(array_map('strval', $orderIds));
        }
    }

    /**
     * The helper classes live in this plugin's namespace; scripts that include only this class file
     * (CLI tests) load them here.
     */
    private static function loadHelperClasses(): void
    {
        foreach ([RetentionPeriod::class => '/../Retention/RetentionPeriod.php', LifetimeLicenses::class => '/../Retention/LifetimeLicenses.php', J2CommerceStack::class => '/../Support/J2CommerceStack.php', ConsentRepository::class => '/../Consent/ConsentRepository.php'] as $class => $file) {
            if (!class_exists($class)) {
                require_once __DIR__ . $file;
            }
        }
    }

    /**
     * Create a database query object (Joomla 4/5/6 compatible).
     * Joomla 6 deprecates getQuery(true) in favor of createQuery().
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

        // Every J2Commerce data set: a migrated site can still hold the user's data in #__j2store_*.
        foreach ($this->forEachDataSet(fn (bool $isJ4): array => [$isJ4, $this->createOrdersDomain($user), $this->createAddressesDomain($user)]) as $index => [$isJ4, $orders, $addresses]) {
            if ($index > 0) {
                $orders->name         = ($isJ4 ? 'j2store_' : 'j2commerce_') . $orders->name;
                $addresses->name      = ($isJ4 ? 'j2store_' : 'j2commerce_') . $addresses->name;
                $orders->description .= $isJ4 ? ' (J2Store tables)' : ' (J2Commerce 6 tables)';
                $addresses->description .= $isJ4 ? ' (J2Store tables)' : ' (J2Commerce 6 tables)';
            }

            $domains[] = $orders;
            $domains[] = $addresses;
        }

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
            $query = $this->createDbQuery()
                ->select(['id', 'email', 'name', 'confirmed', 'creation_date'])
                ->from($db->quoteName($prefix . 'user'))
                ->where($db->quoteName('email') . ' = :email')
                ->bind(':email', $email);
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
            $query = $this->createDbQuery()
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
            $query = $this->createDbQuery()
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

        // Load send/open/click stats per campaign
        try {
            $query = $this->createDbQuery()
                ->select(['us.mail_id', 'us.send_date', 'us.open', 'us.open_date', 'us.bounce',
                          'us.bounce_rule', 'us.unsubscribe', 'us.device', 'us.opened_with',
                          'm.name AS mail_name'])
                ->from($db->quoteName($prefix . 'user_stat', 'us'))
                ->leftJoin(
                    $db->quoteName($prefix . 'mail', 'm') .
                    ' ON ' . $db->quoteName('m.id') . ' = ' . $db->quoteName('us.mail_id')
                )
                ->where($db->quoteName('us.user_id') . ' = ' . (int) $acymUser->id);
            $stats = $db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $stats = [];
        }

        foreach ($stats as $stat) {
            $domain->addItem($this->createItemFromArray([
                'campaign'    => $stat->mail_name ?? 'mail_' . $stat->mail_id,
                'send_date'   => $stat->send_date ?? '',
                'opened'      => $stat->open ? 'yes' : 'no',
                'open_date'   => $stat->open_date ?? '',
                'bounce'      => $stat->bounce ? 'yes' : 'no',
                'bounce_rule' => $stat->bounce_rule ?? '',
                'unsubscribed'=> $stat->unsubscribe ? 'yes' : 'no',
                'device'      => $stat->device ?? '',
                'opened_with' => $stat->opened_with ?? '',
            ], 'stat_' . $stat->mail_id));
        }

        // Load URL click history
        try {
            $query = $this->createDbQuery()
                ->select(['uc.mail_id', 'uc.url_id', 'uc.click', 'uc.date_click',
                          'u.url', 'm.name AS mail_name'])
                ->from($db->quoteName($prefix . 'url_click', 'uc'))
                ->leftJoin(
                    $db->quoteName($prefix . 'url', 'u') .
                    ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('uc.url_id')
                )
                ->leftJoin(
                    $db->quoteName($prefix . 'mail', 'm') .
                    ' ON ' . $db->quoteName('m.id') . ' = ' . $db->quoteName('uc.mail_id')
                )
                ->where($db->quoteName('uc.user_id') . ' = ' . (int) $acymUser->id);
            $clicks = $db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $clicks = [];
        }

        foreach ($clicks as $click) {
            $domain->addItem($this->createItemFromArray([
                'campaign'   => $click->mail_name ?? 'mail_' . $click->mail_id,
                'url'        => $click->url ?? 'url_' . $click->url_id,
                'clicks'     => $click->click,
                'last_click' => $click->date_click ?? '',
            ], 'click_' . $click->mail_id . '_' . $click->url_id));
        }

        // Load action history (incl. IP address)
        try {
            $query = $this->createDbQuery()
                ->select(['h.date', 'h.ip', 'h.action', 'h.data', 'h.unsubscribe_reason'])
                ->from($db->quoteName($prefix . 'history', 'h'))
                ->where($db->quoteName('h.user_id') . ' = ' . (int) $acymUser->id)
                ->order($db->quoteName('h.date') . ' DESC');
            $history = $db->setQuery($query)->loadObjectList();
        } catch (\Exception $e) {
            $history = [];
        }

        foreach ($history as $i => $entry) {
            $domain->addItem($this->createItemFromArray([
                'date'               => date('Y-m-d H:i:s', (int) $entry->date),
                'ip'                 => $entry->ip ?? '',
                'action'             => $entry->action ?? '',
                'data'               => $entry->data ?? '',
                'unsubscribe_reason' => $entry->unsubscribe_reason ?? '',
            ], 'history_' . $i));
        }

        return $domain;
    }

    /**
     * Remove AcyMailing subscriber data via direct DB queries.
     *
     * Deletes the subscriber record and all list associations.
     * Uses raw SQL for version-independence — no AcyMailing PHP classes required.
     *
     * @param   User      $user    The user of the request
     * @param   string[]  $emails  Addresses to look up, captured before Joomla's privacy user plugin
     *                             pseudonymised $user->email; default: $user->email
     */
    protected function removeAcyMailingData(User $user, ?array $emails = null): void
    {
        $prefix = $this->getAcymTablePrefix();
        if ($prefix === null) {
            return;
        }

        $db     = $this->getDatabase();
        $emails = array_values(array_unique(array_filter(array_map('strval', $emails ?? [(string) $user->email]), 'strlen')));

        if ($emails === []) {
            return;
        }

        try {
            $query   = $this->createDbQuery()
                ->select('id')
                ->from($db->quoteName($prefix . 'user'))
                ->whereIn($db->quoteName('email'), $emails, ParameterType::STRING);
            $acymIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
        } catch (\Exception $e) {
            return;
        }

        foreach ($acymIds as $acymId) {
            $this->removeAcyMailingSubscriber($prefix, $acymId, (int) $user->id);
        }
    }

    private function removeAcyMailingSubscriber(string $prefix, int $acymId, int $userId): void
    {
        $db = $this->getDatabase();

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

            // Older AcyMailing versions do not have every table; a missing one must not stop the
            // removal of the subscriber.
            $existing = $db->getTableList();

            foreach ($relatedTables as $table) {
                if (!\in_array($db->replacePrefix($prefix . $table), $existing, true)) {
                    continue;
                }

                $db->setQuery(
                    $this->createDbQuery()
                        ->delete($db->quoteName($prefix . $table))
                        ->where($db->quoteName('user_id') . ' = ' . $acymId)
                )->execute();
            }

            // Delete subscriber record
            $db->setQuery(
                $this->createDbQuery()
                    ->delete($db->quoteName($prefix . 'user'))
                    ->where($db->quoteName('id') . ' = ' . $acymId)
            )->execute();

            $this->logActivity('acymailing_subscriber_deleted', $userId);
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
        $domain = $this->createDomain('orders', 'J2Commerce order data');
        $db     = $this->getDatabase();

        if ($this->isJ2Commerce4()) {
            $query = $this->createDbQuery()
                ->select(['o.*', 'oi.orderitem_name', 'oi.orderitem_sku', 'oi.orderitem_quantity', 'oi.orderitem_finalprice',
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
                        'order_id'           => $orderId,
                        'order_state'        => $row['order_state'],
                        'order_total'        => $row['order_total'],
                        'currency_code'      => $row['currency_code'],
                        'created_on'         => $row['created_on'],
                        'billing_first_name' => $row['billing_first_name'],
                        'billing_last_name'  => $row['billing_last_name'],
                        'user_email'         => $row['user_email'],
                        'items'              => [],
                    ];
                }
                if ($row['orderitem_name']) {
                    $orders[$orderId]['items'][] = [
                        'name'     => $row['orderitem_name'],
                        'sku'      => $row['orderitem_sku'],
                        'quantity' => $row['orderitem_quantity'],
                        'price'    => $row['orderitem_finalprice'],
                    ];
                }
            }
        } else {
            // J2Commerce 6 — billing data is in #__j2commerce_orderinfos, not in #__j2commerce_orders.
            // FK in #__j2commerce_orderitems and #__j2commerce_orderinfos is order_id (VARCHAR),
            // not j2commerce_order_id (the PK of #__j2commerce_orders).
            $query = $this->createDbQuery()
                ->select(['o.*', 'oi.orderitem_name', 'oi.orderitem_sku', 'oi.orderitem_quantity', 'oi.orderitem_finalprice',
                    'inf.billing_first_name', 'inf.billing_last_name'])
                ->from($db->quoteName('#__j2commerce_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2commerce_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->leftJoin($db->quoteName('#__j2commerce_orderinfos', 'inf') . ' ON o.order_id = inf.order_id')
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->bind(':userid', $user->id, ParameterType::INTEGER);

            $db->setQuery($query);
            $rows = $db->loadAssocList();

            $orders = [];
            foreach ($rows as $row) {
                $orderId = $row['j2commerce_order_id'];
                if (!isset($orders[$orderId])) {
                    $orders[$orderId] = [
                        'order_id'           => $orderId,
                        'order_state'        => $row['order_state'] ?? '',
                        'order_total'        => $row['order_total'],
                        'currency_code'      => $row['currency_code'],
                        'created_on'         => $row['created_on'],
                        'billing_first_name' => $row['billing_first_name'] ?? '',
                        'billing_last_name'  => $row['billing_last_name'] ?? '',
                        'user_email'         => $row['user_email'],
                        'items'              => [],
                    ];
                }
                if (!empty($row['orderitem_name'])) {
                    $orders[$orderId]['items'][] = [
                        'name'     => $row['orderitem_name'],
                        'sku'      => $row['orderitem_sku'],
                        'quantity' => $row['orderitem_quantity'],
                        'price'    => $row['orderitem_finalprice'],
                    ];
                }
            }
        }

        foreach ($orders as $order) {
            $domain->addItem($this->createItemFromArray($order, $order['order_id']));
        }

        return $domain;
    }

    protected function createAddressesDomain(User $user): Domain
    {
        $domain = $this->createDomain('addresses', 'J2Commerce address data');
        $db     = $this->getDatabase();

        $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
        $pkCol = $this->isJ2Commerce4() ? 'j2store_address_id' : 'j2commerce_address_id';

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($table))
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':userid', $user->id, ParameterType::INTEGER);

        $db->setQuery($query);
        $addresses = $db->loadAssocList();

        foreach ($addresses as $address) {
            $domain->addItem($this->createItemFromArray($address, $address[$pkCol] ?? $address['id'] ?? null));
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

        $this->accountsBeforeRemoval[(int) $user->id] = [
            'username' => (string) $user->username,
            'email'    => (string) $user->email,
        ];

        // The request is always carried out: orders within the retention period are kept until
        // their retention end, lifetime-license orders keep only their order e-mail address after
        // it (provisional rule, pending confirmation). See processDataRemoval().
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
            $this->processDataRemoval($user, (string) ($eventOrRequest->getRequest()->email ?? ''));
            return;
        }

        // Joomla 4: Direct parameters
        $this->processDataRemoval($user, $eventOrRequest instanceof RequestTable ? (string) $eventOrRequest->email : '');
    }

    /**
     * Process data removal request.
     * 
     * Per Swiss law (OR Art. 958f, MWSTG Art. 70):
     * - Orders within retention period (10 years) are KEPT with full address data
     * - Orders outside retention period are anonymized
     * - Address book entries are always deleted
     * - Cart data is always deleted
     *
     * Joomla's own privacy user plugin pseudonymises the account in the same request. Orders
     * within the retention period are listed to the administrator (message, notification, log)
     * and to the customer (e-mail to the request address).
     *
     * @param User|null $user          User object
     * @param string    $requestEmail  E-mail address of the privacy request
     */
    protected function processDataRemoval(?User $user, string $requestEmail = ''): void
    {
        if (!$user) {
            return;
        }

        // Username and account e-mail as captured in onPrivacyCanRemoveData(): Joomla's privacy
        // user plugin may already have pseudonymised this User object (same ordering, lower
        // extension ID). Without that capture (direct call) the current values are used.
        $userId        = (int) $user->id;
        $account       = $this->accountsBeforeRemoval[$userId] ?? ['username' => (string) $user->username, 'email' => (string) $user->email];
        unset($this->accountsBeforeRemoval[$userId]);
        $username      = $account['username'];
        $accountEmail  = $account['email'];
        $customerEmail = $requestEmail !== '' ? $requestEmail : $accountEmail;
        $check         = $this->checkRetentionPeriod($userId);
        $retained      = $check['orders'];
        $summary       = $this->formatRetainedOrders($retained, $check['lifetime_expired'] ?? []);

        // Log the deletion request
        $this->logActivity('data_deletion_requested', $userId, $summary);
        $this->sendAdminNotification('data_deletion', $user, $summary, $username);

        // Addresses, carts and expired orders in every J2Commerce data set (a migration keeps the
        // #__j2store_* copies; personal data must not survive there).
        $this->forEachDataSet(function () use ($userId): void {
            // Always delete address book entries (not order-related)
            if ($this->params->get('delete_addresses', 1)) {
                $this->deleteAddresses($userId);
            }

            // Always delete cart data
            $this->deleteCartData($userId);

            // Anonymize only orders OUTSIDE retention period
            // Orders within retention period are kept intact for legal compliance
            if ($this->params->get('anonymize_orders', 1)) {
                $this->anonymizeOrders($userId);
            }
        });

        if ($this->params->get('delete_addresses', 1)) {
            $this->logActivity('all_addresses_deleted', $userId);
        }

        if ($this->params->get('anonymize_orders', 1)) {
            $this->logActivity('orders_anonymized', $userId, 'Orders outside retention period anonymized');
        }

        // Consent records of an earlier template override (also guest records with the user's
        // e-mail addresses): anonymized, never assigned to an order.
        try {
            self::loadHelperClasses();
            (new ConsentRepository($this->getDatabase()))->anonymizeLegacyConsents($userId, [$requestEmail, $accountEmail]);
        } catch (\Throwable $e) {
            Log::add('Legacy consent records could not be processed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }

        // Remove AcyMailing subscriber data (request and account e-mail as captured above)
        $this->removeAcyMailingData($user, array_unique(array_filter([$requestEmail, $accountEmail])));

        $this->reportRetainedOrders($retained, $check['lifetime_expired'] ?? [], $customerEmail, $this->customerLanguage($userId));
    }

    /**
     * Language of the customer: customer_language of the newest order, else the default site
     * language.
     */
    protected function customerLanguage(int $userId): string
    {
        $db = $this->getDatabase();

        try {
            $db->setQuery(
                $this->createDbQuery()
                    ->select($db->quoteName('customer_language'))
                    ->from($db->quoteName($this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders'))
                    ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
                    ->order($db->quoteName('created_on') . ' DESC')
                    ->setLimit(1)
            );
            $tag = trim((string) $db->loadResult());
        } catch (\Throwable $e) {
            $tag = '';
        }

        if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag)) {
            try {
                $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            } catch (\Throwable $e) {
                // No application (CLI script): the component cache cannot be used.
                $tag = 'en-GB';
            }
        }

        return $tag;
    }

    /**
     * Language object for $tag with this plugin's strings (fallback: the current language).
     */
    protected function pluginLanguage(string $tag): Language
    {
        try {
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
            $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR, $tag, true)
                || $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce', $tag, true);

            return $language;
        } catch (\Throwable $e) {
            return $this->pluginStrings(self::currentLanguage());
        }
    }

    private function pluginStrings(Language $language): Language
    {
        $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR)
            || $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

        return $language;
    }

    /**
     * Text block listing orders kept until the end of their retention period ('' if none).
     *
     * @param   array          $orders    'orders' of checkRetentionPeriod() (within the retention period)
     * @param   array          $lifetime  'lifetime_expired' of checkRetentionPeriod() (e-mail kept)
     * @param   Language|null  $language  Language of the text (default: current language)
     */
    protected function formatRetainedOrders(array $orders, array $lifetime = [], ?Language $language = null): string
    {
        $language ??= $this->pluginStrings(self::currentLanguage());
        $sprintf    = static fn (string $key, ...$args): string => vsprintf($language->_($key), $args);
        $text       = '';

        if ($orders !== []) {
            $text .= $sprintf('PLG_PRIVACY_J2COMMERCE_REMOVAL_RETAINED_HEADER', (int) $this->params->get('retention_years', 10)) . "\n\n";

            foreach ($orders as $i => $order) {
                $text .= ($i + 1) . '. ' . $sprintf('PLG_PRIVACY_J2COMMERCE_RETENTION_ORDER_TITLE', $order['order_number']) . "\n";
                $text .= '   ' . $sprintf('PLG_PRIVACY_J2COMMERCE_RETENTION_ORDER_DATE', $order['order_date']) . "\n";
                $text .= '   ' . $sprintf('PLG_PRIVACY_J2COMMERCE_RETENTION_UNTIL', $order['retention_end']) . "\n";

                if (!empty($order['lifetime'])) {
                    $text .= '   ' . $language->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_LIFETIME_NOTE') . "\n";
                }

                $text .= "\n";
            }
        }

        if ($lifetime !== []) {
            $text .= $language->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_LIFETIME_HEADER') . "\n\n";

            foreach ($lifetime as $i => $order) {
                $text .= ($i + 1) . '. ' . $sprintf('PLG_PRIVACY_J2COMMERCE_RETENTION_ORDER_TITLE', $order['order_number']) . "\n";
                $text .= '   ' . $sprintf('PLG_PRIVACY_J2COMMERCE_RETENTION_ORDER_DATE', $order['order_date']) . "\n\n";
            }
        }

        return $text;
    }

    /**
     * Feedback after a removal request: the customer e-mail when orders are kept, then the
     * message for the administrator who processes the request.
     *
     * @param   array   $retained       Orders within the retention period
     * @param   array   $lifetime       Expired lifetime-license orders (order e-mail kept)
     * @param   string  $customerEmail  Address of the request (captured before pseudonymisation)
     * @param   string  $languageTag    Language of the customer e-mail
     */
    protected function reportRetainedOrders(array $retained, array $lifetime, string $customerEmail, string $languageTag): void
    {
        $app = $this->feedbackApplication();

        if ($app === null) {
            return;
        }

        $mailState = 'none';

        if ($retained !== [] || $lifetime !== []) {
            $mailState = $this->sendCustomerRetentionNotice($app, $retained, $lifetime, $customerEmail, $languageTag);
        }

        if (!method_exists($app, 'isClient') || !$app->isClient('administrator')) {
            return;
        }

        if ($mailState === 'none') {
            $app->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_NOTHING_RETAINED'), 'message');

            return;
        }

        $key = [
            'sent'    => 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED',
            'invalid' => 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED_NO_ADDRESS',
            'failed'  => 'PLG_PRIVACY_J2COMMERCE_REMOVAL_DONE_RETAINED_MAIL_FAILED',
        ][$mailState];

        $app->enqueueMessage(
            nl2br(htmlspecialchars(Text::sprintf($key, $customerEmail) . "\n\n" . $this->formatRetainedOrders($retained, $lifetime), ENT_QUOTES, 'UTF-8')),
            $mailState === 'sent' ? 'message' : 'warning'
        );
    }

    /**
     * Application for messages and mail (null without an application, e.g. in CLI scripts).
     *
     * @return  object|null
     */
    protected function feedbackApplication(): ?object
    {
        try {
            return $this->getApplication() ?? Factory::getApplication();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * E-mail the kept orders to the customer, in the customer's language.
     *
     * @return  string  'sent', 'invalid' (no valid address) or 'failed'
     */
    protected function sendCustomerRetentionNotice($app, array $retained, array $lifetime, string $customerEmail, string $languageTag): string
    {
        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return 'invalid';
        }

        $language = $this->pluginLanguage($languageTag);

        try {
            $mailer = $app->getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($customerEmail);
            $mailer->setSubject(sprintf($language->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_SUBJECT'), (string) $app->get('sitename')));
            $mailer->setBody(
                $language->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY') . "\n\n"
                . $this->formatRetainedOrders($retained, $lifetime, $language)
                . sprintf($language->_('PLG_PRIVACY_J2COMMERCE_RETENTION_CONTACT'), (string) ($this->params->get('support_email') ?: $app->get('mailfrom')))
            );

            return $mailer->send() === false ? 'failed' : 'sent';
        } catch (\Throwable $e) {
            Log::add('Privacy removal notice to the customer failed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');

            return 'failed';
        }
    }

    /**
     * Delete cart data for user
     *
     * @param int $userId User ID
     */
    protected function deleteCartData(int $userId): void
    {
        $db = $this->getDatabase();

        $safeUserId = (int) $userId;

        if ($this->isJ2Commerce4()) {
            // #__j2store_cartitems has no user_id; delete via cart subquery.
            // $safeUserId inlined directly — bind() on subquery is lost after __toString().
            $subQuery = $this->createDbQuery()
                ->select($db->quoteName('j2store_cart_id'))
                ->from($db->quoteName('#__j2store_carts'))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

            $query = $this->createDbQuery()
                ->delete($db->quoteName('#__j2store_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
            $db->setQuery($query);
            $db->execute();

            $query = $this->createDbQuery()
                ->delete($db->quoteName('#__j2store_carts'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->bind(':userid', $safeUserId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();
        } else {
            // J2Commerce 6 — cartitems have no user_id; delete via cart subquery.
            $subQuery = $this->createDbQuery()
                ->select($db->quoteName('j2commerce_cart_id'))
                ->from($db->quoteName('#__j2commerce_carts'))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

            $query = $this->createDbQuery()
                ->delete($db->quoteName('#__j2commerce_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
            $db->setQuery($query);
            $db->execute();

            $query = $this->createDbQuery()
                ->delete($db->quoteName('#__j2commerce_carts'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->bind(':userid', $safeUserId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();
        }
    }

    protected function checkRetentionPeriod(int $userId): array
    {
        $retentionYears = (int) $this->params->get('retention_years', 10);
        $db = $this->getDatabase();

        if ($this->isJ2Commerce4()) {
            $query = $this->createDbQuery()
                ->select(['o.j2store_order_id', 'o.order_id AS order_number', 'o.created_on', 'o.order_total', 'o.currency_code', 'oi.product_id'])
                ->from($db->quoteName('#__j2store_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2store_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->bind(':userid', $userId, ParameterType::INTEGER);
        } else {
            // FK in #__j2commerce_orderitems is order_id (VARCHAR), not j2commerce_order_id.
            $query = $this->createDbQuery()
                ->select(['o.j2commerce_order_id', 'o.order_id AS order_number', 'o.created_on', 'o.order_total', 'o.currency_code', 'oi.product_id'])
                ->from($db->quoteName('#__j2commerce_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2commerce_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->bind(':userid', $userId, ParameterType::INTEGER);
        }

        $db->setQuery($query);
        $orders = $db->loadObjectList();

        // can_delete stays true: a removal request is always carried out (orders within the
        // retention period are kept, expired lifetime orders keep their e-mail address).
        $result = [
            'can_delete'       => true,
            'retention_years'  => $retentionYears,
            'orders'           => [],
            'lifetime_expired' => [],
        ];

        if (empty($orders)) {
            return $result;
        }

        $now = time();
        // Retention starts at the end of the fiscal year of the order (OR Art. 958f).
        $cutoff          = $this->retentionCutoff();
        $fiscalYearEnd   = (string) $this->params->get('fiscal_year_end', RetentionPeriod::DEFAULT_FISCAL_YEAR_END);
        $zone            = $this->siteTimeZone();
        $lifetimeOrders  = array_flip($this->lifetimeOrderIds(array_map(static fn ($o) => (string) $o->order_number, $orders)));
        $processedOrders = [];

        $pkCol = $this->isJ2Commerce4() ? 'j2store_order_id' : 'j2commerce_order_id';

        foreach ($orders as $order) {
            if (isset($processedOrders[$order->$pkCol])) {
                continue;
            }

            $processedOrders[$order->$pkCol] = true;
            $orderDate  = (new \DateTimeImmutable((string) $order->created_on, new \DateTimeZone('UTC')))->setTimezone($zone)->format('d.m.Y');
            $isExpired  = (string) $order->created_on <= $cutoff;
            $isLifetime = isset($lifetimeOrders[(string) $order->order_number]);

            if ($isExpired) {
                if ($isLifetime) {
                    $result['lifetime_expired'][] = [
                        'order_number' => $order->order_number,
                        'order_date'   => $orderDate,
                    ];
                }

                continue;
            }

            $retentionEndDate = RetentionPeriod::retentionEnd((string) $order->created_on, $retentionYears, $fiscalYearEnd, $zone);
            $yearsRemaining   = max(0, ($retentionEndDate->getTimestamp() - $now) / (365.25 * 24 * 60 * 60));

            // Kept until the retention end; the rest of the request is still carried out.
            $result['orders'][] = [
                'order_number'    => $order->order_number,
                'order_date'      => $orderDate,
                'order_total'     => number_format((float) $order->order_total, 2),
                'currency'        => $order->currency_code,
                'years_remaining' => round($yearsRemaining, 1),
                'retention_end'   => $retentionEndDate->format('d.m.Y'),
                'lifetime'        => $isLifetime,
            ];
        }

        return $result;
    }

    protected function isLifetimeLicense(?int $productId): bool
    {
        if ($productId === null) {
            return false;
        }

        // product_customfields is an optional table created manually (see post-install step 3)
        $db     = $this->getDatabase();
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();

        if ($this->isJ2Commerce4()) {
            // J2Commerce 4: optional manually-created table (see post-install step 3).
            $customTable = 'j2store_product_customfields';
            if (!in_array($prefix . $customTable, $tables, true)) {
                return false;
            }

            $query = $this->createDbQuery()
                ->select($db->quoteName('field_value'))
                ->from($db->quoteName('#__' . $customTable))
                ->where($db->quoteName('product_id') . ' = :productid')
                ->where($db->quoteName('field_name') . ' = ' . $db->quote('is_lifetime_license'))
                ->bind(':productid', $productId, ParameterType::INTEGER);

            $db->setQuery($query);
            $fieldValue = $db->loadResult();

            return $fieldValue !== null && strtolower(trim($fieldValue)) === 'yes';
        }

        // J2Commerce 6: lifetime-licence flag is stored in #__j2commerce_metafields.
        // Schema (verified against J2Commerce 6 install.mysql.utf8.sql):
        //   owner_id INT, owner_resource VARCHAR, metakey VARCHAR, metavalue TEXT
        if (!in_array($prefix . 'j2commerce_metafields', $tables, true)) {
            return false;
        }

        try {
            $query = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__j2commerce_metafields'))
                ->where($db->quoteName('owner_id')       . ' = :productid')
                ->where($db->quoteName('owner_resource') . ' = ' . $db->quote('product'))
                ->where($db->quoteName('metakey')        . ' = ' . $db->quote('is_lifetime_license'))
                ->where('LOWER(TRIM(' . $db->quoteName('metavalue') . ')) = ' . $db->quote('yes'))
                ->bind(':productid', $productId, ParameterType::INTEGER);

            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        } catch (\Exception $e) {
            // Fail-closed: if the query fails (e.g. schema mismatch after a J2Commerce
            // upgrade), treat the product as a lifetime license. This prevents accidental
            // anonymization of a paying customer due to a transient DB error.
            // The operator must resolve the underlying error before erasure can proceed.
            Log::add('isLifetimeLicense J6 query failed — treating as lifetime license: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');

            return true;
        }
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
        // Only orders whose retention period (from the end of their fiscal year) has ended
        $cutoffDate = $this->retentionCutoff();

        $safeUserId = (int) $userId;
        $safeCutoff = $db->quote($cutoffDate);

        // Orders anonymized below: their checkout consent records lose IP address and user agent.
        $db->setQuery(
            $this->createDbQuery()
                ->select($db->quoteName('order_id'))
                ->from($db->quoteName($this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders'))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
                ->where($db->quoteName('created_on') . ' <= ' . $safeCutoff)
        );
        $anonymizedOrderIds = $db->loadColumn() ?: [];

        // Lifetime-license orders keep their order e-mail address (provisional rule, pending confirmation).
        $lifetimeIds  = $this->lifetimeOrderIds($anonymizedOrderIds);
        $emailFilter  = $lifetimeIds === []
            ? ''
            : ' AND ' . $db->quoteName('order_id') . ' NOT IN (' . implode(',', array_map([$db, 'quote'], $lifetimeIds)) . ')';
        $ordersTable  = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';

        if ($anonymizedOrderIds !== []) {
            $db->setQuery(
                $this->createDbQuery()
                    ->update($db->quoteName($ordersTable))
                    ->set($db->quoteName('user_email') . ' = ' . $db->quote('anonymized@deleted.invalid'))
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId . ' AND ' . $db->quoteName('created_on') . ' <= ' . $safeCutoff . $emailFilter)
            )->execute();
        }

        if ($this->isJ2Commerce4()) {
            // Anonymize orders table
            $query = $this->createDbQuery()
                ->update($db->quoteName('#__j2store_orders'))
                ->set([
                    $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                    $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                ])
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
                ->where($db->quoteName('created_on') . ' <= ' . $safeCutoff);
            $db->setQuery($query);
            $db->execute();

            // Anonymize billing/shipping data in orderinfos
            $subQuery = $this->createDbQuery()
                ->select($db->quoteName('order_id'))
                ->from($db->quoteName('#__j2store_orders'))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
                ->where($db->quoteName('created_on') . ' <= ' . $safeCutoff);

            $query = $this->createDbQuery()
                ->update($db->quoteName('#__j2store_orderinfos'))
                ->set([
                    $db->quoteName('billing_first_name')  . ' = ' . $db->quote('Anonymized'),
                    $db->quoteName('billing_last_name')   . ' = ' . $db->quote('User'),
                    $db->quoteName('billing_middle_name') . ' = ' . $db->quote(''),
                    $db->quoteName('billing_phone_1')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_phone_2')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_fax')         . ' = ' . $db->quote(''),
                    $db->quoteName('billing_address_1')   . ' = ' . $db->quote(''),
                    $db->quoteName('billing_address_2')   . ' = ' . $db->quote(''),
                    $db->quoteName('billing_city')        . ' = ' . $db->quote(''),
                    $db->quoteName('billing_zip')         . ' = ' . $db->quote(''),
                    $db->quoteName('billing_company')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_tax_number')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_first_name') . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_last_name')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_middle_name'). ' = ' . $db->quote(''),
                    $db->quoteName('shipping_phone_1')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_phone_2')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_fax')        . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_address_1')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_address_2')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_city')       . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_zip')        . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_company')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_tax_number') . ' = ' . $db->quote(''),
                    $db->quoteName('all_billing')         . ' = ' . $db->quote(''),
                    $db->quoteName('all_shipping')        . ' = ' . $db->quote(''),
                    $db->quoteName('all_payment')         . ' = ' . $db->quote(''),
                ])
                ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
            $db->setQuery($query);
            $db->execute();
        } else {
            // J2Commerce 6 — anonymize PII in #__j2commerce_orders
            $query = $this->createDbQuery()
                ->update($db->quoteName('#__j2commerce_orders'))
                ->set([
                    $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                    $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                ])
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
                ->where($db->quoteName('created_on') . ' <= ' . $safeCutoff);
            $db->setQuery($query);
            $db->execute();

            // J2Commerce 6 — billing/shipping data is in #__j2commerce_orderinfos, not in #__j2commerce_orders
            $subQuery = $this->createDbQuery()
                ->select($db->quoteName('order_id'))
                ->from($db->quoteName('#__j2commerce_orders'))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId)
                ->where($db->quoteName('created_on') . ' <= ' . $safeCutoff);

            $query = $this->createDbQuery()
                ->update($db->quoteName('#__j2commerce_orderinfos'))
                ->set([
                    $db->quoteName('billing_first_name')  . ' = ' . $db->quote('Anonymized'),
                    $db->quoteName('billing_last_name')   . ' = ' . $db->quote('User'),
                    $db->quoteName('billing_middle_name') . ' = ' . $db->quote(''),
                    $db->quoteName('billing_phone_1')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_phone_2')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_fax')         . ' = ' . $db->quote(''),
                    $db->quoteName('billing_address_1')   . ' = ' . $db->quote(''),
                    $db->quoteName('billing_address_2')   . ' = ' . $db->quote(''),
                    $db->quoteName('billing_city')        . ' = ' . $db->quote(''),
                    $db->quoteName('billing_zip')         . ' = ' . $db->quote(''),
                    $db->quoteName('billing_company')     . ' = ' . $db->quote(''),
                    $db->quoteName('billing_tax_number')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_first_name') . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_last_name')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_middle_name'). ' = ' . $db->quote(''),
                    $db->quoteName('shipping_phone_1')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_phone_2')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_fax')        . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_address_1')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_address_2')  . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_city')       . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_zip')        . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_company')    . ' = ' . $db->quote(''),
                    $db->quoteName('shipping_tax_number') . ' = ' . $db->quote(''),
                    $db->quoteName('all_billing')         . ' = ' . $db->quote(''),
                    $db->quoteName('all_shipping')        . ' = ' . $db->quote(''),
                    $db->quoteName('all_payment')         . ' = ' . $db->quote(''),
                ])
                ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
            $db->setQuery($query);
            $db->execute();
        }

        if ($anonymizedOrderIds !== []) {
            (new ConsentRepository($db))->removeOrderEvidence($anonymizedOrderIds);
        }
    }

    protected function deleteAddresses(int $userId): void
    {
        $db    = $this->getDatabase();
        $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';

        $query = $this->createDbQuery()
            ->delete($db->quoteName($table))
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
    protected function sendAdminNotification(string $action, User $user, string $details = '', ?string $username = null): void
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
        // $username: captured before a pseudonymisation of the account in the same request.
        $body = Text::sprintf($langKey, $username ?? $user->username, $user->id);
        
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
                    $db->quote((string) IpHelper::getIp())
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

        $task = $app->getInput()->get('task', '');
        $user = $app->getIdentity();

        if (!$user || $user->guest) {
            return ['success' => false, 'message' => Text::_('JGLOBAL_YOU_MUST_LOGIN_FIRST')];
        }

        switch ($task) {
            case 'deleteAddress':
                return $this->deleteUserAddress($app->getInput()->getInt('address_id', 0), $user->id);
            default:
                return ['success' => false, 'message' => 'Invalid task'];
        }
    }

    protected function deleteUserAddress(int $addressId, int $userId): array
    {
        if (!$addressId) {
            return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_INVALID_ADDRESS')];
        }

        $db    = $this->getDatabase();
        $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
        $pkCol = $this->isJ2Commerce4() ? 'j2store_address_id' : 'j2commerce_address_id';

        // Atomic DELETE with ownership check — avoids TOCTOU race between SELECT and DELETE.
        // If the address does not belong to $userId, affected rows = 0 → not found.
        $query = $this->createDbQuery()
            ->delete($db->quoteName($table))
            ->where($db->quoteName($pkCol) . ' = :addressid')
            ->where($db->quoteName('user_id') . ' = :userid')
            ->bind(':addressid', $addressId, ParameterType::INTEGER)
            ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);

        try {
            $db->execute();

            if ($db->getAffectedRows() === 0) {
                return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_ADDRESS_NOT_FOUND')];
            }

            $user = $this->getApplication()->getIdentity();
            $this->logActivity('address_deleted', $userId, 'Address ID: ' . $addressId);
            $this->sendAdminNotification('address_deleted', $user, 'Address ID: ' . $addressId);

            return ['success' => true];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_ERROR')];
        }
    }

    /**
     * Current language: the application's, or (CLI without application) a language object of the
     * default site language. Factory::getLanguage() is deprecated.
     */
    private static function currentLanguage(): Language
    {
        try {
            $app = Factory::getApplication();

            if (method_exists($app, 'getLanguage')) {
                return $app->getLanguage();
            }
        } catch (\Throwable $e) {
            // No application (CLI script).
        }

        static $fallback = null;

        if ($fallback === null) {
            try {
                $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            } catch (\Throwable $e) {
                $tag = 'en-GB';
            }

            $fallback = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
        }

        return $fallback;
    }
}
