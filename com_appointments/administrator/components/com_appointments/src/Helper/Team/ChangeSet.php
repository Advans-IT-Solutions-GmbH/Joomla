<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * The answer to a sync-collection REPORT (RFC 6578): what changed since a token,
 * in the shape sabre/dav's `SyncSupport::getChangesForCalendar()` returns.
 *
 * @since  1.4.0
 */
final class ChangeSet
{
    /**
     * @param   string    $syncToken  The new token, as the string after `http://sabre.io/ns/sync/`.
     * @param   string[]  $added      Object URIs.
     * @param   string[]  $modified   Object URIs.
     * @param   string[]  $deleted    Object URIs.
     */
    public function __construct(
        public readonly string $syncToken,
        public readonly array $added,
        public readonly array $modified,
        public readonly array $deleted
    ) {
    }

    /**
     * @return  array{syncToken: string, added: string[], modified: string[], deleted: string[]}
     */
    public function toSabre(): array
    {
        return [
            'syncToken' => $this->syncToken,
            'added'     => array_values($this->added),
            'modified'  => array_values($this->modified),
            'deleted'   => array_values($this->deleted),
        ];
    }
}
