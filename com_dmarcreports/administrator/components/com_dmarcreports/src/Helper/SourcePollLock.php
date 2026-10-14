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
 * Serialises polling of a single source across processes with a MySQL advisory
 * lock (GET_LOCK), taken BEFORE a poll reads the import cursor and held until
 * the poll finishes.
 *
 * The on-demand import controller and the scheduler task both read the same
 * stored cursor (uidvalidity/last_uid/deferred_uids) and stream the same mailbox
 * through the {@see Ingestor}. The transaction-scoped {@see SourceLock} only
 * guards the final cursor write, which happens AFTER the slow IMAP fetch, parse
 * and optional server-side delete; two polls that start together therefore both
 * fetch, process and delete the same messages before either records its run.
 * An advisory lock is the right primitive here because it can be held across the
 * network round-trip without keeping a database transaction (and its row locks)
 * open for the whole poll. MySQL releases the lock automatically if the holding
 * connection dies, so a crashed poll never leaves a source permanently wedged.
 *
 * The lock name is namespaced by the schema so two Joomla sites sharing one
 * MySQL server — where GET_LOCK names are server-global — never contend for the
 * same source id.
 *
 * @since  1.1.0
 */
abstract class SourcePollLock
{
    /**
     * Build the server-global advisory lock name for a source.
     *
     * Pure and side-effect free so the naming — determinism, schema
     * namespacing and the 64-character GET_LOCK limit — can be unit tested
     * without a database connection.
     *
     * @param   string  $schema    The current database (schema) name.
     * @param   int     $sourceId  The `#__dmarc_sources` row id.
     */
    public static function buildName(string $schema, int $sourceId): string
    {
        // Hash the schema to a fixed-width, lock-name-safe token so an unusual
        // database name can neither overflow the 64-character GET_LOCK limit nor
        // introduce characters that need quoting.
        $namespace = substr(sha1($schema === '' ? 'default' : $schema), 0, 16);

        return 'dmarc_src_poll_' . $namespace . '_' . $sourceId;
    }

    /**
     * Interpret a GET_LOCK() result. MySQL returns 1 when the lock was obtained,
     * 0 on timeout and NULL on error; only an explicit 1 counts as acquired.
     *
     * Pure so the contention branch can be unit tested without a database.
     *
     * @param   mixed  $raw  The scalar GET_LOCK() returned.
     */
    public static function acquired($raw): bool
    {
        return (string) $raw === '1';
    }

    /**
     * Resolve the advisory lock name for a source against the live connection.
     */
    public static function lockName(DatabaseInterface $db, int $sourceId): string
    {
        $db->setQuery('SELECT DATABASE()');

        return self::buildName((string) $db->loadResult(), $sourceId);
    }

    /**
     * Try to claim the source for this poll without blocking. Returns true only
     * when this connection now holds the lock.
     */
    public static function acquire(DatabaseInterface $db, string $name): bool
    {
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($name) . ', 0)');

        return self::acquired($db->loadResult());
    }

    /**
     * Release a previously acquired advisory lock. Releasing must never abort a
     * finished poll, so a database error here is swallowed; MySQL would release
     * the lock on connection close regardless.
     */
    public static function release(DatabaseInterface $db, string $name): void
    {
        try {
            $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($name) . ')');
            $db->execute();
        } catch (\Throwable $e) {
            // Intentionally ignored: the lock is advisory and auto-released on
            // connection close, so a failed release cannot wedge the source.
        }
    }
}
