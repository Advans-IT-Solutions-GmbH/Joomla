<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * The one place the team calendar services are wired together. Every caller
 * (controllers, plugins, the Scheduler task, tests) gets its services from
 * here, and every service gets the others from here, so no two work packages
 * edit the same file to connect their classes. The implementation classes are
 * fixed by name; each work package replaces the body of its own classes only.
 *
 * Instances live for one request. Nothing here is static state.
 *
 * @since  1.4.0
 */
final class TeamServices
{
    /**
     * @var  array<string, object>
     */
    private $instances = [];

    /**
     * @param   \Closure():\DateTimeImmutable  $clock  Returns "now" in UTC; injected so tests control time.
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly KeyProvider $keyProvider,
        private readonly Registry $params,
        private readonly \Closure $clock
    ) {
    }

    public static function fromApplication(): self
    {
        return new self(
            Factory::getContainer()->get(DatabaseInterface::class),
            new KeyProvider((string) Factory::getApplication()->get('secret', '')),
            ComponentHelper::getParams('com_appointments'),
            static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * Whether the team calendars are switched on and can work at all. Every
     * entry point (CalDAV, «My calendars», the backend views' actions, the
     * booking bridge) answers as if the feature did not exist when this is false.
     */
    public function enabled(): bool
    {
        return (int) $this->params->get('teamcal_enabled', 0) === 1 && CipherSuite::available();
    }

    public function db(): DatabaseInterface
    {
        return $this->db;
    }

    public function keyProvider(): KeyProvider
    {
        return $this->keyProvider;
    }

    public function params(): Registry
    {
        return $this->params;
    }

    /**
     * The CalDAV base address, absolute, always ending in "/": the system plugin's
     * parameter `dav_base_url` when it passes {@see self::acceptDavBaseUrl()},
     * otherwise the site root plus "dav/". The routing path, the well-known
     * redirect, the client instructions and the system check all derive from
     * this one value.
     */
    public function davBaseUrl(): string
    {
        $plugin = PluginHelper::getPlugin('system', 'appointmentsdav');
        $params = new Registry(\is_object($plugin) ? ($plugin->params ?? '') : '');
        $root = rtrim(Uri::root(), '/') . '/';

        return self::acceptDavBaseUrl(trim((string) $params->get('dav_base_url', '')), $root) ?? $root . 'dav/';
    }

    /**
     * The configured base address, normalised, or null when it is not usable:
     * https, a host, no user, password, query or fragment, at most 255
     * characters without blanks or control characters; the path lies below the
     * site root's path and adds 1 to 3 segments of [A-Za-z0-9_~-][A-Za-z0-9._~-]{0,63},
     * the first of which is not a Joomla folder or entry point. Pure function.
     */
    public static function acceptDavBaseUrl(string $url, string $siteRoot): ?string
    {
        if ($url === '' || \strlen($url) > 255 || preg_match('/[\s\x00-\x1f\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if (!\is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $rootPath = rtrim((string) (parse_url($siteRoot, PHP_URL_PATH) ?? ''), '/') . '/';
        $path = (string) ($parts['path'] ?? '/');

        if (!str_starts_with($path, $rootPath)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', substr($path, \strlen($rootPath))), 'strlen'));

        if ($segments === [] || \count($segments) > 3) {
            return null;
        }

        foreach ($segments as $segment) {
            if (!preg_match('/^[A-Za-z0-9_~-][A-Za-z0-9._~-]{0,63}$/', $segment)) {
                return null;
            }
        }

        $reserved = ['administrator', 'api', 'bin', 'cache', 'cli', 'components', 'files', 'images', 'includes', 'index.php',
            'language', 'layouts', 'libraries', 'media', 'modules', 'plugins', 'templates', 'tmp', '.well-known'];

        if (\in_array(strtolower($segments[0]), $reserved, true)) {
            return null;
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return 'https://' . strtolower($parts['host']) . $port . $rootPath . implode('/', $segments) . '/';
    }

    public function now(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    /** Work package B. */
    public function keys(): KeyServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new KeyService($this);
    }

    /** Work package B. */
    public function keySession(): KeySessionInterface
    {
        return $this->instances[__FUNCTION__] ??= new KeySession($this);
    }

    /** Work package C. */
    public function calendars(): CalendarServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new CalendarService($this);
    }

    /** Work package C. */
    public function grants(): GrantServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new GrantService($this);
    }

    /** Work package C. */
    public function invitations(): InvitationServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new InvitationService($this);
    }

    /** Work package D. */
    public function devices(): DeviceServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new DeviceService($this);
    }

    /** Work package D. */
    public function mailer(): TeamMailerInterface
    {
        return $this->instances[__FUNCTION__] ??= new TeamMailer($this);
    }

    /** Work package A. */
    public function objects(): CalendarObjectStoreInterface
    {
        return $this->instances[__FUNCTION__] ??= new CalendarObjectStore($this);
    }

    /** Work package E. */
    public function timing(): TimingIndexInterface
    {
        return $this->instances[__FUNCTION__] ??= new TimingIndex($this);
    }

    /** Work package E. */
    public function bookings(): BookingCalendarBridgeInterface
    {
        return $this->instances[__FUNCTION__] ??= new BookingCalendarBridge($this);
    }

    /** Work package F. */
    public function audit(): AuditServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new AuditService($this);
    }

    /** Work package F. */
    public function lockout(): LockoutServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new LockoutService($this);
    }

    /** Work package F. */
    public function cleanup(): CleanupServiceInterface
    {
        return $this->instances[__FUNCTION__] ??= new CleanupService($this);
    }
}
