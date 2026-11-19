<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

/**
 * Keeps looked-up sender host names in Joomla's cache (its own group, always
 * enabled for this group regardless of the site's page cache setting). A cache
 * that cannot be read or written only means the name is looked up again later:
 * every failure is swallowed, so the cache can never break a page.
 *
 * @since  1.4.0
 */
final class JoomlaHostNameStore implements HostNameStoreInterface
{
    private const GROUP = 'com_dmarcreports_hostnames';

    /** @var object|null|false  The output cache controller; false once it failed to start. */
    private $controller = null;

    public function get(string $ip): ?array
    {
        $controller = $this->controller();

        if ($controller === null) {
            return null;
        }

        try {
            $data = $controller->get(self::id($ip), self::GROUP);
        } catch (\Throwable $e) {
            return null;
        }

        $entry = \is_string($data) ? json_decode($data, true) : null;

        if (!\is_array($entry) || !isset($entry['host'], $entry['expires'])) {
            return null;
        }

        return ['host' => (string) $entry['host'], 'expires' => (int) $entry['expires']];
    }

    public function set(string $ip, string $host, int $expires): void
    {
        $controller = $this->controller();

        if ($controller === null) {
            return;
        }

        try {
            $controller->store(json_encode(['host' => $host, 'expires' => $expires]), self::id($ip), self::GROUP);
        } catch (\Throwable $e) {
            // A cache that cannot be written only costs a repeated lookup.
        }
    }

    private static function id(string $ip): string
    {
        return 'ptr-' . strtolower($ip);
    }

    private function controller(): ?object
    {
        if ($this->controller === false) {
            return null;
        }

        if ($this->controller === null) {
            try {
                $this->controller = Factory::getContainer()
                    ->get(CacheControllerFactoryInterface::class)
                    ->createCacheController(
                        'output',
                        [
                            'defaultgroup' => self::GROUP,
                            'caching'      => true,
                            // Minutes; each entry also carries its own, shorter expiry.
                            'lifetime'     => (int) (SourceHostNames::TTL_CONFIRMED / 60),
                        ]
                    );
            } catch (\Throwable $e) {
                $this->controller = false;

                return null;
            }
        }

        return $this->controller;
    }
}
