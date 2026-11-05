<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * The address of the site's privacy notice, for the notice in the information column and the
 * label of the privacy checkbox. One definition for the booking page and the booking controller,
 * so the label the visitor saw and the label a consent is recorded for cannot differ.
 */
final class PrivacyLink
{
    /**
     * The configured menu item (in the visitor's language when the item has a language
     * association), with its optional anchor, else the configured address, else ''. A menu item
     * the visitor cannot open is never linked. The address is not HTML-escaped.
     */
    public static function resolve(Registry $params): string
    {
        $app = Factory::getApplication();
        $itemId = (int) $params->get('privacy_menuitem', 0);

        if ($itemId > 0) {
            if (Associations::isEnabled()) {
                $associated = Associations::getAssociations('com_menus', '#__menu', 'com_menus.item', $itemId, 'id', '', '');
                $itemId = (int) ($associated[$app->getLanguage()->getTag()]->id ?? $itemId);
            }

            $menuItem = $app->getMenu()->getItem($itemId);

            if ($menuItem !== null && in_array((int) $menuItem->access, array_map('intval', $app->getIdentity()->getAuthorisedViewLevels()), true)) {
                $anchor = trim((string) $params->get('privacy_anchor', ''), " #\t");

                return Route::_('index.php?Itemid=' . $itemId, false) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
            }
        }

        return trim((string) $params->get('privacy_url', ''));
    }
}
