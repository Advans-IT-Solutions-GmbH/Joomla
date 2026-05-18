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
use Joomla\CMS\Router\Route;
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
 * Supported menu item types:
 *   - view=products  (product list, with optional catid filter)
 *   - view=product   (single product)
 *   - view=categories (full category tree)
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
     * Emits one sitemap node per enabled product reachable from this menu item.
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
                break;

            case 'products':
                $this->emitProductsForCategory($collector, $parent, $params, $catid);
                break;

            case 'categories':
                $this->emitAllProducts($collector, $parent, $params);
                break;
        }
    }

    // -------------------------------------------------------------------------
    // Emission helpers
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
     * Build the SEF URL via Joomla's router and emit the node to OSMap.
     *
     * Route::_() applies language prefixes, Itemid resolution and SEF rules
     * consistently with how J2Commerce/J2Store itself builds product URLs.
     */
    protected function printProductNode(
        Collector $collector,
        Item $parent,
        Registry $params,
        object $product
    ): void {
        $internalUrl = sprintf(
            'index.php?option=%s&view=product&id=%d:%s&catid=%d&Itemid=%d',
            $this->component,
            (int) $product->id,
            rawurlencode($product->alias),
            (int) $product->catid,
            (int) $parent->id
        );

        $link = Route::_($internalUrl, false);

        if (empty($link)) {
            return;
        }

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
