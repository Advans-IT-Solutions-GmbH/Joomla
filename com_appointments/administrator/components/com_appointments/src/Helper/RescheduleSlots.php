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

final class RescheduleSlots
{
    /**
     * Group slots by their displayed date, omitting only the exact current appointment instant.
     *
     * @param list<\DateTimeImmutable> $slots
     * @return array<string, list<\DateTimeImmutable>>
     */
    public static function byLocalDay(array $slots, \DateTimeImmutable $current, \DateTimeZone $zone): array
    {
        $grouped = [];
        $currentTimestamp = $current->getTimestamp();

        foreach ($slots as $slot) {
            if ($slot->getTimestamp() === $currentTimestamp) {
                continue;
            }

            $grouped[$slot->setTimezone($zone)->format('Y-m-d')][] = $slot;
        }

        return $grouped;
    }
}
