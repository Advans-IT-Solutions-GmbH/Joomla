<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class PrivacyAddress
{
    public static function normalise(string $value): string
    {
        if (preg_match('//u', $value) !== 1 || trim($value) === '') {
            throw new \InvalidArgumentException('The privacy request address is invalid.');
        }

        return mb_strtolower(trim($value), 'UTF-8');
    }

    public static function matches(string $stored, string $requested): bool
    {
        return self::normalise($stored) === self::normalise($requested);
    }

    public static function answers(?string $value): array
    {
        if ($value === null) {
            return [];
        }
        $answers = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($answers)) {
            throw new \UnexpectedValueException('The booking answers cannot be exported.');
        }

        return $answers;
    }

    public static function readable(mixed $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
