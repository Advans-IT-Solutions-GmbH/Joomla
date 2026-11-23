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
use Advans\Component\DmarcReports\Administrator\Helper\JoomlaHostNameStore;
use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Advans\Component\DmarcReports\Administrator\Helper\SourceHostNames;
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
 * guidance for MTA-STS and TLS-RPT is pure string generation and is folded in
 * while live checks are off, so the main Action Center and the dashboard module
 * surface the one manual DNS/host step; with live checks on, the drill-down and
 * the System Check report the verified state instead. Rendering the dashboard
 * never makes an outbound call:
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
        $allMetrics = $metrics->actionMetricsForAll($summaries, $this->handledSources($status, $summaries));

        // Close stored "new source" items whose source is now confirmed through
        // DKIM. A failure here must never keep the dashboard from rendering.
        try {
            $status->resolveConfirmedNewSources(
                static fn (int $domainId, array $ips): array => $metrics->classifySources($domainId, $ips),
                static fn (array $requests): array => $metrics->classifySourcesAll($requests)
            );
        } catch (\Throwable $e) {
            // The items are resolved on a later page view.
        }

        // The names of the flagged sources come from the cache only: the
        // dashboard never makes an outbound call (see the class comment). The
        // domain view looks the names up.
        $hostNames = new SourceHostNames(null, new JoomlaHostNameStore());
        $domains   = [];

        foreach ($summaries as $summary) {
            $domainId = (int) ($summary['id'] ?? 0);
            $domainMetrics = $allMetrics[$domainId] ?? null;
            $domains[$domainId] = (string) ($summary['domain'] ?? '');

            if ($domainMetrics === null) {
                continue;
            }

            $domainMetrics['flagged_sources'] = self::withHostNames(
                (array) ($domainMetrics['flagged_sources'] ?? []),
                $hostNames
            );

            $findings = $this->domainFindings((string) ($summary['domain'] ?? ''), $params);

            foreach (ActionCenter::generate($domainMetrics, $config, $findings) as $item) {
                $item['domain'] = $summary['domain'];
                $items[]        = $item;
            }
        }

        $partition = $status->partition($items);

        // Items the software resolved itself are listed among the handled ones,
        // so the administrator sees what was confirmed automatically.
        try {
            foreach ($status->autoResolved() as $item) {
                if (isset($domains[(int) $item['domain_id']])) {
                    $item['domain']              = $domains[(int) $item['domain_id']];
                    $partition['suppressed'][] = $item;
                }
            }
        } catch (\Throwable $e) {
            // Only the record of automatic decisions is missing; the list still renders.
        }

        // With live checks on, the drill-down and the System Check verify the
        // published records, so the dashboard leaves the unverified publish
        // reminders out of its active list instead of contradicting them. Handled
        // reminders stay in the suppressed list so they can still be reopened.
        $partition['active'] = MtaStsDiagnostics::withoutUnverifiedReminders(
            $partition['active'],
            (int) $params->get('dns_diagnostics', 0) === 1
        );

        $sort = static fn (array $a, array $b): int => ($b['severity'] <=> $a['severity'])
            ?: strcmp((string) $a['domain'], (string) $b['domain']);

        usort($partition['active'], $sort);
        usort($partition['suppressed'], $sort);

        return $this->actions = $partition;
    }

    /**
     * The source addresses per domain whose action the administrator already
     * handled (done, ignored or still snoozed), so the capped bulk query keeps
     * them apart from the open ones.
     *
     * @param   array<int, array<string, mixed>>  $summaries
     *
     * @return  array<int, array<int, string>>
     */
    private function handledSources(ActionStatus $status, array $summaries): array
    {
        $domainIds = [];

        foreach ($summaries as $summary) {
            $domainIds[] = (int) ($summary['id'] ?? 0);
        }

        $handled = [];
        $now     = time();

        try {
            foreach ($status->statuses() as $key => $state) {
                if ($state['status'] === 'open' || ($state['status'] === 'snoozed' && $state['snooze_until'] <= $now)) {
                    continue;
                }

                $parts    = explode(':', (string) $key, 3);
                $domainId = (int) ($parts[1] ?? 0);

                if (!\in_array($domainId, $domainIds, true)) {
                    continue;
                }

                $ip = ActionStatus::sourceOf((string) $key, $domainId);

                if ($ip !== '') {
                    $handled[$domainId][] = $ip;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return $handled;
    }

    /**
     * The MTA-STS/TLS-RPT publish guidance for one domain. Without live checks
     * the main Action Center and the dashboard module surface the same publish
     * actions as the drill-down; with live checks on, the verified state lives
     * in the drill-down and the System Check (see
     * {@see MtaStsDiagnostics::withoutUnverifiedReminders()}).
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
     * Add the known host name of each flagged source (key `host`, '' when none).
     *
     * @param   array<int, array<string, mixed>>  $sources
     *
     * @return  array<int, array<string, mixed>>
     */
    public static function withHostNames(array $sources, SourceHostNames $hostNames): array
    {
        $names = $hostNames->names(array_map(static fn ($s): string => (string) ($s['ip'] ?? ''), $sources));

        foreach ($sources as &$source) {
            $source['host'] = $names[(string) ($source['ip'] ?? '')] ?? '';
        }

        unset($source);

        return $sources;
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
