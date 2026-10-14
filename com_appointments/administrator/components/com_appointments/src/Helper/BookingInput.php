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

    public function validate(array $input, array $fields, string $language): array
    {
        $name = $this->text($input['name'] ?? '', 255);
        $email = $this->text($input['email'] ?? '', 255);

        if ($name === '' || preg_match('/[\x00-\x1f\x7f]/', $name) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_CONTACT');
        }

        $zone = $this->text($input['timezone'] ?? '', 64);

        if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_ZONE');
        }

        if (!in_array($language, ['en-GB', 'de-DE', 'fr-FR'], true)) {
            $language = 'en-GB';
        }

        $guests = [];
        $rawGuests = $this->text($input['guests'] ?? '', 3000);

        foreach (preg_split('/[\s,;]+/', $rawGuests, -1, PREG_SPLIT_NO_EMPTY) as $guest) {
            if (strlen($guest) > 255 || !filter_var($guest, FILTER_VALIDATE_EMAIL)) {
                throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_GUESTS');
            }

            if (strcasecmp($guest, $email) !== 0) {
                $guests[strtolower($guest)] = $guest;
            }
        }

        if (count($guests) > 10) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_GUESTS');
        }

        $answers = [];
        $phone = null;
        $submitted = $input['fields'] ?? [];

        if (!is_array($submitted)) {
            throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
        }

        foreach ($fields as $field) {
            $id = (string) $field['id'];
            $type = (string) $field['type'];
            $raw = $submitted[$id] ?? '';
            $options = json_decode((string) (($field['options'] ?? '') ?: '[]'), true, 512, JSON_THROW_ON_ERROR) ?? [];

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
                if ($raw === '') {
                    $raw = [];
                }

                if (!is_array($raw)) {
                    throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
                }

                $number = $this->text($raw['number'] ?? '', 40);
                $code = $this->text($raw['country'] ?? '', 3);
                $value = '';

                if ($number !== '') {
                    $digits = preg_replace('/[\s().-]/', '', $number);

                    if (!in_array($code, self::DIAL_CODES, true) || !preg_match('/^[0-9]{3,14}$/D', $digits)
                        || strlen($code . $digits) > 15) {
                        throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
                    }

                    $value = '+' . $code . $digits;
                    $phone ??= $value;
                }
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
                throw new BookingValidationException('COM_APPOINTMENTS_BOOKING_ERROR_FIELDS');
            }

            $answers[$id] = $value;
        }

        return [
            'invitee_name' => $name, 'invitee_email' => $email, 'invitee_phone' => $phone,
            'invitee_timezone' => $zone, 'invitee_language' => $language,
            'guest_emails' => implode("\n", array_values($guests)),
            'custom_fields' => json_encode($answers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];
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
