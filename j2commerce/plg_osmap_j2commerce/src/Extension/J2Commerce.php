<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

namespace Advans\Plugin\Osmap\J2Commerce\Extension;

defined('_JEXEC') or die;

use Alledia\OSMap\Plugin\Base;
use Alledia\OSMap\Sitemap\Collector;
use Alledia\OSMap\Sitemap\Item;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;

class J2Commerce extends Base implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [];
    }

    public function getComponentElement(): string
    {
        return 'com_j2store';
    }

    /**
     * Called by OSMap for each menu item that belongs to com_j2store.
     * Emits one sitemap node per enabled product.
     *
     * Uses the path of the product's published=-2 menu item directly as the
     * link. This produces the same SEF URL (/de/shop/product-alias) that
     * J2Commerce's router generates, without relying on Joomla's router to
     * resolve a com_content Itemid that is hidden from routing.
     */
    public function getTree(Collector $collector, Item $parent, Registry $params): void
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('m.id'),
                $db->quoteName('m.path'),
                $db->quoteName('m.browserNav'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.title'),
            ])
            ->from($db->quoteName('#__menu', 'm'))
            ->join('INNER', $db->quoteName('#__content', 'a')
                . ' ON (m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id, ' . $db->quote('&%') . ')'
                . '  OR m.link LIKE CONCAT(' . $db->quote('%&id=') . ', a.id))'
                . ' AND m.link LIKE ' . $db->quote('%com_content%view=article%'))
            ->join('INNER', $db->quoteName('#__j2store_products', 'p')
                . ' ON p.product_source_id = a.id'
                . ' AND p.product_source = ' . $db->quote('com_content')
                . ' AND p.enabled = 1')
            ->where([
                'm.published = -2',
                'm.parent_id = ' . (int) $parent->id,
                'm.client_id = 0',
            ])
            ->order('a.title ASC');

        $products = $db->setQuery($query)->loadObjectList();

        foreach ($products as $product) {
            $node = (object) [
                'id'         => $product->id,
                'name'       => $product->title,
                'uid'        => 'j2commerce.product.' . $product->id,
                'modified'   => $product->modified,
                'browserNav' => $parent->browserNav,
                'priority'   => $params->get('priority', '0.8'),
                'changefreq' => $params->get('changefreq', 'weekly'),
                'link'       => $product->path,
                'expandible' => false,
            ];

            $collector->printNode($node);
        }
    }
}
