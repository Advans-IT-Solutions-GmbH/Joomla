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
     * The database form of an instant.
     */
    public static function toSql(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
