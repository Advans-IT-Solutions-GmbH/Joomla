<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * What a booking person entered, as plain-text «Label: value» lines for the mails and for the
 * DESCRIPTION of the host's invitation: the answers to the custom fields in the order of the
 * fields ({field_answers}), and the complete details of the host's calendar entry (host()).
 *
 * Every value is plain text. Control characters are removed and line ends become "\n", so a value
 * can neither break a mail header nor an iCalendar content line (IcsWriter escapes "\n" and folds;
 * MailHtml escapes the HTML part). An empty answer reads «no answer», never a dash.
 */
final class BookingDetails
{
    /**
     * «Label: answer» lines of every answer, in the order of $fields (as configured, ordering then
     * id); answers of fields that no longer exist follow, labelled with their id.
     *
     * @param   list<array{id: int|string, label?: string, type?: string}>  $fields
     * @param   mixed                     $stored     The stored answers (JSON text or array).
     * @param   callable(string): string  $translate  Translates COM_APPOINTMENTS_MAIL_ANSWER_*.
     * @param   string                    $labelFormat  «%s:» (or «%s :» in French).
     */
    public static function answers(array $fields, mixed $stored, callable $translate, string $labelFormat = '%s:'): string
    {
        $answers = self::decode($stored);
        $lines = [];

        foreach ($fields as $field) {
            $id = (string) $field['id'];

            if (!array_key_exists($id, $answers)) {
                continue;
            }

            $lines[] = self::line((string) ($field['label'] ?? ''), $id, $answers[$id], (string) ($field['type'] ?? ''), $translate, $labelFormat);
            unset($answers[$id]);
        }

        foreach ($answers as $id => $value) {
            $lines[] = self::line('', (string) $id, $value, '', $translate, $labelFormat);
        }

        return implode("\n", $lines);
    }

    /**
     * The details of the host's calendar entry: one «Label: value» line per entry of $rows
     * (label => value, already translated), an empty value as «no answer», then $answers.
     *
     * @param   array<string, string>     $rows
     * @param   callable(string): string  $translate
     */
    public static function host(array $rows, string $answers, callable $translate, string $labelFormat = '%s:'): string
    {
        $lines = [];

        foreach ($rows as $label => $value) {
            $value = self::clean($value);
            $lines[] = sprintf($labelFormat, self::singleLine((string) $label)) . ' '
                . ($value === '' ? $translate('COM_APPOINTMENTS_MAIL_ANSWER_NONE') : $value);
        }

        if (trim($answers) !== '') {
            $lines[] = self::clean($answers);
        }

        return implode("\n", $lines);
    }

    /** Text without control characters, line ends as "\n", trimmed. */
    public static function clean(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $value);

        return preg_match('//u', $value) === 1 ? trim($value) : '';
    }

    private static function line(string $label, string $id, mixed $value, string $type, callable $translate, string $labelFormat): string
    {
        $label = self::singleLine($label);

        return sprintf($labelFormat, $label !== '' ? $label : '#' . $id) . ' ' . self::value($value, $type, $translate);
    }

    private static function value(mixed $value, string $type, callable $translate): string
    {
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(
                static fn (mixed $item): string => is_scalar($item) ? self::singleLine((string) $item) : '',
                $value
            ), static fn (string $item): bool => $item !== ''));
        } elseif ($type === 'checkbox') {
            return $translate((string) $value === '1' ? 'COM_APPOINTMENTS_MAIL_ANSWER_YES' : 'COM_APPOINTMENTS_MAIL_ANSWER_NO');
        } else {
            $value = is_scalar($value) ? self::clean((string) $value) : '';
        }

        return $value === '' ? $translate('COM_APPOINTMENTS_MAIL_ANSWER_NONE') : $value;
    }

    private static function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::clean($value)));
    }

    /** @return array<string, mixed> */
    private static function decode(mixed $stored): array
    {
        if (is_string($stored)) {
            try {
                $stored = $stored === '' ? [] : json_decode($stored, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return [];
            }
        }

        if (!is_array($stored)) {
            return [];
        }

        $answers = [];

        foreach ($stored as $id => $value) {
            $answers[(string) $id] = $value;
        }

        return $answers;
    }
}
