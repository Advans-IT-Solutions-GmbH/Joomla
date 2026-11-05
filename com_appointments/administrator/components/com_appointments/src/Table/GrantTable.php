<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

/**
 * A grant of one calendar to one user. Writes go through the grant service, which computes `row_mac`.
 *
 * Work package C owns this class.
 *
 * @since  1.4.0
 */
class GrantTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_calendar_grants', 'id', $db);
    }

    /**
     * Every write goes through \Advans\Component\Appointments\Administrator\Service\Team\GrantService,
     * which checks the permission and computes `row_mac` over the verified
     * values. A store() or delete() through this class would bypass both, so
     * both are refused whatever the row carries.
     */
    public function check(): bool
    {
        return false;
    }

    public function store($updateNulls = false): bool
    {
        return false;
    }

    public function delete($pk = null): bool
    {
        return false;
    }
}
