<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\InvitationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TeamServiceAutoload.php';

/**
 * Calendar input (no mass assignment), the calendar and activation MAC field
 * lists, and the activation token, address binding and password rules.
 */
final class CalendarActivationRulesTest extends TestCase
{
    public function testCalendarInputPassesOnlyTheEditableFields(): void
    {
        $clean = CalendarService::normalise([
            'title' => "  Team\x07 ", 'description' => "Line 1\nLine 2", 'colour' => '#a1b2c3', 'allow_external' => '1',
            'blocks_availability' => '0', 'retention_days' => '30', 'owner_user_id' => '42',
            'state' => 0, 'uri' => 'x', 'key_version' => 9, 'synctoken' => 1, 'row_mac' => 'forged', 'id' => 5,
        ], false);

        self::assertSame([
            'title' => 'Team', 'description' => "Line 1\nLine 2", 'colour' => '#A1B2C3', 'allow_external' => true,
            'blocks_availability' => false, 'retention_days' => 30, 'owner_user_id' => 42,
        ], $clean);
    }

    public function testTheOwnerCannotBeChangedByAnUpdate(): void
    {
        self::assertSame(['retention_days' => 7], CalendarService::normalise(['owner_user_id' => 1, 'retention_days' => 7], true));
    }

    public function testInvalidCalendarInputIsRefused(): void
    {
        foreach ([
            [['title' => ''], false],
            [['title' => str_repeat('x', 256), 'owner_user_id' => 1], false],
            [['title' => 'a'], false],
            [['title' => 'a', 'owner_user_id' => 0], false],
            [['title' => 'a', 'owner_user_id' => '1 OR 1=1'], false],
            [['colour' => 'red'], true],
            [['colour' => '#abcd'], true],
            [['allow_external' => '2'], true],
            [['allow_external' => ''], true],
            [['retention_days' => '-1'], true],
            [['retention_days' => -1], true],
            [['title' => 'a', 'owner_user_id' => -5], false],
            [['retention_days' => '65536'], true],
            [['retention_days' => '1e3'], true],
            [['title' => ['array']], true],
            [['description' => str_repeat('x', 2001)], true],
            [['title' => "\xC3\x28"], true],
        ] as [$data, $partial]) {
            try {
                CalendarService::normalise($data, $partial);
                self::fail('Accepted ' . json_encode($data));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCalendarMacCoversTheAccessRelevantColumnsOnly(): void
    {
        $row = (object) [
            'id' => '4', 'uri' => str_repeat('a', 32), 'owner_user_id' => '7', 'allow_external' => '1', 'state' => '1',
            'title' => 'T', 'key_version' => '3', 'rotation_due' => '1', 'synctoken' => '99', 'grant_epoch' => '6', 'row_mac' => 'x',
        ];

        self::assertSame(['id' => 4, 'uri' => str_repeat('a', 32), 'owner_user_id' => 7, 'allow_external' => 1, 'state' => 1, 'grant_epoch' => 6], CalendarService::macFields($row));
        self::assertTrue(CalendarService::validUri(bin2hex(random_bytes(16))));
        self::assertFalse(CalendarService::validUri(str_repeat('A', 32)));
        self::assertFalse(CalendarService::validUri('../' . str_repeat('a', 29)));
    }

    public function testActivationTokenIsLongRandomAndCheckedBeforeAnyLookup(): void
    {
        $tokens = [];

        for ($i = 0; $i < 50; $i++) {
            $token = InvitationService::newToken();
            self::assertTrue(InvitationService::tokenFormatValid($token));
            self::assertSame(32, strlen(base64_decode(strtr($token, '-_', '+/') . '=', true)));
            $tokens[] = $token;
        }

        self::assertCount(50, array_unique($tokens));

        foreach (['', 'short', str_repeat('a', 42), str_repeat('a', 44), str_repeat('a', 42) . '=', str_repeat('a', 42) . '/', str_repeat('a', 42) . "\n"] as $bad) {
            self::assertFalse(InvitationService::tokenFormatValid($bad), json_encode($bad));
        }
    }

    public function testTokenAndAddressHashesAreKeyedAndSeparated(): void
    {
        $key = str_repeat("\x01", 32);
        $token = InvitationService::newToken();

        self::assertSame(32, strlen(InvitationService::tokenHash($key, $token)));
        self::assertNotSame(InvitationService::tokenHash($key, $token), InvitationService::tokenHash(str_repeat("\x02", 32), $token));
        self::assertSame(InvitationService::emailHash($key, ' Someone@Example.TEST '), InvitationService::emailHash($key, 'someone@example.test'));
        self::assertNotSame(InvitationService::emailHash($key, 'a@example.test'), InvitationService::emailHash($key, 'b@example.test'));
        // The same string never gives the same hash as token and as address.
        self::assertNotSame(InvitationService::tokenHash($key, 'x@example.test'), InvitationService::emailHash($key, 'x@example.test'));
    }

    public function testActivationLinksUseOneEntryPointAndKeepSubdirectoryAndPort(): void
    {
        $token = InvitationService::newToken();

        foreach ([
            'http://example.test/' => 'https://example.test',
            'https://example.test/site/' => 'https://example.test/site',
            'https://example.test:8443/site/index.php/index.php/' => 'https://example.test:8443/site',
        ] as $base => $expected) {
            self::assertSame($expected . '/index.php?option=com_appointments&view=activation&token=' . $token, InvitationService::activationLink($base, $token));
        }

        foreach (['', '/site', 'ftp://example.test', 'https://user@example.test/', 'https://example.test/?x=1', 'https://example.test/#fragment'] as $base) {
            try {
                InvitationService::activationLink($base, $token);
                self::fail('Accepted an invalid activation base.');
            } catch (\Advans\Component\Appointments\Administrator\Helper\Team\TeamException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testActivationMacFields(): void
    {
        $row = (object) [
            'id' => '3', 'token_hash' => "\x00\x01", 'user_id' => '5', 'email_hash' => "\xff", 'state' => '1', 'invited_by' => '2',
            'created_utc' => '2026-01-01 00:00:00', 'expires_utc' => '2026-01-04 00:00:00', 'completed_utc' => null,
            'revoked_by' => '9', 'row_mac' => 'x',
        ];

        self::assertSame([
            'token_hash' => '0001', 'user_id' => 5, 'email_hash' => 'ff', 'state' => 1, 'invited_by' => 2,
            'expires_utc' => '2026-01-04 00:00:00', 'completed_utc' => null,
        ], InvitationService::macFields($row));
    }

    public function testMaskedAddressShowsOnlyTheFirstCharacterAndTheDomain(): void
    {
        self::assertSame('a***@example.test', InvitationService::maskEmail('anna@example.test'));
        self::assertSame('***', InvitationService::maskEmail('no-address'));
    }

    public function testPasswordRulesFollowComUsersWithAMinimumOfTwelve(): void
    {
        $rules = ['minimum_length' => 8, 'minimum_integers' => 1, 'minimum_symbols' => 1, 'minimum_uppercase' => 1, 'minimum_lowercase' => 1];

        self::assertTrue(InvitationService::passwordAcceptable('Abcdefghij1!', $rules));
        self::assertFalse(InvitationService::passwordAcceptable('Abcdefgh1!', $rules));
        self::assertFalse(InvitationService::passwordAcceptable('abcdefghij1!', $rules));
        self::assertFalse(InvitationService::passwordAcceptable('Abcdefghijk!', $rules));
        self::assertFalse(InvitationService::passwordAcceptable('Abcdefghij12', $rules));
        self::assertFalse(InvitationService::passwordAcceptable(' Abcdefghij1!', $rules));
        self::assertTrue(InvitationService::passwordAcceptable(str_repeat('x', 20), ['minimum_length' => 20]));
        self::assertFalse(InvitationService::passwordAcceptable(str_repeat('x', 19), ['minimum_length' => 20]));
        self::assertFalse(InvitationService::passwordAcceptable(str_repeat('x', 4097), []));
    }
}
