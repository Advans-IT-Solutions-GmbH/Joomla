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
 * Per-message and per-run ceilings for mailbox ingestion.
 *
 * The reporting address is public, so anybody can send it mail. Without these
 * ceilings one message could carry hundreds of report attachments and archives,
 * and one run could store an unbounded number of reports, because the IMAP
 * client's own run budget only counts raw (compressed) bytes.
 *
 * A message over a per-message ceiling is "held": nothing from it is stored, it
 * stays unread in the mailbox and is flagged for manual review. A message that
 * fits its own ceilings but would push the run over a per-run ceiling is
 * "postponed": it is left untouched and the next run starts with it. The first
 * message a run inspects is always admitted to the run, so a run always makes
 * progress and a postponed message is never starved. Messages from the retry
 * set may use at most half of each run limit, so retries and new mail cannot
 * starve each other.
 *
 * Pure PHP without Joomla dependencies, so the decisions are unit-tested.
 *
 * @since  1.2.0
 */
final class IngestLimits
{
    public const REASON_ATTACHMENTS   = 'attachments';
    public const REASON_REPORTS       = 'reports';
    public const REASON_SIZE          = 'size';
    public const REASON_ARCHIVE_LIMIT = 'archive_limit';
    public const REASON_RUN_REPORTS   = 'run_reports';
    public const REASON_RUN_SIZE      = 'run_size';

    /**
     * Component options with [default, min, max]. The defaults sit well above
     * what real reporters send (one compressed report per message, RFC 7489
     * section 7.2.1.1 and RFC 9990 section 3.5.2) and above the per-document
     * (8 MiB) and per-archive (16 MiB) ceilings of {@see ReportArchive}, so the
     * new limits never hold a message the archive helper would have accepted
     * from a genuine reporter. The bounds match config.xml.
     */
    public const OPTIONS = [
        'max_attachments_per_message' => [10, 1, 100],
        'max_reports_per_message'     => [20, 1, 256],
        'max_message_unpacked_mb'     => [32, 8, 256],
        'max_reports_per_run'         => [1000, 50, 20000],
        'max_run_unpacked_mb'         => [256, 16, 2048],
    ];

    private const MIB = 1048576;

    private int $maxAttachments;
    private int $maxMessageReports;
    private int $maxMessageBytes;
    private int $maxRunReports;
    private int $maxRunBytes;

    /** Reports admitted to this run so far. */
    private int $runReports = 0;

    /** Unpacked bytes inspected in this run so far (admitted and held messages). */
    private int $runBytes = 0;

    /** Reports of retried messages (the retry set) admitted to this run so far. */
    private int $retryReports = 0;

    /** Unpacked bytes of retried messages inspected in this run so far. */
    private int $retryBytes = 0;

    /**
     * The run limits are raised to at least twice the reports and four times
     * the unpacked size per message. Every message within its own limits then
     * fits into half a run, which is the share retries may use, even when it
     * has to be unpacked twice (see Ingestor). So a retry is never starved and
     * new mail always keeps the other half.
     */
    public function __construct(int $maxAttachments, int $maxMessageReports, int $maxMessageBytes, int $maxRunReports, int $maxRunBytes)
    {
        $this->maxAttachments    = max(1, $maxAttachments);
        $this->maxMessageReports = max(1, $maxMessageReports);
        $this->maxMessageBytes   = max(1, $maxMessageBytes);
        $this->maxRunReports     = max($maxRunReports, 2 * $this->maxMessageReports);
        $this->maxRunBytes       = max($maxRunBytes, 4 * $this->maxMessageBytes);
    }

    /**
     * Build the limits from the component options. The reader returns the
     * stored value for an option key (or null); an empty, non-numeric or
     * out-of-range value falls back to the default, like the other numeric
     * options of the component.
     *
     * @param   callable(string): mixed  $read
     */
    public static function fromOptions(callable $read): self
    {
        $value = static function (string $key) use ($read): int {
            [$default, $min, $max] = self::OPTIONS[$key];

            return self::bounded($read($key), $default, $min, $max);
        };

        return new self(
            $value('max_attachments_per_message'),
            $value('max_reports_per_message'),
            $value('max_message_unpacked_mb') * self::MIB,
            $value('max_reports_per_run'),
            $value('max_run_unpacked_mb') * self::MIB
        );
    }

    /**
     * The default limits, as shipped in config.xml.
     */
    public static function defaults(): self
    {
        return self::fromOptions(static fn (): mixed => null);
    }

    /**
     * Read an integer option and fall back to the default when the stored value
     * is empty, non-numeric or outside [min, max].
     */
    public static function bounded(mixed $value, int $default, int $min, int $max): int
    {
        if (!\is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value)) {
            return $default;
        }

