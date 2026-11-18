<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\EventScope;
use Advans\Module\Appointments\Administrator\Helper\Digest;
use PHPUnit\Framework\TestCase;

/**
 * The Joomla-free decisions of the dashboard module (modules/mod_appointments) and of the toolbar of
 * the bookings list (EventScope::allowsAny()).
 */
final class DashboardModuleTest extends TestCase
{
    public function testTheNumberOfAppointmentsStaysWithinItsRange(): void
    {
        self::assertSame(5, Digest::limit(null));
        self::assertSame(5, Digest::limit('abc'));
        self::assertSame(5, Digest::limit(0));
        self::assertSame(5, Digest::limit('-3'));
        self::assertSame(1, Digest::limit('1'));
        self::assertSame(12, Digest::limit(12));
        self::assertSame(20, Digest::limit(500));
    }

    public function testTheFirstViewOfASessionTakesTheStoredVisit(): void
    {
        self::assertSame(['since' => '2026-11-17 08:00:00', 'keep' => '2026-11-17 08:00:00'],
            Digest::baseline(null, '2026-11-17 08:00:00'));
    }

    public function testLaterViewsOfTheSessionKeepTheirReference(): void
    {
        // The stored visit was renewed by the first view; the session still counts from before it.
        self::assertSame(['since' => '2026-11-17 08:00:00', 'keep' => '2026-11-17 08:00:00'],
            Digest::baseline('2026-11-17 08:00:00', '2026-11-18 09:30:00'));
    }

    public function testTheVeryFirstVisitHasNoReference(): void
    {
        self::assertSame(['since' => null, 'keep' => Digest::NO_REFERENCE], Digest::baseline(null, null));
        self::assertSame(['since' => null, 'keep' => Digest::NO_REFERENCE], Digest::baseline(Digest::NO_REFERENCE, '2026-11-18 09:30:00'),
            'The whole first session counts nothing, also after the visit was stored');
        self::assertSame(['since' => null, 'keep' => Digest::NO_REFERENCE], Digest::baseline(null, 'not a time'), 'A damaged stored value counts as none');
        self::assertNotSame('', Digest::NO_REFERENCE, 'Joomla\'s session registry would return its default for an empty string');
    }

    public function testStoredTimesAreStrict(): void
    {
        self::assertTrue(Digest::isTime('2026-11-18 09:30:00'));
        self::assertFalse(Digest::isTime('2026-02-30 09:30:00'));
        self::assertFalse(Digest::isTime('2026-11-18T09:30:00'));
        self::assertFalse(Digest::isTime("2026-11-18 09:30:00\n"));
        self::assertFalse(Digest::isTime(null));
    }

    public function testTheNameOfTheBookingPerson(): void
    {
        self::assertSame('Anna Muster', Digest::name(['invitee_name' => "  Anna \n Muster "]));
        self::assertSame('Anna Muster', Digest::name(['invitee_name' => '', 'invitee_first_name' => 'Anna', 'invitee_last_name' => 'Muster']));
        self::assertSame('', Digest::name(['invitee_name' => null]));
    }

    public function testToolbarButtonsNeedThePermissionOnAListedEventType(): void
    {
        $user = new class () {
            /** @var array<string, bool> */
            public array $rules = [
                'core.manage:com_appointments' => true,
                'core.edit:com_appointments.eventtype.2' => true,
                'core.edit.state:com_appointments.eventtype.2' => true,
                'core.delete:com_appointments.eventtype.3' => true,
            ];

            public function authorise(string $action, string $asset): bool
            {
                return $this->rules[$action . ':' . $asset] ?? false;
            }
        };

        self::assertTrue(EventScope::allowsAny($user, 'core.edit', [1, 2]));
        self::assertFalse(EventScope::allowsAny($user, 'core.edit', [1, 3]));
        self::assertTrue(EventScope::allowsAny($user, 'core.edit.state', [1, 2]));
        self::assertFalse(EventScope::allowsAny($user, 'core.edit.state', [1, 3]));
        self::assertTrue(EventScope::allowsAny($user, 'core.delete', [3]));
        self::assertFalse(EventScope::allowsAny($user, 'core.delete', []), 'No listed event type, no button');
        self::assertFalse(EventScope::canExportCampaigns($user, []), 'An empty result must not grant campaign export');
        self::assertTrue(EventScope::canExportCampaigns($user, [['id' => 2, 'title' => 'Two']]),
            'An authorized event type keeps campaign export available without displayed bookings');
    }
}
