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
 * Classifies an IP literal as a globally routable (public) address or not. The
 * MTA-STS policy fetcher resolves attacker-influenced host names
 * (mta-sts.<domain>, where <domain> can be auto-created from an ingested
 * report) and must refuse to connect to anything that is not a public unicast
 * address, otherwise the request is a server-side request forgery (SSRF) vector
 * reaching loopback, link-local or internal RFC 1918 services.
 *
 * The check is deliberately a pure function over an already-resolved IP string
 * so it can be unit tested exhaustively without DNS. IPv4-mapped IPv6 addresses
 * (::ffff:a.b.c.d), IPv4-compatible addresses (::a.b.c.d) and NAT64
 * (64:ff9b::a.b.c.d) are unwrapped and judged by their embedded IPv4 value. For
 * native IPv6 the classifier fails closed to the global-unicast block 2000::/3
 * and additionally rejects the IANA special-purpose prefixes carved out of it
 * (2001::/23, 2001:db8::/32, 2002::/16 6to4, 2620:4f:8000::/48, 3fff::/20), so a
 * private or internally routed destination cannot be smuggled through a v6 form.
 *
 * @since  1.1.0
 */
final class PrivateAddressFilter
{
    /**
     * Return true only for a globally routable unicast IPv4 or IPv6 address.
     * Anything that is not a valid IP literal, or that falls in a loopback,
     * private, link-local, CGNAT, documentation, reserved, multicast or
     * unspecified range, returns false (fail closed).
     */
    public static function isPublicAddress(string $ip): bool
    {
        $packed = @inet_pton(trim($ip));

        if ($packed === false) {
            return false;
        }

        $length = \strlen($packed);

        if ($length === 4) {
            return self::isPublicIpv4($packed);
        }

        if ($length === 16) {
            // ::ffff:a.b.c.d — judge by the embedded IPv4 address so a private
            // target cannot be reached through the mapped form.
            if (self::isIpv4Mapped($packed)) {
                return self::isPublicIpv4(substr($packed, 12, 4));
            }

            // 64:ff9b::/96 NAT64 embeds an IPv4 address in its last 32 bits;
            // apply the same IPv4 rules so a private destination behind NAT64 is
            // rejected too.
            if (self::isNat64($packed)) {
                return self::isPublicIpv4(substr($packed, 12, 4));
            }

            // ::a.b.c.d IPv4-compatible IPv6 (deprecated): first 96 bits zero,
            // not the ::ffff: mapped form handled above. Judge by the embedded
            // IPv4 so ::127.0.0.1 or ::10.0.0.1 cannot smuggle a private
            // destination. This also covers :: and ::1 (0.0.0.0 / 0.0.0.1 are
            // rejected by the IPv4 rules).
            if (self::isIpv4Compatible($packed)) {
                return self::isPublicIpv4(substr($packed, 12, 4));
            }

            return self::isPublicIpv6($packed);
        }

        return false;
    }

    /**
     * @param  string  $packed  Four packed bytes of an IPv4 address.
     */
    private static function isPublicIpv4(string $packed): bool
    {
        $bytes = array_values(unpack('C4', $packed));
        [$a, $b, $c] = [$bytes[0], $bytes[1], $bytes[2]];

        // 0.0.0.0/8 "this network" / unspecified.
        if ($a === 0) {
            return false;
        }

        // 10.0.0.0/8 private.
        if ($a === 10) {
            return false;
        }

        // 127.0.0.0/8 loopback.
        if ($a === 127) {
            return false;
        }

        // 100.64.0.0/10 CGNAT.
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return false;
        }

        // 169.254.0.0/16 link-local.
        if ($a === 169 && $b === 254) {
            return false;
        }

