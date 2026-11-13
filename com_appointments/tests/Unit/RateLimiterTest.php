<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/Team/LockoutServiceInterface.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/Team/LockoutService.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/RateLimiter.php';

/**
 * Whom the public rate limits count: IPv4 per address, unchanged, IPv6 per /64.
 */
final class RateLimiterTest extends TestCase
{
    public function testIpv4AddressesKeepTheirOwnBucket(): void
    {
        self::assertSame('192.0.2.7', RateLimiter::subject('192.0.2.7'));
        self::assertNotSame(RateLimiter::subject('192.0.2.7'), RateLimiter::subject('192.0.2.8'));
    }

    public function testIpv6AddressesOfOneSlash64ShareABucket(): void
    {
        self::assertSame('2001:db8:1:2::/64', RateLimiter::subject('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(RateLimiter::subject('2001:db8:1:2::1'), RateLimiter::subject('2001:db8:1:2:ffff::9'));
        self::assertSame(RateLimiter::subject('2001:db8:1:2::1'), RateLimiter::subject('2001:0DB8:0001:0002:0000:0000:0000:0001'));
        self::assertNotSame(RateLimiter::subject('2001:db8:1:2::1'), RateLimiter::subject('2001:db8:1:3::1'));
    }

    public function testIpv4MappedIpv6CountsAsItsIpv4Address(): void
    {
        self::assertSame('192.0.2.7', RateLimiter::subject('::ffff:192.0.2.7'));
    }
}
