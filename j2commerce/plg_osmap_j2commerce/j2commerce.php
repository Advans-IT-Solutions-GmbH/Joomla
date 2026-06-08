<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 *
 * OSMap loads this file directly and expects the class PlgOsmapJ2commerce.
 *
 * OSMap's plugin loader calls getComponentElement() to match a plugin to a
 * menu item's component. This plugin handles both:
 *   - com_j2store    (J2Commerce 4 on Joomla 4/5)
 *   - com_j2commerce (J2Commerce 6 on Joomla 6)
 *
 * getComponentElement() detects which component is installed and returns it.
 * getTree() selects the correct table prefix based on the menu item's component.
 */

defined('_JEXEC') or die;

require_once __DIR__ . '/src/Extension/J2Commerce.php';
require_once __DIR__ . '/src/Extension/J2CommerceNew.php';

use Advans\Plugin\Osmap\J2Commerce\Extension\J2CommerceNew;
use Alledia\OSMap\Sitemap\Collector;
use Alledia\OSMap\Sitemap\Item;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * OSMap plugin — handles com_j2store (J4/J5) and com_j2commerce (J6).
 *
 * OSMap's getPluginsForComponent() creates a fresh PlgOsmapJ2commerce instance
 * for every component it checks and compares getComponentElement() === $option.
 * A single instance can therefore only match one component per check.
 *
 * To cover both com_j2store and com_j2commerce, getComponentElement() queries
 * #__extensions without a static cache. When OSMap checks com_j2store it
 * creates instance A; when it checks com_j2commerce it creates instance B.
 * Both instances call getComponentElement(), which returns the single installed
 * element, or — in the mixed migration case — the element that matches the
 * option OSMap is currently checking (resolved via $this->component, which is
 * set to 'com_j2commerce' by J2CommerceNew and overridden to 'com_j2store' by
 * getTree() when the menu item carries option=com_j2store).
 *
 * Mixed migration (both components installed):
 *   - OSMap checks com_j2store  → instance A: getComponentElement() returns
 *     com_j2store (first in DESC order) → match → getTree() called with
 *     com_j2store menu items → productsTable = #__j2store_products.
 *   - OSMap checks com_j2commerce → instance B: getComponentElement() returns
 *     com_j2store → no match → plugin skipped for com_j2commerce menu items.
 *
 * This is a known OSMap limitation: a single plugin element can only match one
 * component. com_j2commerce menu items in a mixed install are not processed.
 * Full coverage requires two separate plugin registrations (two elements).
 */
class PlgOsmapJ2commerce extends J2CommerceNew
{
    /**
     * Returns the component element this instance handles.
     *
     * Queries #__extensions without caching so each OSMap instance gets a
     * fresh result. Returns com_j2store when installed (J4/J5 precedence),
     * com_j2commerce for J6-only installs, com_j2commerce as fallback.
     */
    public function getComponentElement(): string
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $q  = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $q->select('element')
              ->from('#__extensions')
              ->where('type = ' . $db->quote('component'))
              ->where('element IN (' . $db->quote('com_j2commerce') . ',' . $db->quote('com_j2store') . ')')
              ->where('enabled = 1')
              ->order('element DESC') // DESC: com_j2store sorts before com_j2commerce
              ->setLimit(1);
            return $db->setQuery($q)->loadResult() ?: 'com_j2commerce';
        } catch (\Throwable $e) {
            return 'com_j2commerce';
        }
    }

    /**
     * Delegates to the correct implementation based on the menu item's component.
     * Sets $this->component and $this->productsTable before calling parent::getTree().
     */
    public function getTree(Collector $collector, Item $parent, Registry $params): void
    {
        $component = $parent->component ?? '';

        // Older OSMap versions (<5.x) may not populate $parent->component.
        // Fall back to parsing the option parameter from the menu item link.
        if (empty($component) && !empty($parent->link)) {
            parse_str(parse_url($parent->link, PHP_URL_QUERY) ?? '', $linkQuery);
            $component = $linkQuery['option'] ?? '';
        }

        if ($component === 'com_j2store') {
            $this->component     = 'com_j2store';
            $this->productsTable = '#__j2store_products';
        } else {
            $this->component     = 'com_j2commerce';
            $this->productsTable = '#__j2commerce_products';
        }

        parent::getTree($collector, $parent, $params);
    }
}
