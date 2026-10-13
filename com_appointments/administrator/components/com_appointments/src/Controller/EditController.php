<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\FormController;

defined('_JEXEC') or die;

abstract class EditController extends FormController
{
    public function save($key = null, $urlVar = null)
    {
        if (!$this->checkSaveRequest($key, $urlVar)) {
            return false;
        }

        return parent::save($key, $urlVar);
    }

    protected function checkSaveRequest($key = null, $urlVar = null): bool
    {
        if (!$this->checkToken()) {
            return false;
        }

        $key = $urlVar ?: ($key ?: $this->getModel()->getTable()->getKeyName());
        $id  = $this->input->getInt($key);

        // FormController checks edit permission, but a direct POST must also
        // have acquired this record's checkout through the edit task.
        if ($id > 0 && !$this->checkEditId($this->option . '.edit.' . $this->context, $id)) {
            throw new \Exception(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 403);
        }

        return true;
    }
}
