<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;
$states = [0 => 'COM_APPOINTMENTS_BOOKINGS_PENDING', 1 => 'COM_APPOINTMENTS_BOOKINGS_CONFIRMED', 2 => 'COM_APPOINTMENTS_BOOKINGS_CANCELLED'];
$user = Factory::getApplication()->getIdentity();
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=bookings'); ?>" method="post" name="adminForm" id="adminForm">
    <fieldset class="row g-2 mb-3">
        <legend><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_FILTERS'); ?></legend>
        <div class="col-md-3">
            <label for="filter_search"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
            <input id="filter_search" class="form-control" name="filter_search" maxlength="200" value="<?php echo $this->escape($this->state->get('filter.search')); ?>">
        </div>
        <div class="col-md-3">
            <label for="filter_eventtype_id"><?php echo Text::_('COM_APPOINTMENTS_FIELD_EVENTTYPE'); ?></label>
            <select id="filter_eventtype_id" class="form-select" name="filter_eventtype_id">
                <option value=""><?php echo Text::_('JALL'); ?></option>
                <?php foreach ($this->events as $event) : ?>
                    <option value="<?php echo (int) $event['id']; ?>" <?php echo (string) $this->state->get('filter.eventtype_id') === (string) $event['id'] ? 'selected' : ''; ?>><?php echo $this->escape($event['title']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="filter_state"><?php echo Text::_('JSTATUS'); ?></label>
            <select id="filter_state" class="form-select" name="filter_state">
                <option value=""><?php echo Text::_('JALL'); ?></option>
                <?php foreach ($states as $value => $label) : ?>
                    <option value="<?php echo $value; ?>" <?php echo (string) $this->state->get('filter.state') === (string) $value ? 'selected' : ''; ?>><?php echo Text::_($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php foreach (['from' => 'COM_APPOINTMENTS_BOOKINGS_FROM', 'to' => 'COM_APPOINTMENTS_BOOKINGS_TO'] as $key => $label) : ?>
            <div class="col-md-2">
                <label for="filter_<?php echo $key; ?>"><?php echo Text::_($label); ?></label>
                <input type="date" class="form-control" id="filter_<?php echo $key; ?>" name="filter_<?php echo $key; ?>" value="<?php echo $this->escape($this->state->get('filter.' . $key)); ?>">
            </div>
        <?php endforeach; ?>
        <div><button class="btn btn-primary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
    </fieldset>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FIELD_EVENTTYPE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_START'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_CONTACT'); ?></th>
                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_EXPORT'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td><?php echo $this->escape($item->eventtype_title); ?></td>
                    <td><a href="<?php echo Route::_('index.php?option=com_appointments&view=booking&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->start_utc); ?></a></td>
                    <td><?php echo $this->escape((string) $item->invitee_name); ?><br><?php echo $this->escape((string) $item->invitee_email); ?></td>
                    <td><?php echo Text::_($states[(int) $item->state]); ?>
                        <?php if ($item->conference_state === 'failed') : ?>
                            <p class="text-danger"><?php echo Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'); ?><br><?php echo $this->escape((string) $item->conference_error); ?></p>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id)) : ?>
                            <a href="<?php echo Route::_('index.php?option=com_appointments&task=feeds.export&id=' . (int) $item->id); ?>"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_EXPORT'); ?></a>
                        <?php endif; ?>
                    </td>
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
