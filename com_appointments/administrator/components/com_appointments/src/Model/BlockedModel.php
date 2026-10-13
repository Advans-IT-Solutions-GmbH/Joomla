<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;

defined('_JEXEC') or die;

/**
 * Admin model for a single blocked period.
 *
 * @since  1.0.0
 */
class BlockedModel extends AdminModel
{
    public function getTable($name = 'Blocked', $prefix = 'Administrator', $options = [])
    {
        return parent::getTable($name, $prefix, $options);
    }

    public function getItem($pk = null)
    {
        $item = parent::getItem($pk);

        if ($item && empty($item->id) && (int) $item->user_id === 0) {
            // A new absence belongs to whoever is entering it, which is the
            // common case; the field stays editable for a manager entering one
            // for a colleague.
            $item->user_id = (int) Factory::getApplication()->getIdentity()->id;
        }

        return $item;
    }

    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm(
            'com_appointments.blockedperiod',
            'blockedperiod',
            ['control' => 'jform', 'load_data' => $loadData]
        );

        return empty($form) ? false : $form;
    }

    protected function loadFormData()
    {
        $data = Factory::getApplication()->getUserState('com_appointments.edit.blocked.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }
}
