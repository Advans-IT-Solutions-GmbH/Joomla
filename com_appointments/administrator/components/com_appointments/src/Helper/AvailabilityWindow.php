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
 * A half-open interval [start, end) in UTC.
 *
 * Half-open is the whole point: a booking that ends at 10:00 and one that starts
 * at 10:00 do not overlap, and expressing that with inclusive bounds needs a
 * one-second fudge somewhere, which is exactly the kind of detail that produces
 * a double booking once a year.
 *
 * The class is immutable and has no Joomla dependency, so the slot engine can be
 * tested without a Joomla bootstrap.
 *
 * @since  1.0.0
 */
final class AvailabilityWindow
{
    /**
     * @var  \DateTimeImmutable
     */
    private $start;

    /**
     * @var  \DateTimeImmutable
     */
    private $end;

    /**
     * @param   \DateTimeImmutable  $start  Inclusive start, in UTC.
     * @param   \DateTimeImmutable  $end    Exclusive end, in UTC.
     *
     * @throws  \InvalidArgumentException  When the interval is empty or reversed,
     *                                     or when a bound is not in UTC.
     */
    public function __construct(\DateTimeImmutable $start, \DateTimeImmutable $end)
    {
        foreach (['start' => $start, 'end' => $end] as $label => $bound) {
            if ($bound->getTimezone()->getName() !== 'UTC') {
                throw new \InvalidArgumentException(
                    sprintf('The %s of a window must be in UTC, got %s.', $label, $bound->getTimezone()->getName())
                );
            }
        }

        if ($start >= $end) {
            throw new \InvalidArgumentException(
                sprintf('A window must end after it starts, got %s to %s.', $start->format('c'), $end->format('c'))
            );
        }

        $this->start = $start;
        $this->end   = $end;
    }

    /**
     * Build a window from two UTC timestamps in the database format.
     *
     * @param   string  $start  `Y-m-d H:i:s` in UTC.
     * @param   string  $end    `Y-m-d H:i:s` in UTC.
     */
    public static function fromUtcStrings(string $start, string $end): self
    {
        $zone = new \DateTimeZone('UTC');

        return new self(new \DateTimeImmutable($start, $zone), new \DateTimeImmutable($end, $zone));
    }

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): \DateTimeImmutable
    {
        return $this->end;
    }

    /**
     * The length of the window in seconds.
     */
    public function getDurationSeconds(): int
    {
        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }

    /**
     * Whether this window and the other one share at least one instant.
     *
     * Touching windows do not overlap, which is what makes a booking that ends
     * exactly when the next one starts legal.
     */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    /**
     * Whether the given instant lies inside the window.
     */
    public function contains(\DateTimeImmutable $moment): bool
    {
        return $moment >= $this->start && $moment < $this->end;
    }

    /**
     * Whether the window completely contains the other one.
     */
    public function containsWindow(self $other): bool
    {
        return $other->start >= $this->start && $other->end <= $this->end;
    }

    /**
     * Remove the other window from this one.
     *
     * @return  self[]  Nothing when the other window swallows this one, one
     *                  window when it cuts an end off, two when it cuts a hole
     *                  in the middle, and this window unchanged when they do not
     *                  overlap.
     */
    public function subtract(self $other): array
    {
        if (!$this->overlaps($other)) {
            return [$this];
        }

        $result = [];

        if ($other->start > $this->start) {
            $result[] = new self($this->start, $other->start);
        }

        if ($other->end < $this->end) {
            $result[] = new self($other->end, $this->end);
        }

        return $result;
    }

    /**
     * Remove every one of the given windows from this one.
     *
     * @param   self[]  $others  The windows to remove, in any order.
     *
     * @return  self[]  The remaining pieces, ordered by start.
     */
    public function subtractAll(array $others): array
    {
        $remaining = [$this];

        foreach ($others as $other) {
            $next = [];

            foreach ($remaining as $piece) {
                foreach ($piece->subtract($other) as $rest) {
                    $next[] = $rest;
                }
            }

            $remaining = $next;

            if ($remaining === []) {
                return [];
            }
        }

        return self::sort($remaining);
    }

    /**
     * Grow the window by the given number of seconds on each side.
     *
     * Used for buffers: a booking blocks its own time plus the buffer before and
     * after it. Both values are seconds and may be zero.
     *
     * @throws  \InvalidArgumentException  When a buffer is negative.
     */
    public function expand(int $secondsBefore, int $secondsAfter): self
    {
        if ($secondsBefore < 0 || $secondsAfter < 0) {
            throw new \InvalidArgumentException('A buffer cannot be negative.');
        }

        return new self(
            $this->start->modify('-' . $secondsBefore . ' seconds'),
            $this->end->modify('+' . $secondsAfter . ' seconds')
        );
    }

    /**
     * Merge overlapping and touching windows into the smallest equivalent set.
     *
     * @param   self[]  $windows  The windows to merge, in any order.
     *
     * @return  self[]  Ordered by start, with no two of them overlapping or
     *                  touching.
     */
    public static function merge(array $windows): array
    {
        $windows = self::sort($windows);
        $merged  = [];

        foreach ($windows as $window) {
            $last = end($merged);

            // Touching windows are merged as well: 09:00-10:00 and 10:00-11:00
            // describe one period of availability, and keeping them apart would
            // drop a slot that starts inside the first and ends in the second.
            if ($last !== false && $window->start <= $last->end) {
                if ($window->end > $last->end) {
                    $merged[\count($merged) - 1] = new self($last->start, $window->end);
                }

                continue;
            }

            $merged[] = $window;
        }

        return $merged;
    }

    /**
     * Order windows by start, then by end.
     *
     * @param   self[]  $windows  The windows to sort.
     *
     * @return  self[]
     */
    public static function sort(array $windows): array
    {
        $windows = array_values($windows);

        usort(
            $windows,
            static function (self $a, self $b): int {
                return $a->start <=> $b->start ?: $a->end <=> $b->end;
            }
        );

        return $windows;
    }

    /**
     * A readable form for a test failure message.
     */
    public function __toString(): string
    {
        return $this->start->format('Y-m-d H:i:s') . ' to ' . $this->end->format('Y-m-d H:i:s');
    }
}
