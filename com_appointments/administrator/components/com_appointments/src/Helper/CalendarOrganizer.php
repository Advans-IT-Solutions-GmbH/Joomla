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
 * The organiser of the host's own copy of an invitation.
 *
 * A mail client shows an invitation whose organiser is the reader's own address as «you are the
 * organiser» and offers no way to put it into the calendar. The host's copy therefore names
 * another address as organiser and the host as attendee: the option «Organiser of the host's
 * calendar entry» (`calendar_organizer_email`), else Joomla's «From email». An address that is the
 * host's own (compared case-insensitively) or no valid address does not count. Without one the
 * host's copy is published (`METHOD:PUBLISH`) instead of sent as an invitation, and the system
 * checks warn about it (SystemModel).
 */
final class CalendarOrganizer
{
    /**
     * @return  array{email: string, name: string}|null  null when no address distinct from the host exists.
     */
    public static function forHost(string $hostEmail, string $option, string $mailFrom, string $fromName): ?array
    {
        foreach ([$option, $mailFrom] as $candidate) {
            $email = trim($candidate);

            if (self::valid($email) && strcasecmp($email, trim($hostEmail)) !== 0) {
                return ['email' => $email, 'name' => $fromName];
            }
        }

        return null;
    }

    /**
     * How many of $hostEmails have no organiser distinct from their own address (the system check).
     *
     * @param   list<string>  $hostEmails
     */
    public static function hostsWithout(array $hostEmails, string $option, string $mailFrom): int
    {
        return \count(array_filter($hostEmails, static fn (string $email): bool => self::forHost($email, $option, $mailFrom, '') === null));
    }

    /** An address IcsWriter accepts as a calendar participant. */
    public static function valid(string $email): bool
    {
        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && preg_match('/[\x00-\x20\x7f";:,\\\\]/', $email) !== 1;
    }
}
