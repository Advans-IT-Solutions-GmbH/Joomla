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
 * Which J2Commerce data set this site uses: J2Commerce 6 (#__j2commerce_*) or
 * J2Store / J2Commerce 4 (#__j2store_*).
 *
 * The enabled component decides, not the presence of tables: the official migration to
 * J2Commerce 6 keeps the #__j2store_* source tables, so both sets can exist.
 *
 * 1. com_j2commerce enabled and #__j2commerce_orders exists  -> J2Commerce 6
 * 2. com_j2store enabled and #__j2store_orders exists        -> J2Store / J2Commerce 4
 * 3. no enabled component: #__j2commerce_orders exists       -> J2Commerce 6, else J2Store
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

    /** Forget cached results (tests, installer). */
    public static function reset(): void
    {
        self::$cache = [];
    }

    private static function detect(DatabaseInterface $db): bool
    {
        $tables  = $db->getTableList();
        $prefix  = $db->getPrefix();
        $hasJ6   = \in_array($prefix . 'j2commerce_orders', $tables, true);
        $hasJ4   = \in_array($prefix . 'j2store_orders', $tables, true);
        $enabled = [];

        try {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('element') . ' IN (' . $db->quote('com_j2commerce') . ', ' . $db->quote('com_j2store') . ')');
            $db->setQuery($query);
            $enabled = $db->loadColumn() ?: [];
        } catch (\Throwable $e) {
            $enabled = [];
        }

        if ($hasJ6 && \in_array('com_j2commerce', $enabled, true)) {
            return false;
        }

        if ($hasJ4 && \in_array('com_j2store', $enabled, true)) {
            return true;
        }

        return !$hasJ6 && $hasJ4;
    }
}
