<?php
/**
 * @package     J2Commerce Privacy Cleanup Task Plugin
 * @subpackage  Extension
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Task\J2CommercePrivacy\Extension;

defined('_JEXEC') or die;

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
            $cutoffDate     = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));

            $this->logTask("Retention period: {$retentionYears} years");
            $this->logTask("Cutoff date: {$cutoffDate}");

            $ordersTable = $this->isJ2Commerce4() ? '#__j2store_orders' : '#__j2commerce_orders';
            $query = $this->createDbQuery()
                ->select('DISTINCT o.user_id')
                ->from($db->quoteName($ordersTable, 'o'))
                ->where($db->quoteName('o.user_id') . ' > 0')
                ->group($db->quoteName('o.user_id'))
                ->having('MAX(' . $db->quoteName('o.created_on') . ') < ' . $db->quote($cutoffDate));

            $db->setQuery($query);
            $userIds = $db->loadColumn();

            if (empty($userIds)) {
                $this->logTask('No users found with expired retention periods');

                return Status::OK;
            }

            $this->logTask('Found ' . count($userIds) . ' users with expired retention periods');

            $anonymizedCount = 0;
            $partialCount    = 0;
            $errorCount      = 0;

            foreach ($userIds as $userId) {
                try {
                    if ($this->hasLifetimeLicense((int) $userId)) {
                        $this->partialAnonymizeUserData((int) $userId);
                        $partialCount++;
                        $this->logTask("Partially anonymized data for user ID: {$userId} (has lifetime license)");
                        continue;
                    }

                    $this->anonymizeUserData((int) $userId);
                    $anonymizedCount++;
                    $this->logTask("Fully anonymized data for user ID: {$userId}");
                } catch (\Throwable $e) {
                    $errorCount++;
                    $this->logTask("Error anonymizing user ID {$userId}: " . $e->getMessage(), 'error');
                }
            }

            $this->logTask("Cleanup complete: {$anonymizedCount} fully anonymized, {$partialCount} partially anonymized, {$errorCount} errors");

            if ($errorCount > 0 && $anonymizedCount === 0 && $partialCount === 0) {
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
     * Partially anonymize user data while keeping order e-mail addresses.
     *
     * @param   int  $userId  The user ID
     *
     * @return  void
     */
    protected function partialAnonymizeUserData(int $userId): void
    {
        $db         = $this->getDatabase();
        $safeUserId = (int) $userId;

        if ((int) $this->taskParam('anonymize_orders', 1) === 1) {
            $this->anonymizeOrderTables($safeUserId, false);
        }

        if ((int) $this->taskParam('delete_addresses', 1) === 1) {
            $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
            $query = $this->createDbQuery()
                ->delete($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Fully anonymize user data.
     *
     * @param   int  $userId  The user ID
     *
     * @return  void
     */
    protected function anonymizeUserData(int $userId): void
    {
        $db         = $this->getDatabase();
        $safeUserId = (int) $userId;

        if ((int) $this->taskParam('anonymize_orders', 1) === 1) {
            $this->anonymizeOrderTables($safeUserId, true);
        }

        if ((int) $this->taskParam('delete_addresses', 1) === 1) {
            $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
            $query = $this->createDbQuery()
                ->delete($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Anonymize order and order info tables for J2Commerce 4 or 6.
     *
     * @param   int   $userId          The user ID
     * @param   bool  $anonymizeEmail  Whether to anonymize order e-mail addresses
     *
     * @return  void
     */
    private function anonymizeOrderTables(int $userId, bool $anonymizeEmail): void
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
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);
        $db->setQuery($query);
        $db->execute();

        $subQuery = $this->createDbQuery()
            ->select($db->quoteName('order_id'))
            ->from($db->quoteName($ordersTable))
            ->where($db->quoteName('user_id') . ' = ' . (int) $userId);

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
}
