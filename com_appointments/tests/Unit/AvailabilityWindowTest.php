<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityWindowTest extends TestCase
{
    #[DataProvider('invalidUtcBounds')]
    public function testUtcStringFactoryRejectsInvalidBounds(string $start, string $end): void
    {
        $this->expectException(\InvalidArgumentException::class);

        AvailabilityWindow::fromUtcStrings($start, $end);
    }

    public static function invalidUtcBounds(): array
    {
        $start = '2026-06-02 10:00:00';
        $end   = '2026-06-02 11:00:00';
        $rows  = [];

        foreach ([
            '2026-02-31 10:00:00',
            'tomorrow',
            '',
            '0000-00-00 00:00:00',
            '0999-06-02 10:00:00',
            '2026-06-02 10:00:00+02:00',
        ] as $value) {
            $rows['start ' . $value] = [$value, $end];
            $rows['end ' . $value]   = [$start, $value];
        }

        return $rows;
    }

    private function window(string $start, string $end): AvailabilityWindow
    {
        return AvailabilityWindow::fromUtcStrings('2026-06-02 ' . $start, '2026-06-02 ' . $end);
    }

    /**
     * @param  AvailabilityWindow[]  $windows
     *
     * @return string[]
     */
    private function asStrings(array $windows): array
    {
        return array_map(
            static function (AvailabilityWindow $window): string {
                return $window->getStart()->format('H:i') . '-' . $window->getEnd()->format('H:i');
            },
            $windows
        );
    }

    public function testABoundMustBeInUtc(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AvailabilityWindow(
            new \DateTimeImmutable('2026-06-02 10:00', new \DateTimeZone('Europe/Zurich')),
            new \DateTimeImmutable('2026-06-02 11:00', new \DateTimeZone('UTC'))
        );
    }

    public function testAnEmptyWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->window('10:00', '10:00');
    }

    public function testAReversedWindowIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->window('11:00', '10:00');
    }

    public function testTouchingWindowsDoNotOverlap(): void
    {
        // The interval is half-open, so a meeting that ends when the next one
        // starts is legal. Getting this wrong produces a double booking.
        $this->assertFalse($this->window('10:00', '11:00')->overlaps($this->window('11:00', '12:00')));
        $this->assertFalse($this->window('11:00', '12:00')->overlaps($this->window('10:00', '11:00')));
    }

    public function testOverlappingWindowsAreDetectedBothWays(): void
    {
        $this->assertTrue($this->window('10:00', '11:00')->overlaps($this->window('10:30', '12:00')));
        $this->assertTrue($this->window('10:30', '12:00')->overlaps($this->window('10:00', '11:00')));
    }

    public function testContainsIsInclusiveAtTheStartAndExclusiveAtTheEnd(): void
    {
        $window = $this->window('10:00', '11:00');
        $utc    = new \DateTimeZone('UTC');

        $this->assertTrue($window->contains(new \DateTimeImmutable('2026-06-02 10:00', $utc)));
        $this->assertFalse($window->contains(new \DateTimeImmutable('2026-06-02 11:00', $utc)));
        $this->assertFalse($window->contains(new \DateTimeImmutable('2026-06-02 09:59', $utc)));
    }

    public function testSubtractCutsAHoleInTheMiddle(): void
    {
        $result = $this->window('10:00', '12:00')->subtract($this->window('10:30', '11:00'));

        $this->assertSame(['10:00-10:30', '11:00-12:00'], $this->asStrings($result));
    }

    public function testSubtractTrimsAnEnd(): void
    {
        $this->assertSame(
            ['10:30-12:00'],
            $this->asStrings($this->window('10:00', '12:00')->subtract($this->window('09:00', '10:30')))
        );
        $this->assertSame(
            ['10:00-11:30'],
            $this->asStrings($this->window('10:00', '12:00')->subtract($this->window('11:30', '13:00')))
        );
    }

    public function testSubtractCanRemoveTheWholeWindow(): void
    {
        $this->assertSame([], $this->window('10:00', '12:00')->subtract($this->window('09:00', '13:00')));
    }

    public function testSubtractLeavesTheWindowAloneWhenThereIsNoOverlap(): void
    {
        $this->assertSame(
            ['10:00-12:00'],
            $this->asStrings($this->window('10:00', '12:00')->subtract($this->window('12:00', '13:00')))
        );
    }

    public function testSubtractAllRemovesSeveralWindowsInAnyOrder(): void
    {
        $result = $this->window('09:00', '17:00')->subtractAll([
            $this->window('12:00', '13:00'),
            $this->window('10:00', '10:30'),
            $this->window('16:30', '18:00'),
        ]);

        $this->assertSame(
            ['09:00-10:00', '10:30-12:00', '13:00-16:30'],
            $this->asStrings($result)
        );
    }

    public function testExpandGrowsBothSides(): void
    {
        $result = $this->window('10:00', '10:20')->expand(600, 600);

        $this->assertSame('09:50-10:30', $this->asStrings([$result])[0]);
    }

    public function testExpandRejectsANegativeBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->window('10:00', '11:00')->expand(-60, 0);
    }

    public function testMergeJoinsOverlappingAndTouchingWindows(): void
    {
        $result = AvailabilityWindow::merge([
            $this->window('11:00', '12:00'),
            $this->window('10:00', '11:00'),
            $this->window('13:00', '14:00'),
            $this->window('11:30', '12:30'),
        ]);

        // 10:00-11:00 and 11:00-12:00 touch and describe one period of
        // availability; keeping them apart would drop a slot that starts in the
        // first and ends in the second.
        $this->assertSame(['10:00-12:30', '13:00-14:00'], $this->asStrings($result));
    }

    public function testMergeKeepsAWindowThatIsSwallowedByAnother(): void
    {
        $result = AvailabilityWindow::merge([
            $this->window('10:00', '14:00'),
            $this->window('11:00', '12:00'),
        ]);

        $this->assertSame(['10:00-14:00'], $this->asStrings($result));
    }

    public function testDurationIsInSeconds(): void
    {
        $this->assertSame(1200, $this->window('10:00', '10:20')->getDurationSeconds());
    }

    public function testContainsWindowRequiresBothBoundsInside(): void
    {
        $window = $this->window('10:00', '12:00');

        $this->assertTrue($window->containsWindow($this->window('10:00', '12:00')));
        $this->assertTrue($window->containsWindow($this->window('10:30', '11:00')));
        $this->assertFalse($window->containsWindow($this->window('09:30', '11:00')));
        $this->assertFalse($window->containsWindow($this->window('11:00', '12:30')));
    }
}
