<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Advans\Component\DmarcReports\Administrator\Helper\ActionCenter;
use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Supplies the dashboard: per-domain summaries, overall totals and the
 * prioritised Action Center. Recommendations are regenerated live from the
 * data on every load and merged with the persisted status (done, snoozed,
 * ignored) so the administrator's progress is preserved. DNS diagnostics are
 * intentionally left to the drill-down and System Check views to keep the
 * dashboard fast and free of network calls.
 *
 * @since  1.0.0
 */
class DashboardModel extends BaseDatabaseModel
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSummaries(): array
    {
        return (new Metrics($this->getDb()))->domainSummaries();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTotals(): array
    {
        $totals = ['domains' => 0, 'total' => 0, 'dmarc_pass' => 0, 'reject_under_p' => 0, 'pass_rate' => 0.0];

        foreach ($this->getSummaries() as $row) {
            $totals['domains']++;
            $totals['total']          += (int) $row['total'];
            $totals['dmarc_pass']     += (int) $row['dmarc_pass'];
            $totals['reject_under_p'] += (int) $row['reject_under_p'];
        }

        $totals['pass_rate'] = $totals['total'] > 0
            ? round($totals['dmarc_pass'] / $totals['total'] * 100, 1)
            : 0.0;

        return $totals;
    }

    /**
     * The prioritised, de-duplicated list of active action items across all
     * domains, with persisted status merged in.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActions(): array
    {
        $config   = $this->config();
        $metrics  = new Metrics($this->getDb());
        $statuses = $this->persistedStatuses();
        $now      = time();
        $active   = [];

        foreach ($this->getSummaries() as $summary) {
            $domainId = (int) $summary['id'];
            $items    = ActionCenter::generate($metrics->domainMetrics($domainId), $config);

            foreach ($items as $item) {
                $state = $statuses[$item['action_key']] ?? ['status' => 'open', 'snooze_until' => 0];

                if ($state['status'] === 'ignored' || $state['status'] === 'done') {
                    continue;
                }

                if ($state['status'] === 'snoozed' && $state['snooze_until'] > $now) {
                    continue;
                }

                $item['domain']        = $summary['domain'];
                $item['status']        = $state['status'];
                $active[]              = $item;
            }
        }

        usort(
            $active,
            static fn (array $a, array $b): int => ($b['severity'] <=> $a['severity'])
                ?: strcmp((string) $a['domain'], (string) $b['domain'])
        );

        return $active;
    }

    /**
     * @return array<string, array{status: string, snooze_until: int}>
     */
    private function persistedStatuses(): array
    {
        $db    = $this->getDb();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select([$db->quoteName('action_key'), $db->quoteName('status'), $db->quoteName('snooze_until')])
            ->from($db->quoteName('#__dmarc_actions'));

        $rows = $db->setQuery($query)->loadObjectList() ?: [];
        $map  = [];

        foreach ($rows as $row) {
            $map[(string) $row->action_key] = [
                'status'       => (string) $row->status,
                'snooze_until' => $row->snooze_until ? strtotime((string) $row->snooze_until) : 0,
            ];
        }

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $params = ComponentHelper::getParams('com_dmarcreports');

        return [
            'quarantine_pass_threshold' => (float) $params->get('quarantine_pass_threshold', 98),
            'reject_pass_threshold'     => (float) $params->get('reject_pass_threshold', 99),
        ];
    }

    private function getDb(): DatabaseInterface
    {
        return $this->getDatabase();
    }
}
