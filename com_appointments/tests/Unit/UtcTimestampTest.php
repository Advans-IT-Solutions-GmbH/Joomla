<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use PHPUnit\Framework\TestCase;

/**
 * A timestamp that came from a form or a database column has to be read
 * strictly. Reading it leniently would store a time nobody entered, and for an
 * appointment that is a wrong answer rather than a tolerant one.
 */
final class UtcTimestampTest extends TestCase
{
    public function testAFullTimestampIsAccepted(): void
    {
        $parsed = UtcTimestamp::parse('2026-06-02 10:30:00');

        $this->assertNotNull($parsed);
        $this->assertSame('2026-06-02 10:30:00', $parsed->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $parsed->getTimezone()->getName());
    }

    public function testATimestampWithoutSecondsIsAccepted(): void
    {
        $parsed = UtcTimestamp::parse('2026-06-02 10:30');

        $this->assertNotNull($parsed);
        // The leading "!" in the format resets the seconds instead of taking
        // them from the current time, which would make the value depend on
        // when it was read.
        $this->assertSame('2026-06-02 10:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    public function testSurroundingSpaceIsIgnored(): void
    {
        $this->assertNotNull(UtcTimestamp::parse("  2026-06-02 10:30:00\n"));
    }

    /**
     * @dataProvider unusableValues
     */
    public function testAnUnusableValueIsRefused(string $value, string $why): void
    {
        $this->assertNull(UtcTimestamp::parse($value), $why);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function unusableValues(): array
    {
        return [
            'empty'            => ['', 'nothing was entered'],
            'space only'       => ['   ', 'nothing was entered'],
            'zero date'        => ['0000-00-00 00:00:00', 'a database zero date is not a date'],
            'impossible day'   => ['2026-02-31 10:00:00', 'February has no 31st, and it must not become the 3rd of March'],
            'impossible month' => ['2026-13-02 10:00:00', 'there is no thirteenth month'],
            'impossible hour'  => ['2026-06-02 25:00:00', 'there is no twenty-fifth hour'],
            'relative text'    => ['tomorrow', 'relative text is not a timestamp'],
            'now'              => ['now', 'relative text is not a timestamp'],
            'date only'        => ['2026-06-02', 'a blocked period needs a time of day as well'],
            'other format'     => ['02.06.2026 10:30', 'only the database format is accepted'],
            'with timezone'    => ['2026-06-02T10:30:00+02:00', 'the value is UTC by contract, not by suffix'],
            'trailing text'    => ['2026-06-02 10:30:00 oclock', 'the whole value has to be the timestamp'],
            'year below range' => ['0001-01-01 00:00:00', 'the date columns support 1000 onwards, so nothing earlier is written'],
            'year 999'         => ['0999-12-31 23:59:59', 'the last year before the supported range'],
        ];
    }

    public function testTheEdgesOfTheSupportedYearRangeAreAccepted(): void
    {
        // The range is a property of the columns the value is written to, so
        // both ends belong to it and neither is off by a year.
        $this->assertNotNull(UtcTimestamp::parse('1000-01-01 00:00:00'));
        $this->assertNotNull(UtcTimestamp::parse('9999-12-31 23:59:59'));
        $this->assertSame(1000, UtcTimestamp::MIN_YEAR);
        $this->assertSame(9999, UtcTimestamp::MAX_YEAR);
    }

    /**
     * @dataProvider dateValues
     */
    public function testIsDateReadsADateStrictly(string $value, bool $expected, string $why): void
    {
        $this->assertSame($expected, UtcTimestamp::isDate($value), $why);
    }

    /**
     * @return array<string, array{0:string, 1:bool, 2:string}>
     */
    public static function dateValues(): array
    {
        return [
            'a date'           => ['2026-06-02', true, 'the ordinary case'],
            'leap day'         => ['2028-02-29', true, '2028 is a leap year'],
            'first supported'  => ['1000-01-01', true, 'the start of the column range'],
            'last supported'   => ['9999-12-31', true, 'the end of the column range'],
            'no leap day'      => ['2026-02-29', false, '2026 is not a leap year'],
            'impossible day'   => ['2026-02-31', false, 'February has no 31st'],
            'below range'      => ['0001-01-01', false, 'earlier than the column supports'],
            'zero date'        => ['0000-00-00', false, 'a database zero date is not a date'],
            'with a time'      => ['2026-06-02 10:00:00', false, 'a date-specific rule is a date and nothing else'],
            'single digits'    => ['2026-6-2', false, 'only the database format is accepted'],
            'empty'            => ['', false, 'nothing was entered'],
            'relative text'    => ['tomorrow', false, 'relative text is not a date'],
        ];
    }

    public function testIsTimestampAnswersTheCalendarQuestionOnly(): void
    {
        // Used on a value that is still in somebody's local time, before the
        // form filter converts it. Whether the date and the time of day exist
        // at all has the same answer in every zone; whether that wall clock
        // exists in a particular zone is what localInstants() answers below.
        $this->assertTrue(UtcTimestamp::isTimestamp('2026-06-02 10:30:00'));
        $this->assertTrue(UtcTimestamp::isTimestamp('2026-06-02 10:30'));
        $this->assertFalse(UtcTimestamp::isTimestamp('2026-02-31 10:00:00'));
        $this->assertFalse(UtcTimestamp::isTimestamp('tomorrow'));
        $this->assertFalse(UtcTimestamp::isTimestamp(''));
    }

    /**
     * The clocks in Europe/Zurich move forward on 29 March 2026 and back on
     * 25 October 2026, both at 01:00 UTC. Every case below is one of those two
     * nights or an ordinary day, so the expectations are arithmetic rather
     * than taken from the implementation.
     */
    public function testAnOrdinaryWallClockStandsForExactlyOneInstant(): void
    {
        $instants = UtcTimestamp::localInstants('2026-06-02 14:00:00', new \DateTimeZone('Europe/Zurich'));

        $this->assertCount(1, $instants);
        // Summer time, so local 14:00 is 12:00 UTC.
        $this->assertSame('2026-06-02 12:00:00', $instants[0]->format('Y-m-d H:i:s'));
    }

    public function testAWallClockInTheSkippedHourStandsForNoInstant(): void
    {
        // 02:00 local becomes 03:00 local, so 02:30 never happens. PHP's own
        // reading would answer 03:30, which is a different time.
        $this->assertSame(
            [],
            UtcTimestamp::localInstants('2026-03-29 02:30:00', new \DateTimeZone('Europe/Zurich'))
        );
    }

    public function testTheWallClocksAroundTheSkippedHourStillExist(): void
    {
        $zone = new \DateTimeZone('Europe/Zurich');

        $before = UtcTimestamp::localInstants('2026-03-29 01:59:00', $zone);
        $after  = UtcTimestamp::localInstants('2026-03-29 03:00:00', $zone);

        $this->assertCount(1, $before);
        $this->assertSame('2026-03-29 00:59:00', $before[0]->format('Y-m-d H:i:s'));

        $this->assertCount(1, $after);
        $this->assertSame('2026-03-29 01:00:00', $after[0]->format('Y-m-d H:i:s'));
    }

    public function testAWallClockInTheRepeatedHourStandsForTwoInstants(): void
    {
        $instants = UtcTimestamp::localInstants('2026-10-25 02:30:00', new \DateTimeZone('Europe/Zurich'));

        $this->assertCount(2, $instants);
        // First under summer time, then an hour later under winter time, and in
        // that order, because a caller that names both has to name them the way
        // they happen.
        $this->assertSame('2026-10-25 00:30:00', $instants[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-25 01:30:00', $instants[1]->format('Y-m-d H:i:s'));
    }

    public function testTheHourAfterTheRepeatedHourIsNotAmbiguous(): void
    {
        $instants = UtcTimestamp::localInstants('2026-10-25 03:00:00', new \DateTimeZone('Europe/Zurich'));

        $this->assertCount(1, $instants);
        $this->assertSame('2026-10-25 02:00:00', $instants[0]->format('Y-m-d H:i:s'));
    }

    public function testAZoneWithoutATransitionAnswersWithOneInstant(): void
    {
        $instants = UtcTimestamp::localInstants('2026-03-29 02:30:00', new \DateTimeZone('UTC'));

        $this->assertCount(1, $instants);
        $this->assertSame('2026-03-29 02:30:00', $instants[0]->format('Y-m-d H:i:s'));
    }

    public function testAFixedOffsetZoneAnswersWithOneInstantAndNoWarning(): void
    {
        // `DateTimeZone::getTransitions()` answers `false` rather than an empty
        // array for a zone that has none, so this is the case that would raise
        // a warning and lose the offset set if the answer were trusted blindly.
        // "+01:00" and "CET" are both such zones, and a schedule may not use
        // them, but a reader of a single wall clock has to cope with them.
        foreach (['+01:00', 'CET'] as $name) {
            $instants = UtcTimestamp::localInstants('2026-06-02 14:00:00', new \DateTimeZone($name));

            $this->assertCount(1, $instants, $name . ' is one instant');
            $this->assertSame('2026-06-02 13:00:00', $instants[0]->format('Y-m-d H:i:s'), $name . ' is one hour ahead');
        }
    }

    public function testAnImpossibleCalendarDateHasNoInstantInAnyZone(): void
    {
        $this->assertSame(
            [],
            UtcTimestamp::localInstants('2026-02-31 10:00:00', new \DateTimeZone('Europe/Zurich'))
        );
        $this->assertSame([], UtcTimestamp::localInstants('tomorrow', new \DateTimeZone('UTC')));
    }

    public function testTheReturnedInstantsAreInUtc(): void
    {
        $instants = UtcTimestamp::localInstants('2026-06-02 14:00:00', new \DateTimeZone('Europe/Zurich'));

        $this->assertSame('UTC', $instants[0]->getTimezone()->getName());
    }

    public function testToSqlConvertsToUtc(): void
    {
        $zurich = new \DateTimeImmutable('2026-06-02 12:30:00', new \DateTimeZone('Europe/Zurich'));

        $this->assertSame('2026-06-02 10:30:00', UtcTimestamp::toSql($zurich));
    }

    public function testParseAndToSqlAreInverse(): void
    {
        $value  = '2026-12-24 23:59:00';
        $parsed = UtcTimestamp::parse($value);

        $this->assertNotNull($parsed);
        $this->assertSame($value, UtcTimestamp::toSql($parsed));
    }
}
