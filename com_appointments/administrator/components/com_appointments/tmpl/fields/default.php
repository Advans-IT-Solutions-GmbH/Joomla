<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$user = Factory::getApplication()->getIdentity();
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=fields'); ?>" method="post" name="adminForm" id="adminForm">
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_FIELDS_EMPTY'); ?></p>
    <?php else : ?>
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_FIELDS_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FIELD_LABEL'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FIELD_EVENTTYPE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FIELD_REQUIRED'); ?></th>
                <th scope="col"><?php echo Text::_('JFIELD_ORDERING_LABEL'); ?></th>
                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td>
                    <?php if ($user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id)) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_appointments&task=field.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->label); ?></a>
                    <?php else : ?>
                        <?php echo $this->escape($item->label); ?>
                    <?php endif; ?>
                    </td>
                    <td><?php echo $this->escape((string) $item->eventtype_title); ?></td>
                    <td><?php echo Text::_((int) $item->required ? 'JYES' : 'JNO'); ?></td>
                    <td><?php echo (int) $item->ordering; ?></td>
                    <td><?php echo Text::_((int) $item->published === 1 ? 'JPUBLISHED' : 'JUNPUBLISHED'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
