<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * Writes the ingestion-limit log entries to the Joomla log file
 * `com_dmarcreports.php` (category `com_dmarcreports`).
 *
 * An entry names only the source id, the IMAP UID, the limit that was hit and
 * counts or sizes. It never contains report contents, subjects, addresses or
 * other header values of the message, so the log holds no personal data of the
 * sender and nothing the sender controls as free text.
 *
 * @since  1.2.0
 */
final class IngestLog
{
    public const CATEGORY = 'com_dmarcreports';

    private static bool $registered = false;

    /**
     * Log a message held for manual review because it exceeds a per-message
     * limit.
     */
    public static function held(int $sourceId, int $uid, string $reason, int $attachments, int $reports, int $bytes): void
    {
        self::write(
            Log::WARNING,
            sprintf(
                'DMARC ingestion: source %d, UID %d held for manual review (limit: %s; attachments: %d, reports: %d, unpacked bytes: %d). Not stored; left unread and flagged in the mailbox.',
                $sourceId,
                $uid,
                $reason,
                $attachments,
                $reports,
                $bytes
            )
        );
    }

    /**
     * Log a message postponed to the next run because the run limits are
     * reached.
     */
    public static function postponed(int $sourceId, int $uid, string $reason, int $reports, int $bytes): void
    {
        self::write(
            Log::INFO,
            sprintf(
                'DMARC ingestion: source %d, UID %d postponed to the next run (limit: %s; reports: %d, unpacked bytes: %d). Left untouched in the mailbox.',
                $sourceId,
                $uid,
                $reason,
                $reports,
                $bytes
            )
        );
    }

    private static function write(int $priority, string $message): void
    {
        // Logging must never break an import: a missing log folder or an
        // unwritable file only loses the log line, never a report.
        try {
            self::register();
            Log::add($message, $priority, self::CATEGORY);
        } catch (\Throwable) {
            // Ignore: the run summary still names the held messages.
        }
    }

    /**
     * Register the component's own text logger once per request. The file
     * logger reads the log folder from the application, so it is only added
     * when an application exists (the administrator or the scheduler CLI).
     */
    private static function register(): void
    {
        if (self::$registered || Factory::$application === null) {
            return;
        }

        self::$registered = true;

        Log::addLogger(['text_file' => 'com_dmarcreports.php'], Log::ALL, [self::CATEGORY]);
    }
}
