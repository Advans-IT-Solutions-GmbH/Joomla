<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * The public address every e-mail of the component builds its links from: the option
 * `public_site_url`, else Joomla's `live_site`. There is deliberately no fallback to the
 * address of the current request: it follows the request's Host header, which a visitor
 * chooses on a server that answers for any host name. Without a valid configured address
 * the component sends no e-mail and takes no booking (fail closed).
 */
final class PublicSiteUrl
{
    /**
     * The configured site root without a trailing slash, or null when neither value is a plain
     * HTTP(S) root: the first value that is not the empty string, untrimmed, with a lower-case
     * scheme and without user, password, query or fragment.
     */
    public static function resolve(string $publicSiteUrl, string $liveSite): ?string
    {
        $base = $publicSiteUrl !== '' ? $publicSiteUrl : $liveSite;
        $parts = parse_url($base);

        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false || !\is_array($parts)
            || !\in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        return rtrim($base, '/');
    }

    /** The configured site root of the running site, or null (see resolve()). */
    public static function current(): ?string
    {
        return self::resolve((string) ComponentHelper::getParams('com_appointments')->get('public_site_url', ''),
            (string) Factory::getApplication()->get('live_site', ''));
    }
}
