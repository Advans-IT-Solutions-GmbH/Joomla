<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class ReminderOffsets
{
    public static function parse(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $offsets = [];
        foreach (explode(',', $value) as $part) {
            $part = trim($part);
            if (!ctype_digit($part) || (int) $part < 1 || (int) $part > 525600) {
                throw new \InvalidArgumentException('Reminder offsets must be minutes between 1 and 525600.');
            }
            $offsets[(int) $part] = (int) $part;
        }
        rsort($offsets, SORT_NUMERIC);

        return $offsets;
    }

    public static function markers(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $markers = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($markers)) {
            throw new \UnexpectedValueException('The reminder delivery record is invalid.');
        }
        foreach ($markers as $offset => $time) {
            if (!ctype_digit((string) $offset) || (int) $offset < 1 || (int) $offset > 525600
                || (string) (int) $offset !== (string) $offset
                || !is_string($time) || UtcTimestamp::parse($time) === null) {
                throw new \UnexpectedValueException('The reminder delivery record is invalid.');
            }
        }

        return $markers;
    }

    public static function due(string $start, \DateTimeImmutable $now, array $offsets, array $markers): array
    {
        $time = UtcTimestamp::parse($start);
        if ($time === null) {
            throw new \InvalidArgumentException('The reminder booking start is invalid.');
        }
        $remaining = $time->getTimestamp() - $now->getTimestamp();
        if ($remaining <= 0) {
            return [];
        }

        return array_values(array_filter($offsets, static fn (int $offset): bool =>
            $remaining <= $offset * 60 && !array_key_exists($offset, $markers)
        ));
    }
}
