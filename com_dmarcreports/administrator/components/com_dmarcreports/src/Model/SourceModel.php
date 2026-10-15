<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Advans\Component\DmarcReports\Administrator\Helper\CryptoHelper;
use Advans\Component\DmarcReports\Administrator\Helper\ReportProvenance;
use Advans\Component\DmarcReports\Administrator\Helper\SourceIdentity;
use Advans\Component\DmarcReports\Administrator\Helper\SourceLock;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\AdminModel;

defined('_JEXEC') or die;

/**
 * Admin model for a single report source. The mailbox password is encrypted
 * before storage and never returned to the edit form in clear text.
 *
 * @since  1.0.0
 */
class SourceModel extends AdminModel
{
    public function getTable($name = 'Source', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_dmarcreports.source',
            'source',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        if (empty($form)) {
            return false;
        }

        // Without core.edit.state the published field must not be changeable from
        // the edit form. Disabling it hides the control, and unsetting its filter
        // drops the value from the posted data on save, so the restriction is
        // enforced server-side and cannot be bypassed by crafting the request.
        if (!$this->canEditState((object) $data)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        return $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_dmarcreports.edit.source.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        } else {
            // On a validation redisplay Joomla hands back the submitted jform,
            // which would carry the plaintext mailbox password in the session and
            // repopulate the field. Strip the credential so it is never kept in
            // user state nor echoed back; the operator re-enters it to change it.
            if (\is_array($data)) {
                unset($data['password'], $data['password_enc']);
            } elseif (\is_object($data)) {
                unset($data->password, $data->password_enc);
            }
        }

        return $data;
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        // Never expose the stored credential to the form.
        if (\is_object($item) && isset($item->password_enc)) {
            $item->password_enc = '';
            $item->password     = '';
        }

        return $item;
    }

    public function save($data)
    {
        $table = $this->getTable();
        $key   = $table->getKeyName();
        $pk    = (int) ($data[$key] ?? $this->getState($this->getName() . '.id'));

        $newPassword = (string) ($data['password'] ?? '');

        if ($newPassword !== '') {
            $data['password_enc'] = CryptoHelper::encrypt($newPassword);
        } elseif ($pk > 0) {
            // Keep the existing credential when the field is left blank on edit.
            $current = $this->getExistingPassword($pk);
            $data['password_enc'] = $current;
        } else {
            // A new source must carry a credential. The blank-means-unchanged
            // shortcut above only applies to edits; a create saved with an empty
            // password (and sources default to published) would fail IMAP LOGIN
            // on every run, so reject it with a localized validation error.
            $this->setError(Text::_('COM_DMARCREPORTS_SOURCE_ERROR_PASSWORD_REQUIRED'));

            return false;
        }

        unset($data['password']);

        if ($pk > 0) {
            // Moving a source to a different mailbox invalidates its stored UID
            // cursor: the new mailbox's UIDs are unrelated, so resuming above
            // the old high-water UID would permanently skip every lower-UID
            // report there (a coincidentally matching UIDVALIDITY would not
            // trigger the client's own reset). Reset the cursor whenever the
            // mailbox identity changes. An in-flight poll of the old mailbox
            // cannot revive the cursor afterwards, because it re-checks the
            // identity under a row lock before advancing it (see
            // IngestController::recordRun()). The parent-domain lock that guards
            // this write against a concurrent domain delete is taken one layer
            // down, in SourceTable::store().
            $existing = $this->getExistingIdentity($pk);

            if ($existing !== null && SourceIdentity::changed($existing, $data)) {
                $data['uidvalidity'] = 0;
                $data['last_uid']    = 0;
                // The retry set holds UIDs from the previous mailbox, so it is
                // meaningless once the mailbox changes: clear it with the cursor.
                $data['deferred_uids'] = '';
            }
        }

        return parent::save($data);
    }

    /**
     * Load the mailbox identity columns of an existing source so a save can
     * detect a change of mailbox and reset the UID cursor.
     *
     * @return  array<string, mixed>|null
     */
    private function getExistingIdentity(int $pk): ?array
    {
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName(SourceIdentity::FIELDS))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $pk, \Joomla\Database\ParameterType::INTEGER);

        $row = $db->setQuery($query)->loadAssoc();

        return \is_array($row) ? $row : null;
    }

    /**
     * Delete sources while preserving their reports. Each report keeps a
     * nonzero source_id pointing at its mailbox; before the source row is
     * removed its reports are reset to the 0 sentinel (the same value used for
     * manually uploaded reports), so no report is left referencing a source
     * that no longer exists. Both steps run in one transaction.
     *
     * @param   array  $pks  Primary keys of the sources to delete.
     *
     * @return  boolean
     */
    public function delete(&$pks)
    {
        $pks = (array) $pks;
        $ids = array_values(array_filter(array_map('intval', $pks), static fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return parent::delete($pks);
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            // Lock the source rows before resetting their reports so an
            // in-flight ingestion that is about to write a report for one of
            // these sources must wait, then observe the source as deleted and
            // record its report unbound instead of leaving dangling provenance.
            SourceLock::lockMany($db, $ids);

            // Unbind the provenance of every report (DMARC and TLS-RPT) that
            // points at these sources in the same transaction; otherwise a
            // deleted source would leave dangling source_id references. The set
            // of provenance-bearing tables is owned by {@see ReportProvenance}
            // so the domain-cascade delete stays in lockstep with this path.
            ReportProvenance::unbindSources($db, $ids);

            // Remove the source rows inside the same transaction, after their
            // provenance has been unbound, so a source is only ever deleted
            // together with the report references that pointed at it.
            $result = parent::delete($pks);

            if ($result === false) {
                $db->transactionRollback();

                return false;
            }

            $db->transactionCommit();

            return $result;
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    private function getExistingPassword(int $pk): string
    {
        $db    = $this->getDatabase();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('password_enc'))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $pk, \Joomla\Database\ParameterType::INTEGER);

        return (string) $db->setQuery($query)->loadResult();
    }
}
