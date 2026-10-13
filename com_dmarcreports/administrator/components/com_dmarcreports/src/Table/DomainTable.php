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
use Joomla\Database\ParameterType;

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

        // The notes column is a TEXT field (65535 bytes). Reject an over-long
        // value here with a readable message instead of letting the database
        // raise a raw truncation error on store.
        if ($this->notes !== null && strlen((string) $this->notes) > 65535) {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_NOTES_TOO_LONG'));

            return false;
        }

        // The domain column carries a UNIQUE key. Detect a duplicate before the
        // store so the operator sees a localized "already monitored" message
        // rather than the database unique-key violation.
        $db    = $this->getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__dmarc_domains'))
            ->where($db->quoteName('domain') . ' = :domain')
            ->bind(':domain', $this->domain, ParameterType::STRING);

        $currentId = (int) $this->id;

        if ($currentId > 0) {
            $query->where($db->quoteName('id') . ' != :id')
                ->bind(':id', $currentId, ParameterType::INTEGER);
        }

        $query->setLimit(1);
        $db->setQuery($query);

        if ((int) $db->loadResult() > 0) {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_DUPLICATE'));

            return false;
        }

        return true;
    }
}
