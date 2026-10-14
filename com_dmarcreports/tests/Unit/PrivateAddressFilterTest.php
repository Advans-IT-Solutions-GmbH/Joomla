<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\PrivateAddressFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SSRF address classifier that guards the MTA-STS policy
 * fetcher. The fetcher resolves attacker-influenced host names, so every
 * non-public destination must be rejected before any connection is made.
 *
 * @since  1.1.0
 */
class PrivateAddressFilterTest extends TestCase
{
    /**
     * @dataProvider publicAddresses
     */
    public function testPublicAddressesAreAllowed(string $ip): void
    {
        $this->assertTrue(PrivateAddressFilter::isPublicAddress($ip), $ip . ' should be public');
    }

    /**
     * @dataProvider blockedAddresses
     */
    public function testNonPublicAddressesAreRejected(string $ip): void
    {
        $this->assertFalse(PrivateAddressFilter::isPublicAddress($ip), $ip . ' should be blocked');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function publicAddresses(): array
    {
        return [
            'ipv4 public'            => ['93.184.216.34'],
            'ipv4 public 2'         => ['8.8.8.8'],
            'ipv4 just above cgnat' => ['100.128.0.1'],
            'ipv6 public'           => ['2606:2800:220:1:248:1893:25c8:1946'],
            'ipv6 public google'    => ['2001:4860:4860::8888'],
        ];
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function blockedAddresses(): array
    {
        return [
            'ipv4 unspecified'   => ['0.0.0.0'],
            'ipv4 this-net'      => ['0.1.2.3'],
            'ipv4 loopback'      => ['127.0.0.1'],
            'ipv4 private 10'    => ['10.1.2.3'],
            'ipv4 private 172'   => ['172.16.5.4'],
            'ipv4 private 172hi' => ['172.31.255.255'],
            'ipv4 private 192'   => ['192.168.1.1'],
            'ipv4 link-local'    => ['169.254.1.1'],
            'ipv4 cgnat'         => ['100.64.0.1'],
            'ipv4 cgnat hi'      => ['100.127.255.255'],
            'ipv4 testnet1'      => ['192.0.2.5'],
            'ipv4 benchmark'     => ['198.18.0.1'],
            'ipv4 testnet2'      => ['198.51.100.9'],
            'ipv4 testnet3'      => ['203.0.113.9'],
            'ipv4 multicast'     => ['224.0.0.1'],
            'ipv4 reserved'      => ['240.0.0.1'],
            'ipv4 broadcast'     => ['255.255.255.255'],
            'ipv6 unspecified'   => ['::'],
            'ipv6 loopback'      => ['::1'],
            'ipv6 ula fc'        => ['fc00::1'],
            'ipv6 ula fd'        => ['fd12:3456:789a::1'],
            'ipv6 link-local'    => ['fe80::1'],
            'ipv6 multicast'     => ['ff02::1'],
            'ipv6 documentation' => ['2001:db8::1'],
            'ipv4-mapped loop'   => ['::ffff:127.0.0.1'],
            'ipv4-mapped priv'   => ['::ffff:10.0.0.1'],
            'nat64 private'      => ['64:ff9b::10.0.0.1'],
            'not an ip'          => ['example.com'],
            'empty'              => [''],
        ];
    }
}
