<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=schedule&layout=edit&id=' . (int) $this->item->id); ?>"
      method="post" name="adminForm" id="schedule-form" class="form-validate">
    <div class="row">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('details'); ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_WEEKLY_HEADING'); ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_WEEKLY_INTRO'); ?></p>
                    <?php echo $this->form->renderFieldset('weekly'); ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_HEADING'); ?></h2>
                </div>
                <div class="card-body">
                    <p class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_OVERRIDES_INTRO'); ?></p>
                    <?php echo $this->form->renderFieldset('overrides'); ?>
                </div>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="card">
                <div class="card-body">
                    <?php echo $this->form->renderFieldset('state'); ?>
                </div>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
