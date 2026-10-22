<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Helper\BookingValidationException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BookingInputTest extends TestCase
{
    private function input(): array
    {
        return ['name' => 'Fixture Person', 'email' => 'person@example.test', 'timezone' => 'Europe/Zurich'];
    }

    public function testContactGuestDeduplicationAndUnknownFields(): void
    {
        $input = $this->input() + ['guests' => "one@example.test, ONE@example.test\nperson@example.test;two@example.test", 'fields' => [999 => 'discard']];
        $row = (new BookingInput())->validate($input, [], 'fr-FR');
        self::assertSame('fr-FR', $row['invitee_language']);
        self::assertSame("ONE@example.test\ntwo@example.test", $row['guest_emails']);
        self::assertSame('[]', $row['custom_fields']);
        self::assertArrayNotHasKey('ip', $row);
        self::assertArrayNotHasKey('rendered_at', $row);
    }

    public function testMultilineInviteeNameIsRejectedBeforeSaving(): void
    {
        $this->expectException(BookingValidationException::class);
        (new BookingInput())->validate(array_replace($this->input(), ['name' => "Fixture\r\nName"]), [], 'en-GB');
    }

    #[DataProvider('validFields')]
    public function testEveryShippedFieldType(string $type, $value, string $options, $expected): void
    {
        $input = $this->input() + ['fields' => [5 => $value]];
        $row = (new BookingInput())->validate($input, [['id' => 5, 'type' => $type, 'required' => 1, 'options' => $options]], 'de-DE');
        self::assertSame($expected, json_decode($row['custom_fields'], true)['5']);
    }

    public static function validFields(): iterable
    {
        yield ['text', 'Einzel', '[]', 'Einzel'];
        yield ['textarea', "Deux\nlignes", '[]', "Deux\nlignes"];
        yield ['number', '-1.25', '[]', '-1.25'];
        yield ['tel', ['country' => '41', 'number' => '123 456 789'], '[]', '+41123456789'];
        yield ['email', 'field@example.test', '[]', 'field@example.test'];
        yield ['url', 'https://example.test/path', '[]', 'https://example.test/path'];
        yield ['radio', 'B', '["A","B"]', 'B'];
        yield ['checkboxes', ['B', 'A', 'B'], '["A","B"]', ['B', 'A']];
        yield ['checkbox', '1', '[]', '1'];
        yield ['date', '2028-02-29', '[]', '2028-02-29'];
    }

    #[DataProvider('badFields')]
    public function testRejectsMalformedFields(string $type, $value, string $options): void
    {
        $error = $this->refusal($this->input() + ['fields' => [5 => $value]],
            [['id' => 5, 'type' => $type, 'required' => 1, 'options' => $options]]);
        self::assertSame(['field-5'], array_keys($error->fieldErrors));
        self::assertContains($error->fieldErrors['field-5'],
            ['COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED', 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID']);
    }

    public static function badFields(): iterable
    {
        yield ['text', [], '[]'];
        yield ['text', '', '[]'];
        yield ['text', str_repeat('a', 256), '[]'];
        yield ['text', "Two\r\nlines", '[]'];
        yield ['number', 'NaN', '[]'];
        yield ['number', '1e999', '[]'];
        yield ['email', "person@example.test\r\nBcc: other@example.test", '[]'];
        yield ['url', 'javascript:alert(1)', '[]'];
        yield ['radio', 'C', '["A","B"]'];
        yield ['checkboxes', ['C'], '["A","B"]'];
        yield ['checkboxes', 'A', '["A","B"]'];
        yield ['checkbox', '0', '[]'];
        yield ['date', '2026-02-31', '[]'];
        yield ['date', '0999-01-01', '[]'];
        yield ['tel', ['country' => '999', 'number' => '1234'], '[]'];
        yield ['tel', ['country' => '41', 'number' => '<script>'], '[]'];
        yield ['tel', ['country' => '41', 'number' => '12345678901234'], '[]'];
    }

    public function testOptionalTelephoneMayBeOmitted(): void
    {
        $row = (new BookingInput())->validate($this->input(), [['id' => 1, 'type' => 'tel', 'required' => 0, 'options' => null]], 'en-GB');
        self::assertNull($row['invitee_phone']);
    }

    public function testRejectsElevenGuests(): void
    {
        $guests = array_map(static fn ($i) => "guest{$i}@example.test", range(1, 11));
        $error = $this->refusal($this->input() + ['guests' => implode("\n", $guests)], []);
        self::assertSame(['guests' => 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS'], $error->fieldErrors);
    }

    public function testEveryInvalidFieldGetsItsOwnMessageInOneRoundTrip(): void
    {
        $fields = [
            ['id' => 5, 'type' => 'text', 'required' => 1, 'options' => '[]'],
            ['id' => 6, 'type' => 'email', 'required' => 0, 'options' => '[]'],
            ['id' => 7, 'type' => 'text', 'required' => 0, 'options' => '[]'],
        ];
        $error = $this->refusal(['name' => '  ', 'email' => 'not-an-address', 'timezone' => 'Europe/Zurich',
            'guests' => 'guest@', 'fields' => [5 => '', 6 => 'broken', 7 => 'valid']], $fields);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS', $error->languageKey);
        self::assertSame([
            'name' => 'COM_APPOINTMENTS_BOOKING_ERROR_NAME',
            'email' => 'COM_APPOINTMENTS_BOOKING_ERROR_EMAIL',
            'guests' => 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS',
            'field-5' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED',
            'field-6' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID',
        ], $error->fieldErrors);
    }

    public function testMalformedContactValuesAreReportedAtTheirFields(): void
    {
        $error = $this->refusal(['name' => ['array'], 'email' => str_repeat('a', 300) . '@example.test', 'timezone' => 'UTC'], []);
        self::assertSame([
            'name' => 'COM_APPOINTMENTS_BOOKING_ERROR_NAME',
            'email' => 'COM_APPOINTMENTS_BOOKING_ERROR_EMAIL',
        ], $error->fieldErrors);
    }

    public function testTamperedHiddenValuesAreAFormErrorWithoutFieldMessages(): void
    {
        $error = $this->refusal(array_replace($this->input(), ['timezone' => 'Mars/Base']), []);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_ZONE', $error->languageKey);
        self::assertSame([], $error->fieldErrors);
        $error = $this->refusal($this->input() + ['fields' => 'not-an-array'], []);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS', $error->languageKey);
        self::assertSame([], $error->fieldErrors);
    }

    private function refusal(array $input, array $fields): BookingValidationException
    {
        try {
            (new BookingInput())->validate($input, $fields, 'en-GB');
        } catch (BookingValidationException $e) {
            return $e;
        }

        self::fail('The input was accepted.');
    }
}
