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

    public function testTamperedNonceIsRefused(): void
    {
        $key = CipherSuite::randomKey();
        $payload = CipherSuite::encrypt($key, 'text', 'ad');

        self::assertNull(CipherSuite::decrypt($key, chr(ord($payload[0]) ^ 1) . substr($payload, 1), 'ad'));
    }

    public function testSealedBoxOpensOnlyWithItsKeyPair(): void
    {
        $pair = CipherSuite::keyPair();
        $other = CipherSuite::keyPair();
        $sealed = CipherSuite::seal($pair['public'], 'calendar key');

        self::assertSame('calendar key', CipherSuite::open($pair['secret'], $pair['public'], $sealed));
        self::assertNull(CipherSuite::open($other['secret'], $other['public'], $sealed));
        self::assertNull(CipherSuite::open($pair['secret'], $pair['public'], substr($sealed, 0, -1) . chr(ord(substr($sealed, -1)) ^ 1)));
    }

    public function testPublicKeyOfMatchesTheKeyPair(): void
    {
        $pair = CipherSuite::keyPair();

        self::assertSame($pair['public'], CipherSuite::publicKeyOf($pair['secret']));
        self::assertNotSame($pair['public'], CipherSuite::publicKeyOf(CipherSuite::keyPair()['secret']));
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

    public function testInteractiveLimitsAreLibsodiumsOwn(): void
    {
        self::assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, CipherSuite::PWHASH_OPSLIMIT);
        self::assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, CipherSuite::PWHASH_MEMLIMIT);
        self::assertSame(SODIUM_CRYPTO_PWHASH_SALTBYTES, CipherSuite::PWHASH_SALT_BYTES);
    }

    public function testPasswordKeyIsExactlyTheSpecifiedFormula(): void
    {
        $salt = str_repeat("\x11", 16);
        $server = str_repeat("\x22", 32);
        $stretched = sodium_crypto_pwhash(32, 'correct horse', $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $expected = hash_hkdf('sha256', $stretched . $server, 32, 'com_appointments/password-kek/v1', $salt);

        $key = CipherSuite::passwordKey('correct horse', $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT, $server);

        self::assertSame($expected, $key);
        self::assertNotSame($key, CipherSuite::passwordKey('correct horse', str_repeat("\x12", 16), CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT, $server));
        self::assertNotSame($key, CipherSuite::passwordKey('correct horse', $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT, str_repeat("\x23", 32)));
        self::assertNotSame($key, CipherSuite::passwordKey('correct horsE', $salt, CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT, $server));
    }

    public function testPasswordKeyRefusesADowngradeOfTheLimits(): void
    {
        self::assertTrue(CipherSuite::acceptableLimits(CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT));
        self::assertFalse(CipherSuite::acceptableLimits(1, CipherSuite::PWHASH_MEMLIMIT));
        self::assertFalse(CipherSuite::acceptableLimits(CipherSuite::PWHASH_OPSLIMIT, 8192));
        self::assertFalse(CipherSuite::acceptableLimits(CipherSuite::PWHASH_OPSLIMIT_MAX + 1, CipherSuite::PWHASH_MEMLIMIT));
        self::assertFalse(CipherSuite::acceptableLimits(CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT_MAX + 1));

        $this->expectException(\InvalidArgumentException::class);
        CipherSuite::passwordKey('pw', str_repeat("\x11", 16), 1, 8192, str_repeat("\x22", 32));
    }

    public function testPasswordKeyRefusesAWrongSalt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CipherSuite::passwordKey('pw', 'short', CipherSuite::PWHASH_OPSLIMIT, CipherSuite::PWHASH_MEMLIMIT, str_repeat("\x22", 32));
    }

    public function testWipeClearsTheVariable(): void
    {
        $secret = random_bytes(32);
        CipherSuite::wipe($secret);
        self::assertNull($secret);

        $none = null;
        CipherSuite::wipe($none);
        self::assertNull($none);
    }

    public function testWipeOfASharedStringLeavesTheOtherCopyIntact(): void
    {
        $owner = random_bytes(32);
        $copy = $owner;
        $expected = bin2hex($owner);
        CipherSuite::wipe($copy);

        self::assertNull($copy);
        self::assertSame($expected, bin2hex($owner));
    }
}
