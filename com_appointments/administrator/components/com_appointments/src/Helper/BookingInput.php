<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class BookingInput
{
    public const DIAL_CODES = [
        '1', '7', '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45',
        '46', '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62',
        '63', '64', '65', '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
        '211', '212', '213', '216', '218', '220', '221', '222', '223', '224', '225', '226', '227',
        '228', '229', '230', '231', '232', '233', '234', '235', '236', '237', '238', '239', '240',
        '241', '242', '243', '244', '245', '246', '248', '249', '250', '251', '252', '253', '254',
        '255', '256', '257', '258', '260', '261', '262', '263', '264', '265', '266', '267', '268',
        '269', '290', '291', '297', '298', '299', '350', '351', '352', '353', '354', '355', '356',
        '357', '358', '359', '370', '371', '372', '373', '374', '375', '376', '377', '378', '380',
        '381', '382', '383', '385', '386', '387', '389', '420', '421', '423', '500', '501', '502',
        '503', '504', '505', '506', '507', '508', '509', '590', '591', '592', '593', '594', '595',
        '596', '597', '598', '599', '670', '672', '673', '674', '675', '676', '677', '678', '679',
        '680', '681', '682', '683', '685', '686', '687', '688', '689', '690', '691', '692', '850',
        '852', '853', '855', '856', '880', '886', '960', '961', '962', '963', '964', '965', '966',
        '967', '968', '970', '971', '972', '973', '974', '975', '976', '977', '992', '993', '994',
        '995', '996', '998',
    ];

    /** Location types at which the host and the invitee talk on the telephone. */
    public const PHONE_LOCATIONS = ['phone_host_calls', 'phone_invitee_calls'];

    /**
     * Calling codes whose national numbers keep their leading zero after the code
     * (Italy, San Marino, Vatican City, Côte d'Ivoire, Benin, Republic of the Congo). Everywhere
     * else a single leading zero is the national trunk prefix and is not dialled after
     * the country code, so "+41 079 …" is stored as "+4179…".
     */
    private const KEEP_LEADING_ZERO = ['39', '378', '379', '225', '229', '242'];

    /** Default and upper bound of the component option `max_guests` (0 = no guest field). */
    public const DEFAULT_MAX_GUESTS = 10;
    public const MAX_GUESTS_LIMIT = 50;

    /**
     * Longest first or last name. The full name "first last" is stored in the 255-character
     * `invitee_name` column, so both parts together with the space must fit there.
     */
    public const NAME_PART_LENGTH = 127;

    /** The calling code every telephone number must use for the current validate() call, or ''. */
    private string $fixedDialCode = '';

    /**
     * The calling code all telephone fields are limited to, or '' when the visitor chooses
     * the code. It is the preselected code (`phone_default_country`) when the option
     * `phone_country_choice` is 0 and that code is a valid calling code; otherwise ''.
     */
    public static function fixedDialCode(object $params): string
    {
        $get = static fn (string $name, mixed $default): mixed => method_exists($params, 'get')
            ? $params->get($name, $default) : ($params->$name ?? $default);
        $code = trim((string) $get('phone_default_country', ''), " +\t");

        return (string) $get('phone_country_choice', '1') === '0' && in_array($code, self::DIAL_CODES, true) ? $code : '';
    }

    /** Whether an event type with this location type asks for the invitee's telephone number. */
    public static function requiresPhone(string $locationType): bool
    {
        return in_array($locationType, self::PHONE_LOCATIONS, true);
    }

    /**
     * The configured maximum number of additional guests, clamped to 0..50. The form, the
     * hint and the server-side check all read it through this method, so they cannot differ.
     * A value that is not a whole number (an edited parameter) falls back to the default.
     */
    public static function maxGuests(mixed $configured): int
    {
        $value = filter_var($configured, FILTER_VALIDATE_INT);

        if ($value === false) {
            return self::DEFAULT_MAX_GUESTS;
        }

        return max(0, min(self::MAX_GUESTS_LIMIT, $value));
    }

    /**
     * Every field is checked, so the visitor sees all problems at once. A refusal of
     * a visible field is reported for that field (BookingValidationException::$fieldErrors),
     * a tampered hidden value (time zone, field structure) for the form as a whole.
     *
     * With $phoneRequired (both telephone location types) the fixed telephone field
     * `phone` is mandatory and is what `invitee_phone` stores. Otherwise the first answered
     * custom telephone field fills `invitee_phone`, as before.
     *
     * First and last name are separate required fields. `invitee_name` keeps the full name
     * ("first last"), so every consumer of the full name (mails, calendar, lists) is unchanged.
     *
     * $maxGuests is the component option `max_guests` (see maxGuests()). With 0 the form has
     * no guest field, so submitted guests are a tampered form, not a visitor error.
     *
     * $fixedDialCode (see fixedDialCode()) limits every telephone field to that calling code:
     * an empty selector means that code, and another code, chosen or typed with + or 00, is
     * refused at the field.
     */
    public function validate(array $input, array $fields, string $language, bool $phoneRequired = false,
        int $maxGuests = self::DEFAULT_MAX_GUESTS, string $fixedDialCode = ''): array
    {
        $errors = [];
        $this->fixedDialCode = in_array($fixedDialCode, self::DIAL_CODES, true) ? $fixedDialCode : '';
        $maxGuests = self::maxGuests($maxGuests);
        $namePart = function (string $field, string $message) use ($input): string {
            $value = $this->text($input[$field] ?? '', self::NAME_PART_LENGTH);

            if ($value === '' || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new BookingValidationException($message);
            }

            return $value;
        };
        $firstName = $this->collect($errors, 'first_name', 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME',
            fn (): string => $namePart('first_name', 'COM_APPOINTMENTS_BOOKING_ERROR_FIRST_NAME'));
        $lastName = $this->collect($errors, 'last_name', 'COM_APPOINTMENTS_BOOKING_ERROR_LAST_NAME',
            fn (): string => $namePart('last_name', 'COM_APPOINTMENTS_BOOKING_ERROR_LAST_NAME'));
        $email = $this->collect($errors, 'email', 'COM_APPOINTMENTS_BOOKING_ERROR_EMAIL', function () use ($input): string {
            $email = $this->text($input['email'] ?? '', 255);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_EMAIL');
            }

            return $email;
        });

        $zone = $this->text($input['timezone'] ?? '', 64);

        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_ZONE');
        }

        if (!in_array($language, ['en-GB', 'de-DE', 'fr-FR'], true)) {
            $language = 'en-GB';
        }

        if ($maxGuests === 0 && !(is_string($input['guests'] ?? '') && trim($input['guests'] ?? '') === '')) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        // The message is a sprintf text with the maximum (%d), rendered by the layout.
        $guests = $this->collect($errors, 'guests', 'COM_APPOINTMENTS_BOOKING_ERROR_GUESTS', function () use ($input, $email, $maxGuests): array {
            $guests = [];
            // Each address may take 255 characters plus a separator.
            $rawGuests = $this->text($input['guests'] ?? '', max(3000, 256 * $maxGuests));

            foreach (preg_split('/[\s,;]+/', $rawGuests, -1, PREG_SPLIT_NO_EMPTY) as $guest) {
                if (strlen($guest) > 255 || !filter_var($guest, FILTER_VALIDATE_EMAIL)) {
                    throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_GUESTS');
                }

                if (strcasecmp($guest, (string) $email) !== 0) {
                    $guests[strtolower($guest)] = $guest;
                }
            }

            if (count($guests) > $maxGuests) {
                throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_GUESTS');
            }

            return $guests;
        }) ?? [];

        $phone = null;

        if ($phoneRequired) {
            $phone = $this->collect($errors, 'phone', 'COM_APPOINTMENTS_BOOKING_ERROR_PHONE', function () use ($input): string {
                $raw = $input['phone'] ?? [];
                $number = $this->phone($raw === '' ? [] : $raw);

                if ($number === null) {
                    throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED');
                }

                return $number;
            });
        }

        $answers = [];
        $submitted = $input['fields'] ?? [];

        if (!is_array($submitted)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        foreach ($fields as $field) {
            $id = (string) $field['id'];
            // Invalid stored options are a configuration error, not a visitor error.
            $options = json_decode((string) (($field['options'] ?? '') ?: '[]'), true, 512, JSON_THROW_ON_ERROR) ?? [];
            $answer = $this->collect($errors, 'field-' . $id, 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_INVALID',
                fn (): array => $this->answer($field, $submitted[$id] ?? '', $options));

            if ($answer === null) {
                continue;
            }

            if (!$phoneRequired) {
                $phone ??= $answer['phone'];
            }

            $answers[$id] = $answer['value'];
        }

        if ($errors !== []) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS', $errors);
        }

        return [
            'invitee_name' => $firstName . ' ' . $lastName,
            'invitee_first_name' => $firstName, 'invitee_last_name' => $lastName,
            'invitee_email' => $email, 'invitee_phone' => $phone,
            'invitee_timezone' => $zone, 'invitee_language' => $language,
            'guest_emails' => implode("\n", array_values($guests)),
            'custom_fields' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Runs the check of one field. A refusal becomes the message of that field: the
     * required-field message when the field is empty, otherwise the field's own message.
     */
    private function collect(array &$errors, string $field, string $message, callable $check): mixed
    {
        try {
            return $check();
        } catch (BookingValidationException $e) {
            $errors[$field] = $e->languageKey === 'COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED' ? $e->languageKey : $message;

            return null;
        }
    }

    /** @return array{value: string|array, phone: ?string} */
    private function answer(array $field, mixed $raw, array $options): array
    {
        $type = (string) $field['type'];
        $phone = null;

        if ($type === 'checkboxes') {
            if ($raw === '') {
                $raw = [];
            }

            if (!is_array($raw) || count($raw) > 1000) {
                throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
            }

            $value = array_values(array_unique(array_map(fn ($item) => $this->text($item, 255), $raw)));

            foreach ($value as $choice) {
                if (!in_array($choice, $options, true)) {
                    throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
                }
            }
        } elseif ($type === 'tel') {
            $phone = $this->phone($raw === '' ? [] : $raw);
            $value = $phone ?? '';
        } else {
            $value = $this->text($raw, $type === 'textarea' ? 8000 : 255);

            if ($value !== '') {
                $valid = match ($type) {
                    'text' => preg_match('/[\x00-\x1f\x7f]/', $value) === 0,
                    'textarea' => true,
                    'number' => is_numeric($value) && is_finite((float) $value),
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'url' => filter_var($value, FILTER_VALIDATE_URL) !== false
                        && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true),
                    'radio' => in_array($value, $options, true),
                    'checkbox' => $value === '1',
                    'date' => UtcTimestamp::isDate($value),
                    default => false,
                };

                if (!$valid) {
                    throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
                }
            }
        }

        if (!empty($field['required']) && ($value === '' || $value === [])) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELD_REQUIRED');
        }

        return ['value' => $value, 'phone' => $phone];
    }

    /**
     * One telephone number in E.164 form ("+41791234567"), or null when the number is
     * empty. The visitor chooses a calling code and types the national number, or types
     * the whole international number starting with "+" or "00", in which case the
     * calling code is read from the number and the selector is ignored. Spaces,
     * brackets, dots and hyphens are ignored; a national trunk zero is dropped.
     */
    private function phone(mixed $raw): ?string
    {
        if (!is_array($raw)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        $number = $this->text($raw['number'] ?? '', 40);
        $code = $this->text($raw['country'] ?? '', 3);

        if ($number === '') {
            return null;
        }

        $digits = preg_replace('/[\s().\/-]/', '', $number);

        if (preg_match('/^(?:\+|00)([0-9]+)$/D', $digits, $match)) {
            // E.164 calling codes are prefix-free: at most one of 1, 2 or 3 leading digits is a code.
            $code = '';

            foreach ([1, 2, 3] as $length) {
                if (in_array(substr($match[1], 0, $length), self::DIAL_CODES, true)) {
                    $code = substr($match[1], 0, $length);
                    break;
                }
            }

            $digits = substr($match[1], strlen($code));
        } elseif ($this->fixedDialCode !== '' && $code === '') {
            $code = $this->fixedDialCode;
        }

        if ($this->fixedDialCode !== '' && $code !== $this->fixedDialCode) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        if (!in_array($code, self::DIAL_CODES, true)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        if (!in_array($code, self::KEEP_LEADING_ZERO, true) && str_starts_with($digits, '0') && !str_starts_with($digits, '00')) {
            $digits = substr($digits, 1);
        }

        if (!preg_match('/^[0-9]{3,14}$/D', $digits) || strlen($code . $digits) > 15) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        return '+' . $code . $digits;
    }

    private function text($value, int $max): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        $value = trim((string) $value);

        if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || str_contains($value, "\0")) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        return $value;
    }
}
