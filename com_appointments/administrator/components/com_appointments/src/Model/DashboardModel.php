<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Supplies the administrator dashboard.
 *
 * @since  1.0.0
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * The counts the dashboard shows.
     *
     * @return  array{eventtypes:int, bookings:int}
     */
    public function getCounts(): array
    {
        $rows = $this->getEventCounts();
        return ['eventtypes' => count($rows), 'bookings' => array_sum(array_column($rows, 'bookings')),
            'today' => array_sum(array_column($rows, 'today')), 'upcoming' => array_sum(array_column($rows, 'upcoming')),
            'pending' => array_sum(array_column($rows, 'pending'))];
    }

    public function getEventCounts(): array
    {
        $db = $this->getDatabase();
        $model = Factory::getApplication()->bootComponent('com_appointments')->getMVCFactory()
            ->createModel('Bookings', 'Administrator', ['ignore_request' => true]);
        $events = $model->authorisedEvents();
        if (!$events) {
            return [];
        }
        $name = (string) Factory::getApplication()->get('offset', 'UTC');
        if (!in_array($name, \DateTimeZone::listIdentifiers(), true)) {
            throw new \RuntimeException(\Joomla\CMS\Language\Text::_('COM_APPOINTMENTS_SYSTEM_TIMEZONE_INVALID'), 500);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $begin = $now->setTimezone(new \DateTimeZone($name))->setTime(0, 0);
        $end = $begin->modify('+1 day')->setTime(0, 0);
        $quoteTime = static fn (\DateTimeImmutable $time) => $db->quote($time->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        $active = $db->quoteName('b.state') . ' IN (0,1)';
        $query = $this->createDbQuery($db)->select($db->quoteName(['e.id', 'e.title']))
            ->select('COUNT(b.id) AS bookings')
            ->select('COALESCE(SUM(CASE WHEN ' . $active . ' AND b.start_utc >= ' . $quoteTime($begin)
                . ' AND b.start_utc < ' . $quoteTime($end) . ' THEN 1 ELSE 0 END),0) AS today')
            ->select('COALESCE(SUM(CASE WHEN ' . $active . ' AND b.start_utc >= ' . $quoteTime($now) . ' THEN 1 ELSE 0 END),0) AS upcoming')
            ->select('COALESCE(SUM(CASE WHEN b.state = 0 THEN 1 ELSE 0 END),0) AS pending')
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('LEFT', $db->quoteName('#__appointments_bookings', 'b') . ' ON b.eventtype_id = e.id')
            ->where('e.id IN (' . implode(',', array_map(static fn ($event) => (int) $event['id'], $events)) . ')')
            ->group($db->quoteName(['e.id', 'e.title']))->order($db->quoteName('e.title'));
        return $db->setQuery($query)->loadAssocList();
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
