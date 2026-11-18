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
 * Where the component help lives and what it explains about the Action Center.
 * Every Action Center code the engine, the DNS diagnostics and the MTA-STS
 * diagnostics can emit has an entry here, grouped as the help page lists them;
 * a unit test fails when a new code is added without one. Each language key is
 * written out in full so the language linter sees it.
 *
 * @since  1.3.0
 */
final class HelpTopics
{
    /** The help page inside the administrator. */
    public const URL = 'index.php?option=com_dmarcreports&view=help';

    /**
     * The help page without the administrator menu, for the toolbar Help button,
     * which opens it in a small window. Every view passes this address to
     * $toolbar->help() literally, and the module and plugin manifests name a
     * language key with this value in their <help url>, so the repository help
     * lint finds the link; HelpPageTest keeps all of them equal to this constant.
     */
    public const POPUP_URL = 'index.php?option=com_dmarcreports&view=help&tmpl=component';

    /**
     * Action Center codes by group: group heading key => [code => help text key].
     *
     * @var array<string, array<string, string>>
     */
    public const ACTION_GROUPS = [
        'COM_DMARCREPORTS_HELP_GROUP_POLICY' => [
            'no_data'             => 'COM_DMARCREPORTS_HELP_ACTION_NO_DATA',
            'stay_at_none'        => 'COM_DMARCREPORTS_HELP_ACTION_STAY_AT_NONE',
            'move_to_quarantine'  => 'COM_DMARCREPORTS_HELP_ACTION_MOVE_TO_QUARANTINE',
            'stay_at_quarantine'  => 'COM_DMARCREPORTS_HELP_ACTION_STAY_AT_QUARANTINE',
            'move_to_reject'      => 'COM_DMARCREPORTS_HELP_ACTION_MOVE_TO_REJECT',
            'partial_quarantine'  => 'COM_DMARCREPORTS_HELP_ACTION_PARTIAL_QUARANTINE',
            'partial_enforcement' => 'COM_DMARCREPORTS_HELP_ACTION_PARTIAL_ENFORCEMENT',
            'at_target'           => 'COM_DMARCREPORTS_HELP_ACTION_AT_TARGET',
            'enforced'            => 'COM_DMARCREPORTS_HELP_ACTION_ENFORCED',
            'would_be_rejected'   => 'COM_DMARCREPORTS_HELP_ACTION_WOULD_BE_REJECTED',
        ],
        'COM_DMARCREPORTS_HELP_GROUP_SOURCES' => [
            'spf_only_source' => 'COM_DMARCREPORTS_HELP_ACTION_SPF_ONLY_SOURCE',
            'align_source'    => 'COM_DMARCREPORTS_HELP_ACTION_ALIGN_SOURCE',
            'pass_rate_drop'  => 'COM_DMARCREPORTS_HELP_ACTION_PASS_RATE_DROP',
        ],
        'COM_DMARCREPORTS_HELP_GROUP_DNS' => [
            'dns_dmarc_missing'         => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_MISSING',
            'dns_dmarc_subdomain_check' => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_SUBDOMAIN_CHECK',
            'dns_dmarc_duplicate'       => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_DUPLICATE',
            'dns_dmarc_invalid'         => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_INVALID',
            'dns_dmarc_policy_none'     => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_POLICY_NONE',
            'dns_dmarc_no_rua'          => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_NO_RUA',
            'dns_dmarc_pct'             => 'COM_DMARCREPORTS_HELP_ACTION_DNS_DMARC_PCT',
            'dns_spf_missing'           => 'COM_DMARCREPORTS_HELP_ACTION_DNS_SPF_MISSING',
            'dns_spf_duplicate'         => 'COM_DMARCREPORTS_HELP_ACTION_DNS_SPF_DUPLICATE',
            'dns_lookup_error'          => 'COM_DMARCREPORTS_HELP_ACTION_DNS_LOOKUP_ERROR',
        ],
        'COM_DMARCREPORTS_HELP_GROUP_MTASTS' => [
            'mtasts_no_mx'              => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_NO_MX',
            'mtasts_publish'            => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_PUBLISH',
            'mtasts_lookup_error'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_LOOKUP_ERROR',
            'mtasts_record_drift'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_RECORD_DRIFT',
            'mtasts_policy_unreachable' => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_POLICY_UNREACHABLE',
            'mtasts_policy_drift'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_POLICY_DRIFT',
            'mtasts_mx_lookup_error'    => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_MX_LOOKUP_ERROR',
            'mtasts_mx_no_answer'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_MX_NO_ANSWER',
            'mtasts_mx_uncovered'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_MX_UNCOVERED',
            'mtasts_mode_testing'       => 'COM_DMARCREPORTS_HELP_ACTION_MTASTS_MODE_TESTING',
            'tlsrpt_publish'            => 'COM_DMARCREPORTS_HELP_ACTION_TLSRPT_PUBLISH',
            'tlsrpt_missing_rua'        => 'COM_DMARCREPORTS_HELP_ACTION_TLSRPT_MISSING_RUA',
        ],
    ];

    /**
     * Every Action Center code the help page explains.
     *
     * @return  string[]
     */
    public static function actionCodes(): array
    {
        $codes = [];

        foreach (self::ACTION_GROUPS as $entries) {
            foreach (array_keys($entries) as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

}
