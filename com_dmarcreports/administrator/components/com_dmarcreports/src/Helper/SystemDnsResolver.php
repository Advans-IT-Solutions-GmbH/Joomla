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
 * The default resolver, backed by PHP's dns_get_record(). It performs only
 * read-only TXT lookups and never transmits any site data.
 *
 * @since  1.0.0
 */
final class SystemDnsResolver implements DnsResolverInterface
{
    public function txt(string $host): array
    {
        if (!\function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($host, DNS_TXT);

        if (!\is_array($records)) {
            return [];
        }

        $out = [];

        foreach ($records as $record) {
            if (isset($record['entries']) && \is_array($record['entries'])) {
                $out[] = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }
}
