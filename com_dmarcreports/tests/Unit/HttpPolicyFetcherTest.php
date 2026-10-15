<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\HttpPolicyFetcher;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SSRF-hardened MTA-STS policy fetcher.
 *
 * @since  1.1.0
 */
class HttpPolicyFetcherTest extends TestCase
{
    /**
     * @param   string[]  $addresses
     *
     * @return  string[]
     */
    private function bracket(array $addresses): array
    {
        $method = new \ReflectionMethod(HttpPolicyFetcher::class, 'bracketResolveAddresses');
        $method->setAccessible(true);

        return $method->invoke(null, $addresses);
    }

    public function testIpv4AddressesStayUnbracketed(): void
    {
        $this->assertSame(['203.0.113.1'], $this->bracket(['203.0.113.1']));
    }

    public function testIpv6AddressIsBracketed(): void
    {
        // libcurl's CURLOPT_RESOLVE entry is comma-separated, so an IPv6 literal
        // must be bracketed or its own colons break the host:port:addr parse.
        $this->assertSame(['[2001:db8::1]'], $this->bracket(['2001:db8::1']));
    }

    public function testMixedDualStackListBracketsOnlyIpv6(): void
    {
        $this->assertSame(
            ['203.0.113.1', '[2001:db8::1]'],
            $this->bracket(['203.0.113.1', '2001:db8::1'])
        );
    }

    public function testJoinedEntryIsParseableForDualStackHost(): void
    {
        // The joined CURLOPT_RESOLVE value must keep each address delimited by a
        // single comma with every IPv6 literal bracketed.
        $joined = 'mta-sts.example:443:' . implode(',', $this->bracket(['203.0.113.1', '2001:db8::1']));

        $this->assertSame('mta-sts.example:443:203.0.113.1,[2001:db8::1]', $joined);
    }
}
