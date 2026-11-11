<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\IngestLimits;
use PHPUnit\Framework\TestCase;

/**
 * Decisions of the per-message and per-run ingestion limits: under and over
 * each limit, the progress guarantee for the first message of a run, the
 * fallback for invalid options and the agreement with config.xml.
 *
 * @since  1.2.0
 */
class IngestLimitsTest extends TestCase
{
    private const MIB = 1048576;

    public function testDefaultsMatchTheShippedOptions(): void
    {
        $limits = IngestLimits::defaults();

        $this->assertSame(10, $limits->maxAttachments());
        $this->assertSame(20, $limits->maxMessageReports());
        $this->assertSame(32 * self::MIB, $limits->maxMessageBytes());
        $this->assertSame(1000, $limits->maxRunReports());
        $this->assertSame(256 * self::MIB, $limits->maxRunBytes());
    }

    public function testDefaultsLeaveRoomForTheLargestRealReport(): void
    {
        $limits = IngestLimits::defaults();

        // The archive helper accepts one document up to 8 MiB and one archive
        // up to 16 MiB. The per-message default must hold a message with such
        // an archive, so the new limits never hold a genuine report the
        // archive helper would accept.
        $this->assertGreaterThanOrEqual(16 * self::MIB, $limits->maxMessageBytes());
        $this->assertNull($limits->checkMessage(1, 16 * self::MIB));
        // The per-run budget holds several maximal messages.
        $this->assertGreaterThanOrEqual(4 * $limits->maxMessageBytes(), $limits->maxRunBytes());
    }

    public function testConfigXmlDeclaresTheSameDefaultsAndBounds(): void
    {
        $config = simplexml_load_file(\dirname(__DIR__, 2) . '/administrator/components/com_dmarcreports/config.xml');
        $this->assertNotFalse($config);

        foreach (IngestLimits::OPTIONS as $name => [$default, $min, $max]) {
            $fields = $config->xpath('/config/fieldset/field[@name="' . $name . '"]');
            $this->assertCount(1, $fields, $name);
            $field = $fields[0];

            $this->assertSame((string) $default, (string) $field['default'], $name);
            $this->assertSame((string) $min, (string) $field['min'], $name);
            $this->assertSame((string) $max, (string) $field['max'], $name);
            $this->assertSame('range', (string) $field['validate'], $name);
            $this->assertSame('raw', (string) $field['filter'], $name);
            $this->assertNotSame('', (string) $field['message'], $name);
        }
    }

    public function testInvalidOptionsFallBackToTheDefault(): void
    {
        $values = [
            'max_attachments_per_message' => '0',      // below min
            'max_reports_per_message'     => '1e2',    // not a whole number
            'max_message_unpacked_mb'     => '',       // emptied
            'max_reports_per_run'         => '999999', // above max
            'max_run_unpacked_mb'         => ['x'],    // not scalar
        ];

        $limits = IngestLimits::fromOptions(static fn (string $key): mixed => $values[$key] ?? null);

        $this->assertSame(10, $limits->maxAttachments());
        $this->assertSame(20, $limits->maxMessageReports());
        $this->assertSame(32 * self::MIB, $limits->maxMessageBytes());
        $this->assertSame(1000, $limits->maxRunReports());
        $this->assertSame(256 * self::MIB, $limits->maxRunBytes());
    }

    public function testValidOptionsAreUsed(): void
    {
        $values = [
            'max_attachments_per_message' => '3',
            'max_reports_per_message'     => 5,
            'max_message_unpacked_mb'     => '8',
            'max_reports_per_run'         => '50',
            'max_run_unpacked_mb'         => '16',
        ];

        $limits = IngestLimits::fromOptions(static fn (string $key): mixed => $values[$key] ?? null);

        $this->assertSame(3, $limits->maxAttachments());
        $this->assertSame(5, $limits->maxMessageReports());
        $this->assertSame(8 * self::MIB, $limits->maxMessageBytes());
        $this->assertSame(50, $limits->maxRunReports());
        $this->assertSame(16 * self::MIB, $limits->maxRunBytes());
    }

    public function testAttachmentLimit(): void
    {
        $limits = new IngestLimits(3, 10, 1000, 100, 10000);

        $this->assertNull($limits->checkAttachments(0));
        $this->assertNull($limits->checkAttachments(3));
        $this->assertSame(IngestLimits::REASON_ATTACHMENTS, $limits->checkAttachments(4));
        // A part-flooded message with hundreds of report-like parts.
        $this->assertSame(IngestLimits::REASON_ATTACHMENTS, $limits->checkAttachments(1000));
    }

    public function testMessageLimitsUnderAtAndOver(): void
    {
        $limits = new IngestLimits(10, 5, 1000, 100, 10000);

        $this->assertNull($limits->checkMessage(5, 1000));
        $this->assertSame(IngestLimits::REASON_REPORTS, $limits->checkMessage(6, 10));
        $this->assertSame(IngestLimits::REASON_SIZE, $limits->checkMessage(1, 1001));
        // An archive with 256 extracted documents plus a dropped surplus.
        $this->assertSame(IngestLimits::REASON_REPORTS, $limits->checkMessage(256 + 44, 10));
    }

