<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\DomainBinding;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the forced-domain binding rule: a report is stored under a
 * source's bound domain only for the exact domain or a subdomain of it, never
 * for a parent, a sibling or an unrelated domain.
 *
 * @since  1.1.0
 */
class DomainBindingTest extends TestCase
{
    public function testExactMatchIsAccepted(): void
    {
        $this->assertTrue(DomainBinding::accepts('example.com', 'example.com'));
    }

    public function testExactMatchIgnoresCaseAndTrailingDot(): void
    {
        $this->assertTrue(DomainBinding::accepts('Example.COM', 'example.com.'));
    }

    public function testChildOfTheBoundDomainIsAccepted(): void
    {
        $this->assertTrue(DomainBinding::accepts('example.com', 'mail.example.com'));
        $this->assertTrue(DomainBinding::accepts('example.com', 'a.b.example.com'));
    }

    public function testParentOfTheBoundDomainIsRejected(): void
    {
        // A source bound to a tenant subdomain must not take reports for the
        // parent domain, which belongs to a different (wider) tenant.
        $this->assertFalse(DomainBinding::accepts('tenant.example.com', 'example.com'));
    }

    public function testSiblingIsRejected(): void
    {
        $this->assertFalse(DomainBinding::accepts('tenant.example.com', 'other.example.com'));
    }

    public function testUnrelatedAndLookAlikeDomainsAreRejected(): void
    {
        $this->assertFalse(DomainBinding::accepts('example.com', 'example.org'));
        $this->assertFalse(DomainBinding::accepts('example.com', 'evil-example.com'));
        $this->assertFalse(DomainBinding::accepts('example.com', 'example.com.evil.net'));
    }

    public function testCompoundPublicSuffixBoundary(): void
    {
        // Under a compound public suffix the bound registrable domain keeps
        // its own subdomains but never accepts the suffix itself or another
        // registration under it.
        $this->assertTrue(DomainBinding::accepts('example.co.uk', 'example.co.uk'));
        $this->assertTrue(DomainBinding::accepts('example.co.uk', 'mail.example.co.uk'));
        $this->assertFalse(DomainBinding::accepts('example.co.uk', 'co.uk'));
        $this->assertFalse(DomainBinding::accepts('example.co.uk', 'other.co.uk'));
    }

    public function testSingleLabelOrEmptyNamesNeverMatch(): void
    {
        $this->assertFalse(DomainBinding::accepts('uk', 'example.uk'));
        $this->assertFalse(DomainBinding::accepts('', 'example.com'));
        $this->assertFalse(DomainBinding::accepts('example.com', ''));
    }
}
