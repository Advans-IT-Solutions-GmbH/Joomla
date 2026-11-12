<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;

defined('_JEXEC') or die;

/**
 * Who of the users of a target calendar receives which booking mail. Pure, for the tests.
 *
 * A booking lands as a copy in the target calendar of its event type, and every user with an
 * effective access synchronises that calendar. Each of them therefore gets a mail on a new
 * booking, an approval, a reschedule and a cancellation, but never a calendar invitation: the
 * entry already arrives through the synchronisation, and an accepted invitation would show it a
 * second time. The booking person and the additional guests keep their invitations.
 *
 * - A role that reads contents (read, write, manage) gets the details the calendar copy shows.
 * - Free/busy gets the time only, and only when the busy picture changes: no approval mail, no
 *   name, address, title or link. The template text cannot add any either (NotificationService).
 * - The host of the event type is one of these users (the copy is written only with the host's
 *   write access) and is the only one who gets the reminder.
 * - A user who switched the mails of this calendar off in «My calendars» gets none.
 * - Each account and each address once; an address that already gets the booking person's or a
 *   guest's mail gets no second one.
 */
final class CalendarNotice
{
    /** The template of a user whose role reads contents, per notification purpose. */
    public const CONTENT = [
        'confirmation' => 'calendar_booked',
        'pending' => 'calendar_booked',
        'approved' => 'calendar_approved',
        'reschedule' => 'calendar_rescheduled',
        'cancellation' => 'calendar_cancelled',
        'erasure_notice' => 'calendar_cancelled',
        'reminder' => 'calendar_reminder',
    ];

    /** The template of a free/busy user: only when a time becomes busy, moves or becomes free. */
    public const FREEBUSY = [
        'confirmation' => 'calendar_busy',
        'pending' => 'calendar_busy',
        'reschedule' => 'calendar_busy_moved',
        'cancellation' => 'calendar_free',
        'erasure_notice' => 'calendar_free',
    ];

    /** Purposes that go to the host alone, never to the other users of the calendar. */
    public const HOST_ONLY = ['reminder'];

    /**
     * The booking fields a calendar user other than the host receives: what the calendar copy
     * shows (BookingCalendarBridge::objectIcs()) and what that copy is built from. Every other
     * field (telephone, answers, cancellation reason, consent, languages, conference details) stays
     * empty, also in an edited template.
     */
    public const CONTENT_FIELDS = [
        'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'state',
        'invitee_name', 'invitee_first_name', 'invitee_last_name', 'invitee_email', 'guest_emails',
        'conference_url', 'created_utc', 'modified_utc',
    ];

    /** The placeholders a free/busy mail may contain; any other one is left empty. */
    public const FREEBUSY_PLACEHOLDERS = ['{calendar_title}', '{start}', '{end}', '{timezone}'];

    /**
     * @param   string        $purpose     The notification purpose of NotificationService::send().
     * @param   int           $hostUserId  The host of the booking.
     * @param   list<array{user_id: int, email: string, role: int, muted?: bool, backend?: bool}>  $members
     *                                     Every user with an effective access to the target calendar.
     * @param   list<string>  $taken       Addresses that already receive the booking person's or a guest's mail.
     *
     * @return  list<array{user_id: int, email: string, purpose: string, content: bool, host: bool, backend: bool}>
     */
    public static function plan(string $purpose, int $hostUserId, array $members, array $taken = []): array
    {
        $seenUsers = [];
        $seenAddresses = [];

        foreach ($taken as $address) {
            $seenAddresses[self::normalise((string) $address)] = true;
        }

        // The host first, so that the host's own mail wins when an address is shared.
        usort($members, static fn (array $a, array $b): int => ((int) $b['user_id'] === $hostUserId) <=> ((int) $a['user_id'] === $hostUserId));
        $plan = [];

        foreach ($members as $member) {
            $userId = (int) ($member['user_id'] ?? 0);
            $email = trim((string) ($member['email'] ?? ''));
            $role = CalendarRole::tryFrom((int) ($member['role'] ?? 0));
            $host = $userId > 0 && $userId === $hostUserId;

            if ($userId <= 0 || $role === null || !empty($member['muted']) || isset($seenUsers[$userId])
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false || isset($seenAddresses[self::normalise($email)])
                || (\in_array($purpose, self::HOST_ONLY, true) && !$host)
            ) {
                continue;
            }

            $content = $role->holdsKey();
            $template = ($content ? self::CONTENT : self::FREEBUSY)[$purpose] ?? null;

            if ($template === null) {
                continue;
            }

            $seenUsers[$userId] = true;
            $seenAddresses[self::normalise($email)] = true;
            $plan[] = [
                'user_id' => $userId,
                'email' => $email,
                'purpose' => $template,
                'content' => $content,
                'host' => $host,
                // A link to an erased booking would lead nowhere.
                'backend' => $content && !empty($member['backend']) && $purpose !== 'erasure_notice',
            ];
        }

        return $plan;
    }

    /**
     * The text of a free/busy mail: only the placeholders of FREEBUSY_PLACEHOLDERS are filled,
     * every other placeholder of a (possibly edited) template is left empty, so no template can
     * reveal a name, an address or a title to a role that may see busy times only.
     *
     * @param   array{subject: string, body: string}  $template
     * @param   array<string, string>                 $values
     *
     * @return  array{subject: string, body: string}
     */
    public static function freeBusyText(array $template, array $values): array
    {
        $values = array_intersect_key($values, array_flip(self::FREEBUSY_PLACEHOLDERS));
        $fill = static fn (string $text): string => (string) preg_replace('/\{[a-z0-9_.]+\}/i', '', strtr($text, $values));

        return [
            'subject' => trim((string) preg_replace('/[\r\n\t]+/', ' ', $fill($template['subject']))),
            'body' => $fill($template['body']),
        ];
    }

    /**
     * The booking as a calendar user other than the host receives it: the fields of
     * CONTENT_FIELDS, nothing else. Pure.
     *
     * @param   array<string, mixed>  $booking
     *
     * @return  array<string, mixed>
     */
    public static function contentBooking(array $booking): array
    {
        return array_intersect_key($booking, array_flip(self::CONTENT_FIELDS));
    }
    private static function normalise(string $address): string
    {
        return mb_strtolower(trim($address), 'UTF-8');
    }
}
