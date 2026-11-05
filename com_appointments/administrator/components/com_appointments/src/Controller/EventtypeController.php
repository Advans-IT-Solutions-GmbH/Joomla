<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

defined('_JEXEC') or die;

class EventtypeController extends EditController
{
    protected $view_list = 'eventtypes';

    /**
     * Show the form again with the entered values, for the calendar pickers
     * after a host change. Saves nothing; an existing record must be held
     * through the edit task, like a save.
     */
    public function reload($key = null, $urlVar = null)
    {
        if (!$this->checkEditRequest($key, $urlVar)) {
            return false;
        }

        $id = $this->input->getInt($urlVar ?: ($key ?: 'id'));

        if (!($id > 0 ? $this->allowEdit(['id' => $id]) : $this->allowAdd())) {
            throw new \Exception(\Joomla\CMS\Language\Text::_('JLIB_APPLICATION_ERROR_EDIT_NOT_PERMITTED'), 403);
        }

        return parent::reload($key, $urlVar);
    }

    protected function allowEdit($data = [], $key = 'id')
    {
        return $this->app->getIdentity()->authorise('core.edit', 'com_appointments.eventtype.' . (int) ($data[$key] ?? 0));
    }
}
