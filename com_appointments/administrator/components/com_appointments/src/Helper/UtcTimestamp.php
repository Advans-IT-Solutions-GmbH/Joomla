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
 * Reads a stored or submitted timestamp as UTC, strictly.
 *
 * `new DateTimeImmutable($value)` is the wrong tool for a value that came from
 * a form or a database column, because it is forgiving in ways that matter for
 * an appointment: it turns an impossible 2026-02-31 into the 3rd of March, and
 * it accepts relative text such as "tomorrow". Either would store a time nobody
 * entered, and for a blocked period or a booking that is a wrong answer rather
 * than a lenient one.
 *
 * A value is therefore accepted only when it parses under an exact format and
 * comes back out of the parser unchanged.
 *
 * The class is pure: no Joomla, no clock, no database, so it is unit tested
 * without a bootstrap.
 *
 * @since  1.0.0
 */
final class UtcTimestamp
{
    /**
     * The formats accepted, in the order they are tried. The calendar field
     * submits seconds; a stored value or a hand-written one may leave them out.
     */
    private const FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i'];

    /**
     * @param   string  $value  The timestamp as it was submitted or stored.
     *
     * @return  \DateTimeImmutable|null  The instant in UTC, or null when the
     *                                   value is not an exact timestamp.
     */
    public static function parse(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);

        // A database zero date is not a date, and reading it as one would put
        // the value in the year zero.
        if ($value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        foreach (self::FORMATS as $format) {
            // The leading "!" resets every field the format does not name, so
            // a missing seconds field becomes 00 rather than the current one.
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($parsed === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
                continue;
            }

            // The round trip is what catches a normalised date: 2026-02-31
            // parses without an error but comes back as 2026-03-03.
            if ($parsed->format($format) === $value) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Whether the value is an exact timestamp at all.
     *
     * Used on a value that is still in somebody's local time, before Joomla's
     * form filter converts it. It answers only the calendar question, whether
     * the date and the time of day exist, which has the same answer in every
     * zone. Whether that wall clock exists *in a particular zone* is a
     * different question, and `localInstants()` is the one that answers it.
     */
    public static function isTimestamp(string $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Every instant a local wall clock stands for in one time zone.
     *
     * A wall clock is not an instant. On the night the clocks move forward it
     * can stand for no instant at all, because the hour is skipped, and on the
     * night they move back it stands for two, because the hour is repeated.
     * `new DateTime($value, $zone)` hides both cases: it shifts the skipped
     * time to the other side of the transition and picks one of the two
     * repeated ones, so a caller that trusts it stores an instant nobody
     * entered. The three answers are told apart by the size of the result:
     *
     * - empty: the wall clock does not exist in that zone;
     * - one instant: the ordinary case;
     * - two instants: the wall clock is ambiguous, and both are returned in
     *   chronological order so a caller can name them.
     *
     * The method does not pick for the caller, because the right choice
     * depends on what is being read. A recurring availability rule has to
     * produce something and therefore resolves the skipped and the repeated
     * hour by a stated convention, while a one-off absence an operator typed
     * is better refused with a question than stored as a guess.
     *
     * @param   string         $value  The wall clock, in the format `parse()` accepts.
     * @param   \DateTimeZone  $zone   The zone the wall clock is read in.
     *
     * @return  \DateTimeImmutable[]  The matching instants, in UTC.
     */
    public static function localInstants(string $value, \DateTimeZone $zone): array
    {
        // Read strictly first: an impossible calendar date has no instant in
        // any zone, and that is not a time-zone question.
        $wall = self::parse($value);

        if ($wall === null) {
            return [];
        }

        $clock = $wall->format('Y-m-d H:i:s');

        // The instant the wall clock would be if the zone were UTC. Subtracting
        // an offset from it gives the instant that shows that wall clock under
        // that offset, which is the candidate to verify.
        $pivot = $wall->getTimestamp();

        // Every offset the zone uses around the wall clock. Two days either
        // side covers the transition itself whichever way it goes, and the
        // offset at the pivot is added because a zone that never changes has no
        // transition in the span at all.
        $offsets = [$zone->getOffset($wall) => true];

        foreach ($zone->getTransitions($pivot - 172800, $pivot + 172800) as $transition) {
            $offsets[(int) $transition['offset']] = true;
        }

        $instants = [];

        foreach (array_keys($offsets) as $offset) {
            $candidate = new \DateTimeImmutable('@' . ($pivot - $offset));

            // The verification is what makes the result right rather than
            // plausible: an offset the zone uses somewhere in the span does not
            // have to be the offset in force at this candidate.
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i:s') === $clock) {
                $instants[$candidate->getTimestamp()] = $candidate->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        ksort($instants);

        return array_values($instants);
    }

    /**
     * The database form of an instant.
     */
    public static function toSql(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
