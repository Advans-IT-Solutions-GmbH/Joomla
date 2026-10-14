<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class CsvCell
{
    public static function safe(?string $value): string
    {
        $value ??= '';
        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]|^[\t\r\n]/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}
