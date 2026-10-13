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
 * Serialises writes to a domain's child rows against a concurrent domain
 * delete. There are no database-level foreign keys (child tables use a
 * `domain_id = 0` unassigned sentinel an FK cannot satisfy), so the relationship
 * is protected in application code instead: every writer that adds a child row
 * for a domain — ingestion, the Action Center status store and the source
 * table — and the domain delete all take a `FOR UPDATE` lock on the same parent
 * domain row inside their transaction before touching child data. Because they
 * contend on one row, a delete and a child write can no longer interleave into
 * an orphaned row: whoever locks first runs to completion, and the other then
 * sees the committed outcome (the child written before the parent is cleared, or
 * the parent already gone so the write is abandoned).
 *
 * @since  1.0.0
 */
final class DomainLock
{
    /**
     * Lock a single parent domain row for the lifetime of the caller's open
     * transaction. Returns false when the row no longer exists, signalling the
     * caller to abandon the child write rather than orphan it. A plain
     * FOR UPDATE keeps this portable across MySQL and MariaDB; the id is already
     * an integer, so inlining it carries no injection risk.
     *
     * The caller must already have an open transaction, otherwise the lock is
     * released immediately and offers no protection.
     */
    public static function lock(DatabaseInterface $db, int $domainId): bool
    {
        if ($domainId <= 0) {
            return false;
        }

        $sql = 'SELECT ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName('#__dmarc_domains')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $domainId
            . ' FOR UPDATE';

        return (int) $db->setQuery($sql)->loadResult() === $domainId;
    }

    /**
     * Lock several parent domain rows for the lifetime of the caller's open
     * transaction, used by the delete cascade before it clears child tables.
     * The ids must already be validated integers.
     *
     * @param  int[]  $ids
     */
    public static function lockMany(DatabaseInterface $db, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $sql = 'SELECT ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName('#__dmarc_domains')
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
            . ' FOR UPDATE';

        $db->setQuery($sql)->execute();
    }
}
