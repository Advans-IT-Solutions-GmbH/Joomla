<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class BookingGuard
{
    public function accepts(SlotEngine $engine, \DateTimeImmutable $start, \DateTimeImmutable $now, array $busy, array $capCounts): bool
    {
        if ($start <= $now) {
            return false;
        }

        $slots = $engine->findSlots($now, $start, $start->modify('+1 second'), $busy, $capCounts);

        return count($slots) === 1 && $slots[0]->getTimestamp() === $start->getTimestamp();
    }
}
