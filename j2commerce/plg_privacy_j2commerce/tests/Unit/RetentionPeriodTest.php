<?php

namespace Advans\Plugin\Privacy\J2Commerce\Tests\Unit;

use Advans\Plugin\Privacy\J2Commerce\Retention\RetentionPeriod;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the timezone-sensitive accounting retention logic.
 *
 * RetentionPeriod has no Joomla dependencies, so these tests exercise the real class directly
 * (fiscal year boundaries, timezone handling and invalid-date normalisation). Expected values
 * were derived from the documented rules in the class (Swiss OR Art. 958f: from the end of the
 * financial year).
 */
class RetentionPeriodTest extends TestCase
{
    private function zone(string $name): \DateTimeZone
    {
        return new \DateTimeZone($name);
    }

    /**
     * @dataProvider validityProvider
     */
    public function testIsValidFiscalYearEnd(?string $value, bool $expected): void
    {
        $this->assertSame($expected, RetentionPeriod::isValidFiscalYearEnd($value));
    }

    public static function validityProvider(): array
    {
        return [
            'default'              => ['12-31', true],
            'single digits'        => ['1-1', true],
            'leap day allowed'     => ['02-29', true],
            'surrounding spaces'   => [' 6-30 ', true],
            'feb 30 invalid'       => ['02-30', false],
            'apr 31 invalid'       => ['04-31', false],
            'month 13 invalid'     => ['13-01', false],
            'month 00 invalid'     => ['00-10', false],
            'empty invalid'        => ['', false],
            'null invalid'         => [null, false],
            'garbage invalid'      => ['xx', false],
            'full date invalid'    => ['2020-12-31', false],
        ];
    }

    public function testEffectiveFiscalYearEndFallsBackToDefault(): void
    {
        $this->assertSame('12-31', RetentionPeriod::effectiveFiscalYearEnd('02-30'));
        $this->assertSame('12-31', RetentionPeriod::effectiveFiscalYearEnd(null));
        $this->assertSame('06-30', RetentionPeriod::effectiveFiscalYearEnd(' 6-30 '));
        $this->assertSame([12, 31], RetentionPeriod::parseFiscalYearEnd('bogus'));
    }

    public function testFiscalYearEndUsesOrderDateYear(): void
    {
        $end = RetentionPeriod::fiscalYearEnd(
            new \DateTimeImmutable('2016-03-15 10:00:00', new \DateTimeZone('UTC')),
            '12-31',
            $this->zone('UTC')
        );
        $this->assertSame('2016-12-31 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function testFiscalYearEndRollsForwardAfterTheBoundary(): void
    {
        // 1 July is after a 30 June fiscal year end, so it belongs to the next fiscal year.
        $end = RetentionPeriod::fiscalYearEnd(
            new \DateTimeImmutable('2020-07-01 00:00:00', new \DateTimeZone('UTC')),
            '06-30',
            $this->zone('UTC')
        );
        $this->assertSame('2021-06-30 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function testFiscalYearEndNormalisesLeapDayInCommonYear(): void
    {
        // 29 February in the common year 2019 collapses to 28 February.
        $end = RetentionPeriod::fiscalYearEnd(
            new \DateTimeImmutable('2019-01-10 00:00:00', new \DateTimeZone('UTC')),
            '02-29',
            $this->zone('UTC')
        );
        $this->assertSame('2019-02-28 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function testRetentionEndInSiteTimeZone(): void
    {
        $end = RetentionPeriod::retentionEnd('2016-03-15 10:00:00', 10, '12-31', $this->zone('Europe/Zurich'));
        $this->assertSame('2026-12-31 23:59:59', $end->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Zurich', $end->getTimezone()->getName());
    }

    public function testRetentionEndDependsOnTheSiteTimeZone(): void
    {
        // 2016-12-31 23:30 UTC is 2017-01-01 00:30 in Zurich, so it belongs to fiscal year 2017.
        $utc    = RetentionPeriod::retentionEnd('2016-12-31 23:30:00', 10, '12-31', $this->zone('UTC'));
        $zurich = RetentionPeriod::retentionEnd('2016-12-31 23:30:00', 10, '12-31', $this->zone('Europe/Zurich'));

        $this->assertSame('2026-12-31 23:59:59', $utc->format('Y-m-d H:i:s'));
        $this->assertSame('2027-12-31 23:59:59', $zurich->format('Y-m-d H:i:s'));
    }

    public function testRetentionEndReNormalisesLeapDayPerYear(): void
    {
        // Order in fiscal year 2019 (02-28), retention +1 lands in the leap year 2020 (02-29).
        $end = RetentionPeriod::retentionEnd('2019-01-10 00:00:00', 1, '02-29', $this->zone('UTC'));
        $this->assertSame('2020-02-29 23:59:59', $end->format('Y-m-d H:i:s'));
    }

    public function testCutoffReturnsUtcBoundary(): void
    {
        $now    = new \DateTimeImmutable('2027-06-01 00:00:00', new \DateTimeZone('UTC'));
        $cutoff = RetentionPeriod::cutoff(10, '12-31', $now, $this->zone('UTC'));
        // Latest fiscal year end E with E + 10 years < now: 2016-12-31 (kept), 2017-12-31 would not be < now.
        $this->assertSame('2016-12-31 23:59:59', $cutoff);
    }

    public function testCutoffIsConvertedFromTheSiteTimeZoneToUtc(): void
    {
        $now    = new \DateTimeImmutable('2027-06-01 00:00:00', new \DateTimeZone('UTC'));
        $cutoff = RetentionPeriod::cutoff(10, '12-31', $now, $this->zone('Europe/Zurich'));
        // 2016-12-31 23:59:59 Zurich (winter, UTC+1) is 22:59:59 UTC.
        $this->assertSame('2016-12-31 22:59:59', $cutoff);
    }

    public function testCutoffHonoursFiscalYearEndMonth(): void
    {
        $now    = new \DateTimeImmutable('2027-06-01 00:00:00', new \DateTimeZone('UTC'));
        $cutoff = RetentionPeriod::cutoff(5, '06-30', $now, $this->zone('UTC'));
        $this->assertSame('2021-06-30 23:59:59', $cutoff);
    }

    public function testIsExpiredAtTheYearBoundary(): void
    {
        $now = new \DateTimeImmutable('2027-01-01 12:00:00', new \DateTimeZone('UTC'));

        // End of retention 2026-12-31 23:59:59 has passed.
        $this->assertTrue(RetentionPeriod::isExpired('2016-03-15 10:00:00', 10, '12-31', $now, $this->zone('UTC')));
        // End of retention 2027-12-31 23:59:59 is still in the future.
        $this->assertFalse(RetentionPeriod::isExpired('2017-03-15 10:00:00', 10, '12-31', $now, $this->zone('UTC')));
    }
}
