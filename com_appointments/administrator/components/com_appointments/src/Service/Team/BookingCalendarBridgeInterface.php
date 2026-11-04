<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\BusyPeriod;

defined('_JEXEC') or die;

/**
 * Booking page and team calendars (work package E).
 *
 * Busy times of the event type's target calendar (`#__appointments_eventtypes.calendar_id`)
 * and of its further busy calendars (`#__appointments_eventtype_calendars`)
 * block slots, like the host's own bookings and blocked periods. An object the
 * booking flow wrote for a booking of the same host is skipped, because that
 * booking already blocks through the bookings table; a booking of another host
 * in the same calendar blocks like any other object.
 * A booking is written into the target calendar, sealed to the calendar's
 * public key, and follows the booking through reschedule, cancellation and
 * anonymisation. Nothing here may fail a booking: a calendar error is logged
 * and the booking proceeds.
 *
 * @since  1.4.0
 */
interface BookingCalendarBridgeInterface
{
    /**
     * Busy periods of the calendars linked to an event type (meeting and
     * occupied window identical), from the encrypted busy cache, rebuilt when
     * its sync token is stale.
     *
     * Inside the locked booking transaction call it with `readOnly = true`: a
     * stale cache is then recomputed in memory only and never written, because a
     * cache write there can deadlock and roll back the host lock. BookingStore
     * warms the cache with a normal call before it opens the transaction.
     *
     * @param   object  $eventType  Row of `#__appointments_eventtypes` with at least id, host_user_id and calendar_id.
     *
     * @return  BusyPeriod[]
     */
    public function busyFor(object $eventType, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $scheduleZone, bool $readOnly = false): array;

    /**
     * A booking was created, approved or rescheduled: write or replace its object.
     *
     * @param   array<string, mixed>  $booking  Row of `#__appointments_bookings`.
     */
    public function onBookingSaved(array $booking): void;

    /**
     * @param   array<string, mixed>  $booking
     */
    public function onBookingCancelled(array $booking): void;

    /**
     * The booking was anonymised or deleted: remove its objects.
     */
    public function onBookingErased(int $bookingId): void;
}
