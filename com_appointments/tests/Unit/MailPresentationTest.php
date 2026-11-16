<?php

use Advans\Component\Appointments\Administrator\Helper\MailPresentation;
use PHPUnit\Framework\TestCase;

final class MailPresentationTest extends TestCase
{
    public function testLocalizedInviteeBoundsPreserveOriginalUtc(): void
    {
        $booking = ['start_utc' => '2026-10-25 01:30:00', 'end_utc' => '2026-10-25 02:30:00'];
        $presentation = new MailPresentation();
        $this->assertSame([
            '{start}' => '25.10.2026 02:30', '{end}' => '25.10.2026 03:30', '{timezone}' => 'Europe/Zurich',
        ], $presentation->placeholders($booking, 'de-DE', 'Europe/Zurich'));
        $this->assertSame('25/10/2026 01:30', $presentation->placeholders($booking, 'fr-FR', 'UTC')['{start}']);
        $this->assertSame('2026-10-24 18:30', $presentation->placeholders($booking, 'en-GB', 'America/Los_Angeles')['{start}']);
        $this->assertSame('2026-10-25 01:30:00', $booking['start_utc']);
    }

    /** Live booking of 16.11.2026: a visitor in New York, the host in Zurich. */
    public function testInviteeMailAddsTheHostTimeAndTheHostMailTheInviteeTime(): void
    {
        $booking = ['start_utc' => '2026-11-24 13:00:00', 'end_utc' => '2026-11-24 13:20:00', 'invitee_timezone' => 'America/New_York'];
        $presentation = new MailPresentation();

        $this->assertSame([
            '{start}' => '24.11.2026 08:00 (14:00 Europe/Zurich)', '{end}' => '24.11.2026 08:20 (14:20 Europe/Zurich)',
            '{timezone}' => 'America/New_York',
        ], $presentation->placeholders($booking, 'de-DE', 'America/New_York', 'Europe/Zurich'));
        $this->assertSame('24.11.2026 14:00', $presentation->placeholders($booking, 'de-DE', 'Europe/Zurich', 'Europe/Zurich')['{start}'],
            'The same zone adds nothing');
        $this->assertSame('24.11.2026 08:00 (America/New_York)', $presentation->inviteeTime($booking, 'de-DE', 'Europe/Zurich'));
        $this->assertSame('', $presentation->inviteeTime($booking, 'de-DE', 'America/New_York'));
        $this->assertSame('', $presentation->inviteeTime(['invitee_timezone' => null] + $booking, 'de-DE', 'Europe/Zurich'));

        // Another day there: the date is named too.
        $late = ['start_utc' => '2026-11-24 23:30:00', 'end_utc' => '2026-11-25 00:30:00'];
        $this->assertSame('2026-11-24 18:30 (2026-11-25 00:30 Europe/Zurich)',
            $presentation->placeholders($late, 'en-GB', 'America/New_York', 'Europe/Zurich')['{start}']);
    }

    public function testFixedOffsetTimezoneIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MailPresentation())->placeholders(['start_utc' => '2026-10-25 01:30:00', 'end_utc' => '2026-10-25 02:30:00'], 'en-GB', 'CET');
    }

    public function testImpossibleStoredDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new MailPresentation())->placeholders(['start_utc' => '2026-02-31 01:30:00', 'end_utc' => '2026-03-01 02:30:00'], 'en-GB', 'UTC');
    }
}
