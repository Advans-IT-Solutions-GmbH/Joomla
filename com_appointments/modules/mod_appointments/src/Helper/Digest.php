<?php
/**
 * @package     Appointments Dashboard Module
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * The decisions of the dashboard module that need no Joomla, so they are unit tested on their own:
 * how many appointments it lists, the moment «new since your last visit» counts from, and the name
 * it shows for a booking.
 */
final class Digest
{
    /** Appointments listed without a valid setting. */
    public const DEFAULT_COUNT = 5;

    /** Most appointments the module lists. */
    public const MAX_COUNT = 20;

    /** The number of appointments to list: the setting within 1 to MAX_COUNT, DEFAULT_COUNT otherwise. */
    public static function limit(mixed $value): int
    {
        $count = filter_var($value, FILTER_VALIDATE_INT);

        return $count === false || $count < 1 ? self::DEFAULT_COUNT : min($count, self::MAX_COUNT);
    }

    /**
     * The moment «new since your last visit» counts from, and what this browser session keeps.
     *
     * The time of the user's last visit is stored for the account and renewed on every view of the
     * dashboard. The first view of a session takes the stored time as its reference and keeps it in
     * the session, so every later view of the same session counts from the same moment: the count
     * shows what arrived since the previous visit, not since the last reload. Without a stored time
     * (the first visit ever) there is no reference and nothing is counted.
     *
     * @param   string|null  $session  What the session kept ('' = no reference), null on its first view.
     * @param   string|null  $stored   The stored time of the last visit (UTC, Y-m-d H:i:s), null for none.
     *
     * @return  array{since: ?string, keep: string}  The reference (null for none) and the session value.
     */
    public static function baseline(?string $session, ?string $stored): array
    {
        $value = $session ?? (self::isTime($stored) ? (string) $stored : '');

        return ['since' => $value === '' ? null : $value, 'keep' => $value];
    }

    /**
     * The name of the booking person: the full name, else first and last name; '' for none.
     *
     * @param   array<string, mixed>  $row  An opened booking row.
     */
    public static function name(array $row): string
    {
        $name = trim((string) ($row['invitee_name'] ?? ''));

        if ($name === '') {
            $name = trim(trim((string) ($row['invitee_first_name'] ?? '')) . ' ' . trim((string) ($row['invitee_last_name'] ?? '')));
        }

        return (string) preg_replace('/\s+/u', ' ', $name);
    }

    /** Whether $value is a UTC time as the database stores it (Y-m-d H:i:s). */
    public static function isTime(?string $value): bool
    {
        if ($value === null || preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', $value) !== 1) {
            return false;
        }

        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $time !== false && $time->format('Y-m-d H:i:s') === $value;
    }
}
