<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class DefaultTemplates
{
    public const KEYS = [
        'confirmation' => ['COM_APPOINTMENTS_MAIL_CONFIRMATION_SUBJECT', 'COM_APPOINTMENTS_MAIL_CONFIRMATION_BODY'],
        'pending' => ['COM_APPOINTMENTS_MAIL_PENDING_SUBJECT', 'COM_APPOINTMENTS_MAIL_PENDING_BODY'],
        'approved' => ['COM_APPOINTMENTS_MAIL_APPROVED_SUBJECT', 'COM_APPOINTMENTS_MAIL_APPROVED_BODY'],
        'reschedule' => ['COM_APPOINTMENTS_MAIL_RESCHEDULE_SUBJECT', 'COM_APPOINTMENTS_MAIL_RESCHEDULE_BODY'],
        'cancellation' => ['COM_APPOINTMENTS_MAIL_CANCELLATION_SUBJECT', 'COM_APPOINTMENTS_MAIL_CANCELLATION_BODY'],
        'reminder' => ['COM_APPOINTMENTS_MAIL_REMINDER_SUBJECT', 'COM_APPOINTMENTS_MAIL_REMINDER_BODY'],
        'host_notice' => ['COM_APPOINTMENTS_MAIL_HOST_NOTICE_SUBJECT', 'COM_APPOINTMENTS_MAIL_HOST_NOTICE_BODY'],
        'erasure_notice' => ['COM_APPOINTMENTS_MAIL_ERASURE_NOTICE_SUBJECT', 'COM_APPOINTMENTS_MAIL_ERASURE_NOTICE_BODY'],
        'guest_confirmation' => ['COM_APPOINTMENTS_MAIL_GUEST_CONFIRMATION_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_CONFIRMATION_BODY'],
        'guest_pending' => ['COM_APPOINTMENTS_MAIL_GUEST_PENDING_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_PENDING_BODY'],
        'guest_approved' => ['COM_APPOINTMENTS_MAIL_GUEST_APPROVED_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_APPROVED_BODY'],
        'guest_reschedule' => ['COM_APPOINTMENTS_MAIL_GUEST_RESCHEDULE_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_RESCHEDULE_BODY'],
        'guest_cancellation' => ['COM_APPOINTMENTS_MAIL_GUEST_CANCELLATION_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_CANCELLATION_BODY'],
        'guest_reminder' => ['COM_APPOINTMENTS_MAIL_GUEST_REMINDER_SUBJECT', 'COM_APPOINTMENTS_MAIL_GUEST_REMINDER_BODY'],
        'host_approved' => ['COM_APPOINTMENTS_MAIL_HOST_APPROVED_SUBJECT', 'COM_APPOINTMENTS_MAIL_HOST_APPROVED_BODY'],
        'host_reschedule' => ['COM_APPOINTMENTS_MAIL_HOST_RESCHEDULE_SUBJECT', 'COM_APPOINTMENTS_MAIL_HOST_RESCHEDULE_BODY'],
        'host_cancellation' => ['COM_APPOINTMENTS_MAIL_HOST_CANCELLATION_SUBJECT', 'COM_APPOINTMENTS_MAIL_HOST_CANCELLATION_BODY'],
        'host_reminder' => ['COM_APPOINTMENTS_MAIL_HOST_REMINDER_SUBJECT', 'COM_APPOINTMENTS_MAIL_HOST_REMINDER_BODY'],
    ];

    /**
     * Notifications send these purposes; the guest_* purposes above are what an additional
     * guest receives instead, so a guest is never greeted with the booking person's name.
     */
    public const SENT = ['confirmation', 'pending', 'approved', 'reschedule', 'cancellation', 'reminder', 'host_notice', 'erasure_notice'];

    /**
     * The template the host receives for each notification purpose: host wording with the
     * invitee's details, never a mail addressed to the invitee. erasure_notice is host-only.
     */
    public const HOST = [
        'confirmation' => 'host_notice',
        'pending' => 'host_notice',
        'approved' => 'host_approved',
        'reschedule' => 'host_reschedule',
        'cancellation' => 'host_cancellation',
        'reminder' => 'host_reminder',
        'erasure_notice' => 'erasure_notice',
    ];

    /** The template an additional guest receives for each notification purpose. */
    public const GUEST = [
        'confirmation' => 'guest_confirmation',
        'pending' => 'guest_pending',
        'approved' => 'guest_approved',
        'reschedule' => 'guest_reschedule',
        'cancellation' => 'guest_cancellation',
        'reminder' => 'guest_reminder',
    ];

    /**
     * Mails of the internet calendars (work package D). Site-wide only (event type 0), never
     * with a calendar entry attached; NotificationService sends only the purposes of SENT.
     */
    public const TEAM_KEYS = [
        'team_device_added' => ['COM_APPOINTMENTS_TEAM_D_MAIL_DEVICE_ADDED_SUBJECT', 'COM_APPOINTMENTS_TEAM_D_MAIL_DEVICE_ADDED_BODY'],
        'team_invitation' => ['COM_APPOINTMENTS_TEAM_D_MAIL_INVITATION_SUBJECT', 'COM_APPOINTMENTS_TEAM_D_MAIL_INVITATION_BODY'],
        'team_invitation_accepted' => ['COM_APPOINTMENTS_TEAM_D_MAIL_INVITATION_ACCEPTED_SUBJECT', 'COM_APPOINTMENTS_TEAM_D_MAIL_INVITATION_ACCEPTED_BODY'],
        'team_grant_changed' => ['COM_APPOINTMENTS_TEAM_D_MAIL_GRANT_CHANGED_SUBJECT', 'COM_APPOINTMENTS_TEAM_D_MAIL_GRANT_CHANGED_BODY'],
        'team_access_suspended' => ['COM_APPOINTMENTS_TEAM_D_MAIL_ACCESS_SUSPENDED_SUBJECT', 'COM_APPOINTMENTS_TEAM_D_MAIL_ACCESS_SUSPENDED_BODY'],
    ];

    /**
     * Every seeded template purpose: the booking mails and the internet calendar mails.
     *
     * @return  array<string, array{0: string, 1: string}>
     */
    public static function all(): array
    {
        return self::KEYS + self::TEAM_KEYS;
    }
}
