<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Shared J2Commerce version detection and table/column name helpers.
 *
 * The active shop component decides which table set is used, not the mere
 * presence of tables: after a migration both #__j2store_* and
 * #__j2commerce_* exist, and a J2Store site may still carry j2commerce
 * tables from an aborted installation. All table and column names are
 * resolved at runtime so the same code runs on both J2Store/J2Commerce 4
 * (#__j2store_*) and J2Commerce 6 (#__j2commerce_*).
 *
 * Requires the using class to implement getDatabase().
 */
trait J2CommerceAwareTrait
{
    /** @var string|null Cached active shop: 'j2commerce', 'j2store' or '' (none active) */
    private ?string $activeShopCache = null;

    /** @var bool|null Cached result of isJ2Commerce6(), including the fallback */
    private ?bool $isJ2Commerce6Cache = null;

    /**
     * Returns true when J2Commerce 6 is the shop to work with.
     *
     * Order: com_j2commerce enabled with its product table → J2Commerce 6;
     * otherwise com_j2store enabled with its product table → J2Store/J2Commerce 4;
     * otherwise (no active shop) fall back to the former table check.
     *
     * The decision is cached per instance: t() and col() call this for every
     * query, so the fallback must not repeat SHOW TABLES on each call.
     */
    private function isJ2Commerce6(): bool
    {
        if ($this->isJ2Commerce6Cache === null) {
            $shop = $this->getActiveShop();

            // No active shop component: keep the former behaviour so that exports
            // and imports on a site whose shop is temporarily disabled (or whose
            // extension rows are missing) still address the existing tables.
            // J2Commerce 6 wins when its product table exists, as before.
            $this->isJ2Commerce6Cache = $shop !== null
                ? $shop === 'j2commerce'
                : $this->shopTableExists('j2commerce_products');
        }

        return $this->isJ2Commerce6Cache;
    }

    /**
     * Determines the active shop component ('j2commerce' or 'j2store'), or
     * null when neither is enabled together with its product table.
     * The result is cached per instance.
     */
    private function getActiveShop(): ?string
    {
        if ($this->activeShopCache === null) {
            /** @var DatabaseInterface $db */
            $db    = $this->getDatabase();
            $query = $this->createDbQuery()
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->whereIn($db->quoteName('element'), ['com_j2commerce', 'com_j2store'], ParameterType::STRING);

            $enabled = $db->setQuery($query)->loadColumn() ?: [];

            $this->activeShopCache = '';

            if (in_array('com_j2commerce', $enabled, true) && $this->shopTableExists('j2commerce_products')) {
                $this->activeShopCache = 'j2commerce';
            } elseif (in_array('com_j2store', $enabled, true) && $this->shopTableExists('j2store_products')) {
                $this->activeShopCache = 'j2store';
            }
        }

        return $this->activeShopCache === '' ? null : $this->activeShopCache;
    }

    /**
     * Checks whether a table (name without prefix) exists.
     * Uses SHOW TABLES LIKE to avoid stale getTableList() cache (e.g. during install).
     * The LIKE wildcards _ and % in the name are escaped, so only the exact
     * table matches.
     */
    private function shopTableExists(string $table): bool
    {
        /** @var DatabaseInterface $db */
        $db      = $this->getDatabase();
        $pattern = $db->quote($db->escape($db->getPrefix() . $table, true), false);

        return !empty($db->setQuery('SHOW TABLES LIKE ' . $pattern)->loadResult());
    }

    /**
     * Returns the fully-qualified table name for the given suffix.
     *
     * Examples:
     *   t('products')          → #__j2store_products  (J4) / #__j2commerce_products  (J6)
     *   t('product_options')   → #__j2store_product_options / #__j2commerce_product_options
     */
    private function t(string $suffix): string
    {
        return $this->isJ2Commerce6()
            ? '#__j2commerce_' . $suffix
            : '#__j2store_' . $suffix;
    }

    /**
     * Returns the column name, replacing j2store_ with j2commerce_ on J6.
     *
     * Examples:
     *   col('j2store_product_id')  → j2commerce_product_id  (J6)
     *   col('j2store_variant_id')  → j2commerce_variant_id  (J6)
     */
    private function col(string $column): string
    {
        if ($this->isJ2Commerce6()) {
            return str_replace('j2store_', 'j2commerce_', $column);
        }

        return $column;
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(): QueryInterface
    {
        $db = $this->getDatabase();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
