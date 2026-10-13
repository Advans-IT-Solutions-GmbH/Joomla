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
 * The bookable rules of one event type, validated once.
 *
 * This is a value object, not a model: it carries the numbers the slot engine
 * needs and nothing else, so the engine stays a pure function of its inputs and
 * an impossible configuration is rejected where it is built rather than
 * producing strange slots much later.
 *
 * @since  1.0.0
 */
final class SlotRules
{
    /** @var int */
    private $durationMinutes;

    /** @var int */
    private $slotStepMinutes;

    /** @var int */
    private $bufferBeforeMinutes;

    /** @var int */
    private $bufferAfterMinutes;

    /** @var int */
    private $minimumNoticeMinutes;

    /** @var int */
    private $horizonDays;

    /** @var \DateTimeImmutable|null */
    private $horizonStart;

    /** @var \DateTimeImmutable|null */
    private $horizonEnd;

    /** @var int */
    private $maxPerDay;

    /** @var int */
    private $maxPerWeek;

    /**
     * @param   int                      $durationMinutes       Length of the meeting, above zero.
     * @param   int                      $slotStepMinutes       Grid the start times sit on, above zero.
     * @param   int                      $bufferBeforeMinutes   Kept free before a meeting.
     * @param   int                      $bufferAfterMinutes    Kept free after a meeting.
     * @param   int                      $minimumNoticeMinutes  How far ahead the earliest slot is.
     * @param   int                      $horizonDays           Rolling horizon in calendar days; 0 means the fixed range is used.
     * @param   \DateTimeImmutable|null  $horizonStart          Fixed range start, UTC, only with `$horizonDays === 0`.
     * @param   \DateTimeImmutable|null  $horizonEnd            Fixed range end, UTC, only with `$horizonDays === 0`.
     * @param   int                      $maxPerDay             0 means unlimited.
     * @param   int                      $maxPerWeek            0 means unlimited.
     *
     * @throws  \InvalidArgumentException  On a configuration that cannot produce slots.
     */
    public function __construct(
        int $durationMinutes,
        int $slotStepMinutes,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0,
        int $minimumNoticeMinutes = 0,
        int $horizonDays = 30,
        ?\DateTimeImmutable $horizonStart = null,
        ?\DateTimeImmutable $horizonEnd = null,
        int $maxPerDay = 0,
        int $maxPerWeek = 0
    ) {
        if ($durationMinutes <= 0) {
            throw new \InvalidArgumentException('The duration must be at least one minute.');
        }

        if ($slotStepMinutes <= 0) {
            throw new \InvalidArgumentException('The slot step must be at least one minute.');
        }

        foreach ([
            'buffer before'   => $bufferBeforeMinutes,
            'buffer after'    => $bufferAfterMinutes,
            'minimum notice'  => $minimumNoticeMinutes,
            'horizon in days' => $horizonDays,
            'maximum per day' => $maxPerDay,
            'maximum per week' => $maxPerWeek,
        ] as $label => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException(sprintf('The %s cannot be negative.', $label));
            }
        }

        if ($horizonDays === 0) {
            // Without a rolling horizon there has to be a fixed range, otherwise
            // the event type is bookable neither for a day nor for ever and the
            // engine would silently return nothing.
            if ($horizonStart === null || $horizonEnd === null) {
                throw new \InvalidArgumentException(
                    'With a horizon of 0 days a fixed horizon start and end are required.'
                );
            }

            if ($horizonStart >= $horizonEnd) {
                throw new \InvalidArgumentException('The fixed horizon must end after it starts.');
            }
        }

        // The two caps are independent on purpose. A weekly cap below the daily
        // one is a meaningful configuration: five a day and three a week means
        // at most three bookings in that week, whichever days they fall on.

        $this->durationMinutes      = $durationMinutes;
        $this->slotStepMinutes      = $slotStepMinutes;
        $this->bufferBeforeMinutes  = $bufferBeforeMinutes;
        $this->bufferAfterMinutes   = $bufferAfterMinutes;
        $this->minimumNoticeMinutes = $minimumNoticeMinutes;
        $this->horizonDays          = $horizonDays;
        $this->horizonStart         = $horizonStart;
        $this->horizonEnd           = $horizonEnd;
        $this->maxPerDay            = $maxPerDay;
        $this->maxPerWeek           = $maxPerWeek;
    }

    /**
     * Build the rules from an event type row.
     *
     * @param   array  $row  Columns of `#__appointments_eventtypes`.
     */
    public static function fromEventType(array $row): self
    {
        $utc     = new \DateTimeZone('UTC');
        $toStart = static function ($value) use ($utc): ?\DateTimeImmutable {
            $value = (string) ($value ?? '');

            return $value === '' || str_starts_with($value, '0000-00-00')
                ? null
                : new \DateTimeImmutable($value, $utc);
        };

        return new self(
            (int) ($row['duration_minutes'] ?? 0),
            (int) ($row['slot_step_minutes'] ?? 0),
            (int) ($row['buffer_before_minutes'] ?? 0),
            (int) ($row['buffer_after_minutes'] ?? 0),
            (int) ($row['min_notice_minutes'] ?? 0),
            (int) ($row['horizon_days'] ?? 0),
            $toStart($row['horizon_start_utc'] ?? null),
            $toStart($row['horizon_end_utc'] ?? null),
            (int) ($row['max_per_day'] ?? 0),
            (int) ($row['max_per_week'] ?? 0)
        );
    }

    public function getDurationSeconds(): int
    {
        return $this->durationMinutes * 60;
    }

    public function getSlotStepSeconds(): int
    {
        return $this->slotStepMinutes * 60;
    }

    public function getBufferBeforeSeconds(): int
    {
        return $this->bufferBeforeMinutes * 60;
    }

    public function getBufferAfterSeconds(): int
    {
        return $this->bufferAfterMinutes * 60;
    }

    public function getMinimumNoticeSeconds(): int
    {
        return $this->minimumNoticeMinutes * 60;
    }

    public function getHorizonDays(): int
    {
        return $this->horizonDays;
    }

    public function getHorizonStart(): ?\DateTimeImmutable
    {
        return $this->horizonStart;
    }

    public function getHorizonEnd(): ?\DateTimeImmutable
    {
        return $this->horizonEnd;
    }

    public function getMaxPerDay(): int
    {
        return $this->maxPerDay;
    }

    public function getMaxPerWeek(): int
    {
        return $this->maxPerWeek;
    }

    /**
     * One booking of **this** event type as the period it really occupies.
     *
     * The slot engine does no widening of its own, because a host can serve
     * several event types with different buffers and applying one type's
     * buffers to another type's meeting would offer a slot that runs into its
     * buffer. Every caller therefore widens each booking with the rules of the
     * event type that booking belongs to, through this method.
     */
    public function expandBooking(AvailabilityWindow $booking): AvailabilityWindow
    {
        return $booking->expand($this->getBufferBeforeSeconds(), $this->getBufferAfterSeconds());
    }
}
