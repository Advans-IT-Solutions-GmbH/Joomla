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
 *  4. A start is bookable when its meeting fits into a free piece, its meeting
 *     plus its own buffers fits inside the schedule window, and that occupied
 *     period reaches over nobody else's meeting. See {@see self::fits()} for why
 *     it takes those three and not fewer.
 *  5. Starts before "now plus the minimum notice" are dropped, and so are starts
 *     outside the booking horizon, which counts calendar days in the schedule's
 *     zone.
 *  6. A day that has reached the daily cap disappears completely, and so does
 *     the rest of a week that has reached the weekly cap.
 *
 * Two candidates next to each other cannot violate each other, because only one
 * of them can be booked and the next computation sees it.
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
     * @param   BusyPeriod[]          $busy       Everything that is already taken. A booking is
     *                                           built with {@see BusyPeriod::forBooking()} and the
     *                                           rules of **its own** event type, an absence with
     *                                           {@see BusyPeriod::reserved()}. The engine applies
     *                                           no buffers of its own to them: a host can serve
     *                                           several event types with different buffers.
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
        // An instant is an instant: the zone an argument happens to carry is a
        // presentation detail of the caller, not information about the moment.
        // The engine's own invariant is that every window it builds is in UTC,
        // and `$now` in particular comes from wherever the caller got its
        // clock, so a Europe/Zurich instant used to reach AvailabilityWindow
        // through the minimum notice and make it throw rather than answer.
        $now     = self::asUtc($now);
        $fromUtc = self::asUtc($fromUtc);
        $toUtc   = self::asUtc($toUtc);

        $range = $this->bookableRange($now, $fromUtc, $toUtc);

        if ($range === null) {
            return [];
        }

        // The range is a range of *start* times, and a candidate occupies more
        // than its start: its meeting runs for the duration and its buffers
        // reach further on both sides. The schedule therefore has to be asked
        // over the whole span a candidate can occupy. Asking only over the
        // range of starts misses an obstacle that begins just past its end: a
        // Tuesday rule from 22:00 to 02:00, a closed Wednesday and a range
        // ending at Wednesday 00:00 would offer a meeting from 23:30 to 00:30,
        // because the closed day only touches the range and the resolver
        // leaves it out. It also makes the answer independent of the range
        // again, which is the property the resolver already holds to.
        $occupiedFrom = $range->getStart()->modify('-' . $this->rules->getBufferBeforeSeconds() . ' seconds');
        $occupiedTo   = $range->getEnd()->modify(
            '+' . ($this->rules->getDurationSeconds() + $this->rules->getBufferAfterSeconds()) . ' seconds'
        );

        // What the schedule itself makes unavailable is removed the same way an
        // absence is, rather than being cut out of the windows: that keeps the
        // start of a window the start of its rule, so the grid below does not
        // change phase after a clock change or next to a governed day.
        foreach ($this->resolver->unavailablePeriods($occupiedFrom, $occupiedTo) as $period) {
            $busy[] = BusyPeriod::reserved($period);
        }

        $busy  = array_values($busy);
        $slots = [];

        foreach ($this->resolver->resolve($occupiedFrom, $occupiedTo) as $window) {
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
    /**
     * The same instant, read in UTC.
     */
    private static function asUtc(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->getTimezone()->getName() === 'UTC'
            ? $moment
            : $moment->setTimezone(new \DateTimeZone('UTC'));
    }

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
            $localDay = $now->setTimezone($this->resolver->getTimezone());
            $calendar = $now
                ->setDate((int) $localDay->format('Y'), (int) $localDay->format('m'), (int) $localDay->format('d'))
                ->setTime(0, 0);
            // Advance Gregorian dates before resolving the exclusive boundary;
            // arithmetic in the schedule zone would skip a nonexistent date.
            $horizonEnd = $this->resolver->localToUtc(
                $calendar->modify('+' . ($this->rules->getHorizonDays() + 1) . ' days')
            );

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
     * @param   BusyPeriod[]  $busy
     *
     * @return  \DateTimeImmutable[]
     */
    private function slotsInWindow(AvailabilityWindow $window, array $busy): array
    {
        $free = $window->subtractAll(BusyPeriod::occupiedWindows($busy));

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

            if (!$this->resolver->allowsStartFrom($window, $start)) {
                continue;
            }

            $meeting  = new AvailabilityWindow($start, $meetingEnd);
            $occupied = $meeting->expand($before, $after);

            if ($this->fits($meeting, $occupied, $free, $busy)) {
                $slots[] = $start;
            }
        }

        return $slots;
    }

    /**
     * Whether a candidate can be offered.
     *
     * Both directions are checked, and each one for a reason:
     *
     * - The **meeting** has to fit into a free piece of the window. The pieces
     *   are what is left after the occupied periods are removed, so this is
     *   what respects the buffers of what is already booked.
     * - The candidate's **occupied** period must not reach over another
     *   **meeting**. This is what respects this event type's own buffers, and
     *   it is the direction a free-piece check alone cannot see: an event type
     *   with an hour of buffer after a meeting would otherwise place that hour
     *   on top of somebody else's appointment.
     *
     * The occupied periods are deliberately not checked against each other. A
     * buffer is a minimum gap, not a second reservation, so two neighbouring
     * meetings with ten minutes of buffer each need ten minutes between them
     * and not twenty.
     *
     * @param   AvailabilityWindow[]  $free
     * @param   BusyPeriod[]          $busy
     */
    private function fits(
        AvailabilityWindow $meeting,
        AvailabilityWindow $occupied,
        array $free,
        array $busy
    ): bool {
        $inFreePiece = false;

        foreach ($free as $piece) {
            if ($piece->containsWindow($meeting)) {
                $inFreePiece = true;

                break;
            }
        }

        if (!$inFreePiece) {
            return false;
        }

        foreach ($busy as $period) {
            if ($occupied->overlaps($period->getMeeting())) {
                return false;
            }
        }

        return true;
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
