<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Computes the bookable start times of one event type.
 *
 * The engine is a pure function of its inputs: no clock, no database, no
 * Joomla. "Now" is an argument, never `new DateTimeImmutable()` inside, which is
 * what makes the two daylight-saving days and the horizon boundary testable at
 * all.
 *
 * The order of the steps matters and follows the component's slot
 * specification:
 *
 *  1. The schedule is resolved into windows, in the schedule's own time zone and
 *     then converted to UTC. `ScheduleResolver` does that and owns the clock
 *     changes.
 *  2. Everything already taken is removed. The caller hands those periods over
 *     as they really occupy the day: an absence as it stands, and a booking
 *     widened by the buffers of its own event type, because a host can serve
 *     several event types with different buffers. The periods the schedule
 *     itself makes unavailable, the second pass over a repeated hour and the
 *     uncovered part of a governed day, are added here from the resolver and
 *     removed in exactly the same way.
 *  3. Each window is walked on the slot-step grid, anchored to the window start
 *     and not to the pieces a booking may have cut it into, so the offered times
 *     stay on the same grid all day.
 *  4. A start is bookable when the meeting fits into a free piece and the
 *     meeting plus its own buffers fits inside the schedule window. The second
 *     condition is what makes the first and last slot of a window move inward
 *     when buffers are configured.
 *  5. Starts before "now plus the minimum notice" are dropped, and so are starts
 *     outside the booking horizon, which counts calendar days in the schedule's
 *     zone.
 *  6. A day that has reached the daily cap disappears completely, and so does
 *     the rest of a week that has reached the weekly cap.
 *
 * Why a candidate's buffers are checked against the window and not against the
 * free piece: the piece edges already include the buffers of the bookings that
 * cut them, so requiring the candidate's buffer there as well would demand
 * twice the configured gap between two meetings. Two candidates next to each
 * other cannot violate each other, because only one of them can be booked and
 * the next computation sees it.
 *
 * @since  1.0.0
 */
final class SlotEngine
{
    /**
     * @var  ScheduleResolver
     */
    private $resolver;

    /**
     * @var  SlotRules
     */
    private $rules;

    public function __construct(ScheduleResolver $resolver, SlotRules $rules)
    {
        $this->resolver = $resolver;
        $this->rules    = $rules;
    }

    /**
     * The bookable start times inside the requested range.
     *
     * @param   \DateTimeImmutable    $now        The current instant, injected.
     * @param   \DateTimeImmutable    $fromUtc    Inclusive start of the range of interest.
     * @param   \DateTimeImmutable    $toUtc      Exclusive end of the range of interest.
     * @param   AvailabilityWindow[]  $busy       Everything that is already taken, as the periods
     *                                           it really occupies. A booking has to be handed
     *                                           over already widened by the buffers of **its own**
     *                                           event type, with {@see SlotRules::expandBooking()};
     *                                           an absence is handed over as it stands, because it
     *                                           is already the full period. The engine widens
     *                                           nothing here: a host can serve several event types
     *                                           with different buffers, and applying this event
     *                                           type's buffers to another type's meeting would
     *                                           offer a slot that runs into its buffer.
     * @param   \DateTimeImmutable[]  $capCounts  Start times of the active bookings of **this**
     *                                           event type, used for the daily and weekly caps,
     *                                           which are per event type.
     *
     * @return  \DateTimeImmutable[]  Bookable starts in UTC, ordered, without duplicates.
     */
    public function findSlots(
        \DateTimeImmutable $now,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc,
        array $busy = [],
        array $capCounts = []
    ): array {
        $range = $this->bookableRange($now, $fromUtc, $toUtc);

        if ($range === null) {
            return [];
        }

        // What the schedule itself makes unavailable is removed the same way a
        // booking is, rather than being cut out of the windows: that keeps the
        // start of a window the start of its rule, so the grid below does not
        // change phase after a clock change or next to a governed day.
        $busy = AvailabilityWindow::merge(
            array_merge($busy, $this->resolver->unavailablePeriods($range->getStart(), $range->getEnd()))
        );
        $slots = [];

        foreach ($this->resolver->resolve($range->getStart(), $range->getEnd()) as $window) {
            foreach ($this->slotsInWindow($window, $busy) as $slot) {
                $slots[$slot->getTimestamp()] = $slot;
            }
        }

        // The range already excludes everything before the minimum notice and
        // after the horizon, but a window may reach past its edges, so the
        // candidates are filtered once more against the range itself.
        $slots = array_filter(
            $slots,
            static function (\DateTimeImmutable $slot) use ($range): bool {
                return $range->contains($slot);
            }
        );

        $slots = $this->applyCaps($slots, $capCounts);

        ksort($slots);

        return array_values($slots);
    }

