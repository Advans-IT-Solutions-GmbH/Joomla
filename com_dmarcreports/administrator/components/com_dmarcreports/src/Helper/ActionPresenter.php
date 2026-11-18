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
use Joomla\CMS\Router\Route;

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
            'stay_at_none'          => Text::_('COM_DMARCREPORTS_ACTION_STAY_AT_NONE_TITLE'),
            'move_to_reject'        => Text::_('COM_DMARCREPORTS_ACTION_MOVE_TO_REJECT_TITLE'),
            'stay_at_quarantine'    => Text::_('COM_DMARCREPORTS_ACTION_STAY_AT_QUARANTINE_TITLE'),
            'enforced'              => Text::_('COM_DMARCREPORTS_ACTION_ENFORCED_TITLE'),
            'partial_enforcement'   => Text::_('COM_DMARCREPORTS_ACTION_PARTIAL_ENFORCEMENT_TITLE'),
            'partial_quarantine'    => Text::_('COM_DMARCREPORTS_ACTION_PARTIAL_QUARANTINE_TITLE'),
            'at_target'             => Text::_('COM_DMARCREPORTS_ACTION_AT_TARGET_TITLE'),
            'would_be_rejected'     => Text::_('COM_DMARCREPORTS_ACTION_WOULD_BE_REJECTED_TITLE'),
            'align_source'          => Text::_('COM_DMARCREPORTS_ACTION_ALIGN_SOURCE_TITLE'),
            'spf_only_source'       => Text::_('COM_DMARCREPORTS_ACTION_SPF_ONLY_SOURCE_TITLE'),
            'new_source'            => Text::_('COM_DMARCREPORTS_ACTION_NEW_SOURCE_TITLE'),
            'pass_rate_drop'        => Text::_('COM_DMARCREPORTS_ACTION_PASS_RATE_DROP_TITLE'),
            'dns_dmarc_missing'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_MISSING_TITLE'),
            'dns_dmarc_subdomain_check' => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_SUBDOMAIN_CHECK_TITLE'),
            'dns_dmarc_duplicate'   => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_DUPLICATE_TITLE'),
            'dns_dmarc_invalid'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_INVALID_TITLE'),
            'dns_dmarc_policy_none' => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_POLICY_NONE_TITLE'),
            'dns_dmarc_no_rua'      => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_NO_RUA_TITLE'),
            'dns_dmarc_pct'         => Text::_('COM_DMARCREPORTS_ACTION_DNS_DMARC_PCT_TITLE'),
            'dns_spf_missing'       => Text::_('COM_DMARCREPORTS_ACTION_DNS_SPF_MISSING_TITLE'),
            'dns_spf_duplicate'     => Text::_('COM_DMARCREPORTS_ACTION_DNS_SPF_DUPLICATE_TITLE'),
            'dns_lookup_error'      => Text::_('COM_DMARCREPORTS_ACTION_DNS_LOOKUP_ERROR_TITLE'),
            'mtasts_no_mx'          => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_NO_MX_TITLE'),
            'mtasts_mx_lookup_error' => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_MX_LOOKUP_ERROR_TITLE'),
            'mtasts_mx_no_answer'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_MX_NO_ANSWER_TITLE'),
            'mtasts_mx_uncovered'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_MX_UNCOVERED_TITLE'),
            'mtasts_publish'        => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_PUBLISH_TITLE'),
            'mtasts_record_drift'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_RECORD_DRIFT_TITLE'),
            'mtasts_lookup_error'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_LOOKUP_ERROR_TITLE'),
            'mtasts_policy_unreachable' => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_POLICY_UNREACHABLE_TITLE'),
            'mtasts_policy_drift'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_POLICY_DRIFT_TITLE'),
            'mtasts_mode_testing'   => Text::_('COM_DMARCREPORTS_ACTION_MTASTS_MODE_TESTING_TITLE'),
            'tlsrpt_publish'        => Text::_('COM_DMARCREPORTS_ACTION_TLSRPT_PUBLISH_TITLE'),
            'tlsrpt_missing_rua'    => Text::_('COM_DMARCREPORTS_ACTION_TLSRPT_MISSING_RUA_TITLE'),
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
            'stay_at_none'          => Text::sprintf('COM_DMARCREPORTS_ACTION_STAY_AT_NONE_TEXT', ...self::pad($params, 3)),
            'move_to_reject'        => Text::sprintf('COM_DMARCREPORTS_ACTION_MOVE_TO_REJECT_TEXT', ...self::pad($params, 2)),
            'stay_at_quarantine'    => Text::sprintf('COM_DMARCREPORTS_ACTION_STAY_AT_QUARANTINE_TEXT', ...self::pad($params, 3)),
            'enforced'              => Text::sprintf('COM_DMARCREPORTS_ACTION_ENFORCED_TEXT', ...self::pad($params, 1)),
            'partial_enforcement'   => Text::sprintf('COM_DMARCREPORTS_ACTION_PARTIAL_ENFORCEMENT_TEXT', ...self::pad($params, 2)),
            'partial_quarantine'    => Text::sprintf('COM_DMARCREPORTS_ACTION_PARTIAL_QUARANTINE_TEXT', ...self::pad($params, 2)),
            'at_target'             => Text::sprintf('COM_DMARCREPORTS_ACTION_AT_TARGET_TEXT', ...self::pad($params, 2)),
            'would_be_rejected'     => Text::sprintf('COM_DMARCREPORTS_ACTION_WOULD_BE_REJECTED_TEXT', ...self::pad($params, 2)),
            'align_source'          => Text::sprintf('COM_DMARCREPORTS_ACTION_ALIGN_SOURCE_TEXT', ...self::withHost(self::pad($params, 4))),
            'spf_only_source'       => Text::sprintf('COM_DMARCREPORTS_ACTION_SPF_ONLY_SOURCE_TEXT', ...self::withHost(self::pad($params, 5))),
            'new_source'            => Text::sprintf('COM_DMARCREPORTS_ACTION_NEW_SOURCE_TEXT', ...self::pad($params, 1)),
            'pass_rate_drop'        => Text::sprintf('COM_DMARCREPORTS_ACTION_PASS_RATE_DROP_TEXT', ...self::pad($params, 2)),
            'dns_dmarc_missing'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_MISSING_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_subdomain_check' => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_SUBDOMAIN_CHECK_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_duplicate'   => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_DUPLICATE_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_invalid'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_INVALID_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_policy_none' => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_POLICY_NONE_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_no_rua'      => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_NO_RUA_TEXT', ...self::pad($params, 1)),
            'dns_dmarc_pct'         => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_DMARC_PCT_TEXT', ...self::pad($params, 1)),
            'dns_spf_missing'       => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_SPF_MISSING_TEXT', ...self::pad($params, 1)),
            'dns_spf_duplicate'     => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_SPF_DUPLICATE_TEXT', ...self::pad($params, 1)),
            'dns_lookup_error'      => Text::sprintf('COM_DMARCREPORTS_ACTION_DNS_LOOKUP_ERROR_TEXT', ...self::pad($params, 1)),
            'mtasts_no_mx'          => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_NO_MX_TEXT', ...self::pad($params, 1)),
            'mtasts_mx_lookup_error' => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_MX_LOOKUP_ERROR_TEXT', ...self::pad($params, 1)),
            'mtasts_mx_no_answer'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_MX_NO_ANSWER_TEXT', ...self::pad($params, 1)),
            'mtasts_mx_uncovered'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_MX_UNCOVERED_TEXT', ...self::pad($params, 2)),
            'mtasts_publish'        => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_PUBLISH_TEXT', ...self::pad($params, 3)),
            'mtasts_record_drift'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_RECORD_DRIFT_TEXT', ...self::pad($params, 3)),
            'mtasts_lookup_error'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_LOOKUP_ERROR_TEXT', ...self::pad($params, 1)),
            'mtasts_policy_unreachable' => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_POLICY_UNREACHABLE_TEXT', ...self::pad($params, 1)),
            'mtasts_policy_drift'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_POLICY_DRIFT_TEXT', ...self::pad($params, 1)),
            'mtasts_mode_testing'   => Text::sprintf('COM_DMARCREPORTS_ACTION_MTASTS_MODE_TESTING_TEXT', ...self::pad($params, 1)),
            'tlsrpt_publish'        => Text::sprintf('COM_DMARCREPORTS_ACTION_TLSRPT_PUBLISH_TEXT', ...self::pad($params, 2)),
            'tlsrpt_missing_rua'    => Text::sprintf('COM_DMARCREPORTS_ACTION_TLSRPT_MISSING_RUA_TEXT', ...self::pad($params, 1)),
            default                 => Text::_('COM_DMARCREPORTS_ACTION_GENERIC_TEXT'),
        };
    }

    public static function sourceEvidence(array $item): string
    {
        $evidence = (array) ($item['evidence'] ?? []);

        if ($evidence === []) {
            return '';
        }

        $esc = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $start = (int) ($evidence['window_start'] ?? 0);
        $end = (int) ($evidence['window_end'] ?? 0);
        $window = $start > 0 && $end > 0
            ? gmdate('Y-m-d H:i:s', $start) . ' UTC - ' . gmdate('Y-m-d H:i:s', $end) . ' UTC'
            : Text::_('COM_DMARCREPORTS_EVIDENCE_WINDOW_UNKNOWN');
        $host = (string) ($evidence['ptr_host'] ?? '');
        $confidence = (string) ($evidence['reverse_dns_confidence'] ?? 'not_forward_confirmed');

        $rationale = match ((string) ($evidence['assessment'] ?? '')) {
            'legitimate_dkim' => Text::_('COM_DMARCREPORTS_EVIDENCE_ASSESSMENT_DKIM_LEGITIMATE'),
            'spf_only_unresolved' => Text::_('COM_DMARCREPORTS_EVIDENCE_ASSESSMENT_SPF_ONLY_UNRESOLVED'),
            default => Text::_('COM_DMARCREPORTS_EVIDENCE_ASSESSMENT_UNRESOLVED'),
        };

        $dispositions = (int) ($evidence['disposition_none'] ?? 0) . ' / '
            . (int) ($evidence['disposition_quarantine'] ?? 0) . ' / '
            . (int) ($evidence['disposition_reject'] ?? 0);

        return '<dl class="com-dmarcreports-source-evidence small mb-0 mt-2">'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_SOURCE_IP') . '</dt><dd>' . $esc($evidence['source_ip'] ?? '') . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_PTR') . '</dt><dd>' . $esc($host !== '' ? $host : Text::_('COM_DMARCREPORTS_EVIDENCE_NOT_AVAILABLE'))
            . ' (' . $esc($confidence === 'forward_confirmed'
                ? Text::_('COM_DMARCREPORTS_EVIDENCE_FORWARD_CONFIRMED')
                : Text::_('COM_DMARCREPORTS_EVIDENCE_NOT_FORWARD_CONFIRMED')) . ')</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_DKIM') . '</dt><dd>'
            . Text::_('COM_DMARCREPORTS_EVIDENCE_PASS_ALIGNED') . ': ' . (int) ($evidence['dkim_pass_aligned'] ?? 0)
            . ', ' . Text::_('COM_DMARCREPORTS_EVIDENCE_PASS_UNALIGNED') . ': ' . (int) ($evidence['dkim_pass_unaligned'] ?? 0)
            . ', ' . Text::_('COM_DMARCREPORTS_EVIDENCE_NOT_PASS') . ': ' . (int) ($evidence['dkim_not_pass'] ?? 0) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_SPF') . '</dt><dd>'
            . Text::_('COM_DMARCREPORTS_EVIDENCE_PASS_ALIGNED') . ': ' . (int) ($evidence['spf_pass_aligned'] ?? 0)
            . ', ' . Text::_('COM_DMARCREPORTS_EVIDENCE_PASS_UNALIGNED') . ': ' . (int) ($evidence['spf_pass_unaligned'] ?? 0)
            . ', ' . Text::_('COM_DMARCREPORTS_EVIDENCE_NOT_PASS') . ': ' . (int) ($evidence['spf_not_pass'] ?? 0) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_DISPOSITION') . '</dt><dd>' . $esc($dispositions) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_MESSAGE_COUNT') . '</dt><dd>' . (int) ($evidence['message_count'] ?? 0) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_WINDOW') . '</dt><dd>' . $esc($window) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_DOMAIN_CONTEXT') . '</dt><dd>'
            . $esc(Text::_('COM_DMARCREPORTS_EVIDENCE_DOMAIN') . ': ' . (string) ($evidence['domain'] ?? '') . '; '
            . Text::_('COM_DMARCREPORTS_EVIDENCE_POLICY_DOMAIN') . ': ' . (string) ($evidence['policy_domain'] ?? '') . '; '
            . Text::_('COM_DMARCREPORTS_EVIDENCE_HEADER_FROM') . ': ' . (string) ($evidence['header_from'] ?? '') . '; adkim=' . (string) ($evidence['adkim'] ?? '') . '; aspf=' . (string) ($evidence['aspf'] ?? '')) . '</dd>'
            . '<dt>' . Text::_('COM_DMARCREPORTS_EVIDENCE_ASSESSMENT') . '</dt><dd>' . $esc($rationale) . '</dd>'
            . '</dl>';
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
     * The address of the domain view row of the sending source an item is
     * about, or '' when the item is not about one source. Lets the administrator
     * jump from an Action Center item straight to the source's figures.
     *
     * @param   array<string, mixed>  $item
     */
    public static function sourceLink(array $item): string
    {
        $anchor   = self::sourceAnchor($item);
        $domainId = (int) ($item['domain_id'] ?? 0);

        if ($anchor === '' || $domainId <= 0) {
            return '';
        }

        return Route::_('index.php?option=com_dmarcreports&view=detail&domain_id=' . $domainId, false) . '#' . $anchor;
    }

    /**
     * The id of the domain view row of the sending source an item is about, or
     * '' when the item is not about one source.
     *
     * @param   array<string, mixed>  $item
     */
    public static function sourceAnchor(array $item): string
    {
        $code   = (string) ($item['code'] ?? '');
        $params = array_values((array) ($item['params'] ?? []));
        $ip     = (string) ($params[0] ?? '');

        if (!\in_array($code, ['spf_only_source', 'align_source'], true) || $ip === '') {
            return '';
        }

        return SourceClassifier::anchor($ip);
    }

    /**
     * The label of a source class badge in the domain view.
     */
    public static function sourceClassLabel(string $class): string
    {
        return match ($class) {
            SourceClassifier::DKIM            => Text::_('COM_DMARCREPORTS_SOURCE_CLASS_DKIM'),
            SourceClassifier::SPF_ONLY        => Text::_('COM_DMARCREPORTS_SOURCE_CLASS_SPF_ONLY'),
            SourceClassifier::UNAUTHENTICATED => Text::_('COM_DMARCREPORTS_SOURCE_CLASS_UNAUTHENTICATED'),
            SourceClassifier::REJECTED        => Text::_('COM_DMARCREPORTS_SOURCE_CLASS_REJECTED'),
            default                           => '',
        };
    }

    /**
     * Replace the host name parameter (position 2) with a readable placeholder
     * when the source has no forward-confirmed name.
     *
     * @param   array<int, mixed>  $params
     *
     * @return  array<int, mixed>
     */
    private static function withHost(array $params): array
    {
        if (trim((string) ($params[1] ?? '')) === '') {
            $params[1] = Text::_('COM_DMARCREPORTS_SOURCE_HOST_UNKNOWN');
        }

        return $params;
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
