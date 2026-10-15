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
 * Decides whether a report may be stored under the monitored domain a mailbox
 * source is bound to.
 *
 * A bound mailbox still delivers untrusted content, so the domain a report
 * claims (the DMARC policy domain or a TLS-RPT policy domain) must belong to
 * the bound tenant before the report is attributed to it. The correct boundary
 * would be the organizational domain, which needs the Public Suffix List. Joomla
 * does not ship a PSL library and bundling the list is out of proportion for
 * this check, so the rule is deliberately strict and directional:
 *
 * - The report domain equals the bound domain, or
 * - the report domain is a subdomain of the bound domain (a name inside the
 *   bound tenant's own DNS namespace, e.g. mail.example.com for example.com).
 *
 * A parent of the bound domain is never accepted (a source bound to
 * tenant.example.com does not take reports for example.com), and neither is a
 * sibling (other.example.co.uk for example.co.uk) or a look-alike
 * (evil-example.com for example.com). The bound domain must therefore be the
 * registrable domain the operator owns; binding a source to a public suffix
 * such as co.uk is a misconfiguration this check cannot detect without a PSL.
 *
 * Pure and static so the rule is unit-tested without a database.
 *
 * @since  1.1.0
 */
abstract class DomainBinding
{
    /**
     * Whether a report carrying $reportDomain may be stored under the monitored
     * domain $boundDomain. Both names are canonicalized first (case, trailing
     * root dot, IDN); an empty or invalid name never matches.
     */
    public static function accepts(string $boundDomain, string $reportDomain): bool
    {
        $bound  = DomainName::canonicalize($boundDomain);
        $report = DomainName::canonicalize($reportDomain);

        if ($bound === null || $report === null) {
            return false;
        }

        if ($bound === $report) {
            return true;
        }

        // Child of the bound domain only. The leading dot keeps a look-alike
        // such as evil-example.com from matching example.com; the direction of
        // the test keeps a parent of the bound domain from matching.
        return str_ends_with($report, '.' . $bound);
    }
}
