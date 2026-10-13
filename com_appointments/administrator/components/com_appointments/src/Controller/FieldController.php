<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

defined('_JEXEC') or die;

class FieldController extends EditController
{
    protected $view_list = 'fields';

    protected function allowEdit($data = [], $key = 'id')
    {
        $item = $this->getModel()->getItem((int) ($data[$key] ?? 0));

        return $item && $this->app->getIdentity()->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id);
    }
}
