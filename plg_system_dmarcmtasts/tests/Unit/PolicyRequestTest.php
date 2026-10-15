<?php
/**
 * @package     DMARC MTA-STS Policy System Plugin Tests
 * @subpackage  Unit
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\DmarcMtaSts\Tests\Unit;

use Advans\Plugin\System\DmarcMtaSts\Support\PolicyRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Advans\Plugin\System\DmarcMtaSts\Support\PolicyRequest
 */
final class PolicyRequestTest extends TestCase
{
    public function testResolvesBaseDomainForWellKnownRequest(): void
    {
        $this->assertSame(
            'example.com',
            PolicyRequest::domainFor('mta-sts.example.com', '/.well-known/mta-sts.txt')
        );
    }

    public function testIsCaseInsensitiveOnHost(): void
    {
        $this->assertSame(
            'example.com',
            PolicyRequest::domainFor('MTA-STS.Example.COM', '/.well-known/mta-sts.txt')
        );
    }

    public function testMatchesWhenJoomlaRunsInSubdirectory(): void
    {
        $this->assertSame(
            'example.com',
            PolicyRequest::domainFor('mta-sts.example.com', '/joomla/.well-known/mta-sts.txt')
        );
    }

    public function testRejectsNonMtaStsHost(): void
    {
        $this->assertNull(
            PolicyRequest::domainFor('www.example.com', '/.well-known/mta-sts.txt')
        );
    }

    public function testRejectsOtherPaths(): void
    {
        $this->assertNull(
            PolicyRequest::domainFor('mta-sts.example.com', '/.well-known/security.txt')
        );
        $this->assertNull(
            PolicyRequest::domainFor('mta-sts.example.com', '/index.php')
        );
    }

    public function testRejectsBareMtaStsHostWithoutDomain(): void
    {
        $this->assertNull(
            PolicyRequest::domainFor('mta-sts.', '/.well-known/mta-sts.txt')
        );
    }

    public function testPathMatchesHelper(): void
    {
        $this->assertTrue(PolicyRequest::pathMatches('/.well-known/mta-sts.txt'));
        $this->assertFalse(PolicyRequest::pathMatches('/.well-known/mta-sts.txt/extra'));
    }
}
