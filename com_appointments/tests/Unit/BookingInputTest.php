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
        return ['first_name' => 'Fixture', 'last_name' => 'Person', 'email' => 'person@example.test', 'timezone' => 'Europe/Zurich'];
    }

    public function testFirstAndLastNameAreStoredSeparatelyAndAsTheFullName(): void
    {
        $row = (new BookingInput())->validate(array_replace($this->input(), ['first_name' => '  Anna Maria ', 'last_name' => ' Muster-Meier ']), [], 'de-DE');
        self::assertSame('Anna Maria', $row['invitee_first_name']);
        self::assertSame('Muster-Meier', $row['invitee_last_name']);
        self::assertSame('Anna Maria Muster-Meier', $row['invitee_name']);
        self::assertArrayNotHasKey('name', $row);
    }

    public function testFirstAndLastNameAreBothRequiredAndReportedAtTheirFields(): void
    {
        $input = $this->input();
        unset($input['first_name'], $input['last_name']);
        $error = $this->refusal($input + ['name' => 'Legacy Single Field'], []);
        self::assertSame([
            'first_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME',
            'last_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_LAST_NAME',
        ], $error->fieldErrors);

        $error = $this->refusal(array_replace($this->input(), ['last_name' => '   ']), []);
        self::assertSame(['last_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_LAST_NAME'], $error->fieldErrors);
    }

    public function testNamePartsAreLimitedSoTheFullNameFitsItsColumn(): void
    {
        $long = str_repeat('ä', BookingInput::NAME_PART_LENGTH);
        $row = (new BookingInput())->validate(array_replace($this->input(), ['first_name' => $long, 'last_name' => $long]), [], 'en-GB');
        self::assertSame(255, mb_strlen($row['invitee_name']));

        $error = $this->refusal(array_replace($this->input(), ['first_name' => $long . 'a']), []);
        self::assertSame(['first_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME'], $error->fieldErrors);
        $error = $this->refusal(array_replace($this->input(), ['last_name' => ['array']]), []);
        self::assertSame(['last_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_LAST_NAME'], $error->fieldErrors);
    }

    public function testConfiguredGuestMaximumIsEnforced(): void
    {
        $guests = static fn (int $count): string => implode(', ', array_map(static fn ($i) => "guest{$i}@example.test", range(1, $count)));
        $row = (new BookingInput())->validate($this->input() + ['guests' => $guests(3)], [], 'en-GB', false, 3);
        self::assertCount(3, explode("\n", $row['guest_emails']));

        $error = $this->refusal($this->input() + ['guests' => $guests(4)], [], false, 3);
        self::assertSame(['guests' => 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS'], $error->fieldErrors);

        $row = (new BookingInput())->validate($this->input() + ['guests' => $guests(50)], [], 'en-GB', false, 50);
        self::assertCount(50, explode("\n", $row['guest_emails']));
        $error = $this->refusal($this->input() + ['guests' => $guests(51)], [], false, 99);
        self::assertSame(['guests' => 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS'], $error->fieldErrors, 'The maximum is clamped to 50');
    }

    public function testWithoutAGuestFieldSubmittedGuestsAreATamperedForm(): void
    {
        $row = (new BookingInput())->validate($this->input(), [], 'en-GB', false, 0);
        self::assertSame('', $row['guest_emails']);

        $error = $this->refusal($this->input() + ['guests' => 'guest@example.test'], [], false, 0);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS', $error->languageKey);
        self::assertSame([], $error->fieldErrors);
    }

    public function testGuestMaximumOptionIsClamped(): void
    {
        self::assertSame(10, BookingInput::maxGuests(null));
        self::assertSame(10, BookingInput::maxGuests('ten'));
        self::assertSame(0, BookingInput::maxGuests('0'));
        self::assertSame(0, BookingInput::maxGuests(-5));
        self::assertSame(25, BookingInput::maxGuests('25'));
        self::assertSame(50, BookingInput::maxGuests(500));
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
        (new BookingInput())->validate(array_replace($this->input(), ['last_name' => "Fixture\r\nName"]), [], 'en-GB');
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
            ['COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED', 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID',
                'COM_APPOINTMENTS_BOOKING_ERROR_PHONE_LENGTH']);
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

    public function testBothTelephoneLocationTypesRequireThePhone(): void
    {
        self::assertTrue(BookingInput::requiresPhone('phone_host_calls'));
        self::assertTrue(BookingInput::requiresPhone('phone_invitee_calls'));

        foreach (['in_person', 'custom_text', 'conference', ''] as $type) {
            self::assertFalse(BookingInput::requiresPhone($type));
        }
    }

    public function testRequiredFixedPhoneIsReportedAtItsField(): void
    {
        foreach ([[], '', ['country' => '41', 'number' => '']] as $phone) {
            $error = $this->refusal($this->input() + ['phone' => $phone], [], true);
            self::assertSame(['phone' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED'], $error->fieldErrors);
        }

        foreach ([['country' => '41', 'number' => '12'], ['country' => '999', 'number' => '791234567'],
            ['country' => '41', 'number' => 'call me'], ['country' => '', 'number' => '791234567'],
            ['country' => '41', 'number' => '+999 1234567'], 'not-an-array'] as $phone) {
            $error = $this->refusal($this->input() + ['phone' => $phone], [], true);
            self::assertSame(['phone'], array_keys($error->fieldErrors));
        }

        $error = $this->refusal($this->input() + ['phone' => ['country' => '41', 'number' => 'call me']], [], true);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_PHONE', $error->fieldErrors['phone']);
        $error = $this->refusal($this->input() + ['phone' => ['country' => '41', 'number' => '12']], [], true);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_PHONE_LENGTH', $error->fieldErrors['phone']);
    }

    #[DataProvider('implausibleLengths')]
    public function testNumberOfTheWrongLengthForItsCallingCodeIsRefusedWithItsOwnMessage(array $phone): void
    {
        $error = $this->refusal($this->input() + ['phone' => $phone], [], true);
        self::assertSame(['phone' => 'COM_APPOINTMENTS_BOOKING_ERROR_PHONE_LENGTH'], $error->fieldErrors);

        // A custom telephone field gets the same message.
        $error = $this->refusal($this->input() + ['fields' => [7 => $phone]], [['id' => 7, 'type' => 'tel', 'required' => 0, 'options' => null]]);
        self::assertSame(['field-7' => 'COM_APPOINTMENTS_BOOKING_ERROR_PHONE_LENGTH'], $error->fieldErrors);
    }

    public static function implausibleLengths(): iterable
    {
        yield 'Switzerland, three digits' => [['country' => '', 'number' => '+41 123']];
        yield 'Switzerland, three digits with the selector' => [['country' => '41', 'number' => '123']];
        yield 'Switzerland, eight digits' => [['country' => '41', 'number' => '061 000 00 0']];
        yield 'Switzerland, ten digits' => [['country' => '41', 'number' => '61 000 00 000']];
        yield 'Switzerland, ten digits after a trunk zero' => [['country' => '', 'number' => '+41 0 61 000 00 000']];
        yield 'Switzerland, voicemail one digit short' => [['country' => '', 'number' => '+41 860 79 123 45 6']];
        yield 'Switzerland, twelve digits without the voicemail prefix' => [['country' => '41', 'number' => '79 123 45 67 891']];
        yield 'North America, nine digits' =>[['country' => '1', 'number' => '202 555 010']];
        yield 'North America, eleven digits' => [['country' => '', 'number' => '+1 202 555 01000']];
        yield 'France, eight digits' => [['country' => '33', 'number' => '01 23 45 67 8']];
        yield 'Denmark, nine digits' => [['country' => '45', 'number' => '123 456 789']];
        yield 'calling code alone' => [['country' => '', 'number' => '+49']];
        yield 'two digits for a code without a fixed length' => [['country' => '49', 'number' => '30']];
        yield 'sixteen digits in all' => [['country' => '', 'number' => '+49 30 1234 5678 9012']];
    }

    #[DataProvider('internationalNumbers')]
    public function testInternationalNumbersOfAPlausibleLengthAreAccepted(array $phone, string $expected): void
    {
        $row = (new BookingInput())->validate($this->input() + ['phone' => $phone], [], 'en-GB', true);
        self::assertSame($expected, $row['invitee_phone']);
    }

    public static function internationalNumbers(): iterable
    {
        yield 'Switzerland' => [['country' => '41', 'number' => '+41 61 000 00 00'], '+41610000000'];
        yield 'Switzerland, national with trunk zero' => [['country' => '41', 'number' => '061 000 00 00'], '+41610000000'];
        yield 'Switzerland, voicemail' => [['country' => '', 'number' => '+41 860 79 123 45 67'], '+41860791234567'];
        yield 'Switzerland, voicemail national with trunk zero' => [['country' => '41', 'number' => '0860 79 123 45 67'], '+41860791234567'];
        yield 'Germany, short Berlin number' => [['country' => '41', 'number' => '+49 30 000000'], '+4930000000'];
        yield 'Germany, chosen code' => [['country' => '49', 'number' => '030 000000'], '+4930000000'];
        yield 'United States' => [['country' => '41', 'number' => '+1 202 555 0100'], '+12025550100'];
        yield 'France' => [['country' => '', 'number' => '+33 1 23 45 67 89'], '+33123456789'];
        yield 'Denmark' => [['country' => '45', 'number' => '12 34 56 78'], '+4512345678'];
        yield 'Liechtenstein, no fixed length' => [['country' => '', 'number' => '+423 234 56 78'], '+4232345678'];
        yield 'fifteen digits in all' => [['country' => '', 'number' => '+49 30 1234 5678 901'], '+493012345678901'];
    }

    #[DataProvider('phoneNumbers')]
    public function testFixedPhoneIsStoredInInternationalForm(array $phone, string $expected): void
    {
        $row = (new BookingInput())->validate($this->input() + ['phone' => $phone], [], 'de-DE', true);
        self::assertSame($expected, $row['invitee_phone']);
        self::assertSame('[]', $row['custom_fields']);
    }

    public static function phoneNumbers(): iterable
    {
        yield 'national with trunk zero' => [['country' => '41', 'number' => '079 123 45 67'], '+41791234567'];
        yield 'national without trunk zero' => [['country' => '41', 'number' => '79 123 45 67'], '+41791234567'];
        yield 'international plus wins over the selector' => [['country' => '49', 'number' => '+41 (0)79 123-45-67'], '+41791234567'];
        yield 'international 00 prefix' => [['country' => '', 'number' => '0044 20 7946 0958'], '+442079460958'];
        yield 'three-digit code' => [['country' => '423', 'number' => '234 56 78'], '+4232345678'];
        yield 'Italy keeps its leading zero' => [['country' => '39', 'number' => '06 1234 5678'], '+390612345678'];
        yield 'Benin keeps its leading zero, national' => [['country' => '229', 'number' => '01 97 12 34 56'], '+2290197123456'];
        yield 'Benin keeps its leading zero, international' => [['country' => '', 'number' => '+229 01 97 12 34 56'], '+2290197123456'];
        yield 'North America' => [['country' => '1', 'number' => '(212) 555-0100'], '+12125550100'];
    }

    public function testFixedDialCodeAppliesOnlyWithoutChoiceAndAValidDefault(): void
    {
        $params = static fn (array $values): object => (object) $values;
        self::assertSame('41', BookingInput::fixedDialCode($params(['phone_default_country' => '41', 'phone_country_choice' => 0])));
        self::assertSame('41', BookingInput::fixedDialCode($params(['phone_default_country' => '+41', 'phone_country_choice' => '0'])));
        self::assertSame('', BookingInput::fixedDialCode($params(['phone_default_country' => '41', 'phone_country_choice' => 1])));
        self::assertSame('', BookingInput::fixedDialCode($params(['phone_default_country' => '41'])), 'Choice is allowed by default');
        self::assertSame('', BookingInput::fixedDialCode($params(['phone_default_country' => '999', 'phone_country_choice' => 0])));
        self::assertSame('', BookingInput::fixedDialCode($params(['phone_default_country' => '', 'phone_country_choice' => 0])));
    }

    public function testFixedDialCodeRefusesAnyOtherCodeInEveryTelephoneField(): void
    {
        $validate = fn (array $input, array $fields = []): array => (new BookingInput())->validate($input, $fields, 'en-GB', true, 10, '41');
        self::assertSame('+41791234567', $validate($this->input() + ['phone' => ['country' => '', 'number' => '079 123 45 67']])['invitee_phone']);
        self::assertSame('+41791234567', $validate($this->input() + ['phone' => ['country' => '41', 'number' => '+41 79 123 45 67']])['invitee_phone']);

        foreach ([['country' => '49', 'number' => '30 1234567'], ['country' => '41', 'number' => '+49 30 1234567'],
            ['country' => '', 'number' => '0049 30 1234567']] as $phone) {
            try {
                $validate($this->input() + ['phone' => $phone]);
                self::fail('Another calling code was accepted.');
            } catch (BookingValidationException $e) {
                self::assertSame(['phone' => 'COM_APPOINTMENTS_BOOKING_ERROR_PHONE'], $e->fieldErrors);
            }
        }

        $fields = [['id' => 3, 'type' => 'tel', 'required' => 0, 'options' => null]];
        try {
            $validate($this->input() + ['phone' => ['country' => '41', 'number' => '791234567'], 'fields' => [3 => ['country' => '', 'number' => '+44 20 7946 0958']]], $fields);
            self::fail('A custom telephone field accepted another calling code.');
        } catch (BookingValidationException $e) {
            self::assertSame(['field-3' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID'], $e->fieldErrors);
        }

        $row = (new BookingInput())->validate($this->input() + ['phone' => ['country' => '49', 'number' => '30 1234567']], [], 'en-GB', true);
        self::assertSame('+49301234567', $row['invitee_phone'], 'Without a fixed code every code is accepted');
    }

    public function testFixedPhoneTakesPrecedenceOverACustomTelephoneField(): void
    {
        $fields = [['id' => 3, 'type' => 'tel', 'required' => 0, 'options' => null]];
        $input = $this->input() + ['phone' => ['country' => '41', 'number' => '791234567'],
            'fields' => [3 => ['country' => '49', 'number' => '30 1234567']]];
        $row = (new BookingInput())->validate($input, $fields, 'en-GB', true);
        self::assertSame('+41791234567', $row['invitee_phone']);
        self::assertSame('+49301234567', json_decode($row['custom_fields'], true)['3']);

        // Without a telephone location the fixed field is not part of the form and is ignored.
        $row = (new BookingInput())->validate($input, $fields, 'en-GB');
        self::assertSame('+49301234567', $row['invitee_phone']);
        $row = (new BookingInput())->validate($this->input() + ['phone' => 'tampered'], [], 'en-GB');
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
        $error = $this->refusal(['first_name' => '  ', 'last_name' => 'Person', 'email' => 'not-an-address', 'timezone' => 'Europe/Zurich',
            'guests' => 'guest@', 'fields' => [5 => '', 6 => 'broken', 7 => 'valid']], $fields);
        self::assertSame('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS', $error->languageKey);
        self::assertSame([
            'first_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME',
            'email' => 'COM_APPOINTMENTS_BOOKING_ERROR_EMAIL',
            'guests' => 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS',
            'field-5' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED',
            'field-6' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID',
        ], $error->fieldErrors);
    }

    public function testMalformedContactValuesAreReportedAtTheirFields(): void
    {
        $error = $this->refusal(['first_name' => ['array'], 'last_name' => 'Person', 'email' => str_repeat('a', 300) . '@example.test', 'timezone' => 'UTC'], []);
        self::assertSame([
            'first_name' => 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME',
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

    private function refusal(array $input, array $fields, bool $phoneRequired = false, int $maxGuests = 10): BookingValidationException
    {
        try {
            (new BookingInput())->validate($input, $fields, 'en-GB', $phoneRequired, $maxGuests);
        } catch (BookingValidationException $e) {
            return $e;
        }

        self::fail('The input was accepted.');
    }
}
