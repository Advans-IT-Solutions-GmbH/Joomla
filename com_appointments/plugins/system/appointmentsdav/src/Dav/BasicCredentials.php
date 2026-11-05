<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

defined('_JEXEC') or die;

/**
 * HTTP Basic credentials of a CalDAV request.
 *
 * Pure class: no Joomla service, no database.
 *
 * @since  1.4.0
 */
final class BasicCredentials
{
    /** Longest Authorization header accepted; an app password needs far less. */
    private const MAX_HEADER = 1024;

    /** Joomla's user name column holds at most 150 characters. */
    private const MAX_USERNAME = 150;

    private function __construct(
        public readonly string $username,
        public readonly string $password
    ) {
    }

    /**
     * The Authorization header as PHP received it: `HTTP_AUTHORIZATION`, then
     * `REDIRECT_HTTP_AUTHORIZATION` (Apache after an internal rewrite), then
     * `PHP_AUTH_USER`/`PHP_AUTH_PW` (mod_php decoded it itself).
     *
     * @param   array<string, mixed>  $server  `$_SERVER`.
     */
    public static function headerFromServer(array $server): ?string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($server[$key]) && \is_string($server[$key]) && trim($server[$key]) !== '') {
                return trim($server[$key]);
            }
        }

        if (isset($server['PHP_AUTH_USER']) && \is_string($server['PHP_AUTH_USER'])) {
            $password = isset($server['PHP_AUTH_PW']) && \is_string($server['PHP_AUTH_PW']) ? $server['PHP_AUTH_PW'] : '';

            return 'Basic ' . base64_encode($server['PHP_AUTH_USER'] . ':' . $password);
        }

        return null;
    }

    /**
     * Credentials of a `Basic` header, null for anything else: no header, another
     * scheme, invalid Base64, no colon, an empty or overlong user name, or control
     * characters.
     */
    public static function parse(?string $header): ?self
    {
        if ($header === null || \strlen($header) > self::MAX_HEADER) {
            return null;
        }

        if (preg_match('/^Basic[ \t]+([A-Za-z0-9+\/]+={0,2})[ \t]*$/i', $header, $match) !== 1) {
            return null;
        }

        $decoded = base64_decode($match[1], true);

        if ($decoded === false || !str_contains($decoded, ':') || preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1) {
            return null;
        }

        [$username, $password] = explode(':', $decoded, 2);

        if ($username === '' || mb_strlen($username, 'UTF-8') > self::MAX_USERNAME || !mb_check_encoding($username, 'UTF-8')) {
            return null;
        }

        return new self($username, $password);
    }
}
