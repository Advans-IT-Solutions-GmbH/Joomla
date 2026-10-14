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

    public function testMalformedBase64FailsInsteadOfHidingMissingPersonalData(): void
    {
        $this->expectException(RuntimeException::class);
        AppointmentsMail::decoded("Content-Transfer-Encoding: base64\r\n\r\nnot#base64\r\n");
    }
}
