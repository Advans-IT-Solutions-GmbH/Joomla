<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Retention
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Retention;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Orders that contain a product flagged as lifetime license (J2Commerce 4:
 * #__j2store_product_customfields, J2Commerce 6: #__j2commerce_metafields; metakey/field_name
 * is_lifetime_license, value "yes", case-insensitive).
 *
 * Provisional rule (pending confirmation by the maintainer): when such an order is anonymized,
 * by a removal request or by the cleanup task, its order e-mail address is kept for license
 * reactivation; everything else is handled like any other order.
 */
final class LifetimeLicenses
{
    /**
     * Order numbers among $orderIds with a lifetime-license product. Throws on database errors;
     * callers treat that as "all orders are lifetime licenses" (fail-closed).
     *
     * @param   string[]  $orderIds  Order numbers (#__*_orders.order_id)
     *
     * @return  string[]
     */
    public static function orderIds(DatabaseInterface $db, bool $isJ2Commerce4, array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('strval', $orderIds), 'strlen')));

        if ($orderIds === []) {
            return [];
        }

        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $query  = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $in     = implode(',', array_map([$db, 'quote'], $orderIds));

        if ($isJ2Commerce4) {
            if (!\in_array($prefix . 'j2store_product_customfields', $tables, true)) {
                return [];
            }

            $query->select('DISTINCT ' . $db->quoteName('oi.order_id'))
                ->from($db->quoteName('#__j2store_orderitems', 'oi'))
                ->join(
                    'INNER',
                    $db->quoteName('#__j2store_product_customfields', 'cf')
                    . ' ON ' . $db->quoteName('cf.product_id') . ' = ' . $db->quoteName('oi.product_id')
                    . ' AND ' . $db->quoteName('cf.field_name') . ' = ' . $db->quote('is_lifetime_license')
                    . ' AND LOWER(TRIM(' . $db->quoteName('cf.field_value') . ')) = ' . $db->quote('yes')
                )
                ->where($db->quoteName('oi.order_id') . ' IN (' . $in . ')');
        } else {
            if (!\in_array($prefix . 'j2commerce_metafields', $tables, true)) {
                return [];
            }

            $query->select('DISTINCT ' . $db->quoteName('oi.order_id'))
                ->from($db->quoteName('#__j2commerce_orderitems', 'oi'))
                ->join(
                    'INNER',
                    $db->quoteName('#__j2commerce_metafields', 'mf')
                    . ' ON ' . $db->quoteName('mf.owner_id') . ' = ' . $db->quoteName('oi.product_id')
                    . ' AND ' . $db->quoteName('mf.owner_resource') . ' = ' . $db->quote('product')
                    . ' AND ' . $db->quoteName('mf.metakey') . ' = ' . $db->quote('is_lifetime_license')
                    . ' AND LOWER(TRIM(' . $db->quoteName('mf.metavalue') . ')) = ' . $db->quote('yes')
                )
                ->where($db->quoteName('oi.order_id') . ' IN (' . $in . ')');
        }

        $db->setQuery($query);

        return array_values(array_map('strval', $db->loadColumn() ?: []));
    }
}
