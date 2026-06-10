<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Task
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Task;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Database\DatabaseAwareTrait;

/**
 * Automatic data cleanup task for expired retention periods
 *
 * @since  1.0.0
 */
class AutoCleanupTask implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    /**
     * @var string[]
     * @since 1.0.0
     */
    private const TASKS_MAP = [
        'plg_privacy_j2commerce.autocleanup' => [
            'langConstPrefix' => 'PLG_PRIVACY_J2COMMERCE_TASK_AUTOCLEANUP',
            'method'          => 'autoCleanup',
        ],
    ];

    /**
     * Create a database query object (Joomla 4/5/6 compatible)
     *
     * @return  \Joomla\Database\QueryInterface
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
     * Returns an array of events this subscriber will listen to.
     *
     * @return  array
     *
     * @since   1.0.0
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
     * Automatic cleanup of expired user data
     *
     * @param   ExecuteTaskEvent  $event  The event
     *
     * @return  int  Task status
     *
     * @since   1.0.0
     */
    protected function autoCleanup(ExecuteTaskEvent $event): int
    {
        $this->logTask('Starting automatic data cleanup...');

        // TaskPluginTrait populates $this->params from the event on J5.
        // On J6, params may be passed via the event argument instead.
        if ($this->params === null) {
            $this->params = $event->getArgument('params') ?? new \Joomla\Registry\Registry();
        }

        try {
            $db = $this->getDatabase();
            $retentionYears = (int) $this->params->get('retention_years', 10);
            
            // Calculate cutoff date
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionYears} years"));
            
            $this->logTask("Retention period: {$retentionYears} years");
            $this->logTask("Cutoff date: {$cutoffDate}");
            
            // Find users with all orders older than retention period
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
            $partialCount = 0;
            $errorCount = 0;
            
            foreach ($userIds as $userId) {
                try {
                    $hasLifetime = $this->hasLifetimeLicense($userId);
                    
                    if ($hasLifetime) {
                        // Partial anonymization: keep email for license activation
                        $this->partialAnonymizeUserData($userId);
                        $partialCount++;
                        $this->logTask("Partially anonymized data for user ID: {$userId} (has lifetime license)");
                    } else {
                        // Full anonymization
                        $this->anonymizeUserData($userId);
                        $anonymizedCount++;
                        $this->logTask("Fully anonymized data for user ID: {$userId}");
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logTask("Error anonymizing user ID {$userId}: " . $e->getMessage(), 'error');
                }
            }
            
            $this->logTask("Cleanup complete: {$anonymizedCount} fully anonymized, {$partialCount} partially anonymized, {$errorCount} errors");
            
            if ($errorCount > 0 && $anonymizedCount === 0) {
                return Status::KNOCKOUT;
            }
            
            return Status::OK;
            
        } catch (\Exception $e) {
            $this->logTask('Fatal error: ' . $e->getMessage(), 'error');
            return Status::KNOCKOUT;
        }
    }

    /**
     * Check if user has lifetime licenses via J2Store Custom Field
     *
     * @param   int  $userId  The user ID
     *
     * @return  bool
     *
     * @since   1.0.0
     */
    protected function hasLifetimeLicense(int $userId): bool
    {
        $db = $this->getDatabase();

        $tables      = $db->getTableList();
        $prefix      = $db->getPrefix();
        if ($this->isJ2Commerce4()) {
            // J2Commerce 4: optional manually-created table #__j2store_product_customfields.
            $customTable = 'j2store_product_customfields';
            if (!in_array($prefix . $customTable, $tables, true)) {
                return false;
            }

            $ordersTable     = '#__j2store_orders';
            $orderitemsTable = '#__j2store_orderitems';

            $query = $this->createDbQuery()
                ->select('DISTINCT oi.product_id')
                ->from($db->quoteName($ordersTable, 'o'))
                ->leftJoin(
                    $db->quoteName($orderitemsTable, 'oi') .
                    ' ON ' . $db->quoteName('o.order_id') . ' = ' . $db->quoteName('oi.order_id')
                )
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->where($db->quoteName('oi.product_id') . ' IS NOT NULL')
                ->bind(':userid', $userId, \Joomla\Database\ParameterType::INTEGER);

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

        // J2Commerce 6: lifetime-licence flag is stored in #__j2commerce_metafields.
        // Schema (verified against J2Commerce 6 install.mysql.utf8.sql):
        //   owner_id INT, owner_resource VARCHAR, metakey VARCHAR, metavalue TEXT
        // FK in #__j2commerce_orderitems is order_id (VARCHAR), not j2commerce_order_id.
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
                    ' ON '  . $db->quoteName('mf.owner_id')      . ' = '  . $db->quoteName('oi.product_id') .
                    ' AND ' . $db->quoteName('mf.owner_resource') . ' = '  . $db->quote('product') .
                    ' AND ' . $db->quoteName('mf.metakey')        . ' = '  . $db->quote('is_lifetime_license') .
                    ' AND LOWER(TRIM(' . $db->quoteName('mf.metavalue') . ')) = ' . $db->quote('yes')
                )
                ->where($db->quoteName('o.user_id') . ' = :userid')
                ->bind(':userid', $userId, \Joomla\Database\ParameterType::INTEGER);

            $db->setQuery($query);

            return (int) $db->loadResult() > 0;
        } catch (\Exception $e) {
            // Fail-closed: treat as lifetime license on DB error to prevent
            // accidental anonymization during automatic cleanup.
            $this->logTask('hasLifetimeLicense J6 query failed — treating as lifetime license: ' . $e->getMessage());

            return true;
        }
    }

    /**
     * Partially anonymize user data (keep email for lifetime licenses)
     *
     * @param   int  $userId  The user ID
     *
     * @return  void
     *
     * @since   1.0.0
     */
    protected function partialAnonymizeUserData(int $userId): void
    {
        $db         = $this->getDatabase();
        $safeUserId = (int) $userId;

        if ($this->params->get('anonymize_orders', 1)) {
            if ($this->isJ2Commerce4()) {
                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2store_orders'))
                    ->set([
                        $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                        $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
                $db->setQuery($query);
                $db->execute();

                $subQuery = $this->createDbQuery()
                    ->select($db->quoteName('order_id'))
                    ->from($db->quoteName('#__j2store_orders'))
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2store_orderinfos'))
                    ->set([
                        $db->quoteName('billing_first_name')   . ' = ' . $db->quote('Anonymized'),
                        $db->quoteName('billing_last_name')    . ' = ' . $db->quote('User'),
                        $db->quoteName('billing_middle_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_1')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_2')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_fax')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_1')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_2')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_city')         . ' = ' . $db->quote(''),
                        $db->quoteName('billing_zip')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_company')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_tax_number')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_first_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_last_name')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_middle_name') . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_1')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_2')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_fax')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_1')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_2')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_city')        . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_zip')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_company')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_tax_number')  . ' = ' . $db->quote(''),
                        $db->quoteName('all_billing')          . ' = ' . $db->quote(''),
                        $db->quoteName('all_shipping')         . ' = ' . $db->quote(''),
                        $db->quoteName('all_payment')          . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
                $db->setQuery($query);
                $db->execute();
            } else {
                // J2Commerce 6 — billing/shipping data is in #__j2commerce_orderinfos
                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2commerce_orders'))
                    ->set([
                        $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                        $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
                $db->setQuery($query);
                $db->execute();

                $subQuery = $this->createDbQuery()
                    ->select($db->quoteName('order_id'))
                    ->from($db->quoteName('#__j2commerce_orders'))
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2commerce_orderinfos'))
                    ->set([
                        $db->quoteName('billing_first_name')   . ' = ' . $db->quote('Anonymized'),
                        $db->quoteName('billing_last_name')    . ' = ' . $db->quote('User'),
                        $db->quoteName('billing_middle_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_1')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_2')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_fax')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_1')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_2')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_city')         . ' = ' . $db->quote(''),
                        $db->quoteName('billing_zip')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_company')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_tax_number')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_first_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_last_name')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_middle_name') . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_1')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_2')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_fax')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_1')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_2')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_city')        . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_zip')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_company')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_tax_number')  . ' = ' . $db->quote(''),
                        $db->quoteName('all_billing')          . ' = ' . $db->quote(''),
                        $db->quoteName('all_shipping')         . ' = ' . $db->quote(''),
                        $db->quoteName('all_payment')          . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
                $db->setQuery($query);
                $db->execute();
            }
        }

        if ($this->params->get('delete_addresses', 1)) {
            $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
            $query = $this->createDbQuery()
                ->delete($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
            $db->setQuery($query);
            $db->execute();
        }
    }

    protected function anonymizeUserData(int $userId): void
    {
        $db         = $this->getDatabase();
        $safeUserId = (int) $userId;

        if ($this->params->get('anonymize_orders', 1)) {
            if ($this->isJ2Commerce4()) {
                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2store_orders'))
                    ->set([
                        $db->quoteName('user_email') . ' = ' . $db->quote('anonymized@deleted.invalid'),
                        $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                        $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
                $db->setQuery($query);
                $db->execute();

                $subQuery = $this->createDbQuery()
                    ->select($db->quoteName('order_id'))
                    ->from($db->quoteName('#__j2store_orders'))
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2store_orderinfos'))
                    ->set([
                        $db->quoteName('billing_first_name')   . ' = ' . $db->quote('Anonymized'),
                        $db->quoteName('billing_last_name')    . ' = ' . $db->quote('User'),
                        $db->quoteName('billing_middle_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_1')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_2')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_fax')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_1')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_2')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_city')         . ' = ' . $db->quote(''),
                        $db->quoteName('billing_zip')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_company')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_tax_number')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_first_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_last_name')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_middle_name') . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_1')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_2')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_fax')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_1')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_2')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_city')        . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_zip')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_company')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_tax_number')  . ' = ' . $db->quote(''),
                        $db->quoteName('all_billing')          . ' = ' . $db->quote(''),
                        $db->quoteName('all_shipping')         . ' = ' . $db->quote(''),
                        $db->quoteName('all_payment')          . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
                $db->setQuery($query);
                $db->execute();
            } else {
                // J2Commerce 6 — billing/shipping data is in #__j2commerce_orderinfos
                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2commerce_orders'))
                    ->set([
                        $db->quoteName('user_email') . ' = ' . $db->quote('anonymized@deleted.invalid'),
                        $db->quoteName('customer_note') . ' = ' . $db->quote(''),
                        $db->quoteName('ip_address') . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
                $db->setQuery($query);
                $db->execute();

                $subQuery = $this->createDbQuery()
                    ->select($db->quoteName('order_id'))
                    ->from($db->quoteName('#__j2commerce_orders'))
                    ->where($db->quoteName('user_id') . ' = ' . $safeUserId);

                $query = $this->createDbQuery()
                    ->update($db->quoteName('#__j2commerce_orderinfos'))
                    ->set([
                        $db->quoteName('billing_first_name')   . ' = ' . $db->quote('Anonymized'),
                        $db->quoteName('billing_last_name')    . ' = ' . $db->quote('User'),
                        $db->quoteName('billing_middle_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_1')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_phone_2')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_fax')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_1')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_address_2')    . ' = ' . $db->quote(''),
                        $db->quoteName('billing_city')         . ' = ' . $db->quote(''),
                        $db->quoteName('billing_zip')          . ' = ' . $db->quote(''),
                        $db->quoteName('billing_company')      . ' = ' . $db->quote(''),
                        $db->quoteName('billing_tax_number')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_first_name')  . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_last_name')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_middle_name') . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_1')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_phone_2')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_fax')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_1')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_address_2')   . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_city')        . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_zip')         . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_company')     . ' = ' . $db->quote(''),
                        $db->quoteName('shipping_tax_number')  . ' = ' . $db->quote(''),
                        $db->quoteName('all_billing')          . ' = ' . $db->quote(''),
                        $db->quoteName('all_shipping')         . ' = ' . $db->quote(''),
                        $db->quoteName('all_payment')          . ' = ' . $db->quote(''),
                    ])
                    ->where($db->quoteName('order_id') . ' IN (' . $subQuery . ')');
                $db->setQuery($query);
                $db->execute();
            }
        }

        if ($this->params->get('delete_addresses', 1)) {
            $table = $this->isJ2Commerce4() ? '#__j2store_addresses' : '#__j2commerce_addresses';
            $query = $this->createDbQuery()
                ->delete($db->quoteName($table))
                ->where($db->quoteName('user_id') . ' = ' . $safeUserId);
            $db->setQuery($query);
            $db->execute();
        }
    }
}
