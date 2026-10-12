<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * Renders an Action Center item into localised, human readable text at display
 * time. Every language key is written out in full here (never assembled from
 * fragments) so the language linter can see each reference, and the English
 * wording is never stored in the database.
 *
 * @since  1.0.0
 */
final class ActionPresenter
{
    /**
     * @param   array<string, mixed>  $item
     */
    public static function title(array $item): string
    {
        $code = (string) ($item['code'] ?? '');

        return match ($code) {
            'no_data'               => Text::_('COM_DMARCREPORTS_ACTION_NO_DATA_TITLE'),
            'move_to_quarantine'    => Text::_('COM_DMARCREPORTS_ACTION_MOVE_TO_QUARANTINE_TITLE'),
            'resolve_before_quarantine' => Text::_('COM_DMARCREPORTS_ACTION_RESOLVE_BEFORE_QUARANTINE_TITLE'),
            'stay_at_none'          => Text::_('COM_DMARCREPORTS_ACTION_STAY_AT_NONE_TITLE'),
            'move_to_reject'        => Text::_('COM_DMARCREPORTS_ACTION_MOVE_TO_REJECT_TITLE'),
            'resolve_before_reject' => Text::_('COM_DMARCREPORTS_ACTION_RESOLVE_BEFORE_REJECT_TITLE'),
            'stay_at_quarantine'    => Text::_('COM_DMARCREPORTS_ACTION_STAY_AT_QUARANTINE_TITLE'),
            'enforced'              => Text::_('COM_DMARCREPORTS_ACTION_ENFORCED_TITLE'),
            'partial_enforcement'   => Text::_('COM_DMARCREPORTS_ACTION_PARTIAL_ENFORCEMENT_TITLE'),
            'partial_quarantine'    => Text::_('COM_DMARCREPORTS_ACTION_PARTIAL_QUARANTINE_TITLE'),
            'at_target'             => Text::_('COM_DMARCREPORTS_ACTION_AT_TARGET_TITLE'),
            'would_be_rejected'     => Text::_('COM_DMARCREPORTS_ACTION_WOULD_BE_REJECTED_TITLE'),
            'align_source'          => Text::_('COM_DMARCREPORTS_ACTION_ALIGN_SOURCE_TITLE'),
            'new_source'            => Text::_('COM_DMARCREPORTS_ACTION_NEW_SOURCE_TITLE'),
            'pass_rate_drop'        => Text::_('COM_DMARCREPORTS_ACTION_PASS_RATE_DROP_TITLE'),
            'dns_dmarc_missing'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_MISSING_TITLE'),
            'dns_dmarc_duplicate'   => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_DUPLICATE_TITLE'),
            'dns_dmarc_invalid'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_INVALID_TITLE'),
            'dns_dmarc_policy_none' => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_POLICY_NONE_TITLE'),
            'dns_dmarc_no_rua'      => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_NO_RUA_TITLE'),
            'dns_dmarc_pct'         => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_PCT_TITLE'),
            'dns_spf_missing'       => Text::_('COM_DMARCREPORTS_ACTION_DNS_SPF_MISSING_TITLE'),
            'dns_spf_duplicate'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_SPF_DUPLICATE_TITLE'),
            'dns_lookup_error'      => Text::_('COM_DMARCREPORTS_ACTION_DNS_LOOKUP_ERROR_TITLE'),
            default                 => Text::_('COM_DMARCREPORTS_ACTION_GENERIC_TITLE'),
        };
    }

