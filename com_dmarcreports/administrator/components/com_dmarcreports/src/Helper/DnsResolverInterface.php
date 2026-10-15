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
 * Abstraction over DNS TXT/MX lookups so the diagnostics logic can be exercised
 * with deterministic fixtures in unit tests instead of hitting a live resolver.
 *
 * @since  1.0.0
 */
interface DnsResolverInterface
{
    /**
     * Return the TXT records of a host as a list of full strings.
     *
     * The return type separates three outcomes that must not be conflated: a
     * successful lookup with records (a non-empty list), a successful lookup
     * that found none (an empty list), and a lookup that could not be performed
     * or failed (null). Callers rely on null to avoid reporting a transient
     * resolver error as a genuinely missing record.
     *
     * @param   string  $host  The fully qualified host name.
     *
     * @return  string[]|null  The records, an empty array when none exist, or
     *                         null when the lookup itself failed.
     */
    public function txt(string $host): ?array;

    /**
     * Return MX target hostnames, [] when no usable MX exists (including null
     * MX), or null when the lookup failed. No implicit address-record fallback
     * is used: an empty answer cannot confirm MTA-STS MX coverage.
     *
     * @return string[]|null
     */
    public function mx(string $host): ?array;
}
