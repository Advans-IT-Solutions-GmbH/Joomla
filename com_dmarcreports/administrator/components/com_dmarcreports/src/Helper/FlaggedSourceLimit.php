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
 * Bounds the flagged sources the dashboard loads per domain.
 *
 * The dashboard (and the administrator home module, which only shows a few
 * items) must not read, enrich and turn every flagged source of a busy domain
 * into action items. The cut is made in SQL, on the ranked list, so the rows
 * never leave the database. The domain view loads its own, complete list and is
 * the place to reach any source beyond the cap.
 *
 * Pure and Joomla-free so the bound is unit tested.
 *
 * @since  1.4.0
 */
final class FlaggedSourceLimit
{
    /** Most flagged sources the dashboard loads per domain. */
    public const PER_DOMAIN = 50;

    /** Hard upper limit, whatever a caller asks for. */
    public const MAX = 200;

    /**
     * The limit to apply: the default for a missing or non-positive value, never
     * above {@see self::MAX}.
     */
    public static function clamp(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return self::PER_DOMAIN;
        }

        return min($limit, self::MAX);
    }

    /**
     * Wrap a grouped query (one row per domain and source) so that only the top
     * rows of each domain survive, in the given order, and every row carries the
     * domain's full count of flagged sources (`flag_total`).
     *
     * @param   string  $grouped    The grouped inner query; must expose `domain_id`.
     * @param   string  $orderBy    The ranking, e.g. "spf_only DESC, source_ip ASC".
     * @param   int     $limit      Rows to keep per domain (clamped).
     * @param   string  $handled    Optional SQL condition that is true for sources the
     *                              administrator already handled (done, ignored, snoozed).
     *                              Open and handled sources are then capped separately,
     *                              so handled ones never take the slots of open ones and
     *                              stay listed for reopening.
     *
     * @return  string  The SQL text.
     */
    public static function wrap(string $grouped, string $orderBy, int $limit, string $handled = ''): string
    {
        $limit     = self::clamp($limit);
        $partition = $handled === ''
            ? 'g.domain_id'
            : 'g.domain_id, (CASE WHEN ' . $handled . ' THEN 1 ELSE 0 END)';

        return 'SELECT ranked.* FROM (SELECT g.*, '
            . 'ROW_NUMBER() OVER (PARTITION BY ' . $partition . ' ORDER BY ' . $orderBy . ') AS flag_rank, '
            . 'COUNT(*) OVER (PARTITION BY g.domain_id) AS flag_total '
            . 'FROM (' . $grouped . ') AS g) AS ranked '
            . 'WHERE ranked.flag_rank <= ' . $limit . ' '
            . 'ORDER BY ranked.domain_id ASC, ranked.flag_rank ASC';
    }
}