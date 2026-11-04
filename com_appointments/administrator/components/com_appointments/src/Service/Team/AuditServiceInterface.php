<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditVerification;

defined('_JEXEC') or die;

/**
 * The team calendar log (work package F): sign-ins and changes, never read
 * access, twelve months, as a hash chain.
 *
 * `row_hash = HMAC-SHA256(log key, prev_hash || canonical row)`, where the
 * canonical row is the length-prefixed list of every column except `id` and
 * `row_hash` (see {@see \Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite::associatedData()}
 * for the encoding). Appending locks the single row of
 * `#__appointments_teamlog_head` FOR UPDATE, so two requests can never append
 * with the same predecessor. Logging never fails the operation it records.
 *
 * @since  1.4.0
 */
interface AuditServiceInterface
{
    /**
     * @param   string  $event    One of {@see \Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent}.
     * @param   array{actor_user_id?: int|null, actor_device_id?: int|null, subject_user_id?: int|null, calendar_id?: int|null, object_ref?: string|null, details?: array<string, scalar|null>, ip?: string|null}  $context
     *                    `ip` is hashed with a server key before it is stored, never kept in clear.
     *                    `details` must not contain content, secrets or invitee data.
     */
    public function log(string $event, array $context = []): void;

    public function verify(): AuditVerification;

    /**
     * Hex hash of the newest row. Shown in the administrator only.
     */
    public function headHash(): string;

    /**
     * Delete rows older than twelve months and keep the anchor in the head row.
     *
     * @return  int  Rows deleted.
     */
    public function prune(\DateTimeImmutable $nowUtc): int;
}
