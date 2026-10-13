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

        if ($parentIds === []) {
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
