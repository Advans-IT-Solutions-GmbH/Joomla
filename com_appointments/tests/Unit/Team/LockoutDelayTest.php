<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutService;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/LockoutServiceInterface.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/LockoutService.php';

/**
 * The pure parts of the sign-in lock-out: delays, buckets, address grouping
 * and the wait computed from stored rows, with a fixed clock.
 */
final class LockoutDelayTest extends TestCase
{
    private const KEY = 'fedcba9876543210fedcba9876543210';

    public function testDelaysGrowFromTheTenthFailureAndStopAtAnHour(): void
    {
        $delays = array_map([LockoutService::class, 'delayMinutes'], range(0, 25));

        self::assertSame(array_fill(0, TeamStates::LOCK_THRESHOLD, 0), array_slice($delays, 0, 10));
        self::assertSame([1, 2, 4, 8, 16, 32, 60, 60], array_slice($delays, 10, 8));
        self::assertSame(60, LockoutService::delayMinutes(PHP_INT_MAX));
        self::assertLessThanOrEqual(60, max($delays));
    }

    public function testAddressesAreGroupedSoChangingThemDoesNotHelp(): void
    {
        self::assertSame('192.0.2.7', LockoutService::normaliseAddress('192.0.2.7'));
        self::assertSame('192.0.2.7', LockoutService::normaliseAddress('::ffff:192.0.2.7'));
        self::assertSame('2001:db8:1:2::/64', LockoutService::normaliseAddress('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(LockoutService::normaliseAddress('2001:db8:1:2::1'), LockoutService::normaliseAddress('2001:db8:1:2:ffff::9'));
        self::assertNotSame(LockoutService::normaliseAddress('2001:db8:1:2::1'), LockoutService::normaliseAddress('2001:db8:1:3::1'));
        self::assertNull(LockoutService::normaliseAddress(''));
        self::assertNull(LockoutService::normaliseAddress('localhost'));
    }

    public function testBucketsAreKeyedAndHoldNoNameOrAddress(): void
    {
        $buckets = LockoutService::buckets(self::KEY, LockoutService::identityFor(0, ' Someone '), '2001:db8:1:2::1');

        self::assertSame(32, strlen($buckets['user']));
        self::assertSame(32, strlen($buckets['ip']));
        self::assertSame($buckets['user'], LockoutService::buckets(self::KEY, LockoutService::identityFor(0, 'SOMEONE'), '')['user'], 'An unknown name is case-insensitive');
        self::assertSame($buckets['ip'], LockoutService::buckets(self::KEY, 'name:other', '2001:db8:1:2:9::9')['ip']);
        self::assertArrayNotHasKey('ip', LockoutService::buckets(self::KEY, 'name:someone', 'not an address'));
        self::assertNotSame($buckets['user'], LockoutService::buckets(strrev(self::KEY), 'name:someone', '')['user']);
        self::assertStringNotContainsString('someone', implode('', $buckets));
    }

    public function testAnExistingAccountIsBucketedByItsIdWhateverTheSpelling(): void
    {
        // Spellings the database collation treats as the same name resolve to the same id.
        self::assertSame('uid:42', LockoutService::identityFor(42, 'ädmin'));
        self::assertSame('uid:42', LockoutService::identityFor(42, "ad\u{00AD}min"));
        self::assertSame(LockoutService::buckets(self::KEY, LockoutService::identityFor(42, 'Admin'), '')['user'],
            LockoutService::buckets(self::KEY, LockoutService::identityFor(42, 'admín'), '')['user']);
        self::assertNotSame(LockoutService::identityFor(42, 'x'), LockoutService::identityFor(0, 'uid:42'));
    }

    public function testWaitFollowsTheLockAndForgetsAfterADay(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 12:00:00', new \DateTimeZone('UTC'));
        $locked = ['a' => ['failures' => 12, 'locked_until_utc' => '2026-11-04 12:04:00', 'last_failure_utc' => '2026-11-04 12:00:00']];

        self::assertSame(240, LockoutService::waitFor($locked, $now));
        self::assertSame(0, LockoutService::waitFor($locked, $now->modify('+4 minutes')));
        self::assertSame(0, LockoutService::waitFor(['a' => ['failures' => 3, 'locked_until_utc' => null, 'last_failure_utc' => '2026-11-04 11:00:00']], $now));

        $stale = ['a' => ['failures' => 40, 'locked_until_utc' => '2026-11-05 12:00:00', 'last_failure_utc' => '2026-11-03 11:59:59']];
        self::assertSame(0, LockoutService::waitFor($stale, $now), 'A counter without a failure for 24 hours does not lock');

        $two = $locked + ['b' => ['failures' => 16, 'locked_until_utc' => '2026-11-04 13:00:00', 'last_failure_utc' => '2026-11-04 12:00:00']];
        self::assertSame(3600, LockoutService::waitFor($two, $now), 'The longer of the two buckets counts');
    }

    public function testARealLockIsToldApartFromAReservation(): void
    {
        self::assertTrue(LockoutService::isRealLock(['failures' => 12, 'locked_until_utc' => '2026-11-04 12:04:00', 'last_failure_utc' => '2026-11-04 12:00:00']));
        self::assertFalse(LockoutService::isRealLock(['failures' => 12, 'locked_until_utc' => '2026-11-04 12:00:30', 'last_failure_utc' => '2026-11-04 12:00:00']), 'Reservation');
        self::assertFalse(LockoutService::isRealLock(['failures' => 9, 'locked_until_utc' => '2026-11-04 12:00:30', 'last_failure_utc' => '2026-11-04 11:59:00']), 'Below the threshold');
        self::assertFalse(LockoutService::isRealLock(['failures' => 12, 'locked_until_utc' => null, 'last_failure_utc' => '2026-11-04 12:00:00']));
    }

    public function testOwnReservationIsNoLockForTheSameRequest(): void
    {
        $now = new \DateTimeImmutable('2026-11-04 12:00:00', new \DateTimeZone('UTC'));
        $rows = ['a' => ['failures' => 9, 'locked_until_utc' => '2026-11-04 12:00:30', 'last_failure_utc' => '2026-11-04 11:59:00']];

        self::assertSame(30, LockoutService::waitFor($rows, $now), 'Another request holds the attempt');
        self::assertSame(0, LockoutService::waitFor($rows, $now, ['a' => '2026-11-04 12:00:30']), 'This request holds it');
        self::assertSame(30, LockoutService::waitFor($rows, $now, ['a' => '2026-11-04 12:00:29']), 'A different reservation is not ours');
    }
}
