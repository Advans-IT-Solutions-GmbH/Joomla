<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

namespace Advans\Plugin\Osmap\J2Commerce\Extension;

defined('_JEXEC') or die;

use Alledia\OSMap\Sitemap\Collector;
use Alledia\OSMap\Sitemap\Item;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

/**
 * OSMap plugin for J2Store and J2Commerce.
 *
 * OSMap calls getComponentElement() to match this plugin against menu items,
 * then calls getTree() for each matching menu item to collect sitemap nodes.
 *
 * Two sitemap mechanisms are supported:
 *
 * 1. Standard menu items (view=products / view=product / view=categories)
 *    The primary mechanism. getTree() inspects the menu item's view parameter
 *    and queries #__content joined with the products table to find enabled
 *    products. Works on any standard J2Store or J2Commerce installation.
 *
 * 2. Hidden menu items (published=-2, site-specific)
 *    Fallback for installations that manually create hidden com_content menu
 *    items as children of the shop menu item, one per product. These items
 *    carry the SEF path directly (e.g. shop/<product-alias>). If no view
 *    parameter is found on the parent item, getTree() queries #__menu for
 *    published=-2 children and uses their path field as the sitemap URL.
 *
 * Supported components: com_j2store (J2Store) and com_j2commerce (J2Commerce).
 * The plugin registers itself for com_j2store by default. A second subclass
 * (J2CommerceNew) handles com_j2commerce and uses the j2commerce_products table.
 *
 * OSMap discovers plugins by calling getComponentElement() and getTree() —
 * no methods from Alledia\OSMap\Plugin\Base are used. Extending CMSPlugin
 * directly avoids a hard dependency on OSMap's internal class hierarchy,
 * which is not available during Joomla's plugin update/install process.
 */
