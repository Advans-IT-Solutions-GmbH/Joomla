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
 * Turns a weekly schedule plus date-specific overrides into concrete windows in
 * UTC.
 *
 * The schedule's own time zone is the authority for what "Tuesday 14:00" means,
 * so every rule is resolved per concrete date in that zone and only then
 * converted to UTC. That is what makes the two days a year on which the clocks
 * change come out right without a special case anywhere else.
 *
 * Both of those days are handled here and nowhere else:
 *
 * - **Spring forward.** An hour of local time does not exist. A rule whose start
 *   falls inside it starts at the transition instead, a rule whose end falls
 *   inside it ends at the transition, and a rule that lies entirely inside it
 *   yields nothing. No window ever begins at a local time that did not happen.
 * - **Autumn back.** An hour of local time happens twice. The second occurrence
 *   is subtracted from the resolved windows, so that hour is offered once. A
 *   visitor can therefore never book an ambiguous time, and the host does not
 *   see the same wall-clock slot twice.
 *
 * The class is pure: no clock, no database, no Joomla. Everything it needs is an
 * argument.
 *
 * @since  1.0.0
 */
final class ScheduleResolver
{
    /**
     * @var  \DateTimeZone
     */
    private $zone;

    /**
     * Weekly rules by ISO weekday, 1 = Monday to 7 = Sunday.
     *
     * @var  array<int, array<int, array{start:string, end:string}>>
     */
    private $weekly = [];

    /**
     * Date-specific rules by date, `Y-m-d`.
     *
     * @var  array<string, array<int, array{start:string, end:string}>>
     */
    private $overrides = [];

    /**
     * Dates that are closed, `Y-m-d` => true.
     *
     * @var  array<string, bool>
     */
    private $closedDates = [];

