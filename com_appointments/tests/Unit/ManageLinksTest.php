<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\MailComposer;
use Advans\Component\Appointments\Administrator\Helper\ManageLinks;
use PHPUnit\Framework\TestCase;

/**
 * Audit 18.11.2026, B6: the cancellation mail to the booking person offered «Reschedule» and
 * «Cancel» for an appointment that no longer exists. A cancelled booking offers no link, in the
 * footer and in the placeholders; every other state keeps both.
 */
final class ManageLinksTest extends TestCase
{
    private const LINKS = [
        'reschedule_url' => 'https://example.test/index.php?action=reschedule&signature=r',
        'cancel_url' => 'https://example.test/index.php?action=cancel&signature=c',
    ];

    public function testCancelledBookingKeepsNoLink(): void
    {
        self::assertSame([], ManageLinks::forState(self::LINKS, 2));
    }

    public function testPendingAndConfirmedBookingsKeepEveryLink(): void
    {
        self::assertSame(self::LINKS, ManageLinks::forState(self::LINKS, 0));
        self::assertSame(self::LINKS, ManageLinks::forState(self::LINKS, 1));
    }

    public function testFooterListsTheLinksInOrderWithTheirLabels(): void
    {
        $labels = ['COM_APPOINTMENTS_MAIL_RESCHEDULE' => 'Reschedule', 'COM_APPOINTMENTS_MAIL_CANCEL' => 'Cancel'];
        $translate = static fn (string $key): string => $labels[$key];

        self::assertSame("Reschedule:\n" . self::LINKS['reschedule_url'] . "\nCancel:\n" . self::LINKS['cancel_url'],
            ManageLinks::footer(self::LINKS, $translate));
        self::assertSame("Cancel :\n" . self::LINKS['cancel_url'],
            ManageLinks::footer(['cancel_url' => self::LINKS['cancel_url']], $translate, '%s :'));
        self::assertSame('', ManageLinks::footer([], $translate));
    }

    public function testCancellationTemplateRendersNoLink(): void
    {
        $booking = ['id' => 7, 'uid' => 'uid-7', 'sequence' => 2, 'state' => 2, 'start_utc' => '2030-01-15 09:00:00',
            'end_utc' => '2030-01-15 09:30:00', 'invitee_email' => 'invitee@example.test', 'invitee_name' => 'Invitee'];
        $event = ['title' => 'Meeting', 'host_email' => 'host@example.test', 'host_name' => 'Host'];
        // A template an operator wrote with every link placeholder, including the removed {erase_url}.
        $mail = (new MailComposer(new IcsWriter()))->compose(['subject' => 'Cancelled', 'body' => "Move: {reschedule_url}\nCancel: {cancel_url}\nErase: {erase_url}"],
            $booking, $event, ManageLinks::forState(self::LINKS, 2), new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC')));

        self::assertStringNotContainsString('action=', $mail['body']);
        self::assertStringNotContainsString('{erase_url}', $mail['body']);
        self::assertSame('CANCEL', $mail['method'], 'The calendar of a cancelled booking still cancels the entry');
    }
}
