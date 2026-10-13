<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Helper
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Abstraction over the one HTTPS GET that MTA-STS monitoring needs: fetching the
 * policy document published at https://mta-sts.<domain>/.well-known/mta-sts.txt.
 * Keeping it behind an interface lets the diagnostics logic be exercised with
 * deterministic fixtures in unit tests instead of reaching a live web server.
 *
 * @since  1.1.0
 */
interface PolicyFetcherInterface
{
    /**
     * Fetch the body served at an HTTPS URL.
     *
     * The return type separates two outcomes that must not be conflated: a
     * successful fetch (the body as a string, possibly empty) and a fetch that
     * could not be performed, timed out, used an untrusted certificate or did
     * not return HTTP 200 (null). Callers rely on null to avoid reporting a
     * transient outage as a genuinely missing policy.
     *
     * @param   string  $url  The absolute https URL to fetch.
     *
     * @return  string|null  The response body, or null when the fetch failed.
     */
    public function fetch(string $url): ?string;
}
