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
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Supplies the dashboard: per-domain summaries, overall totals and the
 * prioritised Action Center. Recommendations are regenerated live from the
 * data on every load and merged with the persisted status (done, snoozed,
 * ignored) so the administrator's progress is preserved. The DNS/policy publish
 * guidance for MTA-STS and TLS-RPT is pure string generation and is always
 * folded in, so the main Action Center and the dashboard module surface the one
 * manual DNS/host step. Rendering the dashboard never makes an outbound call:
 * the live DNS and MTA-STS/TLS-RPT drift lookups stay in the drill-down view
 * and the System Check, so a page that lists many domains cannot stall on a row
 * of network timeouts.
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
        $params  = ComponentHelper::getParams('com_dmarcreports');
        $metrics = new Metrics($this->getDb());
        $status  = new ActionStatus($this->getDb());
        $items   = [];

        $summaries = $this->getSummaries();

        // Resolve the per-domain Action Center metrics for every domain in a
        // fixed, small number of grouped queries rather than fanning out to
        // several queries per domain.
        $allMetrics = $metrics->actionMetricsForAll($summaries);

        foreach ($summaries as $summary) {
            $domainId = (int) ($summary['id'] ?? 0);
            $domainMetrics = $allMetrics[$domainId] ?? null;

            if ($domainMetrics === null) {
                continue;
            }

            $findings = $this->domainFindings((string) ($summary['domain'] ?? ''), $params);

            foreach (ActionCenter::generate($domainMetrics, $config, $findings) as $item) {
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
     * The MTA-STS/TLS-RPT publish guidance for one domain, mirroring the merge
     * the drill-down performs in {@see DetailModel::getActions()} so the main
     * Action Center and the dashboard module surface the same publish actions.
     * This is pure string generation: it is always network-free, so rendering a
     * dashboard that lists many domains never waits on DNS or HTTPS. The live
     * DNS and MTA-STS/TLS-RPT drift lookups are performed only in the drill-down
     * view and the System Check.
     *
     * @return array<int, array<string, mixed>>
     */
    private function domainFindings(string $domain, Registry $params): array
    {
        if ($domain === '') {
            return [];
        }

        $generated = MtaStsPolicy::generate($params, $domain);

        if (empty($generated['enabled']) && empty($generated['tlsrpt_enabled'])) {
            return [];
        }

        $mtasts = (new MtaStsDiagnostics())->inspect($generated, false);

        return (array) ($mtasts['findings'] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $params = ComponentHelper::getParams('com_dmarcreports');

        return [
            'quarantine_pass_threshold' => $params->get('quarantine_pass_threshold', 95),
            'reject_pass_threshold'     => $params->get('reject_pass_threshold', 99),
        ];
    }

    private function getDb(): DatabaseInterface
    {
        return $this->getDatabase();
    }
}
