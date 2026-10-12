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
use Joomla\CMS\Factory;
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

        return $form ?: false;
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
            $data['password_enc'] = '';
        }

        unset($data['password']);

        return parent::save($data);
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
            $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                ->update($db->quoteName('#__dmarc_reports'))
                ->set($db->quoteName('source_id') . ' = 0')
                ->whereIn($db->quoteName('source_id'), $ids);
            $db->setQuery($query)->execute();

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
