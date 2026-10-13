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
 * Validates and canonicalizes a DNS domain name before it is stored or used as
 * a monitored domain. Both ingestion (the policy domain auto-detected from an
 * untrusted report) and manual domain entry funnel through here so the two
 * paths agree on exactly what a valid domain is and on the storage length
 * limit. An untrusted value such as a URL, an address with embedded whitespace
 * or control characters, or an over-length name is rejected rather than being
 * saved as a bogus "domain" that would pollute the tenant list and produce
 * meaningless DNS diagnostics.
 *
 * @since  1.0.0
 */
abstract class DomainName
{
    /**
     * Largest accepted domain, matching the `VARCHAR(190)` storage column. A
     * value longer than this would fail the insert, so it is rejected up front
     * instead of surfacing as a generic save or ingestion failure.
     */
    public const MAX_LENGTH = 190;

    /**
     * Canonicalize a candidate domain to its lowercase ASCII (punycode) form
     * and validate it as a DNS name. Returns the canonical domain, or null when
     * the input is not a usable domain.
     */
    public static function canonicalize(string $value): ?string
    {
        // Lowercase and strip the dots and whitespace that may legitimately
        // wrap a domain (a trailing root dot, stray spacing) before validation.
        $value = strtolower(trim($value, ". \t\r\n\0\x0B"));

        if ($value === '') {
            return null;
        }

        // Reject anything carrying a character that cannot appear in a bare
        // domain — a scheme or path separator, whitespace or a control byte —
        // so values such as "https://example.org" never reach the structural
        // check below. High bytes are left for the IDNA step that follows.
        if (preg_match('/[^a-z0-9.\-\x80-\xff]/', $value)) {
            return null;
        }

        // Convert an internationalized domain to its ASCII (punycode) form when
        // the intl extension is available; without it a non-ASCII name cannot
        // be canonicalized, so it is rejected rather than stored half-encoded.
        if (preg_match('/[\x80-\xff]/', $value)) {
            if (!\function_exists('idn_to_ascii')) {
                return null;
            }

            $ascii = idn_to_ascii($value);

            if (!\is_string($ascii) || $ascii === '') {
                return null;
            }

            $value = strtolower($ascii);
        }

        if (\strlen($value) > self::MAX_LENGTH) {
            return null;
        }

        if (!self::isValidAsciiDomain($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Validate an already-lowercased ASCII domain as a sequence of DNS labels
     * ending in a plausible top-level label.
     */
    private static function isValidAsciiDomain(string $value): bool
    {
        $labels = explode('.', $value);

        // A monitored domain is always registrable, so it carries at least two
        // labels; a bare single label (or an IP address) is not accepted.
        if (\count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        // The top-level label is alphabetic (e.g. "org") or an ACE/punycode
        // label (e.g. "xn--p1ai"), never all-numeric, so an IPv4 address does
        // not masquerade as a domain.
        $tld = $labels[\count($labels) - 1];

        return (bool) preg_match('/^(?:[a-z]{2,}|xn--[a-z0-9-]+)$/', $tld);
    }
}
