<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Support
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Support;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * J2Commerce data sets of this site: J2Commerce 6 (#__j2commerce_*) and J2Store / J2Commerce 4
 * (#__j2store_*). The official migration to J2Commerce 6 keeps the #__j2store_* source tables, so
 * both sets can exist.
 *
 * - isJ2Commerce4(): the shop that is active (display, recording consent, address actions); the
 *   same rule in all Advans J2Commerce extensions:
 *   1. com_j2commerce enabled and #__j2commerce_orders exists -> J2Commerce 6;
 *   2. otherwise com_j2store enabled and #__j2store_orders exists -> J2Store;
 *   3. otherwise (no enabled component with tables): #__j2commerce_orders exists -> J2Commerce 6,
 *      else J2Store.
 *   An enabled component without tables is skipped; an installed but disabled one does not count.
 * - dataSets(): every set that exists. Removal, anonymization, retention cleanup and export work on
 *   all of them, so no personal data survives in copies left behind by a migration and nothing is
 *   missed before the data transfer.
 */
final class J2CommerceStack
{
    /** @var array<string, bool> Result per database prefix */
    private static array $cache = [];

    public static function isJ2Commerce4(DatabaseInterface $db): bool
    {
        $key = $db->getPrefix();

        if (!array_key_exists($key, self::$cache)) {
            self::$cache[$key] = self::detect($db);
        }

        return self::$cache[$key];
    }

    /**
     * Existing data sets, the active one first.
     *
     * @return  list<bool>  isJ2Commerce4 value per set (false = #__j2commerce_*, true = #__j2store_*)
     */
    public static function dataSets(DatabaseInterface $db): array
    {
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $active = self::isJ2Commerce4($db);
        $sets   = [];

        foreach ([$active, !$active] as $isJ4) {
            if (\in_array($prefix . ($isJ4 ? 'j2store_orders' : 'j2commerce_orders'), $tables, true)) {
                $sets[] = $isJ4;
            }
        }

        return $sets;
    }

    /** Forget cached results (tests, installer). */
    public static function reset(): void
    {
        self::$cache = [];
    }

    private static function detect(DatabaseInterface $db): bool
    {
        $tables     = $db->getTableList();
        $prefix     = $db->getPrefix();
        $hasJ6      = \in_array($prefix . 'j2commerce_orders', $tables, true);
        $hasJ4      = \in_array($prefix . 'j2store_orders', $tables, true);
        $components = [];

        try {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName(['element', 'enabled']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('state') . ' >= 0')
                ->where($db->quoteName('element') . ' IN (' . $db->quote('com_j2commerce') . ', ' . $db->quote('com_j2store') . ')');
            $db->setQuery($query);

            foreach ($db->loadObjectList() ?: [] as $row) {
                $components[(string) $row->element] = (int) $row->enabled === 1;
            }
        } catch (\Throwable $e) {
            $components = [];
        }

        if ($hasJ6 && ($components['com_j2commerce'] ?? false)) {
            return false;
        }

        if ($hasJ4 && ($components['com_j2store'] ?? false)) {
            return true;
        }

        return !$hasJ6 && $hasJ4;
    }
}
