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
 * The mailbox identity of a report source: the fields that together name the
 * remote mailbox a poll reads from. The persisted UID cursor (uidvalidity,
 * last_uid) is scoped to one mailbox, so it is only meaningful while these
 * fields are unchanged. When any of them change the source now points at a
 * different mailbox whose UIDs are unrelated, and a coincidentally matching
 * UIDVALIDITY on the replacement would otherwise let the next poll resume above
 * the old high-water UID and permanently skip every lower-UID report. Callers
 * compare the identity to decide when the cursor must be reset.
 *
 * @since  1.0.0
 */
abstract class SourceIdentity
{
    /**
     * The source columns that define which mailbox is polled. The credential is
     * deliberately excluded: a password change re-authenticates against the
     * same mailbox and must not discard the cursor.
     */
    public const FIELDS = ['protocol', 'host', 'port', 'security', 'username', 'folder'];

    /**
     * Reduce a source (an array or object row) to a normalised identity map of
     * the {@see self::FIELDS}, so two sources can be compared for equality with
     * a strict comparison regardless of their original value types.
     *
     * @param   array<string, mixed>|object  $source
     *
     * @return  array<string, string>
     */
    public static function fingerprint($source): array
    {
        $values = \is_object($source) ? get_object_vars($source) : (array) $source;
        $out    = [];

        foreach (self::FIELDS as $field) {
            $out[$field] = trim((string) ($values[$field] ?? ''));
        }

        return $out;
    }

    /**
     * Whether two sources name different mailboxes, i.e. whether any identity
     * field changed between them.
     *
     * @param   array<string, mixed>|object  $before
     * @param   array<string, mixed>|object  $after
     */
    public static function changed($before, $after): bool
    {
        return self::fingerprint($before) !== self::fingerprint($after);
    }
}
