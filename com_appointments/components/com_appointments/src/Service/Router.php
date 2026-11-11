<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Service;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Categories\CategoryFactoryInterface;
use Joomla\CMS\Component\Router\RouterView;
use Joomla\CMS\Component\Router\RouterViewConfiguration;
use Joomla\CMS\Component\Router\Rules\MenuRules;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Factory;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * SEF router of the site part, built from Joomla's standard rules like the core components.
 *
 * - MenuRules finds the menu item of a booking page in the current language or in "All", so a
 *   link to an event type uses its own "Book an appointment" item, otherwise an "Event types"
 *   list item.
 * - StandardRules builds and parses the event type below a list item by its alias. Below any
 *   other menu item a further segment is not parsed, so it answers 404.
 *
 * There are no rules for addresses without a menu item (no NomenuRules): a page of these views
 * opens only through its menu item (Site\Dispatcher\Dispatcher, MenuViews), so
 * /component/appointments/<view>/… answers 404 instead of naming a view.
 *
 * Only the three menu views are registered. The confirmation page, the signed manage pages and
 * the tasks keep their query string, so a signed link carries its parameters unchanged and the
 * links in e-mails, which are built without a router, keep resolving.
 */
class Router extends RouterView
{
    protected $name = 'appointments';

    private DatabaseInterface $db;

    /**
     * Alias of every published event type by id, loaded once per request in one query, so a list of
     * event types does not cost one lookup per routed link.
     *
     * @var array<int, string>|null
     */
    private ?array $aliases = null;

    public function __construct(
        SiteApplication $app,
        AbstractMenu $menu,
        ?CategoryFactoryInterface $categoryFactory = null,
        ?DatabaseInterface $db = null
    ) {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);

        $eventtypes = new RouterViewConfiguration('eventtypes');
        $this->registerView($eventtypes);

        $eventtype = new RouterViewConfiguration('eventtype');
        $eventtype->setKey('id')->setParent($eventtypes);
        $this->registerView($eventtype);

        // «My calendars» of the internet calendars: its menu item, so tasks return to it.
        $this->registerView(new RouterViewConfiguration('mycalendars'));

        parent::__construct($app, $menu);

        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
    }

    /**
     * Segment of an event type: its alias, or its id when the event type has no alias.
     *
     * @param   string|int  $id     The event type id.
     * @param   array       $query  The request being built.
     *
     * @return  array<int, string>
     */
    public function getEventtypeSegment($id, $query)
    {
        $id = (int) $id;
        $alias = $this->aliases()[$id] ?? '';

        return [$id => $alias === '' ? (string) $id : $alias];
    }

    /**
     * Event type id of a segment: the alias first, then a plain id of a published event type.
     * Access level and language are enforced by the page, which answers 404.
     *
     * @param   string  $segment  The URL segment.
     * @param   array   $query    The variables parsed so far.
     *
     * @return  int|false
     */
    public function getEventtypeId($segment, $query)
    {
        $segment = mb_strtolower((string) $segment);

        if ($segment === '') {
            return false;
        }

        foreach ($this->aliases() as $id => $alias) {
            if ($alias !== '' && mb_strtolower($alias) === $segment) {
                return $id;
            }
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/D', $segment) === 1 && isset($this->aliases()[(int) $segment])) {
            return (int) $segment;
        }

        return false;
    }

    /** @return array<int, string> */
    private function aliases(): array
    {
        if ($this->aliases === null) {
            $query = method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
            // Published event types only: an unpublished one has no address, so a link built for it
            // (for example a redirect to the canonical address) does not name its alias.
            $query->select($this->db->quoteName(['id', 'alias']))
                ->from($this->db->quoteName('#__appointments_eventtypes'))
                ->where($this->db->quoteName('published') . ' = 1');
            $this->aliases = [];

            foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
                $this->aliases[(int) $row->id] = (string) $row->alias;
            }
        }

        return $this->aliases;
    }
}
