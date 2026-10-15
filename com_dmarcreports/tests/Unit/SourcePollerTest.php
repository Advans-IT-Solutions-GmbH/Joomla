<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Service\SourcePoller;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the SourcePoller forcing decision that governs whether a
 * scheduled or on-demand poll attributes every report to the source's bound
 * domain or resolves (and auto-creates) each report's own policy domain.
 *
 * @since  1.1.0
 */
class SourcePollerTest extends TestCase
{
    public function testAutoCreateOffForcesTheBoundDomain(): void
    {
        // Default behaviour: a single-tenant mailbox forces its bound domain so
        // a report for an unrelated domain cannot be cross-attributed.
        $this->assertSame(7, SourcePoller::forcedDomainId(7, false));
    }

    public function testAutoCreateOnDoesNotForceTheBoundDomain(): void
    {
        // The privileged auto-create opt-in must let DomainRegistry resolve and
        // register each report's own domain, so the bound id is NOT forced.
        // Returning 0 selects the resolve-from-report path; forcing a positive
        // id here is exactly the bug that made task_auto_create a no-op.
        $this->assertSame(0, SourcePoller::forcedDomainId(7, true));
    }

    public function testAutoCreateOnNeverLeaksABoundId(): void
    {
        // Regardless of which domain the source is bound to, auto-create must
        // always yield the unforced sentinel so a new domain can be created.
        foreach ([1, 42, 9999] as $boundDomainId) {
            $this->assertSame(0, SourcePoller::forcedDomainId($boundDomainId, true));
        }
    }
}
