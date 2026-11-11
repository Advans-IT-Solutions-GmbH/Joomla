<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * The way back of the public pages: the button «Back to appointments» (on a form «Cancel»).
 *
 * By default it leads to the list of all event types. A "Book an appointment" menu item points at
 * one event type, so on its pages the list would show a visitor the other event types the site
 * deliberately left out of that menu item. There the button is hidden by default; the menu item's
 * options can show it again and choose where it leads ("Navigation" tab).
 *
 * The booking page then has no button. The confirmation, the signed manage pages and their
 * receipts keep a way out within the branch: the menu item's own booking page.
 */
final class BackLink
{
    /** The list of all event types. */
    public const OVERVIEW = 'overview';
    /** A menu item: the configured target or, with the button hidden, the menu item itself. */
    public const ITEM = 'item';
    /** No button. */
    public const NONE = 'none';

    /**
     * Pure decision, testable without Joomla. $active is the active menu item or null.
     *
     * @param   bool  $bookingPage  Whether the page is the booking page (the event type view) itself.
     *
     * @return  array{0: string, 1: int}  One of the constants above and, for ITEM, the Itemid.
     */
    public static function decide(?object $active, bool $bookingPage): array
    {
        $query = $active !== null && is_array($active->query ?? null) ? $active->query : [];

        if (($query['option'] ?? '') !== 'com_appointments' || ($query['view'] ?? '') !== 'eventtype') {
            return [self::OVERVIEW, 0];
        }

        $params = $active->getParams();

        if ((int) $params->get('show_back_link', 0) === 1) {
            $target = (int) $params->get('back_link_itemid', 0);

            return $target > 0 ? [self::ITEM, $target] : [self::OVERVIEW, 0];
        }

        return $bookingPage ? [self::NONE, 0] : [self::ITEM, (int) $active->id];
    }

    /**
     * The address of the way back on the current page, HTML-escaped by Route, or '' for none.
     * A configured menu item that the visitor cannot open, that does not exist in the visitor's
     * language or that is no page of the site (heading, separator, external URL) falls back to the list.
     */
    public static function resolve(bool $bookingPage): string
    {
        $menu = Factory::getApplication()->getMenu();
        [$kind, $itemId] = self::decide($menu->getActive(), $bookingPage);

        if ($kind === self::NONE) {
            return '';
        }

        if ($kind === self::ITEM) {
            // getItems() of the site menu only returns items of the visitor's access levels and language.
            $item = $menu->getItems(['id'], [$itemId], true);

            if (is_object($item) && in_array($item->type ?? '', ['component', 'alias'], true)) {
                return Route::_('index.php?Itemid=' . (int) $item->id);
            }
        }

        return Route::_('index.php?option=com_appointments&view=eventtypes');
    }
}
