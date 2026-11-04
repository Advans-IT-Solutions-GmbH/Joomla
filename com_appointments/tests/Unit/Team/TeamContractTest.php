<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use PHPUnit\Framework\TestCase;

/**
 * The fixed contracts of the team calendars that every work package relies on.
 */
final class TeamContractTest extends TestCase
{
    public function testRolesAreOrderedAndOnlyFreeBusyHoldsNoKey(): void
    {
        self::assertSame([1, 2, 3, 4], array_map(static fn (CalendarRole $r) => $r->value, CalendarRole::cases()));
        self::assertFalse(CalendarRole::FreeBusy->holdsKey());
        self::assertTrue(CalendarRole::Read->holdsKey());
        self::assertFalse(CalendarRole::Read->canWrite());
        self::assertTrue(CalendarRole::Write->canWrite());
        self::assertTrue(CalendarRole::Manage->includes(CalendarRole::Write));
        self::assertFalse(CalendarRole::Write->includes(CalendarRole::Manage));
        self::assertSame(CalendarRole::Write, CalendarRole::maximumForExternal());
    }

    public function testKeyMaterialCannotBeSerialisedClonedOrDumped(): void
    {
        $context = KeyContext::create(5, 1, str_repeat("\x07", 32), str_repeat("\x08", 32));

        self::assertStringNotContainsString(str_repeat("\x07", 32), print_r($context, true));

        try {
            serialize($context);
            self::fail('A key context was serialised.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('serialised', $e->getMessage());
        }

        $this->expectException(\LogicException::class);
        $copy = clone $context;
    }

    public function testWipedKeyIsUnusable(): void
    {
        $key = CalendarKey::create(3, 1, str_repeat("\x09", 32));
        $key->wipe();

        $this->expectException(\LogicException::class);
        $key->key();
    }

    public function testKeyringHoldsItsCurrentVersionOnly(): void
    {
        $ring = new CalendarKeyring(3, 2, [CalendarKey::create(3, 1, str_repeat("\x01", 32)), CalendarKey::create(3, 2, str_repeat("\x02", 32))]);

        self::assertSame(2, $ring->current()->version());
        self::assertNotNull($ring->version(1));
        self::assertNull($ring->version(3));

        $this->expectException(\InvalidArgumentException::class);
        new CalendarKeyring(3, 1, [CalendarKey::create(4, 1, str_repeat("\x01", 32))]);
    }

    public function testDeviceSessionCarriesKeysExactlyWhenActive(): void
    {
        $session = new DeviceSession(1, 5, 'someone', TeamStates::DEVICE_CLEANUP_EMPTY, null);
        self::assertFalse($session->isActive());
        self::assertSame('u5', $session->principal());

        $this->expectException(\InvalidArgumentException::class);
        new DeviceSession(1, 5, 'someone', TeamStates::DEVICE_ACTIVE, null);
    }

    public function testLogEventsAreUniqueAndDotted(): void
    {
        $events = AuditEvent::all();

        self::assertSame($events, array_values(array_unique($events)));

        foreach ($events as $event) {
            self::assertMatchesRegularExpression('/^[a-z]+\.[a-z_]+$/', $event);
            self::assertLessThanOrEqual(48, strlen($event));
        }
    }
}
