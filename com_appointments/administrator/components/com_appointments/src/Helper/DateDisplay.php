<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Formats dates for visitors in the active language. The pattern uses the letters of
 * PHP's date(); "l", "D" and "F" are replaced by the translated weekday, short weekday
 * and month, all other letters keep their date() meaning and a backslash escapes the
 * next character. The names come from Joomla's own language keys (MONDAY, MON,
 * JANUARY ...), which every installed site language provides, and the patterns from
 * the component language file, so each language sets its own order and punctuation.
 * The translator is injected so the class needs no running Joomla.
 */
final class DateDisplay
{
    private const WEEKDAYS = [1 => 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];
    private const WEEKDAYS_SHORT = [1 => 'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    private const MONTHS = [1 => 'JANUARY', 'FEBRUARY', 'MARCH', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUGUST',
        'SEPTEMBER', 'OCTOBER', 'NOVEMBER', 'DECEMBER'];

    /** @var \Closure(string): string */
    private \Closure $translate;

    /** @param callable(string): string $translate */
    public function __construct(callable $translate)
    {
        $this->translate = \Closure::fromCallable($translate);
    }

    public function format(\DateTimeInterface $moment, string $pattern): string
    {
        $result = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            if ($char === '\\') {
                $result .= $i + 1 < $length ? $pattern[++$i] : '';
                continue;
            }

            $result .= match ($char) {
                'l' => ($this->translate)(self::WEEKDAYS[(int) $moment->format('N')]),
                'D' => ($this->translate)(self::WEEKDAYS_SHORT[(int) $moment->format('N')]),
                'F' => ($this->translate)(self::MONTHS[(int) $moment->format('n')]),
                // Only ASCII letters are date() formats; every other byte, including
                // the bytes of a multibyte character, is copied unchanged.
                default => ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z') ? $moment->format($char) : $char,
            };
        }

        return $result;
    }

    /**
     * The local time of $moment in $format. A wall-clock time that occurs twice in the
     * moment's zone (the repeated hour when the clocks go back) gets its UTC offset
     * through $ambiguous ("%1$s" the time, "%2$s" the offset such as +01:00), so two
     * different instants never read the same. All other times stay compact.
     */
    public function time(\DateTimeInterface $moment, string $format, string $ambiguous): string
    {
        $time = $this->format($moment, $format);

        return $this->isRepeated($moment) ? sprintf($ambiguous, $time, $moment->format('P')) : $time;
    }

    /**
     * The time range of an appointment for a summary that already shows the start
     * date. When the end falls on another local date (across midnight), the end also
     * names its date through $dateTime ("%1$s" date, "%2$s" time). Same-day ranges stay
     * compact. $range joins start and end ("%1$s" start, "%2$s" end).
     */
    public function range(\DateTimeInterface $start, \DateTimeInterface $end, string $timeFormat, string $dayFormat,
        string $ambiguous, string $range, string $dateTime): string
    {
        $endText = $this->time($end, $timeFormat, $ambiguous);

        if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $endText = sprintf($dateTime, $this->format($end, $dayFormat), $endText);
        }

        return sprintf($range, $this->time($start, $timeFormat, $ambiguous), $endText);
    }

    /** Whether the local wall-clock time of $moment also occurs at another instant. */
    public function isRepeated(\DateTimeInterface $moment): bool
    {
        $timestamp = $moment->getTimestamp();
        $transitions = $moment->getTimezone()->getTransitions($timestamp - 86400, $timestamp + 86400) ?: [];

        // The first entry is the state at the start of the range, not a transition.
        foreach (array_slice($transitions, 1) as $index => $transition) {
            $before = (int) $transitions[$index]['offset'];
            $shift = $before - (int) $transition['offset'];

            // Clocks going back by $shift seconds repeat the local times of the last
            // $shift seconds before the transition during the first $shift seconds after it.
            if ($shift > 0 && $timestamp >= $transition['ts'] - $shift && $timestamp < $transition['ts'] + $shift) {
                return true;
            }
        }

        return false;
    }

    /**
     * The weekday headings of a calendar week that starts on Monday.
     *
     * @return list<array{short: string, full: string}>
     */
    public function weekdays(): array
    {
        $days = [];

        foreach (self::WEEKDAYS as $number => $key) {
            $days[] = ['short' => ($this->translate)(self::WEEKDAYS_SHORT[$number]), 'full' => ($this->translate)($key)];
        }

        return $days;
    }
}
