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
    ];
}
