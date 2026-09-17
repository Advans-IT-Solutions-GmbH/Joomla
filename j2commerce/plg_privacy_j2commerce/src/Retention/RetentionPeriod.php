<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Retention
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Retention;

defined('_JEXEC') or die;

/**
 * Accounting retention period of an order.
 *
 * The period starts at the end of the fiscal year in which the order was placed (Swiss OR
 * Art. 958f: "from the end of the financial year"; German AO § 147 (4) likewise from the end of
 * the calendar year), not on the order date. With a fiscal year ending on 31 December and a
 * period of 10 years, an order of 15 March 2016 is kept until 31 December 2026 and may be
 * anonymized from 1 January 2027.
 *
 * Order dates (J2Commerce created_on) are stored in UTC. The fiscal year is determined in the
 * site time zone (Joomla "offset"): an order placed on 1 January 2017 at 00:30 in Zurich is stored
 * as 2016-12-31 23:30 UTC and still belongs to fiscal year 2017. cutoff() returns the boundary in
 * UTC for SQL comparisons with created_on.
 *
 * Fiscal year end "MM-DD": month 1-12, day 1 to the month's length in a leap year (02-29 allowed).
 * A day beyond the month's length in a given year (29 February in a common year) means the
 * month's last day. Other values are invalid and count as 12-31.
 */
final class RetentionPeriod
{
    /** Fiscal year end as MM-DD. */
    public const DEFAULT_FISCAL_YEAR_END = '12-31';

    /**
     * Whether a fiscal year end value is valid (see class description).
     */
    public static function isValidFiscalYearEnd(?string $value): bool
    {
        return self::parse($value) !== null;
    }

    /**
     * Month and day of a fiscal year end; invalid values fall back to 31 December.
     *
     * @return  array{0: int, 1: int}
     */
    public static function parseFiscalYearEnd(?string $value): array
    {
        return self::parse($value) ?? [12, 31];
    }

    /**
     * Fiscal year end that is actually used, as MM-DD (for logs and messages).
     */
    public static function effectiveFiscalYearEnd(?string $value): string
    {
        [$month, $day] = self::parseFiscalYearEnd($value);

        return sprintf('%02d-%02d', $month, $day);
    }

    /**
     * Time zone from a Joomla "offset" value; UTC if empty or unknown.
     */
    public static function timeZone(?string $offset): \DateTimeZone
    {
        try {
            return new \DateTimeZone($offset !== null && trim($offset) !== '' ? trim($offset) : 'UTC');
        } catch (\Throwable $e) {
            return new \DateTimeZone('UTC');
        }
    }

    /**
     * End (23:59:59 in $zone) of the fiscal year that contains the given moment.
     */
    public static function fiscalYearEnd(\DateTimeInterface $date, ?string $fiscalYearEnd = null, ?\DateTimeZone $zone = null): \DateTimeImmutable
    {
        [$month, $day] = self::parseFiscalYearEnd($fiscalYearEnd);
        $zone        ??= new \DateTimeZone('UTC');
        $local         = \DateTimeImmutable::createFromInterface($date)->setTimezone($zone);
        $year          = (int) $local->format('Y');
        $end           = self::endOfDay($year, $month, $day, $zone);

        return $end < $local ? self::endOfDay($year + 1, $month, $day, $zone) : $end;
    }

    /**
     * Last moment (23:59:59 in $zone) on which an order placed at $orderDate (UTC, Y-m-d H:i:s)
     * must still be kept.
     */
    public static function retentionEnd(string $orderDate, int $years, ?string $fiscalYearEnd = null, ?\DateTimeZone $zone = null): \DateTimeImmutable
    {
        [$month, $day] = self::parseFiscalYearEnd($fiscalYearEnd);
        $zone        ??= new \DateTimeZone('UTC');
        $end           = self::fiscalYearEnd(new \DateTimeImmutable($orderDate, new \DateTimeZone('UTC')), $fiscalYearEnd, $zone);

        return self::endOfDay((int) $end->format('Y') + max(0, $years), $month, $day, $zone);
    }

    /**
     * Orders with created_on <= the returned value (Y-m-d H:i:s, UTC) are outside the retention
     * period: their retention end lies before $now.
     */
    public static function cutoff(int $years, ?string $fiscalYearEnd = null, ?\DateTimeInterface $now = null, ?\DateTimeZone $zone = null): string
    {
        [$month, $day] = self::parseFiscalYearEnd($fiscalYearEnd);
        $zone        ??= new \DateTimeZone('UTC');
        $years         = max(0, $years);
        $now           = $now !== null ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // The latest fiscal year end E with E + years < now.
        $year = (int) $now->setTimezone($zone)->format('Y') - $years;

        while (self::endOfDay($year + $years, $month, $day, $zone) >= $now) {
            $year--;
        }

        return self::endOfDay($year, $month, $day, $zone)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Whether an order is outside its retention period.
     */
    public static function isExpired(string $orderDate, int $years, ?string $fiscalYearEnd = null, ?\DateTimeInterface $now = null, ?\DateTimeZone $zone = null): bool
    {
        $now = $now !== null ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return self::retentionEnd($orderDate, $years, $fiscalYearEnd, $zone) < $now;
    }

    /**
     * @return  array{0: int, 1: int}|null
     */
    private static function parse(?string $value): ?array
    {
        if (!\is_string($value) || preg_match('/^\s*(\d{1,2})-(\d{1,2})\s*$/', $value, $match) !== 1) {
            return null;
        }

        $month = (int) $match[1];
        $day   = (int) $match[2];

        // 2000 is a leap year: 02-29 is valid, 02-30 and 04-31 are not.
        return checkdate($month, $day, 2000) ? [$month, $day] : null;
    }

    private static function endOfDay(int $year, int $month, int $day, \DateTimeZone $zone): \DateTimeImmutable
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $zone);
        $day   = min($day, (int) $first->format('t'));

        return $first->setDate($year, $month, $day)->setTime(23, 59, 59);
    }
}
