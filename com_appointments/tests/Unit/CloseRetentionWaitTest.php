<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Service/SchedulerService.php';

use Advans\Component\Appointments\Administrator\Service\SchedulerService;
use PHPUnit\Framework\TestCase;

/**
 * The booking retention waits for the CRM transfer, at most CLOSE_WAIT_DAYS longer, and
 * never for a booking without a delivery record.
 */
final class CloseRetentionWaitTest extends TestCase
{
    public function testTheRetentionWaitsForAPendingTransferAtMostThirtyDaysLonger(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 12:00:00', new \DateTimeZone('UTC'));
        $ended = static fn (int $days): \DateTimeImmutable => $now->modify('-' . $days . ' days');

        self::assertSame(30, SchedulerService::CLOSE_WAIT_DAYS);
        // Not delivered yet: waits after the 30 days retention, up to 30 days more.
        foreach (['pending', 'retry', 'blocked', 'uncertain'] as $state) {
            self::assertTrue(SchedulerService::waitsForClose($state, $ended(31), 30, $now), $state);
            self::assertTrue(SchedulerService::waitsForClose($state, $ended(60), 30, $now), $state);
            self::assertFalse(SchedulerService::waitsForClose($state, $ended(61), 30, $now), $state);
        }
        // Delivered, or no transfer for this booking: no wait.
        self::assertFalse(SchedulerService::waitsForClose('done', $ended(31), 30, $now));
        self::assertFalse(SchedulerService::waitsForClose(null, $ended(31), 30, $now));
    }
}
