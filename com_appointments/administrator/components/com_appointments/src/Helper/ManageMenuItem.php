<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\CMS\Access\Access;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The site menu item that frames the signed pages of a booking (reschedule, cancel, erase, the
 * guest's removal page) and their receipts.
 *
 * Without a menu item Joomla shows a component page in the frame of its default menu item, which
 * is usually the home page: its modules, slider and content stand above the receipt. The links
 * therefore carry the Itemid of, in this order:
 *
 *   1. a "Book an appointment" menu item of the booking's event type,
 *   2. an "Event types" list menu item,
 *
 * each first in the language of the link, then in "All". Only published site menu items a guest
 * may open qualify, because the person with the link is usually not logged in. Without one the
 * link has no Itemid and behaves as before.
 *
 * The Itemid only chooses the frame of the page. The signature stays bound to booking id, action
 * and row version, so the parameter grants nothing and a changed Itemid changes nothing else.
 */
final class ManageMenuItem
{
    /**
     * Pure choice among menu item rows, testable without Joomla.
     *
     * @param   iterable<array{id: int|string, link: string, language: string}>  $rows  Published site menu items a guest may open.
     *
     * @return  int  The Itemid, or 0 for none.
     */
    public static function pick(iterable $rows, int $eventtypeId, string $language): int
    {
        $best = [PHP_INT_MAX, PHP_INT_MAX];
        $chosen = 0;

        foreach ($rows as $row) {
            parse_str((string) parse_url((string) $row['link'], PHP_URL_QUERY), $query);

            if (($query['option'] ?? '') !== 'com_appointments') {
                continue;
            }

            $view = $query['view'] ?? '';

            if ($view === 'eventtype' && $eventtypeId > 0 && (int) ($query['id'] ?? 0) === $eventtypeId) {
                $rank = 0;
            } elseif ($view === 'eventtypes') {
                $rank = 2;
            } else {
                continue;
            }

            // The language of the link first, then "All"; an item of another language would
            // switch the page to that language.
            $itemLanguage = (string) $row['language'];

            if ($itemLanguage === '*') {
                $rank++;
            } elseif ($language === '' || $itemLanguage !== $language) {
                continue;
            }

            $candidate = [$rank, (int) $row['id']];

            if ($candidate < $best) {
                $best = $candidate;
                $chosen = (int) $row['id'];
            }
        }

        return $chosen;
    }

    /**
     * The Itemid for the booking pages of an event type in $language (a tag such as de-DE), from
     * the database, so it also works for mails sent from the administrator or the command line.
     */
    public static function find(DatabaseInterface $db, int $eventtypeId, string $language): int
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $levels = array_values(array_unique(array_map('intval', Access::getAuthorisedViewLevels(0))));

        if ($levels === []) {
            return 0;
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'link', 'language']))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('link') . ' LIKE ' . $db->quote('index.php?option=com_appointments&view=eventtype%'))
            ->where($db->quoteName('access') . ' IN (' . implode(',', $levels) . ')')
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= ' . $db->quote($now) . ')')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= ' . $db->quote($now) . ')');

        return self::pick($db->setQuery($query)->loadAssocList(), $eventtypeId, $language);
    }
}
