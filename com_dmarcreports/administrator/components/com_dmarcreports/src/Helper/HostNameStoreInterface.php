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
 * Cache of looked-up sender host names, so a source is resolved at most once
 * per cache lifetime.
 *
 * @since  1.4.0
 */
interface HostNameStoreInterface
{
    /**
     * @return  array{host: string, expires: int}|null  The cached entry, or null when there is none.
     */
    public function get(string $ip): ?array;

    public function set(string $ip, string $host, int $expires): void;
}
