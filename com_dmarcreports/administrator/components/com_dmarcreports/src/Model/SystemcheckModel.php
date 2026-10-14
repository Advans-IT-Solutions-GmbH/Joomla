<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Advans\Component\DmarcReports\Administrator\Helper\EnvironmentCheck;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

/**
 * Supplies the re-runnable System Check view.
 *
 * @since  1.0.0
 */
class SystemcheckModel extends BaseDatabaseModel
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChecks(): array
    {
        return EnvironmentCheck::run();
    }

    /**
     * MTA-STS (RFC 8461) / TLS-RPT (RFC 8460) monitoring for every published
     * monitored domain, surfaced in the System Check view so drift is visible
     * without opening each domain's drill-down. The live DNS/policy lookups stay
     * behind the same `dns_diagnostics` opt-in as the per-domain diagnostics;
     * when it is off only the "publish these records" guidance is produced.
     *
     * @return  array{enabled:bool, network:bool, domains:array<int, array{domain:string, findings:array<int, array{code:string, severity:int, params:array<int,mixed>}>}>}
     *
     * @since  1.1.0
     */
    public function getMtaStsChecks(): array
    {
        $params      = ComponentHelper::getParams('com_dmarcreports');
        $withNetwork = (int) $params->get('dns_diagnostics', 0) === 1;
        $enabled     = (int) $params->get('mtasts_enable', 0) === 1
            || (int) $params->get('tlsrpt_enable', 0) === 1;

        if (!$enabled) {
            return (new MtaStsDiagnostics())->summarize(false, [], $withNetwork);
        }

        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('domain'))
            ->from($db->quoteName('#__dmarc_domains'))
            ->where($db->quoteName('published') . ' = 1')
            ->order($db->quoteName('domain') . ' ASC');

        $domains = $db->setQuery($query)->loadColumn() ?: [];

        $generated = [];

        foreach ($domains as $domain) {
            $domain = (string) $domain;

            if ($domain === '') {
                continue;
            }

            $generated[] = MtaStsPolicy::generate($params, $domain);
        }

        return (new MtaStsDiagnostics())->summarize(true, $generated, $withNetwork);
    }
}
