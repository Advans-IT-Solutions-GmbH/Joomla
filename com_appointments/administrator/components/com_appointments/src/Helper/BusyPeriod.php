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
 * Something that stands in the way of a new booking, as two windows.
 *
 * Both are needed, and that is the whole reason this class exists. A buffer is
 * a minimum gap around a meeting, not a second reservation, so two neighbouring
 * meetings with ten minutes of buffer each need ten minutes between them and
 * not twenty. Deciding that correctly takes both the meeting itself and the
 * period it occupies with its buffers:
 *
 * - A new meeting must not run into the **occupied** period of what is already
 *   there, which is what respects the other event type's buffer.
 * - The new meeting's **own occupied** period must not run into the other
 *   **meeting**, which is what respects this event type's buffer.
 *
 * Checking only the first would let an event type with a long buffer place that
 * buffer on top of somebody else's meeting. Checking the occupied periods
 * against each other would add the two buffers up and leave twice the gap an
 * operator asked for.
 *
 * For an absence, or for a period the schedule itself makes unavailable, the two
 * windows are the same: there is no availability at all, so neither a meeting
 * nor a buffer may lie inside it.
 *
 * @since  1.0.0
 */
final class BusyPeriod
{
    /**
     * @var  AvailabilityWindow
     */
    private $meeting;

    /**
     * @var  AvailabilityWindow
     */
    private $occupied;

    /**
     * @param   AvailabilityWindow  $meeting   The meeting itself, without buffers.
     * @param   AvailabilityWindow  $occupied  The meeting with its own buffers.
     *
     * @throws  \InvalidArgumentException  When the occupied period does not contain the meeting.
     */
    public function __construct(AvailabilityWindow $meeting, AvailabilityWindow $occupied)
    {
        if (!$occupied->containsWindow($meeting)) {
            throw new \InvalidArgumentException(
                'The occupied period of a booking has to contain its meeting.'
            );
        }

        $this->meeting  = $meeting;
        $this->occupied = $occupied;
    }

    /**
     * A booking, with the buffers of the event type **it** belongs to.
     *
     * The rules are those of the booking's own event type, never those of the
     * event type whose slots are being computed: a host can serve several event
     * types with different buffers.
     */
    public static function forBooking(AvailabilityWindow $meeting, SlotRules $rulesOfThatBooking): self
    {
        return new self($meeting, $rulesOfThatBooking->expandBooking($meeting));
    }

    /**
     * A period in which nothing may happen at all: an absence, or time the
     * schedule itself does not offer.
     */
    public static function reserved(AvailabilityWindow $period): self
    {
        return new self($period, $period);
    }

    public function getMeeting(): AvailabilityWindow
    {
        return $this->meeting;
    }

    public function getOccupied(): AvailabilityWindow
    {
        return $this->occupied;
    }

    /**
     * The occupied windows of a list of busy periods.
     *
     * @param   self[]  $periods
     *
     * @return  AvailabilityWindow[]
     */
    public static function occupiedWindows(array $periods): array
    {
        return array_map(
            static function (self $period): AvailabilityWindow {
                return $period->occupied;
            },
            array_values($periods)
        );
    }
}
