<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * How the booking form handles the privacy notice (component option `consent_mode`).
 *
 * - notice:      no checkbox; the notice with its link stands in the information column.
 *                A posted checkbox value is ignored. The default, also for unknown values.
 * - acknowledge: a required checkbox "I have taken note"; nothing is stored.
 * - consent:     a required checkbox "I consent"; the booking stores when (consent_utc) and
 *                to which wording (consent_version) the person consented.
 *
 * Pure: no Joomla service, no database, no clock. The template and the controller derive the
 * label text through labelText(), so the stored version always matches the label that was shown.
 */
final class ConsentMode
{
    public const NOTICE = 'notice';
    public const ACKNOWLEDGE = 'acknowledge';
    public const CONSENT = 'consent';

    /** The configured mode; anything but a known value is NOTICE. */
    public static function fromParams(object $params): string
    {
        $mode = method_exists($params, 'get') ? $params->get('consent_mode', self::NOTICE) : ($params->consent_mode ?? self::NOTICE);

        return in_array($mode, [self::ACKNOWLEDGE, self::CONSENT], true) ? $mode : self::NOTICE;
    }

    public static function requiresCheckbox(string $mode): bool
    {
        return in_array($mode, [self::ACKNOWLEDGE, self::CONSENT], true);
    }

    /**
     * The plain text of the checkbox label, without markup, required mark or the hidden "opens
     * in a new window" text. $linkText replaces %s when a privacy notice link is configured.
     * Empty for NOTICE, which has no checkbox.
     *
     * @param callable(string): string $translate Language key to text, e.g. Text::_().
     */
    public static function labelText(string $mode, bool $hasLink, callable $translate, string $linkText): string
    {
        $keys = match ($mode) {
            self::ACKNOWLEDGE => ['COM_APPOINTMENTS_BOOKING_ACKNOWLEDGE_LINK', 'COM_APPOINTMENTS_BOOKING_ACKNOWLEDGE'],
            self::CONSENT => ['COM_APPOINTMENTS_BOOKING_CONSENT_LINK', 'COM_APPOINTMENTS_BOOKING_CONSENT'],
            default => null,
        };

        if ($keys === null) {
            return '';
        }

        return $hasLink ? str_replace('%s', $linkText, (string) $translate($keys[0])) : (string) $translate($keys[1]);
    }

    /**
     * The version of a consent: sha256 (64 hex characters) of mode, language tag and the exact
     * plain label text. A changed wording, language or mode gives a different version.
     */
    public static function version(string $mode, string $language, string $labelText): string
    {
        return hash('sha256', $mode . "\n" . $language . "\n" . $labelText);
    }
}