        // 172.16.0.0/12 private.
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return false;
        }

        // 192.168.0.0/16 private.
        if ($a === 192 && $b === 168) {
            return false;
        }

        // 192.0.0.0/24 (IETF protocol assignments) and 192.0.2.0/24 (TEST-NET-1).
        if ($a === 192 && $b === 0 && ($c === 0 || $c === 2)) {
            return false;
        }

        // 198.18.0.0/15 benchmarking.
        if ($a === 198 && ($b === 18 || $b === 19)) {
            return false;
        }

        // 198.51.100.0/24 TEST-NET-2.
        if ($a === 198 && $b === 51 && $c === 100) {
            return false;
        }

        // 203.0.113.0/24 TEST-NET-3.
        if ($a === 203 && $b === 0 && $c === 113) {
            return false;
        }

        // 224.0.0.0/4 multicast and 240.0.0.0/4 reserved (incl. 255.255.255.255
        // broadcast).
        if ($a >= 224) {
            return false;
        }

        return true;
    }

    /**
     * @param  string  $packed  Sixteen packed bytes of an IPv6 address.
     */
    private static function isPublicIpv6(string $packed): bool
    {
        $bytes = array_values(unpack('C16', $packed));

        // ::/128 unspecified.
        if ($packed === str_repeat("\0", 16)) {
            return false;
        }

        // ::1/128 loopback.
        if ($packed === str_repeat("\0", 15) . "\x01") {
            return false;
        }

        $first  = $bytes[0];
        $second = $bytes[1];

        // Fail closed: only the global-unicast block 2000::/3 may be public, so
        // every other prefix is rejected by this single gate instead of being
        // allowed through by default. This covers loopback, ULA (fc00::/7),
        // link-local (fe80::/10), deprecated site-local (fec0::/10), multicast
        // (ff00::/8), the discard-only prefix (100::/64), SRv6 (5f00::/16) and
        // the whole 0000::/8 and other IETF-reserved space in one check.
        if (($first & 0xE0) !== 0x20) {
            return false;
        }

        // Within 2000::/3, carve out the IANA special-purpose blocks that are
        // not global unicast and may route internally, so a crafted target in
        // one of them cannot bypass the SSRF guard.

        // 2001::/23 IETF Protocol Assignments: Teredo (2001::/32), benchmarking
        // (2001:2::/48), AMT, AS112-v6, ORCHIDv1/v2 (2001:10::/28, 2001:20::/28)
        // and the PCP/DRIP anycasts all live in this block.
        if ($first === 0x20 && $second === 0x01 && ($bytes[2] & 0xFE) === 0x00) {
            return false;
        }

        // 2001:db8::/32 documentation.
        if ($first === 0x20 && $second === 0x01 && $bytes[2] === 0x0D && $bytes[3] === 0xB8) {
            return false;
        }

        // 2002::/16 6to4 embeds an arbitrary (possibly internal) IPv4 address in
        // bytes 2-5; the gateway is attacker-chosen, so reject the whole range
        // rather than try to vet the embedded address.
        if ($first === 0x20 && $second === 0x02) {
            return false;
        }

        // 2620:4f:8000::/48 Direct Delegation AS112 service.
        if ($first === 0x26 && $second === 0x20 && $bytes[2] === 0x00 && $bytes[3] === 0x4F
            && $bytes[4] === 0x80 && $bytes[5] === 0x00) {
            return false;
        }

        // 3fff::/20 documentation (RFC 9637).
        if ($first === 0x3F && ($second & 0xF0) === 0xF0) {
            return false;
        }

        return true;
    }

    /**
     * ::ffff:0:0/96 IPv4-mapped IPv6: first 80 bits zero, next 16 bits 0xffff.
     *
     * @param  string  $packed  Sixteen packed bytes.
     */
    private static function isIpv4Mapped(string $packed): bool
    {
        return substr($packed, 0, 10) === str_repeat("\0", 10)
            && substr($packed, 10, 2) === "\xFF\xFF";
    }

    /**
     * ::a.b.c.d IPv4-compatible IPv6 (deprecated): the first 96 bits are zero.
     * The ::ffff: mapped form is excluded by isIpv4Mapped() running first.
     *
     * @param  string  $packed  Sixteen packed bytes.
     */
    private static function isIpv4Compatible(string $packed): bool
    {
        return substr($packed, 0, 12) === str_repeat("\0", 12);
    }

    /**
     * 64:ff9b::/96 well-known NAT64 prefix.
     *
     * @param  string  $packed  Sixteen packed bytes.
     */
    private static function isNat64(string $packed): bool
    {
        return substr($packed, 0, 12) === "\x00\x64\xFF\x9B" . str_repeat("\0", 8);
    }
}
