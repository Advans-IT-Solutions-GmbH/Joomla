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
<form action="<?php echo Route::_('index.php?option=com_appointments&view=eventtypes'); ?>" method="post" name="adminForm" id="adminForm">
    <label for="filter_search"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
    <input id="filter_search" name="filter_search" value="<?php echo $this->escape((string) $this->state->get('filter.search')); ?>">
    <button type="submit" class="btn btn-primary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_EMPTY'); ?></p>
    <?php else : ?>
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPE_DURATION'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPE_SCHEDULE'); ?></th>
                <th scope="col"><?php echo Text::_('JFIELD_ORDERING_LABEL'); ?></th>
                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td>
                    <?php if ($user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->id)) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_appointments&task=eventtype.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->title); ?></a>
                    <?php else : ?>
                        <?php echo $this->escape($item->title); ?>
                    <?php endif; ?>
                    </td>
                    <td><?php echo (int) $item->duration_minutes; ?></td>
                    <td><?php echo $this->escape((string) $item->schedule_title); ?></td>
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
