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
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Single source of truth for a report's source provenance. Every stored report
 * keeps a nonzero source_id pointing at the mailbox it was ingested from; the
 * value 0 is the sentinel for an unbound report (the same value used for
 * manually uploaded reports). When a source is about to be removed its reports
 * must first be reset to that sentinel so none is left referencing a source row
 * that no longer exists.
 *
 * Two delete paths need this: {@see \Advans\Component\DmarcReports\Administrator\Model\SourceModel}
 * deletes a source directly, and {@see \Advans\Component\DmarcReports\Administrator\Model\DomainModel}
 * deletes a domain's sources as part of its cascade (where, with auto-creation,
 * a deleted domain's source may hold reports owned by a surviving domain).
 * Routing both through this helper keeps the set of provenance-bearing tables
 * defined in exactly one place, so a future table carrying a source_id cannot be
 * cleared on one path but forgotten on the other.
 *
 * @since  1.1.0
 */
abstract class ReportProvenance
{
    /**
     * The report tables that carry a source_id provenance column and must be
     * unbound before the source they reference is deleted.
     *
     * @return  string[]
     */
    public static function tables(): array
    {
        return ['#__dmarc_reports', '#__dmarc_tlsrpt_reports'];
    }

    /**
     * Reduce a raw list of source primary keys to the distinct, positive
     * integers worth unbinding. Non-numeric, zero and negative entries (and the
     * unbound sentinel itself) are dropped so the generated IN () clause is
     * always valid and never matches the sentinel.
     *
     * @param   array  $sourceIds  Raw source ids from the caller.
     *
     * @return  int[]
     */
    public static function normalizeSourceIds(array $sourceIds): array
    {
        $ids = array_filter(array_map('intval', $sourceIds), static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    /**
     * Reset to the 0 sentinel the source provenance of every report that points
     * at one of the given sources, across all provenance-bearing tables. The
     * caller is responsible for running this inside its open transaction and for
     * holding the appropriate source lock first, so an in-flight ingestion that
     * is about to write a report for one of these sources waits and then records
     * its report unbound instead of leaving a dangling reference.
     *
     * @param   DatabaseInterface  $db         The open database connection.
     * @param   array              $sourceIds  Source ids whose reports to unbind.
     *
     * @return  void
     */
    public static function unbindSources(DatabaseInterface $db, array $sourceIds): void
    {
        $ids = self::normalizeSourceIds($sourceIds);

        if ($ids === []) {
            return;
        }

        foreach (self::tables() as $table) {
            $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                ->update($db->quoteName($table))
                ->set($db->quoteName('source_id') . ' = 0')
                ->whereIn($db->quoteName('source_id'), $ids, ParameterType::INTEGER);
            $db->setQuery($query)->execute();
        }
    }
}
