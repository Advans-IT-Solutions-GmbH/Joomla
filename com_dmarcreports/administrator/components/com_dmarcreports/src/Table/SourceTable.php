<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Table;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\ParameterType;

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
            $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_NO_NAME'));

            return false;
        }

        if ((int) $this->port <= 0) {
            $this->port = 993;
        }

        // A source may be left unbound (domain_id = 0) so ingestion maps each
        // report to the domain named in its XML. Any other value must reference
        // a real domain: a forged or stale id would otherwise be trusted as the
        // forced domain and file reports under a domain that does not exist.
        $this->domain_id = (int) $this->domain_id;

        if ($this->domain_id < 0 || ($this->domain_id > 0 && !$this->domainExists($this->domain_id))) {
            $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_INVALID_DOMAIN'));

            return false;
        }

        return true;
    }

    /**
     * Whether a domain row with the given id exists.
     */
    private function domainExists(int $domainId): bool
    {
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__dmarc_domains'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $domainId, ParameterType::INTEGER);
        $query->setLimit(1);

        return (int) $db->setQuery($query)->loadResult() > 0;
    }
}
