<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Which site requests of the component are reachable: only what a published menu item opens, plus
 * the few endpoints that check a signature, a token or the browser session themselves.
 *
 * - The list of event types, a booking page and «My calendars» open only through the active menu
 *   item of their own type, with that menu item's event type and layout. A query parameter cannot
 *   switch a menu item to another view, another event type, another layout or another format, and
 *   without such a menu item (component address, non-SEF address, another component's menu item)
 *   the page does not exist. An event type list item also opens the booking page of each event type
 *   it lists, below its own address; publication, access level and language are checked by the page.
 * - The confirmation (view booking, this browser's last booking), the signed manage pages (view
 *   manage) and the account activation (view activation, its one-time link) need no menu item of
 *   their own: they carry the frame of the booking page (Itemid) where one exists, and answer 404
 *   without their session state, signature or token.
 * - Tasks: only the ones listed in TASKS. Every other task, including the `display` task that every
 *   Joomla controller has and the default task an unknown task falls back to, is refused.
 *
 * Everything else answers 404, like an address that does not exist, so the answer does not reveal
 * that a view exists. The site dispatcher applies this before any controller runs.
 */
final class MenuViews
{
    /**
     * The tasks of the site, by controller. Each one checks POST, form token, signature, session or
     * sign-in itself (see the controllers).
     *
     * @var array<string, list<string>>
     */
    public const TASKS = [
        'booking' => ['submit', 'ics', 'manage'],
        'guest' => ['confirm', 'remove'],
        'feed' => ['download'],
        'devices' => ['create', 'rename', 'remove'],
        'activation' => ['complete'],
    ];

    /** Views without a menu item type: each checks its session state, signature or token. */
    public const UNFRAMED = ['booking', 'manage', 'activation'];

    /** Layouts of «My calendars» besides the menu item's own: the page of one device, linked from it. */
    private const MYCALENDARS_LAYOUTS = ['device'];

    /**
     * Pure decision, testable without Joomla.
     *
     * @param   string      $controller  Controller of the request as Joomla's dispatcher reads it ('' for none).
     * @param   string      $task        Task of the request ('' for none).
     * @param   string      $view        Requested view ('' for the default view, the list of event types).
     * @param   int         $id          Requested id (the event type of a booking page).
     * @param   string      $layout      Requested layout ('' for the default layout).
     * @param   string      $format      Requested format ('' for html).
     * @param   array|null  $menu        Query of the active menu item, null without one.
     */
    public static function allows(string $controller, string $task, string $view, int $id, string $layout, string $format, ?array $menu): bool
    {
        $controller = strtolower($controller === '' ? 'display' : $controller);
        $task = strtolower($task === '' ? 'display' : $task);

        if ($controller !== 'display' || $task !== 'display') {
            return \in_array($task, self::TASKS[$controller] ?? [], true);
        }

        $view = strtolower($view === '' ? 'eventtypes' : $view);
        $layout = self::layout($layout);
        $format = strtolower($format === '' ? 'html' : $format);

        if (\in_array($view, self::UNFRAMED, true)) {
            return $format === 'html' && $layout === 'default';
        }

        if ($menu === null || ($menu['option'] ?? '') !== 'com_appointments') {
            return false;
        }

        $menuView = strtolower((string) ($menu['view'] ?? ''));
        $menuLayout = self::layout((string) ($menu['layout'] ?? ''));

        return match ($view) {
            'eventtypes' => $menuView === 'eventtypes' && $format === 'html' && $layout === $menuLayout,
            // The date-and-time part on its own (format=raw) is the background update of the same page.
            'eventtype' => $id > 0 && \in_array($format, ['html', 'raw'], true) && (
                ($menuView === 'eventtype' && (int) ($menu['id'] ?? 0) === $id && $layout === $menuLayout)
                || ($menuView === 'eventtypes' && $layout === 'default')
            ),
            'mycalendars' => $menuView === 'mycalendars' && $format === 'html'
                && ($layout === $menuLayout || \in_array($layout, self::MYCALENDARS_LAYOUTS, true)),
            default => false,
        };
    }

    /**
     * Pure choice among menu item rows of one view, testable without Joomla: an item the reader may
     * open (its access level among $levels), in the reader's language first, then in "All", the
     * lowest id within each; an item of another language is never chosen, because it would switch
     * the page to that language. Without a language every language qualifies, "All" first.
     *
     * @param   iterable<array{id: int|string, language: string, access: int|string}>  $rows
     * @param   int[]                                                                 $levels  Authorised view levels of the reader.
     *
     * @return  int  The Itemid, or 0 for none.
     */
    public static function pickItem(iterable $rows, string $language, array $levels): int
    {
        $best = [PHP_INT_MAX, PHP_INT_MAX];
        $chosen = 0;

        foreach ($rows as $row) {
            if (!\in_array((int) $row['access'], $levels, true)) {
                continue;
            }

            $itemLanguage = (string) $row['language'];
            $rank = match (true) {
                $language !== '' && $itemLanguage === $language => 0,
                $itemLanguage === '*' => 1,
                $language === '' => 2,
                default => null,
            };

            if ($rank === null) {
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
     * The published site menu item of a view without further parameters (for example «My calendars»)
     * for a reader with $levels in $language (a tag such as de-DE), from the database, so a mail sent
     * from the administrator or the command line links its page (pickItem()); 0 when there is none.
     *
     * @param   int[]  $levels  Authorised view levels of the reader.
     */
    public static function itemFor(DatabaseInterface $db, string $view, string $language, array $levels): int
    {
        $levels = array_values(array_unique(array_map('intval', $levels)));

        if ($levels === []) {
            return 0;
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $link = 'index.php?option=com_appointments&view=' . $view;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'language', 'access']))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->where($db->quoteName('link') . ' = ' . $db->quote($link))
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= ' . $db->quote($now) . ')')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= ' . $db->quote($now) . ')')
            ->where($db->quoteName('access') . ' IN (' . implode(',', $levels) . ')');

        return self::pickItem($db->setQuery($query)->loadAssocList(), $language, $levels);
    }

    /**
     * A layout name for comparison: no layout and "_:name" (the component's own layout, as Joomla
     * writes it into a menu link) are the plain name. A layout of a template ("template:name") stays
     * as it is, so a request cannot pick a layout of another template.
     */
    private static function layout(string $layout): string
    {
        $layout = strtolower($layout);

        if (str_starts_with($layout, '_:')) {
            $layout = substr($layout, 2);
        }

        return $layout === '' ? 'default' : $layout;
    }
}
