<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../scripts/appointments-mail.php';

final class MailCaptureTest extends TestCase
{
    public function testCapturedMimeDecodesBothCalendarPartsAndText(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nMETHOD:CANCEL\r\nUID:fixture\r\nEND:VCALENDAR\r\n";
        $mime = "Content-Type: multipart/mixed; boundary=\"fixture-boundary\"\r\n\r\n"
            . "--fixture-boundary\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "A=20message\r\n--fixture-boundary\r\nContent-Type: text/calendar\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($calendar), 76, "\r\n")
            . "\r\n--fixture-boundary\r\nContent-Type: application/ics\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($calendar), 76, "\r\n") . "\r\n--fixture-boundary--\r\n";
        $decoded = AppointmentsMail::decoded($mime);
        $this->assertStringContainsString('A message', $decoded);
        $this->assertSame(2, substr_count($decoded, "METHOD:CANCEL\r\n"));
    }

    /** The shape PHPMailer writes for $Ical: text, HTML and the invitation as alternatives. */
    public function testInvitationIsTheOneCalendarAlternative(): void
    {
        $calendar = "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nUID:fixture\r\nEND:VCALENDAR\r\n";
        $alternative = "Content-Type: multipart/alternative;\r\n boundary=\"b1\"\r\n\r\n"
            . "--b1\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\nText\r\n"
            . "--b1\r\nContent-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n<p>Text</p>\r\n"
            . "--b1\r\nContent-Type: text/calendar; method=REQUEST; charset=utf-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n"
            . $calendar . "\r\n--b1--\r\n";

        $this->assertTrue(AppointmentsMail::isInvitation($alternative, 'REQUEST'));
        $this->assertFalse(AppointmentsMail::isInvitation($alternative, 'CANCEL'));
        $this->assertSame([$calendar], AppointmentsMail::calendars($alternative));
        $this->assertSame(["<p>Text</p>\r\n"], AppointmentsMail::parts($alternative, 'text/html'));

        // The earlier shape, the calendar twice as attachments, is no invitation.
        $attached = "Content-Type: multipart/mixed; boundary=\"m\"\r\n\r\n--m\r\nContent-Type: text/plain\r\n\r\nText\r\n"
            . "--m\r\nContent-Type: text/calendar; charset=UTF-8; method=REQUEST; name=invite.ics\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($calendar), 76, "\r\n")
            . "--m\r\nContent-Type: application/ics; name=invite.ics\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($calendar), 76, "\r\n") . "--m--\r\n";
        $this->assertFalse(AppointmentsMail::isInvitation($attached, 'REQUEST'));
    }

    public function testMalformedBase64FailsInsteadOfHidingMissingPersonalData(): void
    {
        $this->expectException(RuntimeException::class);
        AppointmentsMail::decoded("Content-Transfer-Encoding: base64\r\n\r\nnot#base64\r\n");
    }
}
