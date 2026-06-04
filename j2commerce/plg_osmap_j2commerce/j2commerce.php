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
 */
class PlgOsmapJ2commerce extends J2CommerceNew
{
    /**
     * Returns the component this plugin handles.
     * Detects which J2Commerce version is installed at runtime so OSMap
     * dispatches getTree() for the correct menu item component.
     */
    public function getComponentElement(): string
    {
        static $element = null;
        if ($element !== null) {
            return $element;
        }

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $q  = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $q->select('element')
              ->from('#__extensions')
              ->where('type = ' . $db->quote('component'))
              ->where('element IN (' . $db->quote('com_j2commerce') . ',' . $db->quote('com_j2store') . ')')
              ->where('enabled = 1')
              ->order('element ASC');  // ASC: 'com_j2commerce' < 'com_j2store' alphabetically,
                                      // so J6 takes precedence in a migration scenario where both are present.
            $element = $db->setQuery($q)->loadResult() ?: 'com_j2commerce';
        } catch (\Throwable $e) {
            $element = 'com_j2commerce';
        }

        return $element;
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
