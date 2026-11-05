<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;

defined('_JEXEC') or die;

/**
 * The CalDAV base address of the site: the plugin setting «CalDAV address»
 * (absolute, HTTPS) when TeamServices::acceptDavBaseUrl() accepts it, the one
 * place of these rules, or the site URL plus `dav/`. The well-known redirect,
 * the client instructions and the mails use {@see $url}; requests are matched
 * by {@see $path} only, so the server answers on every host name that reaches
 * this Joomla.
 *
 * Pure class: no Joomla service, no database.
 *
 * @since  1.4.0
 */
final class DavAddress
{
    public const KIND_WELL_KNOWN = 'well-known';
    public const KIND_DAV = 'dav';

    /**
     * @param   string  $url       Absolute address, ends with a slash.
     * @param   string  $path      Its path, ends with a slash.
     * @param   string  $sitePath  Path of the site root without trailing slash (empty at the domain root).
     * @param   string  $host      Host of the address, with the port when it has one.
     */
    private function __construct(
        public readonly string $url,
        public readonly string $path,
        public readonly string $sitePath,
        public readonly string $host
    ) {
    }

    /**
     * @param   string  $baseUrl   The DAV base address, normally TeamServices::davBaseUrl(); the
     *                             rules of TeamServices::acceptDavBaseUrl() apply again, so an
     *                             unusable value can never shadow another part of the site.
     * @param   string  $siteRoot  Absolute URL of the site root (Joomla's Uri::root()).
     */
    public static function fromSetting(string $baseUrl, string $siteRoot): self
    {
        $siteRoot = rtrim($siteRoot, '/') . '/';
        $sitePath = rtrim((string) (parse_url($siteRoot, PHP_URL_PATH) ?: '/'), '/');
        $url = TeamServices::acceptDavBaseUrl(trim($baseUrl), $siteRoot)
            ?? (string) preg_replace('#^http://#i', 'https://', $siteRoot) . 'dav/';

        $port = parse_url($url, PHP_URL_PORT);

        return new self($url, (string) parse_url($url, PHP_URL_PATH), $sitePath, strtolower((string) parse_url($url, PHP_URL_HOST)) . ($port ? ':' . $port : ''));
    }

    /**
     * What a request path is for this server: the well-known redirect, the DAV
     * tree, or nothing (null), compared without the query string.
     */
    public function classify(string $requestPath): ?string
    {
        $path = (string) (parse_url('http://h' . (str_starts_with($requestPath, '/') ? '' : '/') . $requestPath, PHP_URL_PATH) ?? '');

        if ($path === $this->sitePath . '/.well-known/caldav' || $path === $this->sitePath . '/.well-known/caldav/') {
            return self::KIND_WELL_KNOWN;
        }

        if ($path . '/' === $this->path || str_starts_with($path, $this->path)) {
            return self::KIND_DAV;
        }

        return null;
    }
}
