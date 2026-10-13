<?php
/**
 * @package     DMARC Reports Ingestion Task Plugin
 * @subpackage  Support
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Task\DmarcReports\Support;

defined('_JEXEC') or die;

/**
 * Pure, Joomla-free aggregation of per-source poll results.
 *
 * Kept free of any framework dependency so the scheduler run totals can be
 * exercised by unit tests without a running Joomla.
 *
 * @since  1.0.0
 */
final class IngestSummary
{
    /**
     * Status string a source poll returns when it failed.
     *
     * Mirrors \Advans\Component\DmarcReports\Administrator\Service\SourcePoller::STATUS_ERROR.
     *
     * @var    string
     * @since  1.0.0
     */
    public const STATUS_ERROR = 'error';

    /**
     * Sum the per-source poll results into run totals.
     *
     * @param   array<int, array<string, mixed>>  $results  One SourcePoller::poll() result per source.
     *
     * @return  array{imported:int,duplicates:int,failed:int,blocked:int,errors:int}
     *
     * @since 1.0.0
     */
    public static function aggregate(array $results): array
    {
        $totals = [
            'imported'   => 0,
            'duplicates' => 0,
            'failed'     => 0,
            'blocked'    => 0,
            'errors'     => 0,
        ];

        foreach ($results as $result) {
            $summary = \is_array($result['summary'] ?? null) ? $result['summary'] : [];

            $totals['imported']   += (int) ($summary['imported'] ?? 0);
            $totals['duplicates'] += (int) ($summary['duplicates'] ?? 0);
            $totals['failed']     += (int) ($summary['failed'] ?? 0);
            $totals['blocked']    += (int) ($result['blocked'] ?? 0);

            if (($result['status'] ?? '') === self::STATUS_ERROR) {
                $totals['errors']++;
            }
        }

        return $totals;
    }
}
