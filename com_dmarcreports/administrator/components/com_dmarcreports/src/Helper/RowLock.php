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
 * Low-level row-locking primitive shared by the domain and source lock
 * protocols. There are no database-level foreign keys between a parent row and
 * its child rows (the child tables use a `0` sentinel an FK cannot satisfy), so
 * the relationship is serialised in application code: every writer that adds a
 * child row and the parent delete all take a `FOR UPDATE` lock on the same
 * parent row inside their transaction before touching child data. Because they
 * contend on one row, a delete and a child write can no longer interleave into
 * an orphaned row — whoever locks first runs to completion, and the other then
 * sees the committed outcome (the child written before the parent is cleared,
 * or the parent already gone so the write is abandoned).
 *
 * A plain FOR UPDATE keeps this portable across MySQL and MariaDB; every id is
 * cast to an integer before it is inlined, so there is no injection risk. The
 * caller must already have an open transaction, otherwise the lock is released
 * immediately and offers no protection.
 *
 * @since  1.0.0
 */
abstract class RowLock
{
    /**
     * Lock a single parent row for the lifetime of the caller's open
     * transaction. Returns false when the row no longer exists, signalling the
     * caller to abandon the child write rather than orphan it.
     */
    public static function lock(DatabaseInterface $db, string $table, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $sql = 'SELECT ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName($table)
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
            . ' FOR UPDATE';

        return (int) $db->setQuery($sql)->loadResult() === $id;
    }

    /**
     * Lock several parent rows for the lifetime of the caller's open
     * transaction, used by a delete cascade before it clears child tables. The
     * ids must already be validated integers.
     *
     * @param  int[]  $ids
     */
    public static function lockMany(DatabaseInterface $db, string $table, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $sql = 'SELECT ' . $db->quoteName('id')
            . ' FROM ' . $db->quoteName($table)
            . ' WHERE ' . $db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'
            . ' FOR UPDATE';

        $db->setQuery($sql)->execute();
    }
}
