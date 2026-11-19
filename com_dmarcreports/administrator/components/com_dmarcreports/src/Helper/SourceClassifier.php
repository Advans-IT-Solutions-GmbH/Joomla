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
 * Decides on its own how far a sending source (one IP address) can be trusted,
 * from the DKIM and SPF results the receivers reported for its mail, so the
 * administrator never has to check a source by hand.
 *
 * Only an aligned DKIM signature proves the domain owner sent the mail: it is
 * made with the domain's own private key. SPF alignment only proves the mail came
 * from a server the domain's SPF record allows, and a hosting provider's shared
 * gateways (an SPF "include:") serve every customer of that provider, so another
 * customer can pass SPF for the domain. Mail that passes DMARC only through SPF is
 * therefore never confirmed automatically.
 *
 * The receivers' reports already state whether DKIM and SPF passed in alignment
 * with the From domain under the domain's adkim/aspf modes (policy_evaluated), so
 * the classification works from the stored figures alone and never needs DNS.
 *
 * Pure and Joomla-free so every branch is unit tested.
 *
 * @since  1.4.0
 */
final class SourceClassifier
{
    /** Every message carried an aligned DKIM signature (or failed and was blocked). */
    public const DKIM = 'dkim';

    /** Some mail passed DMARC only through SPF, without the domain's DKIM signature. */
    public const SPF_ONLY = 'spf_only';

    /** Some mail failed DMARC and was still delivered (p=none, pct below 100 or a receiver exception). */
    public const UNAUTHENTICATED = 'unauthenticated';

    /** All mail failed DMARC and the receivers rejected or quarantined it: the policy works. */
    public const REJECTED = 'rejected';

    /**
     * Classify one source from its message counts.
     *
     * Expected keys (missing keys count as 0): `dkim` (DKIM aligned pass),
     * `spf_only` (SPF aligned pass without aligned DKIM), `fail` (neither aligned)
     * and `fail_delivered` (failing mail the receiver still delivered, disposition
     * none). A source with mixed records takes the most urgent class: SPF-only mail
     * outweighs everything, because on a shared gateway the DKIM-signed mail can be
     * the owner's while the SPF-only mail is someone else's.
     *
     * @param   array<string, mixed>  $counts
     *
     * @return  string  One of the class constants, or '' when there is no mail.
     */
    public static function classify(array $counts): string
    {
        $dkim          = (int) ($counts['dkim'] ?? 0);
        $spfOnly       = (int) ($counts['spf_only'] ?? 0);
        $fail          = (int) ($counts['fail'] ?? 0);
        $failDelivered = min($fail, (int) ($counts['fail_delivered'] ?? 0));

        if ($spfOnly > 0) {
            return self::SPF_ONLY;
        }

        if ($failDelivered > 0) {
            return self::UNAUTHENTICATED;
        }

        if ($dkim > 0) {
            return self::DKIM;
        }

        if ($fail > 0) {
            return self::REJECTED;
        }

        return '';
    }

    /**
     * The figures a source is judged on now: its current reporting window when it
     * sent mail there, otherwise its whole history. A source that has since been
     * fixed (for example DKIM enabled) is then judged on its current mail, while a
     * source that stopped sending keeps the verdict of its last mail.
     *
     * @param   array<string, mixed>  $row  A row with lifetime keys and `w_`-prefixed window keys.
     *
     * @return  array{total: int, dkim: int, spf_only: int, fail: int, fail_delivered: int}
     */
    public static function current(array $row): array
    {
        $prefix = (int) ($row['w_total'] ?? 0) > 0 ? 'w_' : '';

        return [
            'total'          => (int) ($row[$prefix . 'total'] ?? 0),
            'dkim'           => (int) ($row[$prefix . 'dkim'] ?? 0),
            'spf_only'       => (int) ($row[$prefix . 'spf_only'] ?? 0),
            'fail'           => (int) ($row[$prefix . 'fail'] ?? 0),
            'fail_delivered' => (int) ($row[$prefix . 'fail_delivered'] ?? 0),
        ];
    }

    /**
     * The HTML id of a source's row in the domain view, so an Action Center item
     * can link straight to it. Dots and colons map to different characters, so an
     * IPv4 and an IPv6 address never share an id.
     */
    public static function anchor(string $ip): string
    {
        return 'source-' . strtr(strtolower(preg_replace('/[^0-9A-Fa-f.:]/', '', $ip) ?? ''), ['.' => '-', ':' => '_']);
    }

    /**
     * Bootstrap colour of a class badge.
     */
    public static function badgeClass(string $class): string
    {
        return match ($class) {
            self::DKIM            => 'success',
            self::SPF_ONLY        => 'danger',
            self::UNAUTHENTICATED => 'warning',
            self::REJECTED        => 'secondary',
            default               => 'light',
        };
    }
}
