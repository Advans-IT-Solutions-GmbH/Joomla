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
 * An invitation of an external user. Only a keyed hash of the token is stored.
 *
 * Work package C owns this class.
 *
 * @since  1.4.0
 */
class InvitationTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__appointments_invitations', 'id', $db);
    }

    public function check(): bool
    {
        // The service that owns this table validates and MACs every field
        // before a store(). Until it exists, nothing is stored through here.
        return (string) ($this->row_mac ?? '') !== '' && parent::check();
    }
}
