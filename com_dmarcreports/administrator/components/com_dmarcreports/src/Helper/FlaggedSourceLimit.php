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
     *                              Open sources keep $limit rows per domain; handled ones
     *                              are capped separately at {@see self::MAX}, so they never
     *                              take the slots of open ones and stay listed for reopening.
     *
     * Contract: per domain the rows come open sources first, each group in the exact
     * order of $orderBy (the order the domain view ranks them), then the handled
     * sources. The open group is therefore the top $limit open sources of the detail
     * ranking; the handled group is the top {@see self::MAX} handled ones. Without a
     * handled condition there is one group.
     *
     * @return  string  The SQL text.
     */
    public static function wrap(string $grouped, string $orderBy, int $limit, string $handled = ''): string
    {
        $limit = self::clamp($limit);

        if ($handled === '') {
            $source    = '(' . $grouped . ') AS g';
            $partition = 'g.domain_id';
            $cap       = (string) $limit;
        } else {
            // The handled flag is computed in its own layer, so the window only
            // partitions by a plain column (no subquery inside the window spec).
            $source    = '(SELECT g.*, (CASE WHEN ' . $handled . ' THEN 1 ELSE 0 END) AS flag_handled FROM ('
                . $grouped . ') AS g) AS g';
            $partition = 'g.domain_id, g.flag_handled';
            $cap       = '(CASE WHEN ranked.flag_handled = 1 THEN ' . self::MAX . ' ELSE ' . $limit . ' END)';
        }

        return 'SELECT ranked.* FROM (SELECT g.*, '
            . 'ROW_NUMBER() OVER (PARTITION BY ' . $partition . ' ORDER BY ' . $orderBy . ') AS flag_rank, '
            . 'COUNT(*) OVER (PARTITION BY g.domain_id) AS flag_total '
            . 'FROM ' . $source . ') AS ranked '
            . 'WHERE ranked.flag_rank <= ' . $cap . ' '
            . 'ORDER BY ranked.domain_id ASC, ' . ($handled === '' ? '' : 'ranked.flag_handled ASC, ') . 'ranked.flag_rank ASC';
    }
}