    public function testRunLimitsAreAtLeastTwiceTheMessageLimits(): void
    {
        // A run limit below two maximal messages is raised, so every message
        // within its own limits fits into half a run (the retry share).
        $limits = new IngestLimits(10, 100, 10000, 2, 100);

        $this->assertSame(200, $limits->maxRunReports());
        $this->assertSame(20000, $limits->maxRunBytes());

        $kept = new IngestLimits(10, 5, 500, 1000, 100000);
        $this->assertSame(1000, $kept->maxRunReports());
        $this->assertSame(100000, $kept->maxRunBytes());
    }

    public function testFirstMessageOfARunIsAlwaysAdmitted(): void
    {
        // With nothing charged yet the run always admits a message.
        $limits = new IngestLimits(10, 5, 500, 10, 1000);

        $this->assertNull($limits->checkRun(5, 500));
        $limits->charge(5, 500);
        $this->assertNull($limits->checkRun(5, 500));
        $limits->charge(5, 500);

        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $limits->checkRun(1, 1));
    }

    public function testRunLimitsPostponeOnceTheBudgetIsSpent(): void
    {
        $limits = new IngestLimits(10, 5, 500, 10, 1000);

        $limits->charge(4, 400);
        $this->assertNull($limits->checkRun(6, 600));        // exactly at both ceilings
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $limits->checkRun(7, 10));
        $this->assertSame(IngestLimits::REASON_RUN_SIZE, $limits->checkRun(1, 601));

        $limits->charge(6, 600);
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $limits->checkRun(1, 0));
    }

    public function testHeldMessagesConsumeTheRunSizeBudget(): void
    {
        // A flood of held messages (zip bombs, oversized archives) is unpacked
        // before it is held. That work counts, so it cannot repeat without bound
        // in one run.
        $limits = new IngestLimits(10, 100, 500, 1000, 1000);

        $limits->chargeInspected(900);
        $this->assertSame(IngestLimits::REASON_RUN_SIZE, $limits->checkRun(1, 200));
        $this->assertNull($limits->checkRun(1, 100));
    }

    public function testExhaustedRunStopsInspectingFurtherMessages(): void
    {
        $limits = new IngestLimits(10, 2, 500, 5, 1000);

        // A fresh run is never exhausted, so its first message is inspected.
        $this->assertNull($limits->runExhausted());

        // Two discarded gzip bombs spend the size budget although nothing was
        // stored; the next message is then postponed without being unpacked.
        $limits->chargeInspected(600);
        $this->assertNull($limits->runExhausted());
        $limits->chargeInspected(600);
        $this->assertSame(IngestLimits::REASON_RUN_SIZE, $limits->runExhausted());

        $reports = new IngestLimits(10, 2, 500, 5, 1000);
        $reports->charge(5, 10);
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $reports->runExhausted());
    }

    public function testRetriesUseAtMostHalfOfEachRunLimit(): void
    {
        $limits = new IngestLimits(10, 5, 500, 10, 1000);

        // An unused retry share admits any message within its own limits.
        $this->assertNull($limits->checkRetry(5, 500));
        $limits->charge(5, 500, true);

        // The share (5 reports, 500 bytes) is now used up; new mail keeps the
        // other half of the run.
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $limits->retryShareExhausted());
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $limits->checkRetry(1, 1));
        $this->assertNull($limits->runExhausted());
        $this->assertNull($limits->checkRun(5, 500));

        // Bytes of held retries count against the share as well.
        $bytes = new IngestLimits(10, 5, 500, 10, 1000);
        $bytes->chargeInspected(500, true);
        $this->assertSame(IngestLimits::REASON_RUN_SIZE, $bytes->retryShareExhausted());
        $this->assertNull($bytes->runExhausted());

        // Charges of new mail do not touch the retry share.
        $fresh = new IngestLimits(10, 5, 500, 10, 1000);
        $fresh->charge(5, 500);
        $this->assertNull($fresh->retryShareExhausted());
        $this->assertNull($fresh->checkRetry(5, 500));
    }

    public function testNewRunStartsWithAFullBudget(): void
    {
        // Every poll builds a fresh limits object, so a message postponed by
        // one run is the first message of the next one and is admitted there.
        $first = new IngestLimits(10, 5, 500, 10, 1000);
        $first->charge(10, 1000);
        $this->assertSame(IngestLimits::REASON_RUN_REPORTS, $first->checkRun(5, 500));

        $next = new IngestLimits(10, 5, 500, 10, 1000);
        $this->assertNull($next->checkRun(5, 500));
    }

    public function testBoundedHelper(): void
    {
        $this->assertSame(7, IngestLimits::bounded('7', 3, 1, 10));
        $this->assertSame(3, IngestLimits::bounded('11', 3, 1, 10));
        $this->assertSame(3, IngestLimits::bounded('-1', 3, 1, 10));
        $this->assertSame(3, IngestLimits::bounded('5.5', 3, 1, 10));
        $this->assertSame(3, IngestLimits::bounded(null, 3, 1, 10));
        $this->assertSame(10, IngestLimits::bounded(10, 3, 1, 10));
    }
}
