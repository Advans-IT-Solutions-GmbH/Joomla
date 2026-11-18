<?php
/**
 * @package     Appointments Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\Appointments\Administrator\Helper;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\EventScope;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * The next appointments for the administrator dashboard.
 *
 * Shown only to a user who may open the bookings list of com_appointments: the same rule as that
 * list (EventScope::events(): «Access Administration Interface» on the component and «Edit» on the
 * event type), and only the bookings of those event types. Everybody else gets null, and the
 * dispatcher renders nothing.
 */
class AppointmentsHelper
{
    public const COMPONENT = 'com_appointments';

    /** The key of the stored time of the user's last visit in #__user_profiles. */
    public const PROFILE_KEY = 'mod_appointments.lastvisit';

    /** The session key of the reference of «new since your last visit» (Digest::baseline()). */
    public const SESSION_KEY = 'mod_appointments.since';

    /**
     * The next $params->count appointments (future, pending or confirmed, not erased) with their
     * event type and the name of the booking person, the number of bookings made since the user's
     * last visit, the site time zone the times are shown in and the address of the bookings list.
     * Null when the user may not see the bookings list.
     *
     * @return  array{items: list<array{id: int, start_utc: string, state: int, title: string, name: string}>, zone: string,
     *                since: ?string, new: int, error: bool}|null
     */
    public function getDigest(Registry $params, CMSApplicationInterface $app): ?array
    {
        if (!ComponentHelper::isEnabled(self::COMPONENT)) {
            return null;
        }

        $user = $app->getIdentity();

        if ($user === null || (int) $user->id < 1 || !$user->authorise('core.manage', self::COMPONENT)) {
            return null;
        }

        $zone = (string) $app->get('offset', 'UTC');
        $zone = \in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';

        try {
            // Registers the component's classes and services (EventScope, BookingData).
            $app->bootComponent(self::COMPONENT);
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $events = array_map(static fn (array $event): int => (int) $event['id'], EventScope::events($db, $user));

            if ($events === []) {
                return null;
            }

            $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

            return [
                'items' => $this->upcoming($db, $events, $now, Digest::limit($params->get('count', Digest::DEFAULT_COUNT))),
                'zone' => $zone,
            ] + $this->sinceLastVisit($db, $app, (int) $user->id, $events, $now) + ['error' => false];
        } catch (\Throwable $e) {
            // Never the message: it could contain data of a booking.
            Log::add('Appointments dashboard module: the appointments could not be read (' . $e::class . ').', Log::WARNING, self::COMPONENT);

            return ['items' => [], 'zone' => $zone, 'since' => null, 'new' => 0, 'error' => true];
        }
    }

    /**
     * @param   list<int>  $events
     *
     * @return  list<array{id: int, start_utc: string, state: int, title: string, name: string}>
     */
    private function upcoming(DatabaseInterface $db, array $events, string $now, int $limit): array
    {
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.start_utc', 'b.state']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->select(BookingData::lightSelect($db, 'b'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->whereIn($db->quoteName('b.eventtype_id'), $events)
            ->whereIn($db->quoteName('b.state'), [0, 1])
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('b.start_utc') . ' >= :now')
            ->bind(':now', $now)
            ->order([$db->quoteName('b.start_utc'), $db->quoteName('b.id')])
            ->setLimit($limit);
        $data = BookingData::fromApplication();
        $items = [];

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            // A name that cannot be decrypted is shown empty, like in the bookings list.
            $opened = $data->openLenient($row);
            $items[] = [
                'id' => (int) $row['id'],
                'start_utc' => (string) $row['start_utc'],
                'state' => (int) $row['state'],
                'title' => (string) $row['eventtype_title'],
                'name' => Digest::name($opened),
            ];
        }

        return $items;
    }

    /**
     * The number of bookings made since the user's last visit (Digest::baseline()), and the time of
     * this visit stored as the next last visit.
     *
     * @param   list<int>  $events
     *
     * @return  array{since: ?string, new: int}
     */
    private function sinceLastVisit(DatabaseInterface $db, CMSApplicationInterface $app, int $userId, array $events, string $now): array
    {
        $session = $app->getSession();
        $kept = $session->get(self::SESSION_KEY);
        $baseline = Digest::baseline(is_string($kept) ? $kept : null, $this->storedVisit($db, $userId));
        $session->set(self::SESSION_KEY, $baseline['keep']);
        $this->storeVisit($db, $userId, $now);

        if ($baseline['since'] === null) {
            return ['since' => null, 'new' => 0];
        }

        $since = $baseline['since'];
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('COUNT(*)')
            ->from($db->quoteName('#__appointments_bookings'))
            ->whereIn($db->quoteName('eventtype_id'), $events)
            ->where($db->quoteName('anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('created_utc') . ' > :since')
            ->bind(':since', $since);

        return ['since' => $since, 'new' => (int) $db->setQuery($query)->loadResult()];
    }

    private function storedVisit(DatabaseInterface $db, int $userId): ?string
    {
        $key = self::PROFILE_KEY;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :user')
            ->where($db->quoteName('profile_key') . ' = :key')
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':key', $key);
        $value = $db->setQuery($query)->loadResult();

        return is_string($value) && Digest::isTime($value) ? $value : null;
    }

    private function storeVisit(DatabaseInterface $db, int $userId, string $now): void
    {
        $key = self::PROFILE_KEY;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->update($db->quoteName('#__user_profiles'))
            ->set($db->quoteName('profile_value') . ' = :now')
            ->where($db->quoteName('user_id') . ' = :user')
            ->where($db->quoteName('profile_key') . ' = :key')
            ->bind(':now', $now)
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':key', $key);
        $db->setQuery($query)->execute();

        if ($db->getAffectedRows() > 0 || $this->storedVisit($db, $userId) !== null) {
            return;
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->insert($db->quoteName('#__user_profiles'))
            ->columns($db->quoteName(['user_id', 'profile_key', 'profile_value', 'ordering']))
            ->values(':user, :key, :now, 0')
            ->bind(':user', $userId, ParameterType::INTEGER)
            ->bind(':key', $key)
            ->bind(':now', $now);
        $db->setQuery($query)->execute();
    }
}