    /**
     * The intersection of the requested range, the minimum notice and the
     * horizon, or null when nothing is left of it.
     */
    private function bookableRange(
        \DateTimeImmutable $now,
        \DateTimeImmutable $fromUtc,
        \DateTimeImmutable $toUtc
    ): ?AvailabilityWindow {
        $earliest = $now->modify('+' . $this->rules->getMinimumNoticeSeconds() . ' seconds');
        $start    = $fromUtc > $earliest ? $fromUtc : $earliest;
        $end      = $toUtc;

        if ($this->rules->getHorizonDays() > 0) {
            // Calendar days in the schedule's zone, not periods of 24 hours:
            // with a horizon of 30 days the last bookable day is the 30th day
            // after today, and it is bookable through its end.
            $zone      = $this->resolver->getTimezone();
            $localDay  = $now->setTimezone($zone)->format('Y-m-d');
            $lastDay   = (new \DateTimeImmutable($localDay . ' 12:00:00', $zone))
                ->modify('+' . $this->rules->getHorizonDays() . ' days')
                ->format('Y-m-d');
            // Local midnight after the last bookable day, exclusive.
            $horizonEnd = (new \DateTimeImmutable($lastDay . ' 12:00:00', $zone))
                ->modify('+1 day')
                ->setTime(0, 0)
                ->setTimezone(new \DateTimeZone('UTC'));

            if ($horizonEnd < $end) {
                $end = $horizonEnd;
            }
        } else {
            $fixedStart = $this->rules->getHorizonStart();
            $fixedEnd   = $this->rules->getHorizonEnd();

            if ($fixedStart !== null && $fixedStart > $start) {
                $start = $fixedStart;
            }

            if ($fixedEnd !== null && $fixedEnd < $end) {
                $end = $fixedEnd;
            }
        }

        return $start < $end ? new AvailabilityWindow($start, $end) : null;
    }

    /**
     * The bookable starts of one window.
     *
     * @param   AvailabilityWindow[]  $busy
     *
     * @return  \DateTimeImmutable[]
     */
    private function slotsInWindow(AvailabilityWindow $window, array $busy): array
    {
        $free = $window->subtractAll($busy);

        if ($free === []) {
            return [];
        }

        $duration = $this->rules->getDurationSeconds();
        $step     = $this->rules->getSlotStepSeconds();
        $before   = $this->rules->getBufferBeforeSeconds();
        $after    = $this->rules->getBufferAfterSeconds();
        $slots    = [];

        // Anchored to the window start, so a booking that splits the window
        // does not move the grid of the times offered after it.
        for ($start = $window->getStart(); ; $start = $start->modify('+' . $step . ' seconds')) {
            $meetingEnd = $start->modify('+' . $duration . ' seconds');

            // The meeting plus its buffers has to fit inside the window. Once
            // that fails, every later start on the grid fails too.
            if ($meetingEnd->modify('+' . $after . ' seconds') > $window->getEnd()) {
                break;
            }

            if ($start->modify('-' . $before . ' seconds') < $window->getStart()) {
                continue;
            }

            $meeting = new AvailabilityWindow($start, $meetingEnd);

            foreach ($free as $piece) {
                if ($piece->containsWindow($meeting)) {
                    $slots[] = $start;

                    break;
                }
            }
        }

        return $slots;
    }

    /**
     * Remove the days and the weeks that have reached their cap.
     *
     * A reached cap removes the whole day, not merely the next start: offering
     * the rest of a full day would send a visitor into a submission that the
     * booking guard has to refuse.
     *
     * @param   array<int, \DateTimeImmutable>  $slots      Candidates, keyed by timestamp.
     * @param   \DateTimeImmutable[]            $capCounts  Existing starts of this event type.
     *
     * @return  array<int, \DateTimeImmutable>
     */
    private function applyCaps(array $slots, array $capCounts): array
    {
        $perDay  = $this->rules->getMaxPerDay();
        $perWeek = $this->rules->getMaxPerWeek();

        if ($perDay === 0 && $perWeek === 0) {
            return $slots;
        }

        $zone      = $this->resolver->getTimezone();
        $dayCount  = [];
        $weekCount = [];

        foreach ($capCounts as $start) {
            $local = $start->setTimezone($zone);
            $day   = $local->format('Y-m-d');
            // ISO year and week, so the week starts on Monday in the schedule's
            // own zone and the turn of the year does not merge two weeks.
            $week  = $local->format('o-W');

            $dayCount[$day]   = ($dayCount[$day] ?? 0) + 1;
            $weekCount[$week] = ($weekCount[$week] ?? 0) + 1;
        }

        return array_filter(
            $slots,
            static function (\DateTimeImmutable $slot) use ($zone, $perDay, $perWeek, $dayCount, $weekCount): bool {
                $local = $slot->setTimezone($zone);

                if ($perDay > 0 && ($dayCount[$local->format('Y-m-d')] ?? 0) >= $perDay) {
                    return false;
                }

                if ($perWeek > 0 && ($weekCount[$local->format('o-W')] ?? 0) >= $perWeek) {
                    return false;
                }

                return true;
            }
        );
    }
}
