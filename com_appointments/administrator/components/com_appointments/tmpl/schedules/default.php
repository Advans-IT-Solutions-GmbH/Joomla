<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$canEdit = Factory::getApplication()->getIdentity()->authorise('core.edit', 'com_appointments');
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=schedules'); ?>" method="post"
      name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php if ($this->items === []) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_APPOINTMENTS_SCHEDULES_EMPTY'); ?>
                    </div>
                <?php else : ?>
                    <table class="table" id="scheduleList">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_APPOINTMENTS_SCHEDULES_TITLE'); ?>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center">
                                    <?php echo HTMLHelper::_('grid.checkall'); ?>
                                </td>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_FIELD_TITLE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_FIELD_TIMEZONE'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULE_FIELD_OWNER'); ?></th>
                                <th scope="col" class="text-center">
                                    <?php echo Text::_('COM_APPOINTMENTS_SCHEDULES_RULE_COUNT'); ?>
                                </th>
                                <th scope="col" class="text-center"><?php echo Text::_('JSTATUS'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <tr>
                                    <td class="text-center">
                                        <?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?>
                                    </td>
                                    <td>
                                        <?php if ($canEdit) : ?>
                                            <a href="<?php echo Route::_('index.php?option=com_appointments&task=schedule.edit&id=' . (int) $item->id); ?>">
                                                <?php echo $this->escape($item->title); ?>
                                            </a>
                                        <?php else : ?>
                                            <?php echo $this->escape($item->title); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $this->escape($item->timezone); ?></td>
                                    <td><?php echo $this->escape((string) $item->owner_name); ?></td>
                                    <td class="text-center"><?php echo (int) $item->rule_count; ?></td>
                                    <td class="text-center">
                                        <?php echo Text::_((int) $item->published === 1 ? 'JPUBLISHED' : 'JUNPUBLISHED'); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>
