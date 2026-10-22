<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Exact sum of non-negative whole numbers as a decimal string.
 *
 * One report's message total is bounded by PHP_INT_MAX (the parser refuses a
 * larger one), but the totals of several reports in one import can exceed it.
 * Integer addition would then turn into a float and print a wrong, even
 * negative, number. The sum is therefore kept as a decimal string.
 *
 * @since  1.1.0
 */
final class DecimalTotal
{
    /**
     * Add two non-negative whole numbers given as decimal strings or integers.
     *
     * @param   string|int  $a  First addend
     * @param   string|int  $b  Second addend
     *
     * @return  string  The exact sum without leading zeros ("0" for zero)
     */
    public static function add($a, $b): string
    {
        $a = self::normalise((string) $a);
        $b = self::normalise((string) $b);

        $result = '';
        $carry  = 0;

        for ($i = \strlen($a) - 1, $j = \strlen($b) - 1; $i >= 0 || $j >= 0 || $carry > 0; $i--, $j--) {
            $digit  = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $carry  = intdiv($digit, 10);
            $result = (string) ($digit % 10) . $result;
        }

        return self::normalise($result);
    }

    /**
     * A non-negative whole number in canonical form. Anything else counts as zero.
     */
    private static function normalise(string $value): string
    {
        if (!preg_match('/^\d+$/', $value)) {
            return '0';
        }

        $value = ltrim($value, '0');

        return $value === '' ? '0' : $value;
    }
}