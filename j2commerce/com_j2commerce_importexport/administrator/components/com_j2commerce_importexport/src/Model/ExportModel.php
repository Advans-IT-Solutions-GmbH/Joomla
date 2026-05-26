<?php
/**
 * @package     J2Commerce Import/Export Component
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Model;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;

class ExportModel extends BaseDatabaseModel
{
    /** @var bool|null Cached J2Commerce 6 detection result */
    private ?bool $isJ6 = null;

    /**
     * Detect J2Commerce 6 by checking for #__j2commerce_products.
     */
    private function isJ2Commerce6(): bool
    {
        if ($this->isJ6 === null) {
            $db = $this->getDatabase();
            $this->isJ6 = in_array($db->getPrefix() . 'j2commerce_products', $db->getTableList(), true);
        }
        return $this->isJ6;
    }

    /**
     * Return table name — j2store_ prefix for J2Commerce 4, j2commerce_ for J6.
     */
    private function t(string $suffix): string
    {
        return $this->isJ2Commerce6() ? '#__j2commerce_' . $suffix : '#__j2store_' . $suffix;
    }

    /**
     * Return column name — replaces j2store_ prefix with j2commerce_ on J6.
     */
    private function col(string $column): string
    {
        if ($this->isJ2Commerce6()) {
            return str_replace('j2store_', 'j2commerce_', $column);
        }
        return $column;
    }

    /**
     * Create a query object compatible with Joomla 5 and 6.
     */
    private function createDbQuery(): \Joomla\Database\QueryInterface
    {
        $db = $this->getDatabase();
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Export data based on type
     */
    public function exportData(string $type): array
    {
        switch ($type) {
            case 'products_full':
                return $this->exportProductsFull();
            case 'products':
                return $this->exportProducts();
            case 'categories':
                return $this->exportCategories();
            case 'variants':
                return $this->exportVariants();
            case 'prices':
                return $this->exportPrices();
            default:
                throw new \Exception('Invalid export type');
        }
    }

    /**
     * Export complete products with all related data
     */
    protected function exportProductsFull(): array
    {
        $db = $this->getDatabase();
        $products = [];

        // Get all J2Store products with Joomla article data
        $query = $this->createDbQuery()
            ->select([
                'p.*',
                'c.id AS article_id',
                'c.title',
                'c.alias',
                'c.introtext',
                'c.fulltext',
                'c.state AS article_state',
                'c.catid',
                'c.created',
                'c.created_by',
                'c.modified',
                'c.publish_up',
                'c.publish_down',
                'c.images AS article_images',
                'c.urls',
                'c.attribs',
                'c.metakey',
                'c.metadesc',
                'c.metadata',
                'c.access',
                'c.featured',
                'c.language',
                'c.ordering AS article_ordering',
                'cat.title AS category_title',
                'cat.alias AS category_alias',
                'cat.path AS category_path'
            ])
            ->from($db->quoteName($this->t('products'), 'p'))
            ->leftJoin($db->quoteName('#__content', 'c') . ' ON c.id = p.product_source_id')
            ->leftJoin($db->quoteName('#__categories', 'cat') . ' ON cat.id = c.catid')
            ->where('p.product_source = ' . $db->quote('com_content'))
            ->order('p.' . $this->col('j2store_product_id') . ' ASC');

        $db->setQuery($query);
        $baseProducts = $db->loadAssocList();

        foreach ($baseProducts as $product) {
            $productId = (int) $product[$this->col('j2store_product_id')];
            $articleId = (int) $product['article_id'];

            // Get variants
            $product['variants'] = $this->getProductVariants($productId);

            // Get images
            $product['product_images'] = $this->getProductImages($productId);

            // Get options
            $product['options'] = $this->getProductOptions($productId);

            // Get filters
            $product['filters'] = $this->getProductFilters($productId);

            // Get files/downloads
            $product['files'] = $this->getProductFiles($productId);

            // Get tags
            $product['tags'] = $this->getArticleTags($articleId);

            // Get menu item
            $product['menu_item'] = $this->getArticleMenuItem($articleId);

            // Get custom fields
            $product['custom_fields'] = $this->getArticleCustomFields($articleId);

            // Get metafields
            $product['metafields'] = $this->getProductMetafields($productId);

            $products[] = $product;
        }

        return $products;
    }

    /**
     * Get product variants with quantities and prices
     */
    protected function getProductVariants(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['v.*', 'q.quantity', 'q.on_hold', 'q.sold AS qty_sold'])
            ->from($db->quoteName($this->t('variants'), 'v'))
            ->leftJoin($db->quoteName($this->t('productquantities'), 'q') . ' ON q.variant_id = v.' . $this->col('j2store_variant_id'))
            ->where('v.product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER)
            ->order('v.is_master DESC, v.' . $this->col('j2store_variant_id') . ' ASC');

        $db->setQuery($query);
        $variants = $db->loadAssocList();

        // Get tier prices for each variant
        foreach ($variants as &$variant) {
            $variantId = (int) $variant[$this->col('j2store_variant_id')];
            $variant['tier_prices'] = $this->getVariantPrices($variantId);
        }

        return $variants;
    }

    /**
     * Get variant tier prices
     */
    protected function getVariantPrices(int $variantId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('product_prices')))
            ->where('variant_id = :variantid')
            ->bind(':variantid', $variantId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get product images
     */
    protected function getProductImages(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('productimages')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssoc() ?: [];
    }

    /**
     * Get product options with values
     */
    protected function getProductOptions(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select([
                'po.*',
                'o.option_unique_name',
                'o.option_name',
                'o.type AS option_type'
            ])
            ->from($db->quoteName($this->t('product_options'), 'po'))
            ->leftJoin($db->quoteName($this->t('options'), 'o') . ' ON o.' . $this->col('j2store_option_id') . ' = po.option_id')
            ->where('po.product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER)
            ->order('po.ordering ASC');

        $db->setQuery($query);
        $options = $db->loadAssocList();

        // Get option values for each option
        foreach ($options as &$option) {
            $productOptionId = (int) $option[$this->col('j2store_productoption_id')];
            $option['values'] = $this->getProductOptionValues($productOptionId);
        }

        return $options;
    }

    /**
     * Get product option values
     */
    protected function getProductOptionValues(int $productOptionId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select([
                'pov.*',
                'ov.optionvalue_name'
            ])
            ->from($db->quoteName($this->t('product_optionvalues'), 'pov'))
            ->leftJoin($db->quoteName($this->t('optionvalues'), 'ov') . ' ON ov.' . $this->col('j2store_optionvalue_id') . ' = pov.optionvalue_id')
            ->where('pov.productoption_id = :productoptionid')
            ->bind(':productoptionid', $productOptionId, \Joomla\Database\ParameterType::INTEGER)
            ->order('pov.ordering ASC');

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get product filters
     */
    protected function getProductFilters(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select([
                'pf.*',
                'f.filter_name',
                'fg.group_name AS filter_group_name'
            ])
            ->from($db->quoteName($this->t('product_filters'), 'pf'))
            ->leftJoin($db->quoteName($this->t('filters'), 'f') . ' ON f.' . $this->col('j2store_filter_id') . ' = pf.filter_id')
            ->leftJoin($db->quoteName($this->t('filtergroups'), 'fg') . ' ON fg.' . $this->col('j2store_filtergroup_id') . ' = f.group_id')
            ->where('pf.product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get product files (downloads)
     */
    protected function getProductFiles(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('productfiles')))
            ->where('product_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get article tags
     */
    protected function getArticleTags(int $articleId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['t.id', 't.title', 't.alias'])
            ->from($db->quoteName('#__tags', 't'))
            ->innerJoin($db->quoteName('#__contentitem_tag_map', 'tm') . ' ON tm.tag_id = t.id')
            ->where('tm.content_item_id = :articleid')
            ->where('tm.type_alias = ' . $db->quote('com_content.article'))
            ->bind(':articleid', $articleId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get article menu item
     */
    protected function getArticleMenuItem(int $articleId): ?array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName('#__menu'))
            ->where('link LIKE ' . $db->quote('%option=com_content&view=article&id=' . $articleId . '%'))
            ->where('client_id = 0');

        $db->setQuery($query);
        return $db->loadAssoc() ?: null;
    }

    /**
     * Get article custom fields
     */
    protected function getArticleCustomFields(int $articleId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select(['f.name', 'f.title AS field_title', 'f.type AS field_type', 'fv.value'])
            ->from($db->quoteName('#__fields_values', 'fv'))
            ->innerJoin($db->quoteName('#__fields', 'f') . ' ON f.id = fv.field_id')
            ->where('fv.item_id = :articleid')
            ->where('f.context = ' . $db->quote('com_content.article'))
            ->bind(':articleid', $articleId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Get product metafields
     */
    protected function getProductMetafields(int $productId): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('metafields')))
            ->where('owner_id = :productid')
            ->bind(':productid', $productId, \Joomla\Database\ParameterType::INTEGER);

        $db->setQuery($query);
        return $db->loadAssocList() ?: [];
    }

    /**
     * Export basic products (J2Store only)
     */
    protected function exportProducts(): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('products')))
            ->order($this->col('j2store_product_id') . ' ASC');

        $db->setQuery($query);
        return $db->loadAssocList();
    }

    /**
     * Export categories
     */
    protected function exportCategories(): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName('#__categories'))
            ->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'))
            ->order('lft ASC');

        $db->setQuery($query);
        return $db->loadAssocList();
    }

    /**
     * Export variants
     */
    protected function exportVariants(): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('variants')))
            ->order($this->col('j2store_variant_id') . ' ASC');

        $db->setQuery($query);
        return $db->loadAssocList();
    }

    /**
     * Export prices
     */
    protected function exportPrices(): array
    {
        $db = $this->getDatabase();

        $query = $this->createDbQuery()
            ->select('*')
            ->from($db->quoteName($this->t('product_prices')))
            ->order($this->col('j2store_productprice_id') . ' ASC');

        $db->setQuery($query);
        return $db->loadAssocList();
    }
}
