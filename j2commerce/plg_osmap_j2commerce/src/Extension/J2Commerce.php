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
use Joomla\CMS\Uri\Uri;
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
     * The link is passed as an index.php?... URL so OSMap routes it through
     * Joomla's router, which produces the correct absolute SEF URL including
     * the language prefix (e.g. https://advans.ch/de/shop/product-alias).
     * Using the raw menu path (/shop/...) skips the router and omits /de/.
     */
    public function getTree(Collector $collector, Item $parent, Registry $params): void
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                $db->quoteName('m.id'),
                $db->quoteName('m.path'),
                $db->quoteName('m.language'),
                $db->quoteName('m.browserNav'),
                $db->quoteName('a.modified'),
                $db->quoteName('a.title'),
                $db->quoteName('l.sef', 'lang_sef'),
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
            ->join('LEFT', $db->quoteName('#__languages', 'l')
                . ' ON l.lang_code = m.language')
            ->where([
                'm.published = -2',
                'm.parent_id = ' . (int) $parent->id,
                'm.client_id = 0',
            ])
            ->order('a.title ASC');

        $products = $db->setQuery($query)->loadObjectList();

        foreach ($products as $product) {
            // Build the absolute SEF URL directly from the menu path and
            // language SEF prefix. The published=-2 menu items are invisible
            // to Joomla's router, so routing index.php?... does not work.
            // Example: lang_sef=de, path=shop/product-alias → /de/shop/product-alias
            $langPrefix = $product->lang_sef ? $product->lang_sef . '/' : '';
            $link       = Uri::root() . $langPrefix . $product->path;

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
}
