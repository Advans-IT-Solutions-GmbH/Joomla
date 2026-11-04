<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Conference/RuntimeStubs.php';

$serviceDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';

foreach (['KeyServiceInterface', 'KeyService', 'TeamServices'] as $class) {
    require_once $serviceDir . '/' . $class . '.php';
}

/**
 * The parts of the key service that need no database: the normative formulas
 * of specification 5.2, the row MAC encoding, object and timing encryption,
 * and the printed recovery secret. The database flows are covered by the
 * integration part team-b-keys.php.
 */
final class KeyServiceTest extends TestCase
{
    private function service(string $siteSecret = 'unit-test-site-secret'): KeyService
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects(self::never())->method('setQuery');

        return new KeyService(new TeamServices(
            $db,
            new KeyProvider($siteSecret),
            new Registry([]),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04 12:00:00', new \DateTimeZone('UTC'))
        ));
    }

    private function object(int $calendarId, string $uri, int $version, string $payload, int $scheme = TeamStates::SCHEME_CALENDAR_KEY): StoredObject
    {
        return new StoredObject(1, $calendarId, $uri, str_repeat('a', 32), \strlen($payload), $scheme, $version, $payload, '', null, new \DateTimeImmutable('2026-11-04 12:00:00'));
    }

    public function testRowMacFollowsTheSpecifiedEncoding(): void
    {
        $service = $this->service();
        $key = (new KeyProvider('unit-test-site-secret'))->key(KeyProvider::PURPOSE_TEAM_ROW_MAC);
        $expected = hash_hmac('sha256', CipherSuite::associatedData('row', 'appointments_calendar_grants', 'a', 'V1', 'b', 'N', 'c', 'Vx'), $key, true);

        self::assertSame($expected, $service->rowMac('calendar_grants', ['c' => 'x', 'a' => 1, 'b' => null]));
    }

    public function testRowMacIsIndependentOfOrderAndTableSpelling(): void
    {
        $service = $this->service();
        $mac = $service->rowMac('calendar_grants', ['user_id' => 5, 'role' => 3, 'state' => 1]);

        self::assertSame($mac, $service->rowMac('#__appointments_calendar_grants', ['state' => '1', 'user_id' => '5', 'role' => '3']));
        self::assertSame($mac, $service->rowMac('appointments_calendar_grants', ['role' => 3, 'state' => 1, 'user_id' => 5]));
        self::assertSame(32, \strlen($mac));
    }

    public function testRowMacDetectsEveryChange(): void
    {
        $service = $this->service();
        $fields = ['user_id' => 5, 'role' => 3, 'state' => 1, 'confirmed_by' => null];
        $mac = $service->rowMac('calendar_grants', $fields);

        self::assertTrue($service->verifyRowMac('calendar_grants', $fields, $mac));
        self::assertFalse($service->verifyRowMac('calendar_grants', ['role' => 4] + $fields, $mac), 'role raised');
        self::assertFalse($service->verifyRowMac('calendar_grants', ['user_id' => 6] + $fields, $mac), 'other user');
        self::assertFalse($service->verifyRowMac('calendar_grants', ['confirmed_by' => ''] + $fields, $mac), 'null is not empty');
        self::assertFalse($service->verifyRowMac('calendar_grants', ['confirmed_by' => 0] + $fields, $mac), 'null is not zero');
        self::assertFalse($service->verifyRowMac('devices', $fields, $mac), 'other table');
        self::assertFalse($service->verifyRowMac('calendar_grants', $fields + ['extra' => 1], $mac), 'extra field');
        self::assertFalse($service->verifyRowMac('calendar_grants', $fields, substr($mac, 0, 31)), 'short MAC');
        self::assertFalse($this->service('another-site-secret')->verifyRowMac('calendar_grants', $fields, $mac), 'other site secret');
    }

    public function testRowMacTreatsBooleansAsIntegers(): void
    {
        $service = $this->service();

        self::assertSame($service->rowMac('calendars', ['allow_external' => true]), $service->rowMac('calendars', ['allow_external' => 1]));
        self::assertSame($service->rowMac('calendars', ['allow_external' => false]), $service->rowMac('calendars', ['allow_external' => '0']));
    }

    public function testRowMacRefusesUnknownTablesAndNonScalars(): void
    {
        try {
            $this->service()->rowMac('users', ['id' => 1]);
            self::fail('A MAC for a foreign table was computed.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('users', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->service()->rowMac('calendars', ['id' => [1]]);
    }

    public function testMacFieldsOfTheOwnTables(): void
    {
        self::assertSame(['purpose', 'public_key', 'fingerprint', 'created_by', 'created_utc'], KeyService::macFields('keyring'));
        self::assertSame(['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit'], KeyService::macFields('#__appointments_user_keys'));
        self::assertSame(['calendar_id', 'key_version', 'box_public_key', 'box_secret_wrapped', 'recovery_wrapped', 'recovery_fingerprint'], KeyService::macFields('appointments_calendar_keys'));

        $this->expectException(\InvalidArgumentException::class);
        KeyService::macFields('calendar_grants');
    }

    public function testAssociatedDataOfTheWrapsIsTheSpecifiedOne(): void
    {
        $salt = str_repeat("\xab", 16);

        self::assertSame(CipherSuite::associatedData('user-key/password', 5, 2), KeyService::passwordAd(5, 2));
        self::assertSame(CipherSuite::associatedData('user-key/device', 5, 2, str_repeat('ab', 16)), KeyService::deviceAd(5, 2, $salt));
        self::assertSame(CipherSuite::associatedData('calendar-box', 7, 3), KeyService::boxAd(7, 3));
        self::assertNotSame(KeyService::deviceAd(5, 2, $salt), KeyService::deviceAd(5, 2, str_repeat("\xac", 16)));
    }

    public function testObjectRoundTripIsBoundToCalendarUriAndVersion(): void
    {
        $service = $this->service();
        $key = CalendarKey::create(7, 2, CipherSuite::randomKey());
        $ring = new CalendarKeyring(7, 2, [$key]);
        $payload = $service->encryptObject($key, 'event.ics', 'BEGIN:VCALENDAR');

        self::assertSame(
            CipherSuite::decrypt($key->key(), $payload, CipherSuite::associatedData('object', 7, 'event.ics', 2)),
            'BEGIN:VCALENDAR',
            'scheme 1 is encrypt(CK_v, ics, associatedData(object, calendarId, uri, v))'
        );
        self::assertSame('BEGIN:VCALENDAR', $service->decryptObject($ring, $this->object(7, 'event.ics', 2, $payload)));
        self::assertNull($service->decryptObject($ring, $this->object(7, 'other.ics', 2, $payload)), 'moved to another URI');
        self::assertNull($service->decryptObject($ring, $this->object(8, 'event.ics', 2, $payload)), 'moved to another calendar');
        self::assertNull($service->decryptObject($ring, $this->object(7, 'event.ics', 1, $payload)), 'version not in the keyring');
        self::assertNull($service->decryptObject($ring, $this->object(7, 'event.ics', 2, substr($payload, 0, -1) . chr(ord(substr($payload, -1)) ^ 1))), 'tampered');
        self::assertNull($service->decryptObject($ring, $this->object(7, 'event.ics', 2, $payload, 9)), 'unknown scheme');
    }

    public function testObjectOfAnOlderVersionStaysReadable(): void
    {
        $service = $this->service();
        $old = CalendarKey::create(7, 1, CipherSuite::randomKey());
        $ring = new CalendarKeyring(7, 2, [$old, CalendarKey::create(7, 2, CipherSuite::randomKey())]);

        self::assertSame('old', $service->decryptObject($ring, $this->object(7, 'a.ics', 1, $service->encryptObject($old, 'a.ics', 'old'))));
    }

    public function testTimingNeedsTheServerKeyAndItsPlace(): void
    {
        $service = $this->service();
        $sealed = $service->sealTiming(7, 'event.ics', 'DTSTART:20261104T120000Z');

        self::assertSame('DTSTART:20261104T120000Z', $service->openTiming(7, 'event.ics', $sealed));
        self::assertNull($service->openTiming(7, 'other.ics', $sealed));
        self::assertNull($service->openTiming(8, 'event.ics', $sealed));
        self::assertNull($this->service('another-site-secret')->openTiming(7, 'event.ics', $sealed));
        self::assertStringNotContainsString('20261104', $sealed);
    }

    public function testUidHashIsTheSpecifiedHmac(): void
    {
        $key = (new KeyProvider('unit-test-site-secret'))->key(KeyProvider::PURPOSE_TEAM_UID);

        self::assertSame(hash_hmac('sha256', "7\0uid-1@example.test", $key, true), $this->service()->uidHash(7, 'uid-1@example.test'));
        self::assertNotSame($this->service()->uidHash(7, 'uid-1@example.test'), $this->service()->uidHash(8, 'uid-1@example.test'));
    }

    public function testRecoverySecretPrintsAndParsesBack(): void
    {
        $secret = CipherSuite::keyPair()['secret'];
        $printed = KeyService::formatRecoverySecret($secret);

        self::assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){13}$/', $printed);
        self::assertSame($secret, KeyService::parseRecoverySecret($printed));
        self::assertSame($secret, KeyService::parseRecoverySecret(strtolower(str_replace('-', ' ', $printed))), 'case and spaces');
        self::assertSame($secret, KeyService::parseRecoverySecret(str_replace('-', '', $printed)), 'without groups');
    }

    public function testRecoverySecretReadsLookAlikeDigits(): void
    {
        $secret = CipherSuite::keyPair()['secret'];
        $printed = KeyService::formatRecoverySecret($secret);
        $typed = strtr($printed, ['O' => '0', 'I' => '1', 'B' => '8']);

        self::assertSame($secret, KeyService::parseRecoverySecret($typed));
    }

    public function testRecoverySecretRejectsTyposAndWrongLengths(): void
    {
        $printed = KeyService::formatRecoverySecret(CipherSuite::keyPair()['secret']);
        $char = $printed[0] === 'A' ? 'C' : 'A';

        self::assertNull(KeyService::parseRecoverySecret($char . substr($printed, 1)), 'one changed character');
        self::assertNull(KeyService::parseRecoverySecret(substr($printed, 0, -1)), 'too short');
        self::assertNull(KeyService::parseRecoverySecret($printed . 'A'), 'too long');
        self::assertNull(KeyService::parseRecoverySecret(''), 'empty');
        self::assertNull(KeyService::parseRecoverySecret(str_repeat('9', 56)), 'not Base32');
    }

    public function testFingerprintIsSixteenHexCharacters(): void
    {
        $public = CipherSuite::keyPair()['public'];

        self::assertMatchesRegularExpression('/^[0-9A-F]{16}$/', KeyService::fingerprintOf($public));
        self::assertNotSame(KeyService::fingerprintOf($public), KeyService::fingerprintOf(CipherSuite::keyPair()['public']));
    }

    public function testEnvelopeOpensOnlyForItsCalendarAndUri(): void
    {
        $plain = "ACv1\n" . json_encode(['c' => 7, 'u' => 'booking-1.ics']) . "\nBEGIN:VCALENDAR";

        self::assertSame('BEGIN:VCALENDAR', KeyService::openEnvelope($plain, 7, 'booking-1.ics'));
        self::assertNull(KeyService::openEnvelope($plain, 8, 'booking-1.ics'));
        self::assertNull(KeyService::openEnvelope($plain, 7, 'other.ics'));
        self::assertNull(KeyService::openEnvelope("ACv2\n" . substr($plain, 5), 7, 'booking-1.ics'));
        self::assertNull(KeyService::openEnvelope("ACv1\n" . json_encode(['c' => '7', 'u' => 'booking-1.ics']) . "\nX", 7, 'booking-1.ics'), 'the id is an integer');
        self::assertNull(KeyService::openEnvelope("ACv1\nno newline", 7, 'booking-1.ics'));
    }

    public function testNoStubMarkerIsLeftInTheImplementation(): void
    {
        $dir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';

        foreach (['KeyService.php', 'KeySession.php'] as $file) {
            self::assertStringNotContainsString('TEAM_NOT_' . 'READY', (string) file_get_contents($dir . '/' . $file), $file);
        }
    }
}
