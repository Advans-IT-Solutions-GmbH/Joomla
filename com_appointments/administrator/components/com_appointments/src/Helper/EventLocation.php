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
 * The location text of an appointment in the invitation (`LOCATION`) and in the {location}
 * placeholder of a mail.
 *
 * The operator's own text of the event type always wins. A telephone appointment usually has
 * none, and an empty `LOCATION` leaves a calendar entry without any hint how the appointment takes
 * place, so it then gets the translated label of its location type: worded for the invitee in the
 * invitee's mail and on the confirmation page, neutral for the host and the additional guests.
 * Every other type without text stays empty; a video conference gets its join address from the
 * calendar writer (IcsWriter), not from here.
 */
final class EventLocation
{
    /** Labels of the administrator language file for the invitee's mail. */
    public const INVITEE_MAIL = [
        'phone_host_calls' => 'COM_APPOINTMENTS_MAIL_LOCATION_PHONE_HOST',
        'phone_invitee_calls' => 'COM_APPOINTMENTS_MAIL_LOCATION_PHONE_INVITEE',
    ];

    /** Labels of the administrator language file for the host and the additional guests. */
    public const NEUTRAL = [
        'phone_host_calls' => 'COM_APPOINTMENTS_LOCATION_PHONE_HOST',
        'phone_invitee_calls' => 'COM_APPOINTMENTS_LOCATION_PHONE_INVITEE',
    ];

    /** Labels of the site language file, the ones the booking page shows the invitee. */
    public const INVITEE_SITE = [
        'phone_host_calls' => 'COM_APPOINTMENTS_LOCATION_TYPE_PHONE_HOST',
        'phone_invitee_calls' => 'COM_APPOINTMENTS_LOCATION_TYPE_PHONE_INVITEE',
    ];

    /**
     * @param   array<string, string>       $labels     One of the label maps above.
     * @param   callable(string): string    $translate  Translates a language key.
     */
    public static function text(string $type, ?string $stored, array $labels, callable $translate): string
    {
        $stored = trim((string) $stored);

        if ($stored !== '' || !isset($labels[$type])) {
            return $stored;
        }

        return $translate($labels[$type]);
    }
}
