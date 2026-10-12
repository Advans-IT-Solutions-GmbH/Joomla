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
 * Abstraction over DNS TXT lookups so the diagnostics logic can be exercised
 * with deterministic fixtures in unit tests instead of hitting a live resolver.
 *
 * @since  1.0.0
 */
interface DnsResolverInterface
{
    /**
     * Return the TXT records of a host as a list of full strings.
     *
     * @param   string  $host  The fully qualified host name.
     *
     * @return  string[]
     */
    public function txt(string $host): array;
}
