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
use Advans\Component\DmarcReports\Administrator\Helper\ActionStatus;
use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Joomla\CMS\Component\ComponentHelper;
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
    /** @var array<int, array<string, mixed>>|null */
    private ?array $summaries = null;

    /** @var array{active: array<int, array<string, mixed>>, suppressed: array<int, array<string, mixed>>}|null */
    private ?array $actions = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSummaries(): array
    {
        if ($this->summaries === null) {
            $this->summaries = (new Metrics($this->getDb()))->domainSummaries();
        }

        return $this->summaries;
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
        return $this->buildActions()['active'];
    }

    /**
     * The action items the operator has already handled (done, ignored or
     * currently snoozed), so the dashboard can offer to reopen them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSuppressedActions(): array
    {
        return $this->buildActions()['suppressed'];
    }

    /**
     * Generate the Action Center for every domain once, partition the items into
     * active and suppressed by their persisted status, and sort both by severity.
     *
     * @return array{active: array<int, array<string, mixed>>, suppressed: array<int, array<string, mixed>>}
     */
    private function buildActions(): array
    {
        if ($this->actions !== null) {
            return $this->actions;
        }

        $config  = $this->config();
        $metrics = new Metrics($this->getDb());
        $status  = new ActionStatus($this->getDb());
        $items   = [];

        foreach ($this->getSummaries() as $summary) {
            foreach (ActionCenter::generate($metrics->actionMetrics($summary), $config) as $item) {
                $item['domain'] = $summary['domain'];
                $items[]        = $item;
            }
        }

        $partition = $status->partition($items);

        $sort = static fn (array $a, array $b): int => ($b['severity'] <=> $a['severity'])
            ?: strcmp((string) $a['domain'], (string) $b['domain']);

        usort($partition['active'], $sort);
        usort($partition['suppressed'], $sort);

        return $this->actions = $partition;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $params = ComponentHelper::getParams('com_dmarcreports');

        return [
            'quarantine_pass_threshold' => (float) $params->get('quarantine_pass_threshold', 95),
            'reject_pass_threshold'     => (float) $params->get('reject_pass_threshold', 99),
        ];
    }

    private function getDb(): DatabaseInterface
    {
        return $this->getDatabase();
    }
}
