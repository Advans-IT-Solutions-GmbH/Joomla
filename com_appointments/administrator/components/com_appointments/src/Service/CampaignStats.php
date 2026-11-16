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
 * The non-personal campaign aggregate: bookings counted per day of booking (UTC), event type,
 * utm_source, utm_medium, utm_campaign and referrer host ({@see Attribution::campaign()}), with
 * the number per current state. It holds no booking ID, no URL, no click ID, no address and
 * nothing of a device, and it is not changed by anonymisation or erasure: the evaluation of a
 * campaign stays possible after the bookings themselves are anonymised.
 *
 * Counted after the booking's own transaction, best effort: a failure is logged and never
 * fails a booking, a state change or an erasure. Bookings without a record of their origin
 * (made before 1.6.0 or with the option `attribution_capture` off) are not counted.
 *
 * @since  1.6.0
 */
final class CampaignStats
{
    public const TABLE = '#__appointments_campaign_stats';

    /** The counter column of each booking state. */
    private const STATES = [0 => 'pending', 1 => 'confirmed', 2 => 'cancelled'];

    /** Counts a booking that was just stored (opened row with `attribution`). */
    public static function booked(DatabaseInterface $db, array $booking): void
    {
        try {
            $key = self::key($booking);
            $state = self::STATES[(int) ($booking['state'] ?? -1)] ?? null;

            if ($key === null || $state === null) {
                return;
            }

            $columns = array_merge(array_keys($key), ['bookings', $state]);
            $values = array_merge(array_map([$db, 'quote'], array_values($key)), ['1', '1']);
            $db->setQuery('INSERT INTO ' . $db->quoteName(self::TABLE) . ' (' . implode(', ', $db->quoteName($columns)) . ') VALUES ('
                . implode(', ', $values) . ') ON DUPLICATE KEY UPDATE ' . $db->quoteName('bookings') . ' = ' . $db->quoteName('bookings') . ' + 1, '
                . $db->quoteName($state) . ' = ' . $db->quoteName($state) . ' + 1')->execute();
        } catch (\Throwable $e) {
            Log::add('Appointment campaign statistics: a booking could not be counted (' . $e::class . ').', Log::WARNING, 'com_appointments');
        }
    }

    /** Moves a counted booking (opened row before the change) from state $from to state $to. */
    public static function moved(DatabaseInterface $db, array $booking, int $from, int $to): void
    {
        try {
            $key = self::key($booking);
            $old = self::STATES[$from] ?? null;
            $new = self::STATES[$to] ?? null;

            if ($key === null || $old === null || $new === null || $old === $new) {
                return;
            }

            $where = [];

            foreach ($key as $column => $value) {
                $where[] = $db->quoteName($column) . ' = ' . $db->quote($value);
            }

            $db->setQuery('UPDATE ' . $db->quoteName(self::TABLE) . ' SET '
                . $db->quoteName($old) . ' = IF(' . $db->quoteName($old) . ' > 0, ' . $db->quoteName($old) . ' - 1, 0), '
                . $db->quoteName($new) . ' = ' . $db->quoteName($new) . ' + 1 WHERE ' . implode(' AND ', $where))->execute();
        } catch (\Throwable $e) {
            Log::add('Appointment campaign statistics: a state change could not be counted (' . $e::class . ').', Log::WARNING, 'com_appointments');
        }
    }

    /**
     * The aggregate key of a booking: day of creation, event type and campaign. Null for a
     * booking without a record of its origin (made before 1.6.0 or with the option off, already
     * erased), so only bookings counted at their creation are ever moved.
     *
     * @return  array{day: string, eventtype_id: int, utm_source: string, utm_medium: string, utm_campaign: string, referrer_host: string}|null
     */
    private static function key(array $booking): ?array
    {
        $created = \is_string($booking['created_utc'] ?? null) ? UtcTimestamp::parse($booking['created_utc']) : null;
        $event = (int) ($booking['eventtype_id'] ?? 0);
        $record = Attribution::decode($booking['attribution'] ?? null);

        if ($created === null || $event < 1 || $record === null) {
            return null;
        }

        return ['day' => $created->format('Y-m-d'), 'eventtype_id' => $event] + Attribution::campaign($record);
    }
}
