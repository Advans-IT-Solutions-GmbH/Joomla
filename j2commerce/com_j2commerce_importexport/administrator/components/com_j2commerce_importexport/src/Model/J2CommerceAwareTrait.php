<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Model;

defined('_JEXEC') or die;

/**
 * Shared J2Commerce version detection and table/column name helpers.
 *
 * Detects J2Commerce 6 by checking for #__j2commerce_products in the
 * database. All table and column names are resolved at runtime so the
 * same code runs on both J2Commerce 4 (#__j2store_*) and J2Commerce 6
 * (#__j2commerce_*).
 *
 * Requires the using class to implement getDatabase().
 */
trait J2CommerceAwareTrait
{
    /** @var bool|null Cached detection result */
    private ?bool $isJ6Cache = null;

    /**
     * Returns true when J2Commerce 6 tables are present.
     * Uses SHOW TABLES LIKE to avoid stale getTableList() cache (e.g. during install).
     */
    private function isJ2Commerce6(): bool
    {
        if ($this->isJ6Cache === null) {
            $db             = $this->getDatabase();
            $result         = $db->setQuery('SHOW TABLES LIKE ' . $db->quote($db->getPrefix() . 'j2commerce_products'))->loadResult();
            $this->isJ6Cache = !empty($result);
        }

        return $this->isJ6Cache;
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
    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        $db = $this->getDatabase();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
