<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class ModifiedTime
{
    public static function next(string $previous, \DateTimeImmutable $now): string
    {
        $before = UtcTimestamp::parse($previous);

        if ($before === null) {
            throw new \UnexpectedValueException('Invalid modification timestamp.');
        }

        // DATETIME has second precision. Two changes in the same second must
        // still invalidate the links in the previous email.
        $next = $now->getTimestamp() > $before->getTimestamp() ? $now : $before->modify('+1 second');
        $value = $next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        if (UtcTimestamp::parse($value) === null) {
            throw new \OverflowException('Modification timestamp exceeds the supported range.');
        }

        return $value;
    }
}