    /**
     * @param   string  $timezone       IANA name of the schedule's time zone.
     * @param   array   $weeklyRules    Rows with `weekday`, `start_time`, `end_time`.
     * @param   array   $dateOverrides  Rows with `specific_date`, `start_time`,
     *                                  `end_time` and `is_unavailable`.
     *
     * @throws  \InvalidArgumentException  On an unknown zone or an unusable rule.
     */
    public function __construct(string $timezone, array $weeklyRules, array $dateOverrides = [])
    {
        try {
            $this->zone = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Unknown time zone "%s".', $timezone), 0, $e);
        }

        foreach ($weeklyRules as $rule) {
            $weekday = (int) ($rule['weekday'] ?? 0);

            if ($weekday < 1 || $weekday > 7) {
                throw new \InvalidArgumentException(
                    sprintf('A weekly rule needs an ISO weekday from 1 to 7, got "%s".', (string) ($rule['weekday'] ?? ''))
                );
            }

            $this->weekly[$weekday][] = [
                'start' => self::normaliseTime((string) ($rule['start_time'] ?? '')),
                'end'   => self::normaliseTime((string) ($rule['end_time'] ?? '')),
            ];
        }

        foreach ($dateOverrides as $rule) {
            $date = (string) ($rule['specific_date'] ?? '');

            // The shape alone is not enough. 2026-02-31 has the right shape and
            // PHP would quietly read it as the 3rd of March when the windows of
            // that date are built, so the override would land on a date nobody
            // asked for, or, since the key never matches a real date in the
            // range, be ignored without a word.
            if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
                || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            ) {
                throw new \InvalidArgumentException(
                    sprintf('A date-specific rule needs a date that exists, got "%s".', $date)
                );
            }

            // A date that carries a closed-all-day row is closed, whatever else
            // the same date carries. The administrator interface prevents the
            // combination, but a hand-edited row must not reopen a holiday.
            if (!empty($rule['is_unavailable'])) {
                $this->closedDates[$date] = true;

                continue;
            }

            $this->overrides[$date][] = [
                'start' => self::normaliseTime((string) ($rule['start_time'] ?? '')),
                'end'   => self::normaliseTime((string) ($rule['end_time'] ?? '')),
            ];
        }
    }

    /**
     * The schedule's time zone.
     */
    public function getTimezone(): \DateTimeZone
    {
        return $this->zone;
    }

    /**
     * Resolve the schedule into the UTC windows that overlap the given range.
     *
     * The windows are returned **whole, not clipped to the range**, and the
     * windows of two different local dates are **never merged**. Both are
     * deliberate, and both exist because the slot engine anchors its grid to the
     * start of a window:
     *
     * - A window cut off at "now plus the minimum notice" or at the edge of the
     *   booking horizon would move the grid and offer a visitor times like 10:31
     *   that the schedule never contained.
     * - Merging across dates would make the grid depend on the requested range.
     *   A schedule that is open around the clock would produce one window
     *   starting on whichever date the resolution happened to begin with, so a
     *   month-long availability query and a one-day re-check could offer
     *   different times for the same day. Every date's window now depends on
     *   that date's own rules alone, which is what makes the result
     *   range-independent.
     *
     * The price is that a meeting cannot span the boundary between two dates'
     * windows even when the availability is continuous there. An operator who
     * wants that writes one rule that crosses midnight, which stays one window.
     *
     * @param   \DateTimeImmutable  $fromUtc  Inclusive start of the range, in UTC.
     * @param   \DateTimeImmutable  $toUtc    Exclusive end of the range, in UTC.
     *
     * @return  AvailabilityWindow[]  Ordered by start; merged within a date only.
     *
     * @throws  \InvalidArgumentException  When the range is empty or reversed.
     */
    public function resolve(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        $range   = new AvailabilityWindow($fromUtc, $toUtc);
        $windows = [];

        foreach ($this->localDatesFor($range) as $date) {
            foreach ($this->windowsForDate($date) as $window) {
                if ($window->overlaps($range)) {
                    $windows[] = $window;
                }
            }
        }

        return AvailabilityWindow::sort($windows);
    }

    /**
     * The periods inside the range that the schedule itself makes unavailable.
     *
     * These are **not** cut out of the windows `resolve()` returns, and that is
     * the whole point. The slot engine anchors its grid to the start of a
     * window, so cutting a window in two would restart the grid at the second
     * piece: with a 40-minute step, a 01:00 to 05:00 rule would offer 03:00,
     * 03:40 and 04:20 after a clock change instead of staying on the phase the
     * rule began with. Handing these periods to the engine as something to
     * remove keeps the window, and therefore the grid, intact — exactly the way
     * an existing booking is handled.
     *
     * Two kinds of period are reported:
     *
     * - **The second pass over a repeated hour.** On the day the clocks go back,
     *   one hour of local time happens twice. Removing its second occurrence is
     *   what makes that hour bookable once, so no booking can be made at an
     *   ambiguous time.
     * - **The part of a governed day its own rules do not cover.** A
     *   date-specific entry replaces the availability of its date and a closed
     *   date removes it, and that has to hold for time an interval of the
     *   previous date spills into as well: a Monday 22:00 to 02:00 rule must not
     *   keep Tuesday 00:00 to 02:00 open when Tuesday is closed or carries its
     *   own times.
     *
     * @return  AvailabilityWindow[]  Merged and ordered.
     */
    public function unavailablePeriods(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        $range   = new AvailabilityWindow($fromUtc, $toUtc);
        $periods = [];

        foreach ($this->localDatesFor($range) as $date) {
            $repeated = $this->repeatedHour($date);

            if ($repeated !== null) {
                $periods[] = $repeated;
            }

            if (!isset($this->closedDates[$date]) && !isset($this->overrides[$date])) {
                continue;
            }

            $day = $this->localDayWindow($date);

            if ($day === null) {
                continue;
            }

            // Everything of that day its own rules do not cover.
            foreach ($day->subtractAll($this->windowsForDate($date)) as $piece) {
                $periods[] = $piece;
            }
        }

        return array_values(
            array_filter(
                AvailabilityWindow::merge($periods),
                static function (AvailabilityWindow $period) use ($range): bool {
                    return $period->overlaps($range);
                }
            )
        );
    }

    /**
     * One local day as a UTC window, from local midnight to the next local
     * midnight.
     *
     * The length is 23, 24 or 25 hours depending on the day, which is exactly
     * why it is built from the local dates and not by adding 86400 seconds.
     */
    private function localDayWindow(string $date): ?AvailabilityWindow
    {
        $next = (new \DateTimeImmutable($date . ' 12:00:00', $this->zone))
            ->modify('+1 day')
            ->format('Y-m-d');

        $start = $this->localToUtc($date, '00:00:00');
        $end   = $this->localToUtc($next, '00:00:00');

        return $start < $end ? new AvailabilityWindow($start, $end) : null;
    }

    /**
     * The windows of one local date, in UTC.
     *
     * @param   string  $date  `Y-m-d` in the schedule's time zone.
     *
     * @return  AvailabilityWindow[]
     */
    public function windowsForDate(string $date): array
    {
        if (isset($this->closedDates[$date])) {
            return [];
        }

        // A date-specific set of intervals replaces the weekday's rules
        // entirely; it does not add to them.
        if (isset($this->overrides[$date])) {
            $rules = $this->overrides[$date];
        } else {
            $weekday = (int) (new \DateTimeImmutable($date . ' 12:00:00', $this->zone))->format('N');
            $rules   = $this->weekly[$weekday] ?? [];
        }

        if ($rules === []) {
            return [];
        }

        $windows = [];

        foreach ($rules as $rule) {
            $window = $this->ruleToWindow($date, $rule['start'], $rule['end']);

            if ($window !== null) {
                $windows[] = $window;
            }
        }

        // Whole windows, uncut. What a clock change or a governed day takes out
        // of them is reported by unavailablePeriods() instead, so the start of
        // a window stays the start of its rule and the slot grid keeps its
        // phase.
        return AvailabilityWindow::merge($windows);
    }

    /**
     * One rule of one date as a UTC window, or null when it describes no real
     * time at all.
     *
     * @param   string  $date   `Y-m-d` in the schedule's zone.
     * @param   string  $start  `H:i:s` local.
     * @param   string  $end    `H:i:s` local, on the next day when it is not
     *                          after the start.
     */
    private function ruleToWindow(string $date, string $start, string $end): ?AvailabilityWindow
    {
        // An end that is not after the start continues past midnight, which
        // stays one window: a night shift is one period of availability.
        $endDate = $end > $start
            ? $date
            : (new \DateTimeImmutable($date . ' 12:00:00', $this->zone))->modify('+1 day')->format('Y-m-d');

        $startUtc = $this->localToUtc($date, $start);
        $endUtc   = $this->localToUtc($endDate, $end);

        if ($startUtc >= $endUtc) {
            // The rule lies entirely inside an hour that did not happen.
            return null;
        }

        return new AvailabilityWindow($startUtc, $endUtc);
    }

    /**
     * A local date and time as a UTC instant.
     *
     * Two corrections are applied here, and both are the reason this method
     * exists instead of a bare `new DateTimeImmutable()`:
     *
     * - **A time that does not exist.** When the clocks moved forward over it,
     *   the transition instant is returned: the first moment that really
     *   happened at or after the requested one. PHP would otherwise shift the
     *   time by the size of the gap and invent availability at a wall-clock time
     *   nobody ever saw.
     * - **A time that happened twice.** PHP resolves an ambiguous local time to
     *   the *second* occurrence, after the clocks went back. The component's rule
     *   is the first occurrence, so the instant is moved back by the size of the
     *   repeat. Without this, a rule that begins inside the repeated hour would
     *   be read as the occurrence that `windowsForDate()` then removes, and the
     *   hour would be lost entirely instead of being offered once.
     */
    private function localToUtc(string $date, string $time): \DateTimeImmutable
    {
        $local = new \DateTimeImmutable($date . ' ' . $time, $this->zone);
        $utc   = $local->setTimezone(new \DateTimeZone('UTC'));

        if ($local->format('H:i:s') !== $time) {
            $gapEnd = $this->forwardTransition($date);

            // No forward transition found for the date, so the mismatch cannot
            // be explained. Rather than guess, use what PHP resolved.
            return $gapEnd ?? $utc;
        }

        $repeated = $this->repeatedHour($date);

        if ($repeated !== null && $repeated->contains($utc)) {
            return $utc->modify('-' . $repeated->getDurationSeconds() . ' seconds');
        }

        return $utc;
    }

    /**
     * The instant at which the clocks move forward on the given local date, or
     * null when they do not.
     */
    private function forwardTransition(string $date): ?\DateTimeImmutable
    {
        foreach ($this->transitionsOn($date) as $transition) {
            if ($transition['difference'] > 0) {
                return $transition['at'];
            }
        }

        return null;
    }

    /**
     * The second occurrence of the hour that happens twice on the given local
     * date, as a UTC window, or null when the clocks do not move back.
     */
    private function repeatedHour(string $date): ?AvailabilityWindow
    {
        foreach ($this->transitionsOn($date) as $transition) {
            if ($transition['difference'] < 0) {
                return new AvailabilityWindow(
                    $transition['at'],
                    $transition['at']->modify('+' . abs($transition['difference']) . ' seconds')
                );
            }
        }

        return null;
    }

    /**
     * The time-zone transitions that happen on one local date.
     *
     * @return  array<int, array{at:\DateTimeImmutable, difference:int}>  `difference`
     *          is the change of the UTC offset in seconds: positive when the
     *          clocks move forward, negative when they move back.
     */
    private function transitionsOn(string $date): array
    {
        $utc = new \DateTimeZone('UTC');

        // Noon is used as the anchor, because midnight itself can be the moment
        // of a transition in some zones.
        $noon      = new \DateTimeImmutable($date . ' 12:00:00', $this->zone);
        $dayStart  = $noon->modify('-12 hours')->getTimestamp();
        $dayEnd    = $noon->modify('+12 hours')->getTimestamp();
        $found     = [];

        // One day of slack on each side, so the transition in effect at the
        // start of the day is always the first entry returned.
        $transitions = $this->zone->getTransitions($dayStart - 86400, $dayEnd + 86400);

        if (!\is_array($transitions) || \count($transitions) < 2) {
            return [];
        }

        $previous = $transitions[0];

        foreach (\array_slice($transitions, 1) as $transition) {
            $timestamp = (int) $transition['ts'];

            if ($timestamp >= $dayStart && $timestamp < $dayEnd) {
                $found[] = [
                    'at'         => (new \DateTimeImmutable('@' . $timestamp))->setTimezone($utc),
                    'difference' => (int) $transition['offset'] - (int) $previous['offset'],
                ];
            }

            $previous = $transition;
        }

        return $found;
    }

    /**
     * Every local date that can contribute a window to the range.
     *
     * The day before the range is included, because a window that crosses
     * midnight starts on the previous local date.
     *
     * @return  string[]  `Y-m-d`, ordered.
     */
    private function localDatesFor(AvailabilityWindow $range): array
    {
        $first = $range->getStart()->setTimezone($this->zone)->modify('-1 day')->format('Y-m-d');
        $last  = $range->getEnd()->setTimezone($this->zone)->format('Y-m-d');
        $dates = [];

        $cursor = new \DateTimeImmutable($first . ' 12:00:00', $this->zone);
        $end    = new \DateTimeImmutable($last . ' 12:00:00', $this->zone);

        while ($cursor <= $end) {
            $dates[] = $cursor->format('Y-m-d');
            $cursor  = $cursor->modify('+1 day');
        }

        return $dates;
    }

    /**
     * Accept `H:i` and `H:i:s` and return `H:i:s`.
     *
     * @throws  \InvalidArgumentException  On anything else.
     */
    private static function normaliseTime(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', trim($time), $parts)) {
            $hour   = (int) $parts[1];
            $minute = (int) $parts[2];
            $second = (int) ($parts[3] ?? 0);

            if ($hour <= 23 && $minute <= 59 && $second <= 59) {
                return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
            }
        }

        throw new \InvalidArgumentException(sprintf('"%s" is not a time of day.', $time));
    }
}