        $value = (int) $value;

        return $value < $min || $value > $max ? $default : $value;
    }

    /**
     * Whether a message carries more report attachments than one message may.
     *
     * @return  string|null  A REASON_* constant, or null when within the limit.
     */
    public function checkAttachments(int $count): ?string
    {
        return $count > $this->maxAttachments ? self::REASON_ATTACHMENTS : null;
    }

    /**
     * Whether the reports found so far in one message exceed the per-message
     * ceilings. Called after each attachment, so extraction stops as soon as a
     * message is known to be over the limit.
     *
     * @return  string|null  A REASON_* constant, or null when within the limits.
     */
    public function checkMessage(int $reports, int $bytes): ?string
    {
        if ($reports > $this->maxMessageReports) {
            return self::REASON_REPORTS;
        }

        if ($bytes > $this->maxMessageBytes) {
            return self::REASON_SIZE;
        }

        return null;
    }

    /**
     * Whether a message that fits its own ceilings may still be stored in this
     * run. The first message of a run is always admitted, so a run whose
     * per-run ceiling is lower than one message still makes progress.
     *
     * @return  string|null  A REASON_RUN_* constant, or null when admitted.
     */
    public function checkRun(int $reports, int $bytes): ?string
    {
        if ($this->runReports === 0 && $this->runBytes === 0) {
            return null;
        }

        if ($this->runReports + $reports > $this->maxRunReports) {
            return self::REASON_RUN_REPORTS;
        }

        if ($this->runBytes + $bytes > $this->maxRunBytes) {
            return self::REASON_RUN_SIZE;
        }

        return null;
    }

    /**
     * Whether the run budget is already spent, so the next message must not
     * even be unpacked. A fresh run is never exhausted, so the first message
     * of a run is always inspected.
     *
     * @return  string|null  A REASON_RUN_* constant, or null while budget remains.
     */
    public function runExhausted(): ?string
    {
        if ($this->runReports >= $this->maxRunReports) {
            return self::REASON_RUN_REPORTS;
        }

        if ($this->runBytes >= $this->maxRunBytes) {
            return self::REASON_RUN_SIZE;
        }

        return null;
    }

    /**
     * Whether a retried message (from the retry set) still fits the retry share:
     * half of each run limit. The share is checked in addition to
     * {@see checkRun()}. Because of the raised run limits (see the
     * constructor), a message within its own limits always fits an unused
     * share, so every run retries at least one message.
     *
     * @return  string|null  A REASON_RUN_* constant, or null when admitted.
     */
    public function checkRetry(int $reports, int $bytes): ?string
    {
        if ($this->retryReports + $reports > intdiv($this->maxRunReports, 2)) {
            return self::REASON_RUN_REPORTS;
        }

        if ($this->retryBytes + $bytes > intdiv($this->maxRunBytes, 2)) {
            return self::REASON_RUN_SIZE;
        }

        return null;
    }

    /**
     * Whether the retry share is already spent, so the next retried message
     * must not even be unpacked.
     *
     * @return  string|null  A REASON_RUN_* constant, or null while share remains.
     */
    public function retryShareExhausted(): ?string
    {
        if ($this->retryReports >= intdiv($this->maxRunReports, 2)) {
            return self::REASON_RUN_REPORTS;
        }

        if ($this->retryBytes >= intdiv($this->maxRunBytes, 2)) {
            return self::REASON_RUN_SIZE;
        }

        return null;
    }

    /**
     * Charge an admitted message to the run budget, and to the retry share
     * when it came from the retry set.
     */
    public function charge(int $reports, int $bytes, bool $retry = false): void
    {
        $this->runReports += max(0, $reports);
        $this->runBytes   += max(0, $bytes);

        if ($retry) {
            $this->retryReports += max(0, $reports);
            $this->retryBytes   += max(0, $bytes);
        }
    }

    /**
     * Charge unpacked bytes that were not stored to the run budget: those of a
     * held or postponed message and a second extraction. Nothing of them is
     * stored, but the decompression work counts, so a flood of such messages
     * cannot make one run unpack without bound.
     */
    public function chargeInspected(int $bytes, bool $retry = false): void
    {
        $this->runBytes += max(0, $bytes);

        if ($retry) {
            $this->retryBytes += max(0, $bytes);
        }
    }

    public function maxAttachments(): int
    {
        return $this->maxAttachments;
    }

    public function maxMessageReports(): int
    {
        return $this->maxMessageReports;
    }

    public function maxMessageBytes(): int
    {
        return $this->maxMessageBytes;
    }

    public function maxRunReports(): int
    {
        return $this->maxRunReports;
    }

    public function maxRunBytes(): int
    {
        return $this->maxRunBytes;
    }
}
