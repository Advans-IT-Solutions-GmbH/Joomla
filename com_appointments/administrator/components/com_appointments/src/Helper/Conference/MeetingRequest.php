<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

/**
 * Only event metadata crosses the provider boundary, never invitee data.
 * The constructor accepts the booking UID but retains only its derived seed.
 */
final class MeetingRequest
{
    public readonly string $roomSeed;
    public readonly \DateTimeImmutable $startUtc;
    public readonly \DateTimeImmutable $endUtc;
    public readonly string $title;
    public readonly int $duration;

    public function __construct(
        string $bookingUid,
        \DateTimeImmutable $startUtc,
        \DateTimeImmutable $endUtc,
        string $title,
        int $duration
    ) {
        if ($bookingUid === '' || $duration < 1
            || $endUtc->getTimestamp() - $startUtc->getTimestamp() !== $duration * 60
        ) {
            throw new \InvalidArgumentException('Invalid conference meeting interval or seed.');
        }

        $this->roomSeed = hash('sha256', 'com_appointments:conference-seed:' . $bookingUid);
        $this->startUtc = $startUtc->setTimezone(new \DateTimeZone('UTC'));
        $this->endUtc = $endUtc->setTimezone(new \DateTimeZone('UTC'));
        $this->title = $title;
        $this->duration = $duration;
    }
}
