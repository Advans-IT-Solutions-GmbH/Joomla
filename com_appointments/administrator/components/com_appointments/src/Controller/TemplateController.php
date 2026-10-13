<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

defined('_JEXEC') or die;

class TemplateController extends EditController
{
    protected $view_list = 'templates';

    protected function allowEdit($data = [], $key = 'id')
    {
        $item = $this->getModel()->getItem((int) ($data[$key] ?? 0));

        if (!$item || empty($item->id)) {
            return false;
        }

        $user = $this->app->getIdentity();

        return (int) $item->eventtype_id === 0
            ? ($user->authorise('core.options', 'com_appointments') || $user->authorise('core.admin', 'com_appointments'))
            : $user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id);
    }
}
