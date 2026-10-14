<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

defined('_JEXEC') or die;

final class ConferenceUrl
{
    /** HTTPS only, with no credentials, controls, ambiguous escapes or HTML. */
    public static function validate(string $url): string
    {
        if (strlen($url) > 8192) {
            throw new ConferenceException('configuration');
        }

        $decoded = $url;

        // Also reject multiply encoded controls instead of relying on a browser's decoding order.
        for ($i = 0; $i < 8; $i++) {
            if (preg_match('/[\x00-\x20\x7f<>"\\\\]/', $decoded)) {
                throw new ConferenceException('configuration');
            }

            $next = rawurldecode($decoded);

            if ($next === $decoded) {
                break;
            }

            if ($i === 7) {
                throw new ConferenceException('configuration');
            }
            $decoded = $next;
        }

        $parts = parse_url($url);

        if (filter_var($url, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/%(?![0-9a-f]{2})/i', $url)
        ) {
            throw new ConferenceException('configuration');
        }

        return $url;
    }
}
