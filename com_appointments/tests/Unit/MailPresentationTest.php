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
