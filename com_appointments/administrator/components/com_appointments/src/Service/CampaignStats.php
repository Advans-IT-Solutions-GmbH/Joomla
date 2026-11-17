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
 * the current state. No name, address, URL, click ID, IP address or device. An erasure moves the
 * booking into the counts of {@see self::TOTALS} (day, event type, campaign, referrer host, state,
 * number) and deletes its row, so nothing per booking remains of an erased booking and the
 * evaluation of a campaign stays possible after the bookings are anonymised or deleted. The export
 * adds both per day, event type and campaign.
 *
 * Written inside the booking's own transaction, under its host lock: the row and its state
 * always follow the booking in order. A failure is logged and does not fail the booking, except a
 * deadlock or lock wait timeout, which rolled back the whole transaction and is passed on, and a
 * failure to move an erased booking into the counts, which fails the erasure so it stays retryable. Bookings
 * without a record of their origin (made before 1.6.0 or with the option `attribution_capture`
 * off) get no row.
 *
 * @since  1.6.0
 */
final class CampaignStats
{
    public const TABLE = '#__appointments_campaign_stats';

    /** The counts of erased bookings, without anything per booking. */
    public const TOTALS = '#__appointments_campaign_totals';

    /** The key columns a row of {@see self::TABLE} and of {@see self::TOTALS} share. */
    public const KEY = ['day', 'eventtype_id', 'utm_source', 'utm_medium', 'utm_campaign', 'referrer_host'];

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
     * An erased booking (inside the erasure's transaction): its row becomes one more in the counts
     * of {@see self::TOTALS} with the state after the erasure and is deleted. Any failure is passed
     * on, so the erasure and this move commit together or the erasure stays to be done.
     */
    public static function erased(DatabaseInterface $db, int $bookingId, int $state): void
    {
        if ($bookingId < 1 || !\in_array($state, [0, 1, 2], true)) {
            return;
        }

        // A plain read first: a locking read of a row that does not exist would lock the gap.
        $row = $db->setQuery('SELECT ' . implode(', ', $db->quoteName([...self::KEY, 'eventtype_title'])) . ' FROM ' . $db->quoteName(self::TABLE)
            . ' WHERE ' . $db->quoteName('booking_id') . ' = ' . $bookingId)->loadAssoc();

        if (!$row) {
            return;
        }

        $values = array_map([$db, 'quote'], array_values($row));
        $db->setQuery('INSERT INTO ' . $db->quoteName(self::TOTALS) . ' (' . implode(', ', $db->quoteName([...self::KEY, 'eventtype_title'])) . ', '
            . implode(', ', $db->quoteName(['state', 'bookings'])) . ') VALUES (' . implode(', ', $values) . ', ' . $state . ', 1)'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('bookings') . ' = ' . $db->quoteName('bookings') . ' + 1')->execute();
        $db->setQuery('DELETE FROM ' . $db->quoteName(self::TABLE) . ' WHERE ' . $db->quoteName('booking_id') . ' = ' . $bookingId)->execute();
    }

    /** The new state of a booking (inside the transaction of the change); no row, nothing to do. */
    public static function moved(DatabaseInterface $db, int $bookingId, int $state): void
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
