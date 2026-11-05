<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\FormModel;

defined('_JEXEC') or die;

/**
 * The form of an internet calendar's settings. It only renders: reading and
 * writing go through the calendar service, which checks the rights and the row
 * MAC, so this model has no table and no save().
 *
 * @since  1.4.0
 */
class CalendarModel extends FormModel
{
    public function getForm($data = [], $loadData = false)
    {
        $form = $this->loadForm('com_appointments.calendar', 'calendar', ['control' => 'jform', 'load_data' => false]);

        if ($form && $data) {
            $form->bind($data);
        }

        return $form;
    }
}
