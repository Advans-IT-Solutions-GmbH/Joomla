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
use Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts;
use Advans\Plugin\Privacy\J2Commerce\Support\J2CommerceStack;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Date\Date;
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
use Joomla\CMS\User\UserHelper;
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

    /** The removal request being reported has no Joomla account (guest customer). */
    private bool $guestRequest = false;

    /** Order e-mail of anonymized orders; never matched as a customer address. */
    public const ANONYMIZED_EMAIL = 'anonymized@deleted.invalid';

    /**
     * Route of the confirmation page for privacy requests (com_ajax, this plugin). The bundled
     * system plugin links the administrator request mail to it (GUEST_CONFIRM_ROUTE there).
     */
    public const CONFIRM_ROUTE = 'index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy&format=html&task=confirmRequest';

    /** Task of the confirmation page in onAjaxJ2commercePrivacy(). */
    public const CONFIRM_TASK = 'confirmRequest';

    /** Result codes of confirmPrivacyRequest(). */
    public const CONFIRM_OK      = 'confirmed';
    public const CONFIRM_FAILED  = 'failed';
    public const CONFIRM_EXPIRED = 'expired';

    /** Session key of the failed confirmation attempts. */
    public const CONFIRM_ATTEMPTS_KEY = 'plg_privacy_j2commerce.confirm_attempts';

    /** Failed attempts per session within CONFIRM_ATTEMPT_WINDOW seconds before the page refuses. */
    public const CONFIRM_MAX_FAILURES = 5;

    public const CONFIRM_ATTEMPT_WINDOW = 900;

    /** Hash verifications per confirmation attempt (one per request type: export, remove). */
    private const CONFIRM_HASH_CHECKS = 2;

    /** Validity of a confirmation token, as in Joomla's com_privacy ConfirmModel. */
    private const CONFIRM_TOKEN_LIFETIME = 'P1D';

    /**
     * Hash of a random value, created like a confirmation token hash (UserHelper::hashPassword(),
     * same algorithm and cost) once per request: verified in place of a missing request, so a
     * missing request takes as long as a wrong token.
     */
    private static ?string $dummyTokenHash = null;

    /**
     * E-mail addresses of a privacy request as they are compared with order and address data:
     * trimmed, lower case, plausible addresses only, without the placeholder of anonymized orders
     * and without duplicates.
     *
     * @param   array<int, mixed>  $emails
     *
     * @return  string[]
     */
    public static function requestEmails(array $emails): array
    {
        self::loadHelperClasses();
        $result = [];

        foreach ($emails as $email) {
            $email = mb_strtolower(trim((string) $email), 'UTF-8');

            // Same address syntax as the address tokens of consent bodies (ConsentRepository).
            if ($email === self::ANONYMIZED_EMAIL || !ConsentRepository::isAddress($email)) {
                continue;
            }

            $result[$email] = $email;
        }

        return array_values($result);
    }

    /**
     * Whether an account address is the request address (byte-exact after trimming and lower
     * case). An empty request address (direct call without a request) is no contradiction.
     */
    public static function accountMatchesRequest(string $accountEmail, string $requestEmail): bool
    {
        self::loadHelperClasses();

        return trim($requestEmail) === ''
            || ConsentRepository::normaliseAddress($accountEmail) === ConsentRepository::normaliseAddress($requestEmail);
    }

    /**
     * Warn the administrator (and the log) about an account of another address.
     */
    protected function reportAccountMismatch(string $key, string $accountEmail, string $requestEmail): void
    {
        Log::add('Privacy request: the account address does not match the request address', Log::WARNING, 'plg_privacy_j2commerce');
        $app = $this->feedbackApplication();

        if ($app !== null && method_exists($app, 'enqueueMessage')) {
            $app->enqueueMessage(vsprintf($this->pluginStrings(self::currentLanguage())->_($key), [$accountEmail, $requestEmail]), 'warning');
        }
    }

    /**
     * SQL condition for the records of a privacy request: the records of the account ($userId,
     * if any) and the guest records (user_id 0) whose e-mail column holds one of the request's
     * addresses, compared case-insensitively. Records of another account never match, also when
     * they carry the same e-mail address. Values are quoted inline, so the condition can be used
     * in subqueries.
     *
     * @param   int       $userId       Joomla user ID of the request (0: no account)
     * @param   string[]  $emails       E-mail addresses of the request (see requestEmails())
     * @param   string    $emailColumn  E-mail column of the table (orders: user_email, addresses: email)
     * @param   string    $alias        Table alias, '' for none
     */
    protected function ownerCondition(int $userId, array $emails, string $emailColumn = 'user_email', string $alias = ''): string
    {
        $db     = $this->getDatabase();
        $prefix = $alias !== '' ? $alias . '.' : '';
        $parts  = [];

        if ($userId > 0) {
            $parts[] = $db->quoteName($prefix . 'user_id') . ' = ' . $userId;
        }

        $emails = self::requestEmails($emails);

        // The stored addresses that are a request address are decided in PHP
        // (ConsentRepository::addressValues()), then matched byte-exact: under the column
        // collation "muller@" would also match the order of "müller@", another person.
        if ($emails !== []) {
            $isJ4   = $this->isJ2Commerce4();
            $table  = $emailColumn === 'email'
                ? ($isJ4 ? '#__j2store_addresses' : '#__j2commerce_addresses')
                : ($isJ4 ? '#__j2store_orders' : '#__j2commerce_orders');
            $key    = ($isJ4 ? 'j2store_' : 'j2commerce_') . ($emailColumn === 'email' ? 'address_id' : 'order_id');
            $values = ConsentRepository::addressValues($db, $table, $key, $emailColumn, $emails, [$db->quoteName('user_id') . ' = 0']);

            if ($values !== []) {
                $parts[] = '(' . $db->quoteName($prefix . 'user_id') . ' = 0 AND '
                    . ConsentRepository::exactValuesCondition($db, $db->quoteName($prefix . $emailColumn), $values) . ')';
            }
        }

        return $parts === [] ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
    }

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
        $classes = [
            RetentionPeriod::class => '/../Retention/RetentionPeriod.php',
            LifetimeLicenses::class => '/../Retention/LifetimeLicenses.php',
            ConfirmAttempts::class => '/../Security/ConfirmAttempts.php',
            J2CommerceStack::class => '/../Support/J2CommerceStack.php',
            ConsentRepository::class => '/../Consent/ConsentRepository.php',
        ];

        foreach ($classes as $class => $file) {
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
            // Without a Joomla account for the request address (guest customer) the user is null;
            // the guest orders are then found through the e-mail address of the request.
            $user         = $eventOrUser->getUser();
            $requestEmail = (string) ($eventOrUser->getRequest()->email ?? '');

            // Joomla finds the account with the column collation, which equates "muller@" and
            // "müller@": such an account belongs to another person and is not exported here.
            if ($user && !self::accountMatchesRequest((string) $user->email, $requestEmail)) {
                $this->reportAccountMismatch('PLG_PRIVACY_J2COMMERCE_EXPORT_ACCOUNT_MISMATCH', (string) $user->email, $requestEmail);
                $user = null;
            }

            if (!$user && self::requestEmails([$requestEmail]) === []) {
                return;
            }

            $domains = $this->collectExportDomains($user, $requestEmail);
            $eventOrUser->addResult($domains);
            return;
        }

        // Joomla 4: Direct User parameter
        $user = $eventOrUser;
        return $this->collectExportDomains($user);
    }

    /**
     * Collect all export domains of a privacy request: the account's data (if there is an
     * account) and the guest orders placed with the request or account e-mail address.
     *
     * @param   User|null  $user          Account of the request address (null: guest customer)
     * @param   string     $requestEmail  E-mail address of the privacy request
     */
    protected function collectExportDomains(?User $user, string $requestEmail = ''): array
    {
        $domains = [];
        $emails  = self::requestEmails([$requestEmail, $user->email ?? '']);

        // Every J2Commerce data set: a migrated site can still hold the user's data in #__j2store_*.
        foreach ($this->forEachDataSet(fn (bool $isJ4): array => [$isJ4, $this->createOrdersDomain($user, $emails), $this->createAddressesDomain($user, $emails)]) as $index => [$isJ4, $orders, $addresses]) {
            if ($index > 0) {
                $orders->name         = ($isJ4 ? 'j2store_' : 'j2commerce_') . $orders->name;
                $addresses->name      = ($isJ4 ? 'j2store_' : 'j2commerce_') . $addresses->name;
                $orders->description .= $isJ4 ? ' (J2Store tables)' : ' (J2Commerce 6 tables)';
                $addresses->description .= $isJ4 ? ' (J2Store tables)' : ' (J2Commerce 6 tables)';
            }

            $domains[] = $orders;
            $domains[] = $addresses;
        }

        // Checkout consents of guest orders: Joomla's consent plugin exports only the records of
        // the account (user_id), guest orders have user_id 0.
        $consents = $this->createGuestConsentsDomain($emails);
        if ($consents !== null) {
            $domains[] = $consents;
        }

        // Guest records of an earlier template override that name the request or account address.
        $legacy = $this->createLegacyGuestConsentsDomain($emails);
        if ($legacy !== null) {
            $domains[] = $legacy;
        }

        if ($user && $this->params->get('include_joomla_data', 1)) {
            $domains[] = $this->createJoomlaUserDomain($user);
            $domains[] = $this->createJoomlaProfileDomain($user);
            $domains[] = $this->createJoomlaActionLogsDomain($user);
        }

        $acymDomain = $this->createAcyMailingDomain($user, $emails);
        if ($acymDomain !== null) {
            $domains[] = $acymDomain;
        }

        return $domains;
    }

    /**
     * Order numbers of the guest orders (user_id 0) placed with one of $emails, in every
     * J2Commerce data set or only in the J2Commerce 6 tables.
     *
     * @param   string[]  $emails
     * @param   bool      $j2commerce6Only  Only #__j2commerce_orders (checkout consents are recorded
     *                                      for these orders only, identified by the order number)
     *
     * @return  string[]
     */
    protected function guestOrderIds(array $emails, bool $j2commerce6Only = false): array
    {
        if (self::requestEmails($emails) === []) {
            return [];
        }

        $db  = $this->getDatabase();
        $ids = [];

        foreach ($this->forEachDataSet(function () use ($db, $emails, $j2commerce6Only): array {
            if ($j2commerce6Only && $this->isJ2Commerce4()) {
                return [];
            }

            $pkCol = $this->isJ2Commerce4() ? 'j2store_order_id' : 'j2commerce_order_id';
            $db->setQuery(
                $this->createDbQuery()
                    ->select($db->quoteName([$pkCol, 'order_id']))
                    ->from($db->quoteName($this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders'))
                    ->where($this->ownerCondition(0, $emails))
            );
            $pkByNumber = [];

            foreach ($db->loadObjectList() ?: [] as $row) {
                $pkByNumber[(string) $row->order_id][] = (int) $row->$pkCol;
            }

            // A number shared with an order outside the request cannot be attributed.
            return array_keys(array_diff_key($pkByNumber, $this->sharedOrderNumbers($pkByNumber)));
        }) as $orderIds) {
            foreach ($orderIds as $orderId) {
                $ids[(string) $orderId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * Checkout consent records of the guest orders placed with one of $emails, in every state
     * (valid, obsolete, invalidated), with their state (null if there is none). The consent system
     * plugin records consents for J2Commerce 6 orders only and marks them with the order number,
     * so only J2Commerce 6 orders are looked up: an order number of the J2Store tables could
     * belong to another customer's J2Commerce 6 order.
     *
     * @param   string[]  $emails
     */
    protected function createGuestConsentsDomain(array $emails): ?Domain
    {
        $orderIds = $this->guestOrderIds($emails, true);

        if ($orderIds === []) {
            return null;
        }

        self::loadHelperClasses();
        $domain = null;

        foreach ((new ConsentRepository($this->getDatabase()))->findConsentsForOrders($orderIds) as $record) {
            if ((int) $record->user_id !== 0) {
                continue;
            }

            $domain ??= $this->createDomain('checkout_consents', 'J2Commerce checkout consents of guest orders');
            $text     = trim(strip_tags(str_replace(['</p>', '<br>', '<br />', '<br/>'], "\n", (string) $record->body)));

            $domain->addItem($this->createItemFromArray([
                'order_id' => (string) $record->order_id,
                'state'    => (int) $record->state,
                'created'  => (string) $record->created,
                'consent'  => preg_replace("/\n{2,}/", "\n", $text),
            ], $record->id));
        }

        return $domain;
    }

    /**
     * Guest consent records (user_id 0) of an earlier template override that name one of $emails
     * as a complete address (null if none). They are exported unchanged.
     *
     * @param   string[]  $emails
     */
    protected function createLegacyGuestConsentsDomain(array $emails): ?Domain
    {
        if ($emails === []) {
            return null;
        }

        self::loadHelperClasses();
        $domain = null;

        foreach ((new ConsentRepository($this->getDatabase()))->findLegacyGuestConsents($emails) as $record) {
            $domain ??= $this->createDomain('legacy_consents', 'Privacy consents of an earlier shop template (guest records)');
            $text     = trim(strip_tags(str_replace(['</p>', '<br>', '<br />', '<br/>'], "\n", (string) $record->body)));

            $domain->addItem($this->createItemFromArray([
                'state'   => (int) $record->state,
                'created' => (string) $record->created,
                'consent' => preg_replace("/\n{2,}/", "\n", html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')),
            ], $record->id));
        }

        return $domain;
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
    protected function createAcyMailingDomain(?User $user, ?array $emails = null): ?Domain
    {
        $prefix = $this->getAcymTablePrefix();
        if ($prefix === null) {
            return null;
        }

        $db        = $this->getDatabase();
        $acymUsers = [];

        // Default: the account e-mail. A privacy request passes its addresses (request address
        // first); every subscriber record of these addresses is exported.
        self::loadHelperClasses();

        foreach ($emails ?? [(string) ($user->email ?? '')] as $email) {
            $email = ConsentRepository::normaliseAddress((string) $email);

            if ($email === '') {
                continue;
            }

            // Load subscriber record: the address is decided in PHP (see ownerCondition())
            try {
                $values = ConsentRepository::addressValues($db, $prefix . 'user', 'id', 'email', [$email]);

                if ($values === []) {
                    continue;
                }

                $query = $this->createDbQuery()
                    ->select(['id', 'email', 'name', 'confirmed', 'creation_date'])
                    ->from($db->quoteName($prefix . 'user'))
                    ->where(ConsentRepository::exactValuesCondition($db, $db->quoteName('email'), $values));

                foreach ($db->setQuery($query)->loadObjectList() ?: [] as $row) {
                    $acymUsers[(int) $row->id] = $row;
                }
            } catch (\Exception $e) {
                return null;
            }
        }

        if ($acymUsers === []) {
            return null;
        }

        $domain = $this->createDomain('newsletter_subscriptions', Text::_('PLG_PRIVACY_J2COMMERCE_ACYM_DOMAIN'));

        foreach ($acymUsers as $acymUser) {
            // Several subscribers (request and account address): IDs per subscriber.
            $itemPrefix = \count($acymUsers) > 1 ? 'subscriber_' . (int) $acymUser->id . '_' : '';

            $domain->addItem($this->createItemFromArray([
                'email'     => $acymUser->email,
                'name'      => $acymUser->name ?? '',
                'confirmed' => $acymUser->confirmed ? 'yes' : 'no',
                'created'   => $acymUser->creation_date ?? '',
            ], $itemPrefix . 'subscriber'));

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
                ], $itemPrefix . 'subscription_' . $sub->list_id));
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
                ], $itemPrefix . 'field_' . $field->field_id));
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
                ], $itemPrefix . 'stat_' . $stat->mail_id));
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
                ], $itemPrefix . 'click_' . $click->mail_id . '_' . $click->url_id));
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
                ], $itemPrefix . 'history_' . $i));
            }
        }

        return $domain;
    }

    /**
     * Remove AcyMailing subscriber data via direct DB queries.
     *
     * Deletes the subscriber record and all list associations.
     * Uses raw SQL for version-independence — no AcyMailing PHP classes required.
     *
     * @param   User|null  $user    The user of the request (null: guest customer without account)
     * @param   string[]   $emails  Addresses to look up, captured before Joomla's privacy user plugin
     *                              pseudonymised $user->email; default: $user->email
     */
    protected function removeAcyMailingData(?User $user, ?array $emails = null): void
    {
        $prefix = $this->getAcymTablePrefix();
        if ($prefix === null) {
            return;
        }

        self::loadHelperClasses();
        $db     = $this->getDatabase();
        $emails = array_values(array_unique(array_filter(
            array_map(static fn ($email): string => ConsentRepository::normaliseAddress((string) $email), $emails ?? [(string) ($user->email ?? '')]),
            'strlen'
        )));

        if ($emails === []) {
            return;
        }

        try {
            // The address is decided in PHP (see ownerCondition()).
            $values  = ConsentRepository::addressValues($db, $prefix . 'user', 'id', 'email', $emails);
            $acymIds = [];

            if ($values !== []) {
                $query   = $this->createDbQuery()
                    ->select('id')
                    ->from($db->quoteName($prefix . 'user'))
                    ->where(ConsentRepository::exactValuesCondition($db, $db->quoteName('email'), $values));
                $acymIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);
            }
        } catch (\Exception $e) {
            return;
        }

        foreach ($acymIds as $acymId) {
            $this->removeAcyMailingSubscriber($prefix, $acymId, (int) ($user->id ?? 0));
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

    /**
     * Orders of the account and the guest orders (user_id 0) placed with one of $emails.
     *
     * @param   User|null  $user    Account (null: guest customer without account)
     * @param   string[]   $emails  E-mail addresses of the request
     */
    protected function createOrdersDomain(?User $user, array $emails = []): Domain
    {
        $domain = $this->createDomain('orders', 'J2Commerce order data');
        $db     = $this->getDatabase();
        $owner  = $this->ownerCondition((int) ($user->id ?? 0), $emails, 'user_email', 'o');

        if ($this->isJ2Commerce4()) {
            $query = $this->createDbQuery()
                ->select(['o.*', 'oi.orderitem_name', 'oi.orderitem_sku', 'oi.orderitem_quantity', 'oi.orderitem_finalprice',
                    'inf.billing_first_name', 'inf.billing_last_name'])
                ->from($db->quoteName('#__j2store_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2store_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->leftJoin($db->quoteName('#__j2store_orderinfos', 'inf') . ' ON o.order_id = inf.order_id')
                ->where($owner);

            $db->setQuery($query);
            $rows   = $db->loadAssocList();
            $shared = $this->sharedNumbersOfRows($rows);

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
                        'billing_first_name' => isset($shared[(string) $row['order_id']]) ? '' : $row['billing_first_name'],
                        'billing_last_name'  => isset($shared[(string) $row['order_id']]) ? '' : $row['billing_last_name'],
                        'user_email'         => $row['user_email'],
                        'items'              => [],
                    ];
                }
                if ($row['orderitem_name'] && !isset($shared[(string) $row['order_id']])) {
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
                ->where($owner);

            $db->setQuery($query);
            $rows   = $db->loadAssocList();
            $shared = $this->sharedNumbersOfRows($rows);

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
                        'billing_first_name' => isset($shared[(string) $row['order_id']]) ? '' : ($row['billing_first_name'] ?? ''),
                        'billing_last_name'  => isset($shared[(string) $row['order_id']]) ? '' : ($row['billing_last_name'] ?? ''),
                        'user_email'         => $row['user_email'],
                        'items'              => [],
                    ];
                }
                if (!empty($row['orderitem_name']) && !isset($shared[(string) $row['order_id']])) {
                    $orders[$orderId]['items'][] = [
                        'name'     => $row['orderitem_name'],
                        'sku'      => $row['orderitem_sku'],
                        'quantity' => $row['orderitem_quantity'],
                        'price'    => $row['orderitem_finalprice'],
                    ];
                }
            }
        }

        // Complete billing and shipping data of each order (order info row), except for order
        // numbers shared with an order outside the request.
        $pkCol      = $this->isJ2Commerce4() ? 'j2store_order_id' : 'j2commerce_order_id';
        $numberByPk = [];

        foreach ($rows as $row) {
            $numberByPk[$row[$pkCol]] = (string) $row['order_id'];
        }

        $infos = $this->orderInfoRows(array_values(array_diff(array_unique(array_values($numberByPk)), array_map('strval', array_keys($shared)))));

        foreach ($orders as $pk => $order) {
            $orderInfos = $infos[$numberByPk[$pk] ?? ''] ?? [];

            if ($orderInfos !== []) {
                // Order fields keep precedence over equally named order info columns.
                $order += array_shift($orderInfos);

                if ($orderInfos !== []) {
                    $order['additional_order_info'] = $orderInfos;
                }
            }

            $domain->addItem($this->createItemFromArray($order, $order['order_id']));
        }

        return $domain;
    }

    /**
     * Order info rows (billing and shipping data) of the given order numbers in the current data
     * set, without their own key and the order number.
     *
     * @param   string[]  $orderNumbers
     *
     * @return  array<string, array<int, array<string, mixed>>>  Order number => rows
     */
    protected function orderInfoRows(array $orderNumbers): array
    {
        $db     = $this->getDatabase();
        $table  = $this->isJ2Commerce4() ? '#__j2store_orderinfos' : '#__j2commerce_orderinfos';
        $infoPk = $this->isJ2Commerce4() ? 'j2store_orderinfo_id' : 'j2commerce_orderinfo_id';
        $result = [];

        foreach (array_chunk(array_map('strval', $orderNumbers), 500) as $chunk) {
            $db->setQuery(
                $this->createDbQuery()
                    ->select('*')
                    ->from($db->quoteName($table))
                    ->whereIn($db->quoteName('order_id'), $chunk, ParameterType::STRING)
            );

            foreach ($db->loadAssocList() ?: [] as $info) {
                $number = (string) $info['order_id'];
                unset($info['order_id'], $info[$infoPk]);
                $result[$number][] = $info;
            }
        }

        return $result;
    }

    /**
     * Order numbers of exported order rows that also belong to an order outside the request:
     * their order items and billing names are joined only by the order number and are not
     * exported, they could be another customer's.
     *
     * @param   array<int, array<string, mixed>>  $rows  Rows with order_id and the primary key
     *
     * @return  array<string, true>
     */
    protected function sharedNumbersOfRows(array $rows): array
    {
        $pkCol      = $this->isJ2Commerce4() ? 'j2store_order_id' : 'j2commerce_order_id';
        $pkByNumber = [];

        foreach ($rows as $row) {
            $pkByNumber[(string) $row['order_id']][(int) $row[$pkCol]] = (int) $row[$pkCol];
        }

        $shared = $this->sharedOrderNumbers(array_map('array_values', $pkByNumber));

        if ($shared !== []) {
            Log::add('Export: order numbers shared with another order, items and billing names not exported: ' . implode(', ', array_keys($shared)), Log::WARNING, 'plg_privacy_j2commerce');
        }

        return $shared;
    }

    /**
     * Saved addresses of the account and guest addresses (user_id 0) with one of $emails.
     *
     * @param   User|null  $user    Account (null: guest customer without account)
     * @param   string[]   $emails  E-mail addresses of the request
     */
    protected function createAddressesDomain(?User $user, array $emails = []): Domain
    {
        $domain = $this->createDomain('addresses', 'J2Commerce address data');
        $db     = $this->getDatabase();

        $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
        $pkCol = $this->isJ2Commerce4() ? 'j2store_address_id' : 'j2commerce_address_id';

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($table))
            ->where($this->ownerCondition((int) ($user->id ?? 0), $emails, 'email'));

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
            $status = $this->checkCanRemoveData($user, (string) ($eventOrRequest->getRequest()->email ?? ''));
            $eventOrRequest->addResult($status);
            return;
        }

        // Joomla 4: Direct parameters
        return $this->checkCanRemoveData($user, $eventOrRequest instanceof RequestTable ? (string) $eventOrRequest->email : '');
    }

    /**
     * Check if user data can be removed
     *
     * @param   User|null  $user          Account Joomla found for the request address
     * @param   string     $requestEmail  E-mail address of the request ('' when unknown)
     */
    protected function checkCanRemoveData(?User $user, string $requestEmail = ''): Status
    {
        $status = new Status();

        if (!$user) {
            return $status;
        }

        // Joomla finds the account with the column collation, which equates "muller@" and
        // "müller@". Such an account belongs to another person: the request is refused, so that
        // neither this plugin nor Joomla's user plugin changes that account.
        if (!self::accountMatchesRequest((string) $user->email, $requestEmail)) {
            $status->canRemove = false;
            // The plugin strings are loaded explicitly: this runs in com_privacy's request.
            $status->reason    = vsprintf($this->pluginStrings(self::currentLanguage())->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_ACCOUNT_MISMATCH'), [(string) $user->email, $requestEmail]);

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
            $user    = $eventOrRequest->getUser();
            $request = $eventOrRequest->getRequest();
            $this->processDataRemoval($user, (string) ($request->email ?? ''), (int) ($request->id ?? 0));
            return;
        }

        // Joomla 4: Direct parameters
        $this->processDataRemoval(
            $user,
            $eventOrRequest instanceof RequestTable ? (string) $eventOrRequest->email : '',
            $eventOrRequest instanceof RequestTable ? (int) $eventOrRequest->id : 0
        );
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
     * Besides the orders of the account, the guest orders (user_id 0) placed with the request or
     * account e-mail address are processed: J2Commerce never links a guest order to an account
     * created later with the same address. Without an account ($user null) only these guest
     * orders and guest addresses are processed.
     *
     * @param User|null $user          User object (null: no Joomla account for the request address)
     * @param string    $requestEmail  E-mail address of the privacy request
     * @param int       $requestId     ID of the privacy request (0: unknown), named in the action log
     */
    protected function processDataRemoval(?User $user, string $requestEmail = '', int $requestId = 0): void
    {
        // Username and account e-mail as captured in onPrivacyCanRemoveData(): Joomla's privacy
        // user plugin may already have pseudonymised this User object (same ordering, lower
        // extension ID). Without that capture (direct call) the current values are used.
        $userId        = $user ? (int) $user->id : 0;
        $account       = $user
            ? ($this->accountsBeforeRemoval[$userId] ?? ['username' => (string) $user->username, 'email' => (string) $user->email])
            : ['username' => '', 'email' => ''];
        unset($this->accountsBeforeRemoval[$userId]);
        $username      = $account['username'];
        $accountEmail  = $account['email'];

        // An account of another address (see checkCanRemoveData()) is never processed.
        if ($user && !self::accountMatchesRequest($accountEmail, $requestEmail)) {
            Log::add('Removal skipped: the account address does not match the request address', Log::WARNING, 'plg_privacy_j2commerce');

            return;
        }

        $emails        = self::requestEmails([$requestEmail, $accountEmail]);

        if ($userId <= 0 && $emails === []) {
            return;
        }

        $customerEmail = $requestEmail !== '' ? $requestEmail : $accountEmail;
        $check         = $this->retentionOverDataSets($userId, $emails);
        // Before the anonymization: it replaces the order e-mail the guest orders are found by.
        $languageTag   = $this->customerLanguage($userId, $emails);
        $retained      = $check['orders'];
        $summary       = $this->formatRetainedOrders($retained, $check['lifetime_expired'] ?? []);

        // Log the deletion request: the entry names the request and the counts; its text comes
        // from the language key in the language of the person reading the User Actions Log.
        $this->logActivity('data_deletion_requested', $userId, '', [
            'requestid' => $requestId > 0 ? (string) $requestId : '-',
            'retained'  => (string) \count($retained),
            'lifetime'  => (string) \count($check['lifetime_expired'] ?? []),
        ]);

        if ($user) {
            $this->sendAdminNotification('data_deletion', $user, $summary, $username);
        } else {
            $this->sendAdminNotification('guest_data_deletion', new User(), $summary, $customerEmail);
        }

        // Addresses, carts and expired orders in every J2Commerce data set (a migration keeps the
        // #__j2store_* copies; personal data must not survive there).
        $this->forEachDataSet(function () use ($userId, $emails): void {
            // Always delete address book entries (not order-related)
            if ($this->params->get('delete_addresses', 1)) {
                $this->deleteAddresses($userId, $emails);
            }

            // Carts belong to an account; guest carts carry no e-mail address and expire with
            // the session. Never called with user ID 0: that would hit every guest cart.
            if ($userId > 0) {
                $this->deleteCartData($userId);
            }

            // Anonymize only orders OUTSIDE retention period
            // Orders within retention period are kept intact for legal compliance
            if ($this->params->get('anonymize_orders', 1)) {
                $this->anonymizeOrders($userId, $emails);
            }
        });

        if ($this->params->get('delete_addresses', 1)) {
            $this->logActivity('all_addresses_deleted', $userId, '', ['requestid' => $requestId > 0 ? (string) $requestId : '-']);
        }

        if ($this->params->get('anonymize_orders', 1)) {
            $this->logActivity('orders_anonymized', $userId, '', ['requestid' => $requestId > 0 ? (string) $requestId : '-']);
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

        $this->guestRequest = $user === null;

        try {
            $this->reportRetainedOrders($retained, $check['lifetime_expired'] ?? [], $customerEmail, $languageTag);
        } finally {
            $this->guestRequest = false;
        }
    }

    /**
     * Language of the customer: customer_language of the newest order (of the account or a guest
     * order with one of $emails), else the default site language.
     *
     * @param   int       $userId  Joomla user ID (0: no account)
     * @param   string[]  $emails  E-mail addresses of the request
     */
    protected function customerLanguage(int $userId, array $emails = []): string
    {
        $db     = $this->getDatabase();
        $tag    = '';
        $newest = '';

        try {
            // Newest order over every J2Commerce data set (a migrated site keeps #__j2store_*).
            foreach ($this->forEachDataSet(function () use ($db, $userId, $emails): ?object {
                $db->setQuery(
                    $this->createDbQuery()
                        ->select($db->quoteName(['customer_language', 'created_on']))
                        ->from($db->quoteName($this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders'))
                        ->where($this->ownerCondition($userId, $emails))
                        ->order($db->quoteName('created_on') . ' DESC')
                        ->setLimit(1)
                );

                return $db->loadObject() ?: null;
            }) as $order) {
                if ($order !== null && (string) $order->created_on > $newest) {
                    $newest = (string) $order->created_on;
                    $tag    = trim((string) $order->customer_language);
                }
            }
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
     * Language key of the customer e-mail text: it names only what this request removed (an
     * account exists or not, saved addresses deleted by the option "Delete Addresses" or kept).
     */
    protected function customerNoticeBodyKey(): string
    {
        $addressesDeleted = (bool) (int) $this->params->get('delete_addresses', 1);

        if ($this->guestRequest) {
            return $addressesDeleted
                ? 'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_GUEST'
                : 'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_GUEST_ADDRESSES_KEPT';
        }

        return $addressesDeleted
            ? 'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY'
            : 'PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_BODY_ADDRESSES_KEPT';
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
            $mailer = $this->createMailer();
            $mailer->addRecipient($customerEmail);
            $mailer->setSubject(sprintf($language->_('PLG_PRIVACY_J2COMMERCE_REMOVAL_CUSTOMER_SUBJECT'), (string) $app->get('sitename')));
            $mailer->setBody(
                $language->_($this->customerNoticeBodyKey()) . "\n\n"
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

    /**
     * checkRetentionPeriod() over every J2Commerce data set, as the removal itself runs on every
     * set: a migrated site can hold an order only in the inactive copy. An order number found in
     * several sets (migration copy) is listed once, the active set first.
     *
     * @param   int       $userId  Joomla user ID (0: no account)
     * @param   string[]  $emails  E-mail addresses of the request
     */
    protected function retentionOverDataSets(int $userId, array $emails = []): array
    {
        $result = [
            'can_delete'       => true,
            'retention_years'  => (int) $this->params->get('retention_years', 10),
            'orders'           => [],
            'lifetime_expired' => [],
        ];
        $checks = $this->forEachDataSet(fn (): array => $this->checkRetentionPeriod($userId, $emails));
        $seen   = [];

        // One seen set for both lists: an order number is reported once. A copy that is still
        // within the retention period in one set wins over an expired copy in another set (the
        // order is kept, so it is reported as kept).
        foreach (['orders', 'lifetime_expired'] as $key) {
            foreach ($checks as $check) {
                foreach ($check[$key] ?? [] as $order) {
                    $number = (string) $order['order_number'];

                    if (!isset($seen[$number])) {
                        $seen[$number] = true;
                        $result[$key][] = $order;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Orders of a removal request within the retention period (kept and reported) and expired
     * lifetime-license orders (anonymized, e-mail kept), in the current data set.
     *
     * @param   int       $userId  Joomla user ID (0: no account)
     * @param   string[]  $emails  E-mail addresses of the request: guest orders (user_id 0) with
     *                             one of them are included
     */
    protected function checkRetentionPeriod(int $userId, array $emails = []): array
    {
        $retentionYears = (int) $this->params->get('retention_years', 10);
        $db = $this->getDatabase();

        if ($this->isJ2Commerce4()) {
            $query = $this->createDbQuery()
                ->select(['o.j2store_order_id', 'o.order_id AS order_number', 'o.created_on', 'o.order_total', 'o.currency_code', 'oi.product_id'])
                ->from($db->quoteName('#__j2store_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2store_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->where($this->ownerCondition($userId, $emails, 'user_email', 'o'));
        } else {
            // FK in #__j2commerce_orderitems is order_id (VARCHAR), not j2commerce_order_id.
            $query = $this->createDbQuery()
                ->select(['o.j2commerce_order_id', 'o.order_id AS order_number', 'o.created_on', 'o.order_total', 'o.currency_code', 'oi.product_id'])
                ->from($db->quoteName('#__j2commerce_orders', 'o'))
                ->leftJoin($db->quoteName('#__j2commerce_orderitems', 'oi') . ' ON o.order_id = oi.order_id')
                ->where($this->ownerCondition($userId, $emails, 'user_email', 'o'));
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
     * Order numbers of $pkByNumber that also belong to an order outside it, in the orders table of
     * the current data set. Order info rows, order items and checkout consent records are linked
     * only by the order number, so for such a number they cannot be told apart.
     *
     * @param   array<string, int[]>  $pkByNumber  Order number => primary keys of the request's orders
     *
     * @return  array<string, true>
     */
    protected function sharedOrderNumbers(array $pkByNumber): array
    {
        if ($pkByNumber === []) {
            return [];
        }

        $db          = $this->getDatabase();
        $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
        $pkCol       = $this->isJ2Commerce4() ? 'j2store_order_id' : 'j2commerce_order_id';
        $ownPks      = array_flip(array_merge(...array_values($pkByNumber)));
        $shared      = [];

        foreach (array_chunk(array_map('strval', array_keys($pkByNumber)), 500) as $chunk) {
            $db->setQuery(
                $this->createDbQuery()
                    ->select($db->quoteName([$pkCol, 'order_id']))
                    ->from($db->quoteName($ordersTable))
                    ->whereIn($db->quoteName('order_id'), $chunk, ParameterType::STRING)
            );

            foreach ($db->loadObjectList() ?: [] as $row) {
                if (!isset($ownPks[(int) $row->$pkCol])) {
                    $shared[(string) $row->order_id] = true;
                }
            }
        }

        return $shared;
    }

    /**
     * Anonymize orders that are OUTSIDE the retention period.
     * Orders within retention period (default 10 years) are kept intact
     * due to Swiss legal requirements (OR Art. 958f, MWSTG Art. 70).
     *
     * The orders are those of the account and the guest orders (user_id 0) placed with one of
     * $emails. They are selected once by primary key; the updates of the orders table use these
     * keys, because the first update replaces the e-mail address the guest orders were found by.
     * Order info rows and checkout consent records are linked only by the order number: an order
     * number that also belongs to an order outside this request is left alone there (logged).
     *
     * @param int      $userId User ID (0: no account)
     * @param string[] $emails E-mail addresses of the request (guest orders)
     */
    protected function anonymizeOrders(int $userId, array $emails = []): void
    {
        $db          = $this->getDatabase();
        $isJ4        = $this->isJ2Commerce4();
        $ordersTable = $isJ4 ? '#__j2store_orders' : '#__j2commerce_orders';
        $infosTable  = $isJ4 ? '#__j2store_orderinfos' : '#__j2commerce_orderinfos';
        $pkCol       = $isJ4 ? 'j2store_order_id' : 'j2commerce_order_id';

        // Only orders whose retention period (from the end of their fiscal year) has ended
        $db->setQuery(
            $this->createDbQuery()
                ->select($db->quoteName([$pkCol, 'order_id']))
                ->from($db->quoteName($ordersTable))
                ->where($this->ownerCondition($userId, $emails))
                ->where($db->quoteName('created_on') . ' <= ' . $db->quote($this->retentionCutoff()))
        );
        $rows = $db->loadObjectList() ?: [];

        if ($rows === []) {
            return;
        }

        $pkByNumber = [];

        foreach ($rows as $row) {
            $pkByNumber[(string) $row->order_id][] = (int) $row->$pkCol;
        }

        $orderIds = array_map('strval', array_keys($pkByNumber));
        $ownPks   = array_flip(array_merge(...array_values($pkByNumber)));
        $shared   = $this->sharedOrderNumbers($pkByNumber);

        if ($shared !== []) {
            Log::add(
                'Order numbers shared with another order, order info and consent evidence not anonymized: ' . implode(', ', array_keys($shared)),
                Log::WARNING,
                'plg_privacy_j2commerce'
            );
        }

        // Lifetime-license orders keep their order e-mail address (provisional rule, pending confirmation).
        $lifetimeIds = array_flip($this->lifetimeOrderIds($orderIds));
        $emailPks    = [];

        foreach ($pkByNumber as $orderId => $pks) {
            if (!isset($lifetimeIds[(string) $orderId])) {
                array_push($emailPks, ...$pks);
            }
        }

        foreach (array_chunk($emailPks, 500) as $chunk) {
            $db->setQuery(
                $this->createDbQuery()
                    ->update($db->quoteName($ordersTable))
                    ->set($db->quoteName('user_email') . ' = ' . $db->quote(self::ANONYMIZED_EMAIL))
                    ->whereIn($db->quoteName($pkCol), $chunk)
            )->execute();
        }

        // Anonymize orders table
        foreach (array_chunk(array_keys($ownPks), 500) as $chunk) {
            $db->setQuery(
                $this->createDbQuery()
                    ->update($db->quoteName($ordersTable))
                    ->set([
                        $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                        $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                    ])
                    ->whereIn($db->quoteName($pkCol), $chunk)
            )->execute();
        }

        $ownNumbers = array_values(array_filter($orderIds, static fn (string $id): bool => !isset($shared[$id])));

        // Billing/shipping data is in the orderinfos table (J2Commerce 4 and 6), not in the orders table
        foreach (array_chunk($ownNumbers, 500) as $chunk) {
            $db->setQuery(
                $this->createDbQuery()
                    ->update($db->quoteName($infosTable))
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
                    ->whereIn($db->quoteName('order_id'), $chunk, ParameterType::STRING)
            )->execute();
        }

        // Checkout consents are recorded for J2Commerce 6 orders only and name the order number:
        // their IP address and user agent are removed in the J2Commerce 6 data set. A J2Store
        // order number could belong to another customer's J2Commerce 6 order.
        if (!$isJ4 && $ownNumbers !== []) {
            (new ConsentRepository($db))->removeOrderEvidence($ownNumbers);
        }
    }

    /**
     * Delete the saved addresses of a removal request: those of the account and guest addresses
     * (user_id 0) with one of the request's e-mail addresses.
     *
     * @param int      $userId User ID (0: no account)
     * @param string[] $emails E-mail addresses of the request
     */
    protected function deleteAddresses(int $userId, array $emails = []): void
    {
        $db    = $this->getDatabase();
        $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';

        $query = $this->createDbQuery()
            ->delete($db->quoteName($table))
            ->where($this->ownerCondition($userId, $emails, 'email'));

        $db->setQuery($query);
        $db->execute();
    }

    // =========================================================================
    // ADMIN NOTIFICATIONS
    // =========================================================================

    /**
     * Language key of the administrator notification per action. Written out in
     * full instead of being assembled from the action, so every key the plugin
     * can use is visible to the language usage check.
     */
    private const ADMIN_NOTIFICATION_KEYS = [
        'address_deleted'     => 'PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_ADDRESS_DELETED',
        'data_deletion'       => 'PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_DATA_DELETION',
        'guest_data_deletion' => 'PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_GUEST_DATA_DELETION',
    ];

    /**
     * Language key of the User Actions Log entry per action (see
     * ADMIN_NOTIFICATION_KEYS for why the keys are written out).
     */
    private const ACTIVITY_LOG_KEYS = [
        'acymailing_subscriber_deleted' => 'PLG_PRIVACY_J2COMMERCE_LOG_ACYMAILING_SUBSCRIBER_DELETED',
        'address_deleted'               => 'PLG_PRIVACY_J2COMMERCE_LOG_ADDRESS_DELETED',
        'all_addresses_deleted'         => 'PLG_PRIVACY_J2COMMERCE_LOG_ALL_ADDRESSES_DELETED',
        'data_deletion_requested'       => 'PLG_PRIVACY_J2COMMERCE_LOG_DATA_DELETION_REQUESTED',
        'orders_anonymized'             => 'PLG_PRIVACY_J2COMMERCE_LOG_ORDERS_ANONYMIZED',
    ];

    /**
     * Send admin notification email about privacy-related user action
     *
     * @param string $action Action type, a key of ADMIN_NOTIFICATION_KEYS
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

        $langKey = self::ADMIN_NOTIFICATION_KEYS[$action] ?? null;

        if ($langKey === null) {
            Log::add('Privacy admin notification skipped: unknown action ' . $action, Log::WARNING, 'plg_privacy_j2commerce');

            return;
        }

        $subject = Text::_('PLG_PRIVACY_J2COMMERCE_ADMIN_NOTIFICATION_SUBJECT');

        // $username: captured before a pseudonymisation of the account in the same request.
        $body = Text::sprintf($langKey, $username ?? $user->username, $user->id);
        
        if (!empty($details)) {
            $body .= "\n\n" . $details;
        }

        try {
            $mailer = $this->createMailer();
            $mailer->addRecipient($adminEmail);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $mailer->send();
        } catch (\Throwable $e) {
            // Log error but don't fail the operation
            Log::add('Privacy admin notification failed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }
    }

    protected function createMailer()
    {
        return Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
    }

    // =========================================================================
    // ACTIVITY LOGGING
    // =========================================================================

    /**
     * Log privacy-related activity
     *
     * @param string $action  Action type, a key of ACTIVITY_LOG_KEYS
     * @param int    $userId  User ID
     * @param string $details Additional details
     */
    protected function logActivity(string $action, int $userId, string $details = '', array $data = []): void
    {
        if (!$this->params->get('activity_logging', 0)) {
            return;
        }

        // Add to Joomla action log
        Log::add(
            sprintf('Privacy action: %s for user %d. %s', $action, $userId, trim($details . ($data !== [] ? ' ' . json_encode($data) : ''))),
            Log::INFO,
            'plg_privacy_j2commerce'
        );

        // Also store in database for audit trail. The entry carries a language key
        // that Joomla's User Actions Log translates; an action without its own key
        // is only written to the log file.
        $logKey = self::ACTIVITY_LOG_KEYS[$action] ?? null;

        if ($logKey === null) {
            Log::add('Privacy action without a log text: ' . $action, Log::WARNING, 'plg_privacy_j2commerce');

            return;
        }

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
                    $db->quote($logKey) . ',' .
                    $db->quote(json_encode(['action' => $action, 'details' => $details] + $data)) . ',' .
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
    /**
     * com_ajax entry point (index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy).
     *
     * - task=confirmRequest (format=html): confirmation page for privacy requests, open to
     *   guests (see handleConfirmationPage())
     * - task=deleteAddress (format=json): address deletion, logged-in users with form token
     *
     * The result is added to the com_ajax event (a SubscriberInterface listener's return value is
     * not collected) and also returned for direct calls.
     *
     * @param   \Joomla\Event\EventInterface|null  $event  The onAjaxJ2commercePrivacy event
     *
     * @return  array|string
     */
    public function onAjaxJ2commercePrivacy($event = null)
    {
        $app  = $this->getApplication();
        $task = (string) $app->getInput()->getCmd('task', '');

        if ($task === self::CONFIRM_TASK) {
            // Public page: no exception text reaches the visitor (com_ajax prints it otherwise).
            try {
                $result = $this->handleConfirmationPage($app);
            } catch (\Throwable $e) {
                Log::add('Privacy request confirmation page failed: ' . $e->getMessage(), Log::ERROR, 'plg_privacy_j2commerce');
                $result = $this->renderConfirmationMessage(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_FAILED'), 'danger');
            }
        } else {
            $result = $this->handleAccountTask($app, $task);
        }

        if (\is_object($event) && method_exists($event, 'addResult')) {
            $event->addResult($result);
        }

        return $result;
    }

    /**
     * Tasks of a logged-in user (form token required).
     */
    protected function handleAccountTask($app, string $task): array
    {
        if (!Session::checkToken('get') && !Session::checkToken()) {
            return ['success' => false, 'message' => Text::_('JINVALID_TOKEN')];
        }

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

    // =========================================================================
    // CONFIRMATION OF PRIVACY REQUESTS (GUESTS)
    // =========================================================================

    /**
     * Confirmation page for privacy requests that the customer cannot confirm through
     * com_privacy: Joomla shows its confirmation form only to logged-in users, so a customer
     * without an account (guest order) could never confirm a request an administrator created
     * for their address.
     *
     * GET shows a form (e-mail address, token from the mail, prefilled from the link). POST checks
     * the form token and then the attempt (attemptConfirmation()): failed attempts are limited per
     * session, per e-mail address and per client IP address. A wrong code, an address without a
     * pending request, a request that is no longer pending and a used-up limit get the same message
     * and the same work, so the page does not tell whether a request exists. Only a matching code
     * that has expired gets its own message.
     */
    protected function handleConfirmationPage($app): string
    {
        $document = method_exists($app, 'getDocument') ? $app->getDocument() : null;

        if ($document !== null && method_exists($document, 'setTitle')) {
            $document->setTitle(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_TITLE'));
        }

        if (!(int) $this->params->get('guest_confirmation', 1)) {
            return $this->renderConfirmationMessage(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_UNAVAILABLE'), 'warning');
        }

        $input = $app->getInput();

        if (strtoupper((string) $input->getMethod()) !== 'POST') {
            return $this->renderConfirmationForm('', self::cleanConfirmToken($input->get->getString('confirm_token', '')));
        }

        $email   = trim($input->post->getString('email', ''));
        $token   = self::cleanConfirmToken($input->post->getString('confirm_token', ''));
        $session = $app->getSession();

        // The posted form token is checked here instead of Session::checkToken(), which redirects
        // a new or expired session to the home page: this public page answers on its own.
        if ((string) $input->post->get(Session::getFormToken(), '', 'alnum') === '') {
            return $this->renderConfirmationForm($email, $token, Text::_('JINVALID_TOKEN_NOTICE'), 'danger');
        }

        // Joomla's IpHelper uses X-Forwarded-For only with "Behind Load Balancer" enabled in the
        // Global Configuration; otherwise the address of the connection.
        $status = $this->attemptConfirmation($email, $token, $session, (string) IpHelper::getIp());

        if ($status === self::CONFIRM_OK) {
            return $this->renderConfirmationMessage(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_SUCCESS'), 'success');
        }

        if ($status === self::CONFIRM_EXPIRED) {
            return $this->renderConfirmationMessage(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_EXPIRED'), 'warning');
        }

        return $this->renderConfirmationForm($email, $token, Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_FAILED'), 'danger');
    }

    /**
     * One confirmation attempt with its limits: failed attempts per session (CONFIRM_MAX_FAILURES)
     * and, stored server-side so that a new session does not reset them, per e-mail address and
     * per client IP address (Security\ConfirmAttempts). While a limit is used up the request is
     * not confirmed, even with the right code, but the same work is done (a code check that
     * cannot match) and the same answer given as for a wrong code. Every failure counts for the
     * session, the address and the IP address.
     *
     * @param   object  $session   Session with get($name, $default) and set($name, $value)
     * @param   string  $clientIp  Client IP address (only its hash is stored)
     *
     * @return  string  CONFIRM_OK, CONFIRM_EXPIRED or CONFIRM_FAILED
     */
    public function attemptConfirmation(string $email, string $token, object $session, string $clientIp, ?int $now = null): string
    {
        self::loadHelperClasses();
        $now      = $now ?? time();
        $subjects = ['email' => ConsentRepository::normaliseAddress($email), 'ip' => trim($clientIp)];
        $limiter  = $this->confirmAttempts();
        $blocked  = self::confirmAttemptsExceeded($session, $now);

        if (!$blocked && $limiter !== null) {
            try {
                $blocked = $limiter->isBlocked($subjects, $now);
            } catch (\Throwable $e) {
                Log::add('Confirmation attempt limit not readable: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
            }
        }

        try {
            // Blocked: the same work with a code that cannot match, so the answer time is the same.
            $status = $this->confirmPrivacyRequest($email, $blocked ? bin2hex(random_bytes(16)) : $token);
        } catch (\Throwable $e) {
            // com_ajax would print the exception class and message to the visitor.
            Log::add('Privacy request confirmation failed: ' . $e->getMessage(), Log::ERROR, 'plg_privacy_j2commerce');
            $status = self::CONFIRM_FAILED;
        }

        if ($blocked) {
            $status = self::CONFIRM_FAILED;
        }

        if ($status === self::CONFIRM_OK) {
            $session->set(self::CONFIRM_ATTEMPTS_KEY, null);

            return $status;
        }

        self::registerConfirmFailure($session, $now);

        if ($limiter !== null) {
            try {
                $limiter->registerFailure($subjects, $now);
            } catch (\Throwable $e) {
                Log::add('Confirmation attempt not counted: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
            }
        }

        return $status;
    }

    /**
     * Server-side attempt counter; null without its table or application (logged).
     */
    protected function confirmAttempts(): ?ConfirmAttempts
    {
        try {
            self::loadHelperClasses();
            $app = $this->getApplication() ?? Factory::getApplication();

            return new ConfirmAttempts($this->getDatabase(), (string) $app->get('secret', ''));
        } catch (\Throwable $e) {
            Log::add('Confirmation attempt limit not available: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');

            return null;
        }
    }

    /**
     * A confirmation token as Joomla creates it (letters and digits), else ''. Placeholders such
     * as {TOKEN} from a mail template that was not filled are dropped.
     */
    public static function cleanConfirmToken(string $token): string
    {
        $token = trim($token);

        return preg_match('/^[A-Za-z0-9]{16,64}$/', $token) ? $token : '';
    }

    /**
     * Whether this session has used up its failed attempts (CONFIRM_MAX_FAILURES within
     * CONFIRM_ATTEMPT_WINDOW seconds).
     *
     * @param   object  $session  Session with get($name, $default)
     */
    public static function confirmAttemptsExceeded(object $session, ?int $now = null): bool
    {
        $now      = $now ?? time();
        $attempts = $session->get(self::CONFIRM_ATTEMPTS_KEY);

        if (!\is_array($attempts) || $now - (int) ($attempts['since'] ?? 0) > self::CONFIRM_ATTEMPT_WINDOW) {
            return false;
        }

        return (int) ($attempts['count'] ?? 0) >= self::CONFIRM_MAX_FAILURES;
    }

    /**
     * Count a failed confirmation attempt in this session.
     *
     * @param   object  $session  Session with get($name, $default) and set($name, $value)
     */
    public static function registerConfirmFailure(object $session, ?int $now = null): void
    {
        $now      = $now ?? time();
        $attempts = $session->get(self::CONFIRM_ATTEMPTS_KEY);

        if (!\is_array($attempts) || $now - (int) ($attempts['since'] ?? 0) > self::CONFIRM_ATTEMPT_WINDOW) {
            $attempts = ['count' => 0, 'since' => $now];
        }

        $attempts['count'] = (int) ($attempts['count'] ?? 0) + 1;
        $session->set(self::CONFIRM_ATTEMPTS_KEY, $attempts);
    }

    /**
     * Confirm a pending privacy request (status 0) with its e-mail address and the token of the
     * confirmation mail, with the checks of Joomla's com_privacy ConfirmModel: the token is
     * verified against the stored hash (UserHelper::verifyPassword()) and is valid for 24 hours
     * from confirm_token_created_at. On success the request gets status 1 (confirmed) and an
     * empty token, so the link cannot be used again; Joomla's super users are notified and the
     * action log records the confirmation like a confirmation through com_privacy.
     *
     * An expired token that matches invalidates the request (status -1), as in com_privacy. A
     * wrong token changes nothing.
     *
     * @return  string  CONFIRM_OK, CONFIRM_EXPIRED or CONFIRM_FAILED
     */
    public function confirmPrivacyRequest(string $email, string $token): string
    {
        $emails = self::requestEmails([$email]);
        $token  = self::cleanConfirmToken($token);
        $db     = $this->getDatabase();
        $rows   = [];

        // The address is decided in PHP (see ownerCondition()): "dürchtest@" must not reach the
        // request of "durchtest@".
        $values = $emails !== [] && $token !== ''
            ? ConsentRepository::addressValues($db, '#__privacy_requests', 'id', 'email', [$emails[0]], [$db->quoteName('status') . ' = 0'])
            : [];

        if ($values !== []) {
            $db->setQuery(
                $this->createDbQuery()
                    ->select($db->quoteName(['id', 'email', 'request_type', 'confirm_token', 'confirm_token_created_at']))
                    ->from($db->quoteName('#__privacy_requests'))
                    ->where($db->quoteName('status') . ' = 0')
                    ->where($db->quoteName('confirm_token') . ' <> ' . $db->quote(''))
                    ->where($db->quoteName('confirm_token_created_at') . ' IS NOT NULL')
                    ->where(ConsentRepository::exactValuesCondition($db, $db->quoteName('email'), $values))
                    ->order($db->quoteName('id') . ' DESC')
                    ->setLimit(self::CONFIRM_HASH_CHECKS)
            );
            $rows = $db->loadObjectList() ?: [];
        }

        // Always the same number of hash verifications (com_privacy allows one open request per
        // type, so at most export and removal): the answer time does not tell whether or how many
        // requests exist for the address, nor which of them matched.
        $match = null;

        // Created on every first attempt of a request, whether or not it is needed, so its cost
        // does not tell either.
        self::$dummyTokenHash ??= UserHelper::hashPassword(UserHelper::genRandomPassword(32));

        for ($i = 0; $i < self::CONFIRM_HASH_CHECKS; $i++) {
            $row = $rows[$i] ?? null;

            if ($row === null) {
                UserHelper::verifyPassword($token !== '' ? $token : 'invalid', self::$dummyTokenHash);
                continue;
            }

            if (UserHelper::verifyPassword($token, (string) $row->confirm_token) && $match === null) {
                $match = $row;
            }
        }

        if ($match === null) {
            return self::CONFIRM_FAILED;
        }

        $expires = (new Date((string) $match->confirm_token_created_at))->add(new \DateInterval(self::CONFIRM_TOKEN_LIFETIME));

        if (new Date('now') > $expires) {
            $db->setQuery(
                $this->createDbQuery()
                    ->update($db->quoteName('#__privacy_requests'))
                    ->set($db->quoteName('status') . ' = -1')
                    ->set($db->quoteName('confirm_token') . ' = ' . $db->quote(''))
                    ->set($db->quoteName('confirm_token_created_at') . ' = NULL')
                    ->where($db->quoteName('id') . ' = ' . (int) $match->id)
                    ->where($db->quoteName('status') . ' = 0')
                    ->where($db->quoteName('confirm_token') . ' = :hash')
                    ->bind(':hash', $match->confirm_token)
            )->execute();

            // A new code issued in the meantime (request resent) is not touched.
            return (int) $db->getAffectedRows() === 1 ? self::CONFIRM_EXPIRED : self::CONFIRM_FAILED;
        }

        // Only the request still pending with this token: a parallel confirmation wins once.
        $db->setQuery(
            $this->createDbQuery()
                ->update($db->quoteName('#__privacy_requests'))
                ->set($db->quoteName('status') . ' = 1')
                ->set($db->quoteName('confirm_token') . ' = ' . $db->quote(''))
                ->where($db->quoteName('id') . ' = ' . (int) $match->id)
                ->where($db->quoteName('status') . ' = 0')
                ->where($db->quoteName('confirm_token') . ' = :hash')
                ->bind(':hash', $match->confirm_token)
        )->execute();

        if ((int) $db->getAffectedRows() !== 1) {
            return self::CONFIRM_FAILED;
        }

        $this->afterRequestConfirmed($match);

        return self::CONFIRM_OK;
    }

    /**
     * What com_privacy does after a confirmation: notify the super users (Joomla messages) and
     * write the action log entry COM_PRIVACY_ACTION_LOG_CONFIRMED_REQUEST. Failures are logged
     * and never undo the confirmation.
     */
    protected function afterRequestConfirmed(object $request): void
    {
        try {
            $app      = Factory::getApplication();
            $language = $app->getLanguage();
            $language->load('com_privacy', JPATH_SITE) || $language->load('com_privacy', JPATH_SITE . '/components/com_privacy');
            $language->load('com_privacy', JPATH_ADMINISTRATOR) || $language->load('com_privacy', JPATH_ADMINISTRATOR . '/components/com_privacy');
        } catch (\Throwable $e) {
            Log::add('Privacy request confirmation: no application: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');

            return;
        }

        try {
            $app->bootComponent('com_messages')->getMVCFactory()->createModel('Message', 'Administrator')->notifySuperUsers(
                Text::_('COM_PRIVACY_ADMIN_NOTIFICATION_USER_CONFIRMED_REQUEST_SUBJECT'),
                Text::sprintf('COM_PRIVACY_ADMIN_NOTIFICATION_USER_CONFIRMED_REQUEST_MESSAGE', $request->email)
            );
        } catch (\Throwable $e) {
            Log::add('Privacy request confirmation: super users not notified: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }

        try {
            $app->bootComponent('com_actionlogs')->getMVCFactory()
                ->createModel('Actionlog', 'Administrator', ['ignore_request' => true])
                ->addLog([[
                    'action'       => 'request-confirmed',
                    'subjectemail' => $request->email,
                    'id'           => (int) $request->id,
                    'itemlink'     => 'index.php?option=com_privacy&view=request&id=' . (int) $request->id,
                ]], 'COM_PRIVACY_ACTION_LOG_CONFIRMED_REQUEST', 'com_privacy.request');
        } catch (\Throwable $e) {
            Log::add('Privacy request confirmation: action log not written: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
        }
    }

    /**
     * Absolute URL of the confirmation page.
     */
    public static function confirmationUrl(): string
    {
        return rtrim(Uri::root(), '/') . '/' . self::CONFIRM_ROUTE;
    }

    /**
     * Confirmation form (e-mail address, token, form token).
     */
    protected function renderConfirmationForm(string $email, string $token, string $message = '', string $type = 'info'): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="j2commerce-privacy-confirm">'
            . '<h1>' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_TITLE')) . '</h1>'
            . ($message !== '' ? '<div class="alert alert-' . $e($type) . '" role="alert">' . $e($message) . '</div>' : '')
            . '<p>' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_INTRO')) . '</p>'
            . '<form action="' . $e(self::confirmationUrl()) . '" method="post">'
            . '<div class="mb-3">'
            . '<label class="form-label" for="j2commerce-privacy-confirm-email">' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_EMAIL_LABEL')) . '</label>'
            . '<input class="form-control" type="email" id="j2commerce-privacy-confirm-email" name="email" value="' . $e($email) . '" required autocomplete="email" maxlength="320">'
            . '</div>'
            . '<div class="mb-3">'
            . '<label class="form-label" for="j2commerce-privacy-confirm-token">' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_TOKEN_LABEL')) . '</label>'
            . '<input class="form-control" type="text" id="j2commerce-privacy-confirm-token" name="confirm_token" value="' . $e($token) . '" required autocomplete="off" maxlength="64" pattern="[A-Za-z0-9]+">'
            . '</div>'
            . '<input type="hidden" name="' . $e(Session::getFormToken()) . '" value="1">'
            . '<button type="submit" class="btn btn-primary">' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_SUBMIT')) . '</button>'
            . '</form>'
            . '</div>';
    }

    /**
     * Result of the confirmation page without a form.
     */
    protected function renderConfirmationMessage(string $message, string $type): string
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        return '<div class="j2commerce-privacy-confirm">'
            . '<h1>' . $e(Text::_('PLG_PRIVACY_J2COMMERCE_CONFIRM_TITLE')) . '</h1>'
            . '<div class="alert alert-' . $e($type) . '" role="alert">' . $e($message) . '</div>'
            . '</div>';
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
            $this->logActivity('address_deleted', $userId, '', ['addressid' => (string) $addressId]);
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
