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

        // The notes column is a TEXT field (max 65535 bytes). Reject an
        // over-long value here with a readable message instead of letting the
        // database raise a raw truncation error on store.
        if ($this->notes !== null && strlen((string) $this->notes) > 65535) {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_NOTES_TOO_LONG'));

            return false;
        }

        // The domain column carries a UNIQUE key. Detect a duplicate before the
        // store so the operator sees a localized "already monitored" message
        // rather than the database unique-key violation.
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
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

    /**
     * Store the row, translating the unique-key violation that the pre-flight
     * duplicate check in {@see self::check()} cannot rule out. That check and the
     * INSERT are not atomic, so two concurrent creates for the same canonical
     * domain can both pass it and one still hit the UNIQUE constraint. Catch that
     * race at the authoritative store boundary and surface the same localized
     * "already monitored" message instead of leaking the raw database error.
     *
     * @param   bool  $updateNulls  True to update null values as well.
     *
     * @return  bool  True on success.
     */
    public function store($updateNulls = false): bool
    {
        try {
            $result = parent::store($updateNulls);
        } catch (\RuntimeException $e) {
            if ($this->isDuplicateKeyError($e)) {
                $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_DUPLICATE'));

                return false;
            }

            throw $e;
        }

        // On the supported Joomla 5.4/6 range Table::store() uses the legacy
        // error mode by default: it catches the database exception, records the
        // raw message with setError() and returns false instead of throwing. So
        // the duplicate-key race normally lands here, not in the catch above —
        // inspect the stored error and translate it to the same localized
        // "already monitored" message rather than leaking the raw SQL error.
        if ($result === false && $this->messageLooksLikeDuplicate((string) $this->getError())) {
            $this->setError(Text::_('COM_DMARCREPORTS_DOMAIN_ERROR_DUPLICATE'));
        }

        return $result;
    }

    /**
     * Whether a store failure is a duplicate-key (UNIQUE) violation. The domain
     * column carries the only non-primary unique key on this table, so a
     * duplicate-entry error can only mean the domain is already monitored.
     *
     * @param   \Throwable  $e  The caught store exception.
     */
    private function isDuplicateKeyError(\Throwable $e): bool
    {
        foreach ([$e, $e->getPrevious()] as $throwable) {
            if ($throwable === null) {
                continue;
            }

            $code = (string) $throwable->getCode();

            // Match the MySQL driver duplicate-entry code specifically. The
            // SQLSTATE 23000 class also covers NOT NULL and other integrity
            // violations, so it must not be treated as a duplicate on its own.
            if ($code === '1062') {
                return true;
            }

            if ($this->messageLooksLikeDuplicate($throwable->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an error message is a MySQL duplicate-entry (UNIQUE) violation,
     * used to translate both the thrown exception and the legacy false-return
     * error string Table::store() records. Matches the driver error number 1062
     * or the duplicate-entry text only — the generic SQLSTATE 23000 class also
     * covers NOT NULL and other integrity errors and must not be matched here.
     */
    private function messageLooksLikeDuplicate(string $message): bool
    {
        return stripos($message, 'Duplicate entry') !== false
            || preg_match('/\b1062\b/', $message) === 1;
    }
}
