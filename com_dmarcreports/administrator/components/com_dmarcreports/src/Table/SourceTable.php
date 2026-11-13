<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Table;

use Advans\Component\DmarcReports\Administrator\Helper\DomainLock;
use Advans\Component\DmarcReports\Administrator\Helper\SourceIdentity;
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
    /**
     * Credential snapshot the next store() must still find, or null. Private
     * and with a leading underscore, so neither the database driver nor
     * getProperties() treats it as a column.
     *
     * @var  array<string, mixed>|null
     */
    private ?array $_credentialSnapshot = null;

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

        // Apply the default port only when none was supplied. Any supplied
        // value is validated strictly here — the form range rule covers the UI,
        // this backstop covers every path that writes the table directly — so a
        // non-numeric string, 0, a negative value or an out-of-range number is
        // rejected instead of being silently coerced to the default.
        if ($this->port === null || $this->port === '') {
            $this->port = 993;
        } elseif (preg_match('/^\d+$/', (string) $this->port) !== 1 || (int) $this->port < 1 || (int) $this->port > 65535) {
            // Require a plain decimal integer: is_numeric() would also accept
            // decimal ("1.5") and exponent ("1e2") notation and silently coerce
            // them to a different port than the operator submitted.
            $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_INVALID_PORT'));

            return false;
        } else {
            $this->port = (int) $this->port;
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
     * Store the source, taking the shared parent-domain lock when the source is
     * bound to a domain so a concurrent domain delete cannot clear the sources
     * table and then remove the domain while this row is written, which would
     * leave it orphaned. When the source is moved between domains (or unbound),
     * both the origin and destination parents are locked: pinning the origin
     * stops a concurrent delete of the old parent from cascading this row away
     * between the read and the write and leaving an UPDATE that touches no row
     * yet still reports success. The two parents are locked in ascending id order
     * so two concurrent moves of the same pair cannot deadlock. An unbound source
     * (domain_id = 0) that was already unbound has no parent to protect and is
     * stored directly. See {@see DomainLock} for the protocol.
     *
     * @param   boolean  $updateNulls  Whether null values are updated.
     *
     * @return  boolean
     */
    public function store($updateNulls = false)
    {
        // Taken once: a later store() of the same instance is not guarded.
        $guard                    = $this->_credentialSnapshot;
        $this->_credentialSnapshot = null;

        $newDomainId = (int) $this->domain_id;
        $oldDomainId = $this->currentDomainId();

        // Positive parents that must be pinned for the write: the destination and,
        // for a move, the origin. Deduplicate and sort ascending for a stable lock
        // order shared with the delete cascade's IN (...) FOR UPDATE.
        $parentIds = [];

        if ($newDomainId > 0) {
            $parentIds[$newDomainId] = true;
        }

        if ($oldDomainId > 0) {
            $parentIds[$oldDomainId] = true;
        }

        if ($parentIds === [] && $guard === null) {
            return parent::store($updateNulls);
        }

        $parentIds = array_keys($parentIds);
        sort($parentIds);

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            foreach ($parentIds as $parentId) {
                $locked = DomainLock::lock($db, $parentId);

                // The destination domain must exist; a missing target is invalid
                // and rejects the whole save. A missing origin is acceptable here
                // (its delete may already have run) and is caught below by the
                // row-existence check rather than reported as an invalid domain.
                if (!$locked && $parentId === $newDomainId) {
                    $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_INVALID_DOMAIN'));
                    $db->transactionRollback();

                    return false;
                }
            }

            // With the origin parent pinned, confirm an existing row still exists:
            // a concurrent delete of the old parent may already have cascaded this
            // source away, in which case parent::store() would UPDATE no row yet
            // still return true and silently drop the change.
            if ((int) $this->id > 0 && !$this->rowExists((int) $this->id)) {
                $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_DELETED'));
                $db->transactionRollback();

                return false;
            }

            // A save that keeps the stored password: lock the row (after the
            // parent domains, the order the delete cascade uses) and re-read
            // target and ciphertext with that locking read. If a concurrent
            // save changed either since the snapshot, refuse, so one server's
            // password is never written next to another server's target.
            if ($guard !== null && !$this->credentialStillMatches($guard)) {
                $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_PASSWORD_REENTER'));
                $db->transactionRollback();

                return false;
            }

            if (!parent::store($updateNulls)) {
                $db->transactionRollback();

                return false;
            }

            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        return true;
    }

    /**
     * The domain_id currently stored for this row, or 0 when the row is new or no
     * longer present. Read before locking so a move can also pin the origin
     * parent domain.
     */
    private function currentDomainId(): int
    {
        $id = (int) $this->id;

        if ($id <= 0) {
            return 0;
        }

        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('domain_id'))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $query->setLimit(1);

        $value = $db->setQuery($query)->loadResult();

        return $value === null ? 0 : (int) $value;
    }

    /**
     * Require that target and stored ciphertext are still those of the given
     * snapshot when this row is written. Used by a save that keeps the stored
     * password (blank password field).
     *
     * @param   array<string, mixed>  $snapshot  Credential target fields and password_enc.
     */
    public function requireUnchangedCredential(array $snapshot): void
    {
        $this->_credentialSnapshot = $snapshot;
    }

    /**
     * Lock this source row and compare its current credential with the
     * snapshot. The locking read returns the latest committed row version, not
     * an older read view of the transaction. A missing row does not match.
     *
     * @param   array<string, mixed>  $snapshot
     */
    private function credentialStillMatches(array $snapshot): bool
    {
        $id = (int) $this->id;

        if ($id <= 0) {
            return false;
        }

        $db      = $this->getDatabase();
        $columns = array_map(
            static fn (string $column): string => $db->quoteName($column),
            array_merge(SourceIdentity::CREDENTIAL_TARGET_FIELDS, ['password_enc'])
        );

        $sql = 'SELECT ' . implode(', ', $columns)
            . ' FROM ' . $db->quoteName('#__dmarc_sources')
            . ' WHERE ' . $db->quoteName('id') . ' = ' . $id
            . ' FOR UPDATE';

        $row = $db->setQuery($sql)->loadAssoc();

        return \is_array($row) && SourceIdentity::sameCredential($snapshot, $row);
    }

    /**
     * Whether the source row with the given id still exists.
     */
    private function rowExists(int $id): bool
    {
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $query->setLimit(1);

        return (int) $db->setQuery($query)->loadResult() === $id;
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
