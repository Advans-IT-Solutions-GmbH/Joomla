<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\Attribution;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * The campaign statistics without personal data: one row per booking with the day of booking
 * (UTC), the event type and its title at that time, utm_source, utm_medium, utm_campaign and
 * referrer host ({@see Attribution::campaign()}, an e-mail address or telephone number never) and
 * the current state. No name, address, URL, click ID, IP address or device. An erasure keeps the
 * row (only the state follows a cancellation) and removes its link to the booking, so the
 * evaluation of a campaign stays possible after the bookings are anonymised or deleted. The export
 * counts them per day, event type and campaign.
 *
 * Written inside the booking's own transaction, under its host lock: the row and its state
 * always follow the booking in order. A failure is logged and does not fail the booking, except a
 * deadlock or lock wait timeout, which rolled back the whole transaction and is passed on. Bookings
 * without a record of their origin (made before 1.6.0 or with the option `attribution_capture`
 * off) get no row.
 *
 * @since  1.6.0
 */
final class CampaignStats
{
    public const TABLE = '#__appointments_campaign_stats';

    /** Adds the row of a booking that is being stored (clear row with `attribution`, inside its transaction). */
    public static function booked(DatabaseInterface $db, array $booking, string $eventTitle): void
    {
        try {
            $record = Attribution::decode($booking['attribution'] ?? null);
            $created = \is_string($booking['created_utc'] ?? null) ? UtcTimestamp::parse($booking['created_utc']) : null;
            $id = (int) ($booking['id'] ?? 0);
            $state = (int) ($booking['state'] ?? -1);

            if ($record === null || $created === null || $id < 1 || !\in_array($state, [0, 1, 2], true)) {
                return;
            }

            $row = (object) (['booking_id' => $id, 'day' => $created->format('Y-m-d'), 'eventtype_id' => (int) $booking['eventtype_id'],
                'eventtype_title' => Attribution::clean($eventTitle, 255)] + Attribution::campaign($record) + ['state' => $state]);
            $db->insertObject(self::TABLE, $row);
        } catch (\Throwable $e) {
            // A deadlock or lock wait rolled back the whole booking transaction: the caller must fail.
            if (Team\AuditService::isLockConflict($e)) {
                throw $e;
            }

            Log::add('Appointment campaign statistics: a booking could not be counted (' . $e::class . ').', Log::WARNING, 'com_appointments');
        }
    }

    /**
     * The new state of a booking (inside the transaction of the change); no row, nothing to do.
     * $unlink (an erasure) also removes the link to the booking: the row is then a count only.
     */
    public static function moved(DatabaseInterface $db, int $bookingId, int $state, bool $unlink = false): void
    {
        if ($bookingId < 1 || !\in_array($state, [0, 1, 2], true)) {
            return;
        }

        try {
            // A plain read first: an UPDATE of a row that does not exist would lock the gap and make a
            // concurrent booking of another host wait for this transaction.
            $counted = $db->setQuery('SELECT ' . $db->quoteName('booking_id') . ' FROM ' . $db->quoteName(self::TABLE)
                . ' WHERE ' . $db->quoteName('booking_id') . ' = ' . $bookingId)->loadResult();

            if ($counted === null) {
                return;
            }

            $db->setQuery('UPDATE ' . $db->quoteName(self::TABLE) . ' SET ' . $db->quoteName('state') . ' = ' . $state
                . ($unlink ? ', ' . $db->quoteName('booking_id') . ' = NULL' : '')
                . ' WHERE ' . $db->quoteName('booking_id') . ' = ' . $bookingId)->execute();
        } catch (\Throwable $e) {
            // A deadlock or lock wait rolled back the whole booking transaction: the caller must fail.
            if (Team\AuditService::isLockConflict($e)) {
                throw $e;
            }

            Log::add('Appointment campaign statistics: a state change could not be counted (' . $e::class . ').', Log::WARNING, 'com_appointments');
        }
    }
}
