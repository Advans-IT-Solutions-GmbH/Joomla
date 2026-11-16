<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\BookingDetails;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/BookingDetails.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';

final class BookingDetailsTest extends TestCase
{
    public static function translate(string $key): string
    {
        return ['COM_APPOINTMENTS_MAIL_ANSWER_YES' => 'Ja', 'COM_APPOINTMENTS_MAIL_ANSWER_NO' => 'Nein',
            'COM_APPOINTMENTS_MAIL_ANSWER_NONE' => 'keine Angabe'][$key] ?? $key;
    }

    private function fields(): array
    {
        // In the order of the booking form (ordering), not of the ids.
        return [
            ['id' => 7, 'label' => 'Firma', 'type' => 'text'],
            ['id' => 3, 'label' => 'Anliegen', 'type' => 'textarea'],
            ['id' => 9, 'label' => 'Themen', 'type' => 'checkboxes'],
            ['id' => 4, 'label' => 'Newsletter', 'type' => 'checkbox'],
            ['id' => 5, 'label' => 'Rückruf erwünscht', 'type' => 'checkbox'],
            ['id' => 6, 'label' => 'Website', 'type' => 'url'],
        ];
    }

    public function testAnswersInFieldOrderWithReadableValues(): void
    {
        $stored = json_encode(['3' => "Zeile eins\r\nZeile zwei", '4' => '1', '5' => '', '6' => '', '7' => 'Muster AG',
            '9' => ['IT-Support', 'Netzwerk'], '42' => 'Antwort eines gelöschten Felds'], JSON_UNESCAPED_UNICODE);

        self::assertSame(
            "Firma: Muster AG\nAnliegen: Zeile eins\nZeile zwei\nThemen: IT-Support, Netzwerk\nNewsletter: Ja\n"
            . "Rückruf erwünscht: Nein\nWebsite: keine Angabe\n#42: Antwort eines gelöschten Felds",
            BookingDetails::answers($this->fields(), $stored, [self::class, 'translate'])
        );
    }

    public function testEmptyAnswersAndNoAnswersAtAll(): void
    {
        self::assertSame('Themen : keine Angabe', BookingDetails::answers([['id' => 9, 'label' => 'Themen', 'type' => 'checkboxes']],
            ['9' => []], [self::class, 'translate'], '%s :'), 'The French label format, an empty choice is «no answer»');
        self::assertSame('', BookingDetails::answers($this->fields(), '[]', [self::class, 'translate']));
        self::assertSame('', BookingDetails::answers($this->fields(), null, [self::class, 'translate']));
        self::assertSame('', BookingDetails::answers($this->fields(), '{broken', [self::class, 'translate']), 'Unreadable answers are left out, the mail still goes');
    }

    public function testControlCharactersAndHeaderInjectionAreNeutralised(): void
    {
        $text = BookingDetails::answers([['id' => 1, 'label' => "Fra\nge\x07", 'type' => 'textarea']],
            ['1' => "Text\x00\x1b mit\rBcc: x@example.test\nEND:VEVENT"], [self::class, 'translate']);

        self::assertSame("Fra ge: Text mit\nBcc: x@example.test\nEND:VEVENT", $text);
        self::assertSame(0, preg_match('/[\x00-\x08\x0b\x0c\x0d\x0e-\x1f\x7f]/', $text));

        // In the invitation the line ends are escaped: no answer starts a content line of its own.
        $ics = (new IcsWriter())->attachment(
            ['uid' => 'u', 'sequence' => 0, 'state' => 1, 'start_utc' => '2026-11-24 13:00:00', 'end_utc' => '2026-11-24 13:20:00'],
            ['title' => 'T', 'host_email' => 'host@example.test', 'description' => $text],
            new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC'))
        );
        self::assertSame(1, substr_count($ics, "\r\nEND:VEVENT\r\n"));
        self::assertStringNotContainsString("\r\nBcc:", $ics);
    }

    public function testHostDetails(): void
    {
        $details = BookingDetails::host(['Name' => 'Erika Muster', 'E-Mail' => 'erika@example.test', 'Telefon' => '',
            'Zeit beim Besucher' => '24.11.2026 08:00 (America/New_York)'], "Firma: Muster AG", [self::class, 'translate']);

        self::assertSame("Name: Erika Muster\nE-Mail: erika@example.test\nTelefon: keine Angabe\n"
            . "Zeit beim Besucher: 24.11.2026 08:00 (America/New_York)\nFirma: Muster AG", $details);
    }
}
