<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class PrivacyAddress
{
    public static function normalise(string $value): string
    {
        if (preg_match('//u', $value) !== 1 || trim($value) === '') {
            throw new \InvalidArgumentException('The privacy request address is invalid.');
        }

        return mb_strtolower(trim($value), 'UTF-8');
    }

    public static function matches(string $stored, string $requested): bool
    {
        return self::normalise($stored) === self::normalise($requested);
    }

    /**
     * The additional guests of a booking as stored in `guest_emails` (one address per line).
     *
     * @return list<string>
     */
    public static function guestList(?string $stored): array
    {
        $guests = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $stored) as $line) {
            if (trim($line) !== '') {
                $guests[] = trim($line);
            }
        }

        return $guests;
    }

    /**
     * Whether $address is one of the guests: exact per line after trimmed Unicode lowercasing, like
     * the invitee address, never a substring of another address.
     */
    public static function isGuest(?string $stored, string $address): bool
    {
        foreach (self::guestList($stored) as $guest) {
            if (self::sameAddress($guest, $address)) {
                return true;
            }
        }

        return false;
    }

    /** The guest list without $address, in the stored format (one address per line). */
    public static function withoutGuest(?string $stored, string $address): string
    {
        return implode("\n", array_values(array_filter(self::guestList($stored),
            static fn (string $guest): bool => !self::sameAddress($guest, $address))));
    }

    private static function sameAddress(string $stored, string $requested): bool
    {
        try {
            return self::matches($stored, $requested);
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    public static function answers(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $answers = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($answers)) {
            throw new \UnexpectedValueException('The booking answers cannot be exported.');
        }

        return $answers;
    }

    public static function readable(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
