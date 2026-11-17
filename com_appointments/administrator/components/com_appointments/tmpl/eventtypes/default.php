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
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$user = Factory::getApplication()->getIdentity();
$listOrder = $this->escape((string) $this->state->get('list.ordering'));
$listDirn = strtolower((string) $this->state->get('list.direction')) === 'desc' ? 'desc' : 'asc';
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=eventtypes'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_($this->activeFilters ? 'JGLOBAL_NO_MATCHING_RESULTS' : 'COM_APPOINTMENTS_EVENTTYPES_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table" id="eventtypeList">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'); ?></caption>
            <thead><tr>
                <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JGLOBAL_TITLE', 'e.title', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_EVENTTYPE_DURATION', 'e.duration_minutes', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_EVENTTYPE_SCHEDULE', 's.title', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JFIELD_ORDERING_LABEL', 'e.ordering', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'e.published', $listDirn, $listOrder); ?></th>
                <th scope="col" class="w-1"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'e.id', $listDirn, $listOrder); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
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
                    <td><?php echo (int) $item->id; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
