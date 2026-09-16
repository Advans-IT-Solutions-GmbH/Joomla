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
 * All dates are handled in UTC, like the J2Commerce created_on columns.
 */
final class RetentionPeriod
{
    /** Fiscal year end as MM-DD. */
    public const DEFAULT_FISCAL_YEAR_END = '12-31';

    /**
     * Month and day of a fiscal year end "MM-DD". Invalid values (including 02-29, which does not
     * exist every year) fall back to 31 December.
     *
     * @return  array{0: int, 1: int}
     */
    public static function parseFiscalYearEnd(?string $value): array
    {
        if (\is_string($value) && preg_match('/^\s*(\d{1,2})-(\d{1,2})\s*$/', $value, $match) === 1) {
            $month = (int) $match[1];
            $day   = (int) $match[2];

            if (checkdate($month, $day, 2001)) {
                return [$month, $day];
            }
        }

        return [12, 31];
    }

    /**
     * End (23:59:59) of the fiscal year that contains the given date.
     */
    public static function fiscalYearEnd(\DateTimeInterface $date, ?string $fiscalYearEnd = null): \DateTimeImmutable
    {
        [$month, $day] = self::parseFiscalYearEnd($fiscalYearEnd);
        $date          = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'));
        $end           = self::endOfDay((int) $date->format('Y'), $month, $day);

        return $end < $date ? self::endOfDay((int) $date->format('Y') + 1, $month, $day) : $end;
    }

    /**
     * Last day (23:59:59) on which an order placed at $orderDate must still be kept.
     */
    public static function retentionEnd(string $orderDate, int $years, ?string $fiscalYearEnd = null): \DateTimeImmutable
    {
        $end = self::fiscalYearEnd(new \DateTimeImmutable($orderDate, new \DateTimeZone('UTC')), $fiscalYearEnd);

        return self::endOfDay((int) $end->format('Y') + max(0, $years), (int) $end->format('n'), (int) $end->format('j'));
    }

    /**
     * Orders with created_on <= the returned value (Y-m-d H:i:s, UTC) are outside the retention
     * period: their retention end lies before $now.
     */
    public static function cutoff(int $years, ?string $fiscalYearEnd = null, ?\DateTimeInterface $now = null): string
    {
        [$month, $day] = self::parseFiscalYearEnd($fiscalYearEnd);
        $utc           = new \DateTimeZone('UTC');
        $now           = $now !== null ? \DateTimeImmutable::createFromInterface($now)->setTimezone($utc) : new \DateTimeImmutable('now', $utc);

        // The latest fiscal year end E with E + years < now, i.e. E < now - years.
        $year = (int) $now->format('Y') - max(0, $years);
        $end  = self::endOfDay($year, $month, $day);

        while (self::endOfDay((int) $end->format('Y') + max(0, $years), $month, $day) >= $now) {
            $end = self::endOfDay((int) $end->format('Y') - 1, $month, $day);
        }

        return $end->format('Y-m-d H:i:s');
    }

    /**
     * Whether an order is outside its retention period.
     */
    public static function isExpired(string $orderDate, int $years, ?string $fiscalYearEnd = null, ?\DateTimeInterface $now = null): bool
    {
        $now = $now !== null ? \DateTimeImmutable::createFromInterface($now) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return self::retentionEnd($orderDate, $years, $fiscalYearEnd) < $now;
    }

    private static function endOfDay(int $year, int $month, int $day): \DateTimeImmutable
    {
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $day), new \DateTimeZone('UTC'));
    }
}
