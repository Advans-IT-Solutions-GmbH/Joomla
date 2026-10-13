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

final class Anonymiser
{
    public const ERASED_FIELDS = [
        'invitee_name', 'invitee_email', 'invitee_phone', 'invitee_timezone',
        'invitee_language', 'guest_emails', 'custom_fields', 'cancel_reason',
        'conference_provider', 'conference_url', 'conference_room',
        'conference_state', 'conference_error',
    ];

    /**
     * Returns a row copy; persistence, room teardown, audit and the host-only
     * delivery belong to the caller's transaction. Already erased rows are unchanged.
     *
     * @param array<string, mixed> $booking A stored booking row.
     * @return array<string, mixed>
     */
    public function anonymise(array $booking, \DateTimeImmutable $now): array
    {
        if (!empty($booking['anonymised_utc'])) {
            return $booking;
        }

        $start = UtcTimestamp::parse((string) ($booking['start_utc'] ?? ''));
        $modified = UtcTimestamp::parse((string) ($booking['modified_utc'] ?? ''));

        if ($start === null || $modified === null || !in_array($booking['state'] ?? null, [0, 1, 2, '0', '1', '2'], true)) {
            throw new \InvalidArgumentException('Invalid booking to anonymise.');
        }

        $utcNow = $now->setTimezone(new \DateTimeZone('UTC'));

        if (UtcTimestamp::parse($utcNow->format('Y-m-d H:i:s')) === null) {
            throw new \InvalidArgumentException('Anonymisation clock exceeds the timestamp range.');
        }

        if ($start > $utcNow && (int) $booking['state'] !== 2) {
            $sequence = $booking['sequence'] ?? null;

            if (filter_var($sequence, FILTER_VALIDATE_INT) === false || (int) $sequence < 0 || (int) $sequence === PHP_INT_MAX) {
                throw new \InvalidArgumentException('Invalid booking sequence.');
            }

            $booking['state'] = 2;
            $booking['sequence'] = (int) $sequence + 1;
        }

        foreach (self::ERASED_FIELDS as $field) {
            $booking[$field] = null;
        }

        $booking['anonymised_utc'] = $utcNow->format('Y-m-d H:i:s');
        // A second-granularity row version must change even in the same second.
        $version = $modified->getTimestamp() >= $utcNow->getTimestamp()
            ? $modified->modify('+1 second')
            : $utcNow;

        if (UtcTimestamp::parse($version->format('Y-m-d H:i:s')) === null) {
            throw new \InvalidArgumentException('Booking row version exceeds the timestamp range.');
        }

        $booking['modified_utc'] = $version->format('Y-m-d H:i:s');

        return $booking;
    }

    /**
     * Strict allowlist, even if called with an original, not-yet-erased row.
     * Never pass the original booking to the deletion notification composer.
     */
    public function cancellationPayload(array $cancelledBooking): array
    {
        if (!in_array($cancelledBooking['state'] ?? null, [2, '2'], true)) {
            throw new \InvalidArgumentException('A host cancellation requires a cancelled booking.');
        }

        return array_intersect_key($cancelledBooking, array_flip([
            'id', 'uid', 'sequence', 'eventtype_id', 'host_user_id',
            'start_utc', 'end_utc', 'state', 'modified_utc',
        ]));
    }
}
