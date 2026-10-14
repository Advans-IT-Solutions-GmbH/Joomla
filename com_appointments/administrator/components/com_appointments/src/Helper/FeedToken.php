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

final class FeedToken
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** The caller persists this replacement for just the selected feed. */
    public static function rotate(string $previous): string
    {
        do {
            $replacement = self::generate();
        } while (hash_equals($previous, $replacement));

        return $replacement;
    }

    public static function verify(string $stored, string $supplied): bool
    {
        // Even a missing/malformed stored token goes through the comparison.
        $valid = preg_match('/\A[a-f0-9]{64}\z/D', $stored) === 1;
        $matches = hash_equals($valid ? $stored : str_repeat('0', 64), $supplied);

        return $valid && $matches;
    }
}
