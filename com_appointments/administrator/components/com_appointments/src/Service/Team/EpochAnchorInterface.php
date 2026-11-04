<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

defined('_JEXEC') or die;

/**
 * Anchor of the replay protection outside the database (work package B).
 *
 * Row MACs prove that a row was written by the server, not that it is the
 * newest one: a database writer could play back an older, validly signed grant
 * or device. Every calendar therefore carries `grant_epoch` and every user key
 * row `device_epoch`; both are part of the MACs and rise with every revocation,
 * downgrade, block and key change. This anchor keeps the highest epoch seen per
 * calendar and per user in a small file below the Joomla directory, signed with
 * a server key (KeyProvider::PURPOSE_TEAM_ANCHOR), so an epoch rolled back in
 * the database together with its rows is detected: a row is valid only when its
 * epoch is at least the anchored one.
 *
 * File: `administrator/components/com_appointments/data/epochs.php`, a PHP file
 * that returns its data only inside Joomla (`defined('_JEXEC') or die`), written
 * atomically (temporary file and rename) under an exclusive lock. When the file
 * cannot be written, every method keeps working without the anchor, writable()
 * is false and the system check warns that rollback protection is off. A
 * missing or wrongly signed file is recreated from the database and logged once.
 * Restoring a full backup of files and database together is a legitimate
 * rollback and stays consistent.
 *
 * @since  1.4.0
 */
interface EpochAnchorInterface
{
    public const KIND_CALENDAR = 'calendar';
    public const KIND_USER = 'user';

    /**
     * Record a new epoch after the database change committed. Never lowers a value.
     */
    public function record(string $kind, int $id, int $epoch): void;

    /**
     * The anchored epoch, 0 when none is known (or the anchor is not writable).
     */
    public function minimum(string $kind, int $id): int;

    /**
     * Whether the epoch of a row is acceptable: at least the anchored one.
     */
    public function accepts(string $kind, int $id, int $epoch): bool;

    public function writable(): bool;
}
