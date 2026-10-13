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
        ];
    }

    public function testIsTimestampAnswersTheSameQuestionWithoutAnInstant(): void
    {
        // Used on a value that is still in somebody's local time, before the
        // form filter converts it: whether a date and time exist has the same
        // answer in every zone.
        $this->assertTrue(UtcTimestamp::isTimestamp('2026-06-02 10:30:00'));
        $this->assertTrue(UtcTimestamp::isTimestamp('2026-06-02 10:30'));
        $this->assertFalse(UtcTimestamp::isTimestamp('2026-02-31 10:00:00'));
        $this->assertFalse(UtcTimestamp::isTimestamp('tomorrow'));
        $this->assertFalse(UtcTimestamp::isTimestamp(''));
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
