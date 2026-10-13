<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Table;

use Advans\Component\DmarcReports\Administrator\Helper\DomainName;
use Joomla\CMS\Language\Text;
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
        if (trim((string) $this->domain, ". \t\r\n") === '') {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_NO_DOMAIN'));

            return false;
        }

        // Validate and canonicalize with the same rules ingestion applies to an
        // auto-detected policy domain, so a manual entry cannot store a value
        // the parser would reject and both paths share the 190-character limit.
        $domain = DomainName::canonicalize((string) $this->domain);

        if ($domain === null) {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_INVALID_DOMAIN'));

            return false;
        }

        $this->domain = $domain;

        if ($this->title === null || trim((string) $this->title) === '') {
            $this->title = $this->domain;
        }

        return true;
    }
}
