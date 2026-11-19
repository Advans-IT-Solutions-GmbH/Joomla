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
 * Reverse (PTR) and forward (A/AAAA) lookups for the sending sources, behind an
 * interface so the forward-confirmation logic is unit tested with fixtures.
 *
 * @since  1.4.0
 */
interface ReverseDnsResolverInterface
{
    /**
     * The PTR names of an IP address.
     *
     * @return  string[]|null  The names, an empty array when none exist, or null when the lookup failed.
     */
    public function ptr(string $ip): ?array;

    /**
     * The IPv4 and IPv6 addresses a host name resolves to.
     *
     * @return  string[]|null  The addresses, an empty array when none exist, or null when the lookup failed.
     */
    public function addresses(string $host): ?array;
}
