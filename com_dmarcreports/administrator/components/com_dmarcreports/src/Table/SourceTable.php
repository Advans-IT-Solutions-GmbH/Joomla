<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Table;

use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;

defined('_JEXEC') or die;

/**
 * Table for a report source (mailbox).
 *
 * @since  1.0.0
 */
class SourceTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__dmarc_sources', 'id', $db);
    }

    public function check(): bool
    {
        if (trim((string) $this->name) === '') {
            $this->setError(\Joomla\CMS\Language\Text::_('COM_DMARCREPORTS_SOURCE_ERROR_NO_NAME'));

            return false;
        }

        if ((int) $this->port <= 0) {
            $this->port = 993;
        }

        return true;
    }
}
