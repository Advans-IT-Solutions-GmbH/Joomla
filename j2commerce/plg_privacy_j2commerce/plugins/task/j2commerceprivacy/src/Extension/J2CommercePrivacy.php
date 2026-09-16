<?php
/**
 * @package     J2Commerce Privacy Cleanup Task Plugin
 * @subpackage  Extension
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Task\J2CommercePrivacy\Extension;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Advans\Plugin\Privacy\J2Commerce\Retention\LifetimeLicenses;
use Advans\Plugin\Privacy\J2Commerce\Retention\RetentionPeriod;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * Automatic data cleanup task for expired J2Commerce retention periods.
 *
 * @since  1.5.4
 */
final class J2CommercePrivacy extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    /**
     * @var string[]
     * @since 1.5.4
     */
    protected const TASKS_MAP = [
        'plg_task_j2commerceprivacy.autocleanup' => [
            'langConstPrefix' => 'PLG_TASK_J2COMMERCEPRIVACY_TASK_AUTOCLEANUP',
            'form'            => 'autocleanup',
            'method'          => 'autoCleanup',
        ],
    ];

    /**
     * @var boolean
     * @since 1.5.4
     */
    protected $autoloadLanguage = true;

    /**
     * Scheduler task parameters for the currently running routine.
     *
     * @var object|Registry|null
     */
    private $routineParams = null;

    /**
     * Returns an array of events this subscriber will listen to.
     *
     * @return string[]
     *
     * @since 1.5.4
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Create a database query object compatible with Joomla 5 and 6.
     *
     * @return \Joomla\Database\QueryInterface
     */
    protected function createDbQuery()
    {
        $db = $this->getDatabase();

        if (method_exists($db, 'createQuery')) {
            return $db->createQuery();
        }

        return $db->getQuery(true);
    }

    protected function isJ2Commerce4(): bool
    {
        static $result = null;

        if ($result === null) {
            $db     = $this->getDatabase();
            $tables = $db->getTableList();
            $prefix = $db->getPrefix();
            $result = in_array($prefix . 'j2store_orders', $tables, true);
        }

        return $result;
    }

    /**
     * Read a parameter from the current scheduler task.
     *
     * @param   string  $name     Parameter name
     * @param   mixed   $default  Default value
     *
     * @return  mixed
     */
    private function taskParam(string $name, $default)
    {
        if ($this->routineParams instanceof Registry) {
            return $this->routineParams->get($name, $default);
        }

        if (is_object($this->routineParams) && property_exists($this->routineParams, $name)) {
            return $this->routineParams->{$name};
        }

        return $default;
    }

    /**
     * Automatic cleanup of expired user data.
     *
     * @param   ExecuteTaskEvent  $event  The event
     *
     * @return  int  Task status
     *
     * @since   1.5.4
     */
    protected function autoCleanup(ExecuteTaskEvent $event): int
    {
        $this->routineParams = $event->getArgument('params') ?? new \stdClass();

        $this->logTask('Starting automatic J2Commerce data cleanup...');

        try {
            $db             = $this->getDatabase();
            $retentionYears = (int) $this->taskParam('retention_years', 10);
            $fiscalYearEnd  = (string) $this->taskParam('fiscal_year_end', '12-31');

            // The retention period starts at the end of the fiscal year of the order (OR Art. 958f).
            if (!$this->loadPrivacyClass(RetentionPeriod::class, '/Retention/RetentionPeriod.php')) {
                $this->logTask('Retention helper of the privacy plugin not found; nothing anonymized', 'error');

                return Status::KNOCKOUT;
            }

            if (!RetentionPeriod::isValidFiscalYearEnd($fiscalYearEnd)) {
                $this->logTask("Invalid fiscal year end '{$fiscalYearEnd}', using " . RetentionPeriod::DEFAULT_FISCAL_YEAR_END, 'warning');
            }

            $fiscalYearEnd = RetentionPeriod::effectiveFiscalYearEnd($fiscalYearEnd);
            $zone          = $this->siteTimeZone();
            $cutoffDate    = RetentionPeriod::cutoff($retentionYears, $fiscalYearEnd, null, $zone);

            $this->logTask("Retention period: {$retentionYears} years from the end of the fiscal year ({$fiscalYearEnd}, time zone {$zone->getName()})");
            $this->logTask("Orders created on or before {$cutoffDate} are outside the retention period");

            $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
            $query = $this->createDbQuery()
                ->select('DISTINCT o.user_id')
                ->from($db->quoteName($ordersTable, 'o'))
                ->where($db->quoteName('o.user_id') . ' > 0')
                ->group($db->quoteName('o.user_id'))
                ->having('MAX(' . $db->quoteName('o.created_on') . ') <= ' . $db->quote($cutoffDate));

            $db->setQuery($query);
            $userIds = $db->loadColumn();

            $guestErrors = 0;

            if ((int) $this->taskParam('anonymize_orders', 1) === 1) {
                try {
                    $this->anonymizeExpiredGuestOrders($cutoffDate);
                } catch (\Throwable $e) {
                    $guestErrors++;
                    $this->logTask('Error anonymizing guest orders: ' . $e->getMessage(), 'error');
                }
            }

            if (empty($userIds)) {
                $this->logTask('No users found with expired retention periods');

                return $guestErrors > 0 ? Status::KNOCKOUT : Status::OK;
            }

            $this->logTask('Found ' . count($userIds) . ' users with expired retention periods');

            $anonymizedCount = 0;
            $partialCount    = 0;
            $errorCount      = 0;

            foreach ($userIds as $userId) {
                try {
                    // Lifetime-license orders keep only their order e-mail address (provisional
                    // rule, pending confirmation); all other orders are fully anonymized.
                    $lifetimeOrders = $this->anonymizeUserData((int) $userId);

                    if ($lifetimeOrders > 0) {
                        $partialCount++;
                        $this->logTask("Anonymized data for user ID: {$userId}; {$lifetimeOrders} lifetime-license order(s) keep the order e-mail");
                        continue;
                    }

                    $anonymizedCount++;
                    $this->logTask("Fully anonymized data for user ID: {$userId}");
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->logTask("Error anonymizing user ID {$userId}: " . $e->getMessage(), 'error');
                }
            }

            $this->logTask("Cleanup complete: {$anonymizedCount} fully anonymized, {$partialCount} partially anonymized, {$errorCount} errors");

            if ($guestErrors > 0 || ($errorCount > 0 && $anonymizedCount === 0 && $partialCount === 0)) {
                return Status::KNOCKOUT;
            }

            return Status::OK;
        } catch (\Throwable $e) {
            $this->logTask('Fatal error: ' . $e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }
    }

    /**
     * Check if a user bought a product flagged as lifetime license.
     *
     * @param   int  $userId  The user ID
     *
     * @return  bool
     */
    protected function hasLifetimeLicense(int $userId): bool
    {
        $db     = $this->getDatabase();
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();

        if ($this->isJ2Commerce4()) {
            $customTable = 'j2store_product_customfields';

            if (!in_array($prefix . $customTable, $tables, true)) {
                return false;
            }

            $query = $this->createDbQuery()
                ->select('DISTINCT oi.product_id')
                ->from($db->quoteName('#__j2store_orders', 'o'))
                ->leftJoin(
                    $db->quoteName('#__j2store_orderitems', 'oi') .
                    ' ON ' . $db->quoteName('o.order_id') . ' = ' . $db->quoteName('oi.order_id')
                )
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->where($db->quoteName('oi.product_id') . ' IS NOT NULL')
                ->bind(':userid', $userId, ParameterType::INTEGER);

            $db->setQuery($query);
            $productIds = $db->loadColumn();

            if (empty($productIds)) {
                return false;
            }

            $query = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__' . $customTable))
                ->where($db->quoteName('product_id') . ' IN (' . implode(',', array_map('intval', $productIds)) . ')')
                ->where($db->quoteName('field_name') . ' = ' . $db->quote('is_lifetime_license'))
                ->where('LOWER(TRIM(' . $db->quoteName('field_value') . ')) = ' . $db->quote('yes'));

            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        }

        if (!in_array($prefix . 'j2commerce_metafields', $tables, true)) {
            return false;
        }

        try {
            $query = $this->createDbQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__j2commerce_orderitems', 'oi'))
                ->join(
                    'INNER',
                    $db->quoteName('#__j2commerce_orders', 'o') .
                    ' ON ' . $db->quoteName('o.order_id') . ' = ' . $db->quoteName('oi.order_id')
                )
                ->join(
                    'INNER',
                    $db->quoteName('#__j2commerce_metafields', 'mf') .
                    ' ON '  . $db->quoteName('mf.owner_id') . ' = ' . $db->quoteName('oi.product_id') .
                    ' AND ' . $db->quoteName('mf.owner_resource') . ' = ' . $db->quote('product') .
                    ' AND ' . $db->quoteName('mf.metakey') . ' = ' . $db->quote('is_lifetime_license') .
                    ' AND LOWER(TRIM(' . $db->quoteName('mf.metavalue') . ')) = ' . $db->quote('yes')
                )
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->bind(':userid', $userId, ParameterType::INTEGER);

            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        } catch (\Throwable $e) {
            $this->logTask('hasLifetimeLicense J2Commerce 6 query failed, treating as lifetime license: ' . $e->getMessage(), 'warning');

            return true;
        }
    }

    /**
     * Site time zone (Global Configuration "Website Time Zone").
     */
    private function siteTimeZone(): \DateTimeZone
    {
        try {
            $offset = (string) $this->getApplication()->get('offset', 'UTC');
        } catch (\Throwable $e) {
            $offset = 'UTC';
        }

        return RetentionPeriod::timeZone($offset);
    }

    /**
     * Anonymize user data; lifetime-license orders keep their order e-mail address.
     * Kept for backward compatibility: same as anonymizeUserData().
     *
     * @param   int  $userId  The user ID
     *
     * @return  void
     */
    protected function partialAnonymizeUserData(int $userId): void
    {
        $this->anonymizeUserData($userId);
    }

    /**
     * Anonymize the user's orders (lifetime-license orders keep their order e-mail address) and
     * delete the saved addresses.
     *
     * @param   int  $userId  The user ID
     *
     * @return  int  Number of lifetime-license orders whose e-mail address was kept
     */
    protected function anonymizeUserData(int $userId): int
    {
        $db         = $this->getDatabase();
        $safeUserId = (int) $userId;
        $lifetime   = 0;

        if ((int) $this->taskParam('anonymize_orders', 1) === 1) {
            $lifetime = $this->anonymizeOrderTables($safeUserId);
        }

        if ((int) $this->taskParam('delete_addresses', 1) === 1) {
            $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
            $query = $this->createDbQuery()
                ->delete($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
            $db->setQuery($query);
            $db->execute();
        }

        return $lifetime;
    }

    /**
     * Anonymize order and order info tables of a user for J2Commerce 4 or 6. Lifetime-license
     * orders keep their order e-mail address.
     *
     * @param   int  $userId  The user ID
     *
     * @return  int  Number of lifetime-license orders
     */
    private function anonymizeOrderTables(int $userId): int
    {
        $db          = $this->getDatabase();
        $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
        $db->setQuery(
            $this->createDbQuery()
                ->select($db->quoteName('order_id'))
                ->from($db->quoteName($ordersTable))
                ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
        );
        $orderIds = array_map('strval', $db->loadColumn() ?: []);
        $lifetime = $this->ordersWithLifetimeLicense($orderIds);
        $userCond = $db->quoteName('user_id') . ' = ' . (int) $userId;

        if ($lifetime === []) {
            $this->anonymizeOrdersWhere($userCond, true);
        } else {
            $in = implode(',', array_map([$db, 'quote'], $lifetime));
            $this->anonymizeOrdersWhere($userCond . ' AND ' . $db->quoteName('order_id') . ' NOT IN (' . $in . ')', true);
            $this->anonymizeOrdersWhere($userCond . ' AND ' . $db->quoteName('order_id') . ' IN (' . $in . ')', false);
        }

        $this->removeConsentEvidence($userId);

        return \count($lifetime);
    }

    /**
     * Anonymize guest orders (user_id = 0) outside the retention period. Guest orders have no user
     * account, so they are handled per order. Orders with a lifetime license keep their e-mail
     * address (license reactivation), like the partial anonymization of registered users.
     *
     * @param   string  $cutoffDate  Orders created on or before this date are processed
     *
     * @return  void
     */
    private function anonymizeExpiredGuestOrders(string $cutoffDate): void
    {
        $db          = $this->getDatabase();
        $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
        $infosTable  = $this->isJ2Commerce4() ? '#__j2store_orderinfos' : '#__j2commerce_orderinfos';

        // Only orders that still contain personal data (repeated runs skip finished orders).
        $query = $this->createDbQuery()
            ->select('DISTINCT ' . $db->quoteName('o.order_id'))
            ->from($db->quoteName($ordersTable, 'o'))
            ->join('LEFT', $db->quoteName($infosTable, 'oi') . ' ON ' . $db->quoteName('oi.order_id') . ' = ' . $db->quoteName('o.order_id'))
            ->where($db->quoteName('o.user_id') . ' = 0')
            ->where($db->quoteName('o.created_on') . ' <= ' . $db->quote($cutoffDate))
            ->where('(' . $db->quoteName('o.ip_address') . ' <> ' . $db->quote('')
                . ' OR ' . $db->quoteName('o.customer_note') . ' <> ' . $db->quote('')
                . ' OR ' . $db->quoteName('oi.billing_first_name') . ' <> ' . $db->quote('Anonymized') . ')');
        $db->setQuery($query);
        $orderIds = array_values(array_filter(array_map('strval', $db->loadColumn() ?: [])));

        if ($orderIds === []) {
            $this->logTask('No guest orders with expired retention periods');

            return;
        }

        $lifetime = $this->ordersWithLifetimeLicense($orderIds);

        foreach (array_chunk($orderIds, 200) as $chunk) {
            $full    = array_values(array_diff($chunk, $lifetime));
            $partial = array_values(array_intersect($chunk, $lifetime));

            foreach ([[$full, true], [$partial, false]] as [$ids, $anonymizeEmail]) {
                if ($ids === []) {
                    continue;
                }

                $this->anonymizeOrdersWhere(
                    $db->quoteName('user_id') . ' = 0 AND ' . $db->quoteName('order_id') . ' IN (' . implode(',', array_map([$db, 'quote'], $ids)) . ')',
                    $anonymizeEmail
                );
            }

            $this->removeConsentEvidenceForOrders($chunk);
        }

        $this->logTask('Anonymized ' . count($orderIds) . ' guest order(s), ' . count($lifetime) . ' of them with lifetime license (e-mail kept)');
    }

    /**
     * Order numbers among $orderIds that contain a product flagged as lifetime license.
     * Fail-closed: if the lookup fails, every order counts as lifetime license (e-mail kept).
     *
     * @param   string[]  $orderIds  Order numbers
     *
     * @return  string[]
     */
    private function ordersWithLifetimeLicense(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        try {
            if (!$this->loadPrivacyClass(LifetimeLicenses::class, '/Retention/LifetimeLicenses.php')) {
                throw new \RuntimeException('LifetimeLicenses helper of the privacy plugin not found');
            }

            return LifetimeLicenses::orderIds($this->getDatabase(), $this->isJ2Commerce4(), $orderIds);
        } catch (\Throwable $e) {
            $this->logTask('Lifetime license lookup failed, keeping the order e-mail addresses: ' . $e->getMessage(), 'warning');

            return array_values(array_map('strval', $orderIds));
        }
    }
    /**
     * Anonymize the orders matching $where (SQL condition on the orders table) and their order infos.
     *
     * @param   string  $where           Condition on the orders table (already quoted)
     * @param   bool    $anonymizeEmail  Whether to anonymize order e-mail addresses
     *
     * @return  void
     */
    private function anonymizeOrdersWhere(string $where, bool $anonymizeEmail): void
    {
        $db          = $this->getDatabase();
        $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
        $infosTable  = $this->isJ2Commerce4() ? '#__j2store_orderinfos' : '#__j2commerce_orderinfos';

        $sets = [
            $db->quoteName('customer_note') . ' = ' . $db->quote(''),
            $db->quoteName('ip_address') . ' = ' . $db->quote(''),
        ];

        if ($anonymizeEmail) {
            $sets[] = $db->quoteName('user_email') . ' = ' . $db->quote('anonymized@deleted.invalid');
        }

        $query = $this->createDbQuery()
            ->update($db->quoteName($ordersTable))
            ->set($sets)
            ->where($where);
        $db->setQuery($query);
        $db->execute();

        $subQuery = $this->createDbQuery()
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName($ordersTable))
            ->where($where);

        $query = $this->createDbQuery()
            ->update($db->quoteName($infosTable))
            ->set([
                $db->quoteName('billing_first_name') . ' = ' . $db->quote('Anonymized'),
                $db->quoteName('billing_last_name') . ' = ' . $db->quote('User'),
                $db->quoteName('billing_middle_name') . ' = ' . $db->quote(''),
                $db->quoteName('billing_phone_1') . ' = ' . $db->quote(''),
                $db->quoteName('billing_phone_2') . ' = ' . $db->quote(''),
                $db->quoteName('billing_fax') . ' = ' . $db->quote(''),
                $db->quoteName('billing_address_1') . ' = ' . $db->quote(''),
                $db->quoteName('billing_address_2') . ' = ' . $db->quote(''),
                $db->quoteName('billing_city') . ' = ' . $db->quote(''),
                $db->quoteName('billing_zip') . ' = ' . $db->quote(''),
                $db->quoteName('billing_company') . ' = ' . $db->quote(''),
                $db->quoteName('billing_tax_number') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_first_name') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_last_name') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_middle_name') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_phone_1') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_phone_2') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_fax') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_address_1') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_address_2') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_city') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_zip') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_company') . ' = ' . $db->quote(''),
                $db->quoteName('shipping_tax_number') . ' = ' . $db->quote(''),
                $db->quoteName('all_billing') . ' = ' . $db->quote(''),
                $db->quoteName('all_shipping') . ' = ' . $db->quote(''),
                $db->quoteName('all_payment') . ' = ' . $db->quote(''),
            ])
            ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Remove IP address and user agent from the checkout consent records of the anonymized orders
     * (the consent records themselves stay as evidence). The repository belongs to the privacy
     * plugin that ships this task plugin.
     *
     * @param   int  $userId  The user ID
     *
     * @return  void
     */
    private function removeConsentEvidence(int $userId): void
    {
        $db          = $this->getDatabase();
        $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
        $query       = $this->createDbQuery()
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName($ordersTable))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
        $db->setQuery($query);
        $orderIds = $db->loadColumn() ?: [];

        $changed = $this->removeConsentEvidenceForOrders($orderIds);
        $this->logTask("Removed IP address and user agent from {$changed} consent record(s) of user ID: {$userId}");
    }

    /**
     * @param   string[]  $orderIds  Anonymized order numbers
     *
     * @return  int  Number of consent records changed
     */
    private function removeConsentEvidenceForOrders(array $orderIds): int
    {
        if ($orderIds === []) {
            return 0;
        }

        if (!$this->loadPrivacyClass(ConsentRepository::class, '/Consent/ConsentRepository.php')) {
            $this->logTask('Consent repository of the privacy plugin not found; consent records not changed', 'warning');

            return 0;
        }

        return (new ConsentRepository($this->getDatabase()))->removeOrderEvidence($orderIds);
    }

    /**
     * Load a class of the privacy plugin that ships this task plugin (namespace map first, then
     * the file under plugins/privacy/j2commerce/src).
     */
    private function loadPrivacyClass(string $class, string $relativeFile): bool
    {
        $file = JPATH_PLUGINS . '/privacy/j2commerce/src' . $relativeFile;

        if (!class_exists($class) && is_file($file)) {
            require_once $file;
        }

        return class_exists($class);
    }
}