    /**
     * @param   array<string, mixed>  $item
     */
    public static function text(array $item): string
    {
        $code   = (string) ($item['code'] ?? '');
        $params = array_values((array) ($item['params'] ?? []));

        return match ($code) {
            'no_data'               => Text::sprintf('COM_DMARCREPORTS_ACTION_NO_DATA_TEXT', ...self::pad($params, 1)),
            'move_to_quarantine'    => Text::sprintf('COM_DMARCREPORTS_ACTION_MOVE_TO_QUARANTINE_TEXT', ...self::pad($params, 2)),
            'resolve_before_quarantine' => Text::sprintf('COM_DMARCREPORTS_ACTION_RESOLVE_BEFORE_QUARANTINE_TEXT', ...self::pad($params, 3)),
            'stay_at_none'          => Text::sprintf('COM_DMARCREPORTS_ACTION_STAY_AT_NONE_TEXT', ...self::pad($params, 3)),
            'move_to_reject'        => Text::sprintf('COM_DMARCREPORTS_ACTION_MOVE_TO_REJECT_TEXT', ...self::pad($params, 2)),
            'resolve_before_reject' => Text::sprintf('COM_DMARCREPORTS_ACTION_RESOLVE_BEFORE_REJECT_TEXT', ...self::pad($params, 3)),
            'stay_at_quarantine'    => Text::sprintf('COM_DMARCREPORTS_ACTION_STAY_AT_QUARANTINE_TEXT', ...self::pad($params, 3)),
            'enforced'              => Text::sprintf('COM_DMARCREPORTS_ACTION_ENFORCED_TEXT', ...self::pad($params, 1)),
            'partial_enforcement'   => Text::sprintf('COM_DMARCREPORTS_ACTION_PARTIAL_ENFORCEMENT_TEXT', ...self::pad($params, 2)),
            'partial_quarantine'    => Text::sprintf('COM_DMARCREPORTS_ACTION_PARTIAL_QUARANTINE_TEXT', ...self::pad($params, 2)),
            'at_target'             => Text::sprintf('COM_DMARCREPORTS_ACTION_AT_TARGET_TEXT', ...self::pad($params, 2)),
            'would_be_rejected'     => Text::sprintf('COM_DMARCREPORTS_ACTION_WOULD_BE_REJECTED_TEXT', ...self::pad($params, 2)),
            'align_source'          => Text::sprintf('COM_DMARCREPORTS_ACTION_ALIGN_SOURCE_TEXT', ...self::pad($params, 3)),
            'new_source'            => Text::sprintf('COM_DMARCREPORTS_ACTION_NEW_SOURCE_TEXT', ...self::pad($params, 2)),
            'pass_rate_drop'        => Text::sprintf('COM_DMARCREPORTS_ACTION_PASS_RATE_DROP_TEXT', ...self::pad($params, 2)),
            'dns_dmarc_missing'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_MISSING_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_duplicate'   => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_DUPLICATE_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_invalid'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_INVALID_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_policy_none' => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_POLICY_NONE_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_no_rua'      => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_NO_RUA_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_pct'         => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_PCT_TEXT', ...self::pad($params, 1)),
            'dns_spf_missing'       => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_SPF_MISSING_TEXT', ...self::pad($params, 1)),
            'dns_spf_duplicate'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_SPF_DUPLICATE_TEXT', ...self::pad($params, 1)),
            'dns_lookup_error'      => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_LOOKUP_ERROR_TEXT', ...self::pad($params, 1)),
            default                 => Text::_('COM_DMARCREPORTS_ACTION_GENERIC_TEXT'),
        };
    }

    public static function severityLabel(int $severity): string
    {
        return match ($severity) {
            3       => Text::_('COM_DMARCREPORTS_SEVERITY_ACT_NOW'),
            2       => Text::_('COM_DMARCREPORTS_SEVERITY_PLAN_SOON'),
            1       => Text::_('COM_DMARCREPORTS_SEVERITY_REVIEW'),
            default => Text::_('COM_DMARCREPORTS_SEVERITY_INFO'),
        };
    }

    public static function severityClass(int $severity): string
    {
        return match ($severity) {
            3       => 'danger',
            2       => 'warning',
            1       => 'info',
            default => 'success',
        };
    }

    /**
     * Pad the parameter list to the length a format string expects, so a report
     * with unexpected gaps never triggers a sprintf argument warning.
     *
     * @param   array<int, mixed>  $params
     *
     * @return  array<int, mixed>
     */
    private static function pad(array $params, int $length): array
    {
        return array_pad(\array_slice($params, 0, $length), $length, '');
    }
}
