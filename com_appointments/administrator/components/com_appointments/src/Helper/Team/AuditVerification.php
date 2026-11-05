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
 * Result of checking the hash chain of the team calendar log.
 *
 * @since  1.4.0
 */
final class AuditVerification
{
    /**
     * @param   bool      $intact        Every row checked, every link and keyed hash valid.
     * @param   int       $checkedRows   Rows checked.
     * @param   int|null  $firstBrokenId First row whose link or hash does not match, null when intact.
     * @param   string    $headHash      Hex hash of the newest row: the checksum shown in the backend only.
     */
    public function __construct(
        public readonly bool $intact,
        public readonly int $checkedRows,
        public readonly ?int $firstBrokenId,
        public readonly string $headHash
    ) {
    }
}
