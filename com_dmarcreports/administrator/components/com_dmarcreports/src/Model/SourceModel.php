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
