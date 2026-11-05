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
    /** Seconds an administrator's page view spends on the migration at most. */
    private const MIGRATION_SECONDS = 5;

    /**
     * Continue the migration of booking rows of 1.3 (team-calendars.md, 5.10)
     * at an administrator's visit of the component, a few seconds per page, so
     * it never depends on the Scheduler (whose tasks an update creates switched
     * off). Never fails the page.
     *
     * @return  int  Bookings of 1.3 still in clear text after the migration window (shown as «not verified»).
     */
    public static function continueMigration(): int
    {
        try {
            $app = Factory::getApplication();
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) $app->get('secret', ''));

            // After the end (also on every new installation) nothing to do, and no table scan.
            if (\Advans\Component\Appointments\Administrator\Service\BookingRecords::finished($db, $keys)) {
                return 0;
            }

            if ($app->getIdentity()->authorise('core.admin', 'com_appointments')) {
                // Also opens a migration the update could not open (an installation over existing
                // tables, an error in the update): idempotent, and only while no row is sealed.
                \Advans\Component\Appointments\Administrator\Service\BookingRecords::begin($db, $keys);
                \Advans\Component\Appointments\Administrator\Service\BookingRecords::resume($db, $keys, self::MIGRATION_SECONDS);
            }

            return \Advans\Component\Appointments\Administrator\Service\BookingRecords::unverified($db, $keys);
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Appointment booking migration could not continue: ' . \get_class($e) . '.', \Joomla\CMS\Log\Log::WARNING, 'com_appointments');

            return 0;
        }
    }

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
