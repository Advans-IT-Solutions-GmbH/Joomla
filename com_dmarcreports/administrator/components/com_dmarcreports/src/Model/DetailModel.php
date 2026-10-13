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
use Advans\Component\DmarcReports\Administrator\Helper\DnsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\Metrics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Supplies the per-domain drill-down: metrics, top sources, timeline, live DNS
 * diagnostics and the domain's prioritised action items. It degrades
 * gracefully when no domain is selected.
 *
 * @since  1.0.0
 */
class DetailModel extends BaseDatabaseModel
{
    public function getDomainId(): int
    {
        return (int) Factory::getApplication()->getInput()->getInt('domain_id', 0);
    }

    /**
     * The monitored domains available for selection.
     *
     * @return array<int, object>
     */
    public function getDomainList(): array
    {
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select([$db->quoteName('id'), $db->quoteName('domain'), $db->quoteName('title')])
            ->from($db->quoteName('#__dmarc_domains'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('domain') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * The full metric bundle for the selected domain, or null when none.
     *
     * @return array<string, mixed>|null
     */
    public function getMetrics(): ?array
    {
        $domainId = $this->getDomainId();

        if ($domainId <= 0) {
            return null;
        }

        $metrics = (new Metrics($this->getDatabase()))->domainMetrics($domainId);

        if (($metrics['domain'] ?? '') === '') {
            return null;
        }

        return $metrics;
    }

    /**
     * Live DNS diagnostics for the selected domain.
     *
     * The lookups are opt-in: they are only performed when the operator has
     * enabled the "dns_diagnostics" option, because each lookup discloses the
     * monitored domain to the configured resolver. When the option is off the
     * method returns a neutral, disabled result so the drill-down keeps working
     * without any outbound DNS traffic.
     *
     * @param   array<string, mixed>  $metrics
     *
     * @return  array<string, mixed>
     */
    public function getDnsDiagnostics(array $metrics): array
    {
        if ((int) ComponentHelper::getParams('com_dmarcreports')->get('dns_diagnostics', 0) !== 1) {
            return [
                'enabled'   => false,
                'domain'    => (string) $metrics['domain'],
                'dmarc'     => ['present' => false, 'error' => false, 'record' => ''],
                'spf'       => ['present' => false, 'error' => false, 'record' => ''],
                'findings'  => [],
                'suggested' => '',
            ];
        }

        return ['enabled' => true] + (new DnsDiagnostics())->inspect((string) $metrics['domain']);
    }

    /**
     * MTA-STS (RFC 8461) and TLS-RPT (RFC 8460) monitoring for the selected
     * domain. The generated records to publish are always returned so the view
     * can show the one manual DNS/host step; the live record, hosted policy and
     * TLS-RPT lookups are only performed when the operator has enabled the
     * "dns_diagnostics" option, because they disclose the monitored domain to
     * the configured resolver and the policy host.
     *
     * @param   array<string, mixed>  $metrics
     *
     * @return  array<string, mixed>
     */
    public function getMtaStsDiagnostics(array $metrics): array
    {
        $params    = ComponentHelper::getParams('com_dmarcreports');
        $generated = MtaStsPolicy::generate($params, (string) $metrics['domain']);

        if (!$generated['enabled']) {
            return ['enabled' => false, 'generated' => $generated, 'findings' => []];
        }

        $withNetwork = (int) $params->get('dns_diagnostics', 0) === 1;

        return (new MtaStsDiagnostics())->inspect($generated, $withNetwork);
    }

    /**
     * TLS-RPT (RFC 8460) delivery summary for the selected domain, read from the
     * ingested TLS-RPT reports so the drill-down view can surface successful and
     * failed TLS sessions alongside the DMARC figures.
     *
     * @param   array<string, mixed>  $metrics
     *
     * @return  array<string, mixed>
     */
    public function getTlsRptSummary(array $metrics): array
    {
        return (new Metrics())->tlsRptSummary((int) $metrics['domain_id']);
    }

    /**
     * The prioritised action items for the selected domain.
     *
     * @param   array<string, mixed>  $metrics
     * @param   array<string, mixed>  $dns
     * @param   array<string, mixed>  $mtasts
     *
     * @return  array<int, array<string, mixed>>
     */
    public function getActions(array $metrics, array $dns, array $mtasts = []): array
    {
        $params = ComponentHelper::getParams('com_dmarcreports');
        $config = [
            'quarantine_pass_threshold' => $params->get('quarantine_pass_threshold', 95),
            'reject_pass_threshold'     => $params->get('reject_pass_threshold', 99),
        ];

        $findings = array_merge(
            (array) ($dns['findings'] ?? []),
            (array) ($mtasts['findings'] ?? [])
        );

        $items = ActionCenter::generate($metrics, $config, $findings);

        // Respect the status the operator has already set for these items on the
        // dashboard (done, ignored or snoozed) so the drill-down stays in sync.
        return (new ActionStatus($this->getDatabase()))->partition($items)['active'];
    }
}
