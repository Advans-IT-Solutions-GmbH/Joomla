<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Serialises writes to a source's child rows (its stored reports) against a
 * concurrent source delete. Ingestion loads a source, makes a slow IMAP call
 * and only then inserts reports carrying that source's id; a delete running in
 * between would reset those reports to the unbound sentinel and remove the
 * source, leaving the late insert pointing at a source that no longer exists.
 * Both the report insert and the source delete take a {@see RowLock} on the
 * same source row inside their transaction, so the late insert either commits
 * before the delete or sees the source gone and records the report unbound.
 *
 * @since  1.0.0
 */
abstract class SourceLock
{
    /**
     * Lock a single source row for the lifetime of the caller's open
     * transaction. Returns false when the row no longer exists.
     */
    public static function lock(DatabaseInterface $db, int $sourceId): bool
    {
        return RowLock::lock($db, '#__dmarc_sources', $sourceId);
    }

    /**
     * Lock several source rows for the lifetime of the caller's open
     * transaction, used by the delete path before it resets child reports.
     *
     * @param  int[]  $ids
     */
    public static function lockMany(DatabaseInterface $db, array $ids): void
    {
        RowLock::lockMany($db, '#__dmarc_sources', $ids);
    }
}
