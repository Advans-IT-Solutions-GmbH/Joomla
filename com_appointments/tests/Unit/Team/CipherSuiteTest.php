<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use PHPUnit\Framework\TestCase;

final class CipherSuiteTest extends TestCase
{
    public function testLibsodiumIsAvailableInTheTestEnvironment(): void
    {
        // The team calendars refuse to run without libsodium; the CI must have it,
        // otherwise every other test of this class proves nothing.
        self::assertTrue(CipherSuite::available());
    }

    public function testSymmetricRoundTripIsBoundToKeyAndAssociatedData(): void
    {
        $key = CipherSuite::randomKey();
        $ad = CipherSuite::associatedData('object', 7, 'event.ics');
        $payload = CipherSuite::encrypt($key, 'BEGIN:VCALENDAR', $ad);

        self::assertSame('BEGIN:VCALENDAR', CipherSuite::decrypt($key, $payload, $ad));
        self::assertNull(CipherSuite::decrypt($key, $payload, CipherSuite::associatedData('object', 8, 'event.ics')));
        self::assertNull(CipherSuite::decrypt(CipherSuite::randomKey(), $payload, $ad));
        self::assertNull(CipherSuite::decrypt($key, substr($payload, 0, -1) . chr(ord(substr($payload, -1)) ^ 1), $ad));
        self::assertNull(CipherSuite::decrypt($key, 'short', $ad));
    }

    public function testEveryEncryptionUsesAFreshNonce(): void
    {
        $key = CipherSuite::randomKey();

        self::assertNotSame(CipherSuite::encrypt($key, 'same', 'ad'), CipherSuite::encrypt($key, 'same', 'ad'));
    }

    public function testSealedBoxOpensOnlyWithItsKeyPair(): void
    {
        $pair = CipherSuite::keyPair();
        $other = CipherSuite::keyPair();
        $sealed = CipherSuite::seal($pair['public'], 'calendar key');

        self::assertSame('calendar key', CipherSuite::open($pair['secret'], $pair['public'], $sealed));
        self::assertNull(CipherSuite::open($other['secret'], $other['public'], $sealed));
    }

    public function testAssociatedDataIsUnambiguous(): void
    {
        self::assertNotSame(CipherSuite::associatedData('p', 'a', 'bc'), CipherSuite::associatedData('p', 'ab', 'c'));
        self::assertNotSame(CipherSuite::associatedData('p', 'a'), CipherSuite::associatedData('q', 'a'));
    }

    public function testKeysOfTheWrongLengthAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CipherSuite::encrypt('too short', 'x', 'ad');
    }
}
