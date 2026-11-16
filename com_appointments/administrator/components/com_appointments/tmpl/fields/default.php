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
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;
$user = Factory::getApplication()->getIdentity();
$listOrder = $this->escape((string) $this->state->get('list.ordering'));
$listDirn = strtolower((string) $this->state->get('list.direction')) === 'desc' ? 'desc' : 'asc';
$types = [
    'text' => 'COM_APPOINTMENTS_FIELD_TEXT',
    'textarea' => 'COM_APPOINTMENTS_FIELD_TEXTAREA',
    'number' => 'COM_APPOINTMENTS_FIELD_NUMBER',
    'tel' => 'COM_APPOINTMENTS_FIELD_TEL',
    'email' => 'COM_APPOINTMENTS_FIELD_EMAIL',
    'url' => 'COM_APPOINTMENTS_FIELD_URL',
    'radio' => 'COM_APPOINTMENTS_FIELD_RADIO',
    'checkboxes' => 'COM_APPOINTMENTS_FIELD_CHECKBOXES',
    'checkbox' => 'COM_APPOINTMENTS_FIELD_CHECKBOX',
    'date' => 'COM_APPOINTMENTS_FIELD_DATE',
];
$saveOrderingUrl = '';

if ($this->dragOrdering && $this->items) {
    // Rows move only within their event type (data-draggable-group), as in the core article list.
    $saveOrderingUrl = 'index.php?option=com_appointments&task=fields.saveOrderAjax&tmpl=component&' . Session::getFormToken() . '=1';
    HTMLHelper::_('draggablelist.draggable');
}
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=fields'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_($this->activeFilters ? 'JGLOBAL_NO_MATCHING_RESULTS' : 'COM_APPOINTMENTS_FIELDS_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table" id="fieldList">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_FIELDS_TITLE'); ?></caption>
            <thead><tr>
                <td class="w-1 text-center"><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col" class="w-1 text-center"><?php echo HTMLHelper::_('searchtools.sort', '', 'f.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-sort'); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_FIELD_LABEL', 'f.label', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_FIELD_EVENTTYPE', 'e.title', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_FIELD_TYPE', 'f.type', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_FIELD_REQUIRED', 'f.required', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'f.published', $listDirn, $listOrder); ?></th>
                <th scope="col" class="w-1"><?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'f.id', $listDirn, $listOrder); ?></th>
            </tr></thead>
            <tbody<?php if ($saveOrderingUrl !== '') : ?> class="js-draggable" data-url="<?php echo $this->escape($saveOrderingUrl); ?>" data-direction="<?php echo $listDirn; ?>" data-nested="true"<?php endif; ?>>
            <?php foreach ($this->items as $i => $item) : ?>
                <?php $canChange = $user->authorise('core.edit.state', 'com_appointments.eventtype.' . (int) $item->eventtype_id); ?>
                <tr data-draggable-group="<?php echo (int) $item->eventtype_id; ?>">
                    <td class="text-center"><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td class="text-center">
                        <?php if (!$canChange) : ?>
                            <span class="sortable-handler inactive"><span class="icon-ellipsis-v" aria-hidden="true"></span></span>
                        <?php elseif ($saveOrderingUrl === '') : ?>
                            <span class="sortable-handler inactive" title="<?php echo $this->escape(Text::_('JORDERINGDISABLED')); ?>"><span class="icon-ellipsis-v" aria-hidden="true"></span></span>
                        <?php else : ?>
                            <span class="sortable-handler"><span class="icon-ellipsis-v" aria-hidden="true"></span></span>
                            <input type="text" name="order[]" size="5" value="<?php echo (int) $item->ordering; ?>" class="width-20 text-area-order hidden" aria-label="<?php echo $this->escape(Text::_('JFIELD_ORDERING_LABEL')); ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                    <?php if ($user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id)) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_appointments&task=field.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->label); ?></a>
                    <?php else : ?>
                        <?php echo $this->escape($item->label); ?>
                    <?php endif; ?>
                    </td>
                    <td><?php echo $this->escape((string) $item->eventtype_title); ?></td>
                    <td><?php echo isset($types[(string) $item->type]) ? Text::_($types[(string) $item->type]) : $this->escape((string) $item->type); ?></td>
                    <td><?php echo Text::_((int) $item->required ? 'JYES' : 'JNO'); ?></td>
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
