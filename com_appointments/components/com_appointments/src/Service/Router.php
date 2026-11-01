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
use Joomla\CMS\Component\Router\Rules\NomenuRules;
use Joomla\CMS\Component\Router\Rules\StandardRules;
use Joomla\CMS\Factory;
use Joomla\CMS\Menu\AbstractMenu;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * SEF router of the site part, built from Joomla's standard rules like the core components.
 *
 * - MenuRules finds the menu item of a booking page in the current language or in "All", so a
 *   link to an event type uses its own "Book an appointment" item, otherwise an "Event types"
 *   list item, otherwise the component address.
 * - StandardRules builds and parses the event type below a list item by its alias.
 * - NomenuRules handles /component/appointments/eventtype/<alias> without a menu item.
 *
 * Only the two menu views are registered. The confirmation page, the signed manage pages and
 * the tasks keep their query string, so a signed link carries its parameters unchanged and the
 * links in e-mails, which are built without a router, keep resolving.
 */
class Router extends RouterView
{
    protected $name = 'appointments';

    private DatabaseInterface $db;

    /** @var array<int, string|null> */
    private array $aliases = [];

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

        parent::__construct($app, $menu);

        $this->attachRule(new MenuRules($this));
        $this->attachRule(new StandardRules($this));
        $this->attachRule(new NomenuRules($this));
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
        $alias = $this->alias($id);

        return [$id => $alias === null || $alias === '' ? (string) $id : $alias];
    }

    /**
     * Event type id of a segment: the alias first, then a plain id of an existing event type.
     * Publication, access and language are enforced by the page, which answers 404.
     *
     * @param   string  $segment  The URL segment.
     * @param   array   $query    The variables parsed so far.
     *
     * @return  int|false
     */
    public function getEventtypeId($segment, $query)
    {
        $segment = (string) $segment;

        if ($segment === '') {
            return false;
        }

        $dbQuery = $this->createQuery();
        $dbQuery->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__appointments_eventtypes'))
            ->where($this->db->quoteName('alias') . ' = :alias')
            ->bind(':alias', $segment);
        $id = (int) $this->db->setQuery($dbQuery)->loadResult();

        if ($id > 0) {
            return $id;
        }

        if (preg_match('/^[1-9][0-9]{0,9}$/D', $segment) === 1 && $this->alias((int) $segment) !== null) {
            return (int) $segment;
        }

        return false;
    }

    /** Alias of an existing event type, '' for one without alias, null when it does not exist. */
    private function alias(int $id): ?string
    {
        if ($id < 1) {
            return null;
        }

        if (!array_key_exists($id, $this->aliases)) {
            $query = $this->createQuery();
            $query->select($this->db->quoteName('alias'))
                ->from($this->db->quoteName('#__appointments_eventtypes'))
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER);
            $alias = $this->db->setQuery($query)->loadResult();
            $this->aliases[$id] = $alias === null ? null : (string) $alias;
        }

        return $this->aliases[$id];
    }

    private function createQuery()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