class J2Commerce extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * The Joomla component option this instance handles.
     * Subclass J2CommerceNew overrides this to 'com_j2commerce'.
     */
    protected string $component = 'com_j2store';

    /**
     * Products table — #__j2store_products for J2Store, #__j2commerce_products for J2Commerce.
     */
    protected string $productsTable = '#__j2store_products';

    public static function getSubscribedEvents(): array
    {
        return [];
    }

    public function getComponentElement(): string
    {
        return $this->component;
    }

    /**
     * Called by OSMap for each menu item whose option matches getComponentElement().
     *
     * Primary: view-based queries for standard J2Store/J2Commerce menu items.
     * Fallback: published=-2 hidden children for installations that manually
     * create hidden com_content menu items per product (site-specific pattern).
     */
    public function getTree(Collector $collector, Item $parent, Registry $params): void
    {
        parse_str(parse_url($parent->link ?? '', PHP_URL_QUERY) ?? '', $query);

        $view  = $query['view'] ?? '';
        $catid = isset($query['catid']) ? (int) $query['catid'] : null;
        $id    = isset($query['id'])    ? (int) $query['id']    : null;

        switch ($view) {
            case 'product':
                if ($id) {
                    $this->emitSingleProduct($collector, $parent, $params, $id);
                }
                return;

            case 'products':
                $this->emitProductsForCategory($collector, $parent, $params, $catid);
                return;

            case 'categories':
                $this->emitAllProducts($collector, $parent, $params);
                return;
        }

        // Fallback: look for manually created published=-2 hidden menu children.
        $parentId = (int) ($parent->id ?? 0);

        if ($parentId > 0) {
            $this->emitHiddenMenuChildren($collector, $parent, $params, $parentId);
        }
    }

    // -------------------------------------------------------------------------
    // Mechanism 1: J2Store published=-2 hidden menu children
    // -------------------------------------------------------------------------

    /**
     * Queries #__menu for published=-2 children of $parentId, joins #__content
     * and the products table to verify each product is enabled, then emits one
     * sitemap node per product using the menu item's SEF path as the URL.
     *
     * Returns true if at least one node was emitted, false if no children found.
     */
    protected function emitHiddenMenuChildren(
        Collector $collector,
        Item $parent,
        Registry $params,
        int $parentId
    ): bool {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('m.id'),
                $db->quoteName('m.path'),
                $db->quoteName('m.browserNav'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.title'),
            ])
            ->from($db->quoteName('#__menu', 'm'))
            ->join(
                'INNER',
                $db->quoteName('#__content', 'a')
                . ' ON (m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id, ' . $db->quote('&%') . ')'
                . '  OR m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id))'
                . ' AND m.link LIKE ' . $db->quote('%com_content%view=article%')
            )
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
            )
            ->where($db->quoteName('m.published') . ' = -2')
            ->where($db->quoteName('m.parent_id') . ' = :parentId')
            ->where($db->quoteName('m.client_id') . ' = 0')
            ->bind(':parentId', $parentId, ParameterType::INTEGER)
            ->order($db->quoteName('a.title') . ' ASC');

        $items = $db->setQuery($query)->loadObjectList() ?: [];

        foreach ($items as $item) {
            $this->printMenuPathNode($collector, $parent, $params, $item);
        }

        return count($items) > 0;
    }

    // -------------------------------------------------------------------------
    // Mechanism 2: J2Commerce 4+ view-based queries
    // -------------------------------------------------------------------------

    protected function emitSingleProduct(
        Collector $collector,
        Item $parent,
        Registry $params,
        int $articleId
    ): void {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.alias'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.catid'),
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
            )
            ->where($db->quoteName('a.id') . ' = :id')
            ->where($db->quoteName('a.state') . ' = 1')
            ->bind(':id', $articleId, ParameterType::INTEGER);

        $product = $db->setQuery($query)->loadObject();

        if ($product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    protected function emitProductsForCategory(
        Collector $collector,
        Item $parent,
        Registry $params,
        ?int $catid
    ): void {
        foreach ($this->loadProducts($catid) as $product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    protected function emitAllProducts(
        Collector $collector,
        Item $parent,
        Registry $params
    ): void {
        foreach ($this->loadProducts(null) as $product) {
            $this->printProductNode($collector, $parent, $params, $product);
        }
    }

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    /**
     * @return object[]
     */
    protected function loadProducts(?int $catid): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.alias'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.catid'),
            ])
            ->from($db->quoteName('#__content', 'a'))
            ->join(
                'INNER',
                $db->quoteName($this->productsTable, 'p')
                . ' ON ' . $db->quoteName('p.product_source_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('p.enabled') . ' = 1'
            )
            ->where($db->quoteName('a.state') . ' = 1')
            ->order($db->quoteName('a.title') . ' ASC');

        if ($catid !== null) {
            $query->where($db->quoteName('a.catid') . ' = :catid')
                  ->bind(':catid', $catid, ParameterType::INTEGER);
        }

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    // -------------------------------------------------------------------------
    // Node output
    // -------------------------------------------------------------------------

    /**
     * Emits a sitemap node using the menu item's SEF path directly as the URL.
     * Used for J2Store's published=-2 hidden menu items, where the path field
     * already contains the correct SEF URL (e.g. "shop/product-alias").
     */
    protected function printMenuPathNode(
        Collector $collector,
        Item $parent,
        Registry $params,
        object $item
    ): void {
        if (empty($item->path)) {
            return;
        }

        $node = (object) [
            'id'         => $item->id,
            'name'       => $item->title,
            'uid'        => 'j2commerce.product.' . $item->id,
            'modified'   => $item->modified,
            'browserNav' => $item->browserNav ?? $parent->browserNav,
            'priority'   => $params->get('priority', '0.8'),
            'changefreq' => $params->get('changefreq', 'weekly'),
            'link'       => $item->path,
            'expandible' => false,
        ];

        $collector->printNode($node);
    }

    /**
     * Builds a product URL via the component's view=product route and emits
     * the node. Used for J2Commerce 4+ view-based menu items.
     */
    protected function printProductNode(
        Collector $collector,
        Item $parent,
        Registry $params,
        object $product
    ): void {
        // Build a non-SEF URL; OSMap will apply SEF routing if configured.
        $link = sprintf(
            'index.php?option=%s&view=product&id=%d:%s&catid=%d&Itemid=%d',
            $this->component,
            (int) $product->id,
            rawurlencode($product->alias),
            (int) $product->catid,
            (int) $parent->id
        );

        $node = (object) [
            'id'         => $product->id,
            'name'       => $product->title,
            'uid'        => 'j2commerce.product.' . $product->id,
            'modified'   => $product->modified,
            'browserNav' => $parent->browserNav,
            'priority'   => $params->get('priority', '0.8'),
            'changefreq' => $params->get('changefreq', 'weekly'),
            'link'       => $link,
            'expandible' => false,
        ];

        $collector->printNode($node);
    }
}
