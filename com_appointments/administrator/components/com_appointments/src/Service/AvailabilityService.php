<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\BusyPeriod;
use Advans\Component\Appointments\Administrator\Helper\ScheduleResolver;
use Advans\Component\Appointments\Administrator\Helper\SlotEngine;
use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class AvailabilityService
{
    private ?TeamServices $team = null;

    public function __construct(private DatabaseInterface $db)
    {
    }

    /**
     * `$locked` is true inside the booking transaction that holds the host lock:
     * busy times of internet calendars are then read without writing their
     * cache (see BookingCalendarBridgeInterface::busyFor()).
     *
     * @return array{engine:SlotEngine,busy:array,caps:array}
     */
    public function context(array $event, \DateTimeImmutable $from, \DateTimeImmutable $to, int $excludeBooking = 0, bool $locked = false): array
    {
        $db = $this->db;
        $scheduleId = (int) $event['schedule_id'];
        $hostId = (int) $event['host_user_id'];
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['timezone', 'published']))->from($db->quoteName('#__appointments_schedules'))
            ->where($db->quoteName('id') . ' = ' . $scheduleId);
        $schedule = $db->setQuery($query)->loadAssoc();

        if (!$schedule || (int) $schedule['published'] !== 1) {
            throw new \DomainException('The assigned schedule is unavailable.');
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['weekday', 'specific_date', 'start_time', 'end_time', 'is_unavailable']))
            ->from($db->quoteName('#__appointments_schedule_rules'))
            ->where($db->quoteName('schedule_id') . ' = ' . $scheduleId);
        $weekly = [];
        $overrides = [];

        foreach ($db->setQuery($query)->loadAssocList() as $rule) {
            if ($rule['specific_date'] === null) {
                $weekly[] = $rule;
            } else {
                $overrides[] = $rule;
            }
        }

        $rules = SlotRules::fromEventType($event);
        $zone = new \DateTimeZone($schedule['timezone']);
        [$low, $high] = $this->window($from, $to, $zone, $rules);
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.id', 'b.eventtype_id', 'b.start_utc', 'b.end_utc',
            'e.buffer_before_minutes', 'e.buffer_after_minutes']))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.host_user_id') . ' = ' . $hostId)
            ->where($db->quoteName('b.state') . ' IN (0,1)')
            ->where($db->quoteName('b.id') . ' <> ' . $excludeBooking)
            ->where('DATE_SUB(' . $db->quoteName('b.start_utc') . ', INTERVAL ' . $db->quoteName('e.buffer_before_minutes') . ' MINUTE) < ' . $db->quote($high->format('Y-m-d H:i:s')))
            ->where('DATE_ADD(' . $db->quoteName('b.end_utc') . ', INTERVAL ' . $db->quoteName('e.buffer_after_minutes') . ' MINUTE) > ' . $db->quote($low->format('Y-m-d H:i:s')));
        $busy = [];
        $caps = [];

        foreach ($db->setQuery($query)->loadAssocList() as $booking) {
            $start = $this->timestamp($booking['start_utc']);
            $end = $this->timestamp($booking['end_utc']);
            $meeting = new AvailabilityWindow($start, $end);
            $busy[] = new BusyPeriod($meeting, $meeting->expand((int) $booking['buffer_before_minutes'] * 60, (int) $booking['buffer_after_minutes'] * 60));

            if ((int) $booking['eventtype_id'] === (int) $event['id']) {
                $caps[] = $start;
            }
        }

        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['start_utc', 'end_utc']))->from($db->quoteName('#__appointments_blocked'))
            ->where($db->quoteName('user_id') . ' = ' . $hostId)
            ->where($db->quoteName('start_utc') . ' < ' . $db->quote($high->format('Y-m-d H:i:s')))
            ->where($db->quoteName('end_utc') . ' > ' . $db->quote($low->format('Y-m-d H:i:s')));

        foreach ($db->setQuery($query)->loadAssocList() as $blocked) {
            $busy[] = BusyPeriod::reserved(new AvailabilityWindow($this->timestamp($blocked['start_utc']), $this->timestamp($blocked['end_utc'])));
        }

        $busy = array_merge($busy, $this->calendarBusy($event, $low, $high, $zone, $locked));

        return ['engine' => new SlotEngine(new ScheduleResolver($schedule['timezone'], $weekly, $overrides), $rules), 'busy' => $busy, 'caps' => $caps];
    }

    public function slots(array $event, \DateTimeImmutable $now, \DateTimeImmutable $from, \DateTimeImmutable $to, int $excludeBooking = 0): array
    {
        $context = $this->context($event, $from, $to, $excludeBooking);

        return $context['engine']->findSlots($now, $from, $to, $context['busy'], $context['caps']);
    }

    /**
     * Warm the busy cache of the event type's internet calendars for the window
     * context() reads, outside any transaction. Never throws.
     */
    public function warmCalendars(array $event, \DateTimeImmutable $from, \DateTimeImmutable $to): void
    {
        try {
            $db = $this->db;
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('timezone'))->from($db->quoteName('#__appointments_schedules'))
                ->where($db->quoteName('id') . ' = ' . (int) $event['schedule_id']);
            $timezone = $db->setQuery($query)->loadResult();

            if (!is_string($timezone) || $timezone === '') {
                return;
            }

            $rules = SlotRules::fromEventType($event);
            $zone = new \DateTimeZone($timezone);
            [$low, $high] = $this->window($from, $to, $zone, $rules);
            $this->calendarBusy($event, $low, $high, $zone, false);
        } catch (\Throwable $e) {
            // Warming is an optimisation; the locked read computes what is missing.
        }
    }

    /**
     * Busy periods of the internet calendars linked to the event type. A
     * calendar error never blocks or fails a booking: it yields no periods.
     *
     * @return BusyPeriod[]
     */
    private function calendarBusy(array $event, \DateTimeImmutable $low, \DateTimeImmutable $high, \DateTimeZone $zone, bool $readOnly): array
    {
        try {
            $team = $this->team ??= TeamServices::fromApplication();

            if (!$team->enabled()) {
                return [];
            }

            return $team->bookings()->busyFor((object) $event, $low, $high, $zone, $readOnly);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array{0:\DateTimeImmutable,1:\DateTimeImmutable} */
    private function window(\DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $zone, SlotRules $rules): array
    {
        $utc = new \DateTimeZone('UTC');
        // Include whole adjacent weeks for caps, plus the candidate's complete occupancy.
        $low = $from->setTimezone($zone)->modify('monday this week')->setTime(0, 0)->setTimezone($utc)
            ->modify('-' . $rules->getBufferBeforeSeconds() . ' seconds');
        $high = $to->setTimezone($zone)->modify('monday next week')->setTime(0, 0)->setTimezone($utc)
            ->modify('+' . ($rules->getDurationSeconds() + $rules->getBufferAfterSeconds()) . ' seconds');

        return [$low, $high];
    }

    private function timestamp(string $value): \DateTimeImmutable
    {
        $parsed = UtcTimestamp::parse($value);

        if ($parsed === null) {
            throw new \UnexpectedValueException('An appointment contains an invalid UTC timestamp.');
        }

        return $parsed;
    }
}
