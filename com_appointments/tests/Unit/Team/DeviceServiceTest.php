<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Conference/RuntimeStubs.php';
$teamDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';
require_once $teamDir . '/TeamServices.php';
require_once $teamDir . '/DeviceServiceInterface.php';
require_once $teamDir . '/DeviceService.php';

/**
 * Work package D: the pure parts of the device service and the refusals that
 * must happen before any database access.
 */
final class DeviceServiceTest extends TestCase
{
    #[DataProvider('names')]
    public function testDeviceNamesAreNormalisedOrRefused(string $input, ?string $expected): void
    {
        self::assertSame($expected, DeviceService::normaliseName($input));
    }

    public static function names(): array
    {
        return [
            'plain' => ['iPhone', 'iPhone'],
            'spaces collapsed and trimmed' => ["  Outlook \t Büro  ", 'Outlook Büro'],
            'apostrophe and parentheses' => ["Pascal's iPad (privat)", "Pascal's iPad (privat)"],
            'accents' => ['Téléphone 2', 'Téléphone 2'],
            'exactly 100 characters' => [str_repeat('a', 100), str_repeat('a', 100)],
            'empty' => ['', null],
            'only spaces' => ['   ', null],
            '101 characters' => [str_repeat('a', 101), null],
            'markup' => ['<script>alert(1)</script>', null],
            'markup inside' => ['Phone <b>', null],
            'double quote' => ['Phone "x"', null],
            'leading punctuation' => ['-phone', null],
            'control character' => ["Phone\x07", null],
            'newline inside is flattened' => ["Phone\nTwo", 'Phone Two'],
            'semicolon' => ['a;b', null],
        ];
    }

    public function testEveryClientHasALabelAndNothingElse(): void
    {
        self::assertSame(DeviceService::CLIENTS, array_keys(DeviceService::CLIENT_LABELS));

        foreach (DeviceService::CLIENTS as $client) {
            self::assertTrue(DeviceService::isClient($client));
        }

        self::assertFalse(DeviceService::isClient('outlook-new'));
        self::assertFalse(DeviceService::isClient(''));
        self::assertFalse(DeviceService::isClient('APPLE'));
    }

    public function testRowMacCoversTheAccessColumnsInAFixedShape(): void
    {
        $fields = DeviceService::macFields([
            'id' => '7', 'user_id' => '42', 'secret_hash' => str_repeat("\x01", 32), 'kek_salt' => str_repeat("\x02", 16),
            'usk_wrapped' => null, 'user_key_version' => '3', 'state' => '2', 'device_epoch' => '9',
            'name' => 'not covered', 'last_sync_utc' => '2026-11-04 10:00:00',
        ]);

        self::assertSame(['id', 'user_id', 'secret_hash', 'kek_salt', 'usk_wrapped', 'user_key_version', 'state', 'device_epoch'], array_keys($fields));
        self::assertSame(9, $fields['device_epoch']);
        self::assertSame(7, $fields['id']);
        self::assertSame(42, $fields['user_id']);
        self::assertSame(3, $fields['user_key_version']);
        self::assertSame(2, $fields['state']);
        self::assertNull($fields['usk_wrapped']);
        self::assertSame(str_repeat("\x01", 32), $fields['secret_hash']);
    }

    public function testADeviceMacWithoutTheUsersEpochIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeviceService::macFields(['id' => 1, 'user_id' => 2, 'secret_hash' => 'a', 'kek_salt' => 'b', 'usk_wrapped' => null, 'user_key_version' => 1, 'state' => 1]);
    }

    #[DataProvider('notAppPasswords')]
    public function testAuthenticationRefusesAnythingButAnAppPasswordWithoutTouchingTheDatabase(string $username, string $password): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects(self::never())->method('setQuery');
        $db->expects(self::never())->method('quoteName');

        $services = new TeamServices(
            $db,
            new KeyProvider(str_repeat('s', 32)),
            new Registry(['teamcal_enabled' => 1]),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-11-04 10:00:00', new \DateTimeZone('UTC'))
        );

        self::assertNull((new DeviceService($services))->authenticate($username, $password));
    }

    public static function notAppPasswords(): array
    {
        $valid = AppPassword::format(str_repeat("\x05", AppPassword::BYTES));

        return [
            'a Joomla password' => ['alice', 'Correct horse battery staple 1!'],
            'empty password' => ['alice', ''],
            'too short' => ['alice', substr($valid, 0, -1)],
            'one character too many' => ['alice', $valid . 'A'],
            'outside the alphabet' => ['alice', str_replace('A', '1', str_pad('', 32, 'A'))],
            'empty user name' => ['', $valid],
        ];
    }

    public function testADisabledFeatureAuthenticatesNobody(): void
    {
        $db = $this->createMock(DatabaseInterface::class);
        $db->expects(self::never())->method('setQuery');
        $services = new TeamServices(
            $db,
            new KeyProvider(str_repeat('s', 32)),
            new Registry(['teamcal_enabled' => 0]),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );

        self::assertNull((new DeviceService($services))->authenticate('alice', AppPassword::generate()['display']));
    }

    public function testOnlyTheCleanupDirectionIsAStateChange(): void
    {
        $services = new TeamServices(
            $this->createMock(DatabaseInterface::class),
            new KeyProvider(str_repeat('s', 32)),
            new Registry(['teamcal_enabled' => 1]),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
        $devices = new DeviceService($services);

        foreach ([[2, 1], [3, 1], [3, 2], [0, 1], [1, 0], [1, 1]] as [$from, $to]) {
            try {
                $devices->changeState(5, $from, $to);
                self::fail("State change $from to $to was accepted.");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Unsupported', $e->getMessage());
            }
        }
    }
}
