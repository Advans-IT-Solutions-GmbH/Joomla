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
 * Table for a monitored domain.
 *
 * @since  1.0.0
 */
class DomainTable extends Table
{
    public function __construct(DatabaseDriver $db)
    {
        parent::__construct('#__dmarc_domains', 'id', $db);
    }

    public function check(): bool
    {
        $this->domain = strtolower(trim((string) $this->domain, ". \t"));

        if ($this->domain === '') {
            $this->setError(\Joomla\CMS\Language\Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_NO_DOMAIN'));

            return false;
        }

        if ($this->title === null || trim((string) $this->title) === '') {
            $this->title = $this->domain;
        }

        return true;
    }
}
