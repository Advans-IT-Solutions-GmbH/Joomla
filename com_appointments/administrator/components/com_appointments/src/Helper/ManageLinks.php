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
 * The signed links a mail to the booking person offers, by the state of the booking.
 *
 * A cancelled appointment can no longer be rescheduled or cancelled: its mails offer no link (their
 * reschedule and cancel links would open the notice that the appointment is cancelled). Every other
 * state offers both. The links that are left out are also empty in the template placeholders
 * ({reschedule_url}, {cancel_url}) of the mail.
 */
final class ManageLinks
{
    /** The footer of the mail in this order: link field => label key. */
    public const FOOTER = [
        'reschedule_url' => 'COM_APPOINTMENTS_MAIL_RESCHEDULE',
        'cancel_url' => 'COM_APPOINTMENTS_MAIL_CANCEL',
    ];

    /** State of a cancelled booking. */
    private const CANCELLED = 2;

    /**
     * The links of $links (NotificationService::links()) a mail about a booking in $state offers.
     *
     * @param   array<string, string>  $links
     *
     * @return  array<string, string>
     */
    public static function forState(array $links, int $state): array
    {
        if ($state === self::CANCELLED) {
            unset($links['reschedule_url'], $links['cancel_url']);
        }

        return $links;
    }

    /**
     * «Label:» and the address on a line of its own for every link of $links, in the order of
     * FOOTER, blank lines between none. '' when there is no link.
     *
     * @param   array<string, string>     $links
     * @param   callable(string): string  $translate    Translates the label keys.
     * @param   string                    $labelFormat  «%s:» (or «%s :» in French).
     */
    public static function footer(array $links, callable $translate, string $labelFormat = '%s:'): string
    {
        $lines = [];

        foreach (self::FOOTER as $field => $key) {
            if (!empty($links[$field])) {
                $lines[] = sprintf($labelFormat, $translate($key)) . "\n" . $links[$field];
            }
        }

        return implode("\n", $lines);
    }
}
