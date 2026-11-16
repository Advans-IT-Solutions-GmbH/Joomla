<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;
$states = [0 => 'COM_APPOINTMENTS_BOOKINGS_PENDING', 1 => 'COM_APPOINTMENTS_BOOKINGS_CONFIRMED', 2 => 'COM_APPOINTMENTS_BOOKINGS_CANCELLED'];
$user = Factory::getApplication()->getIdentity();
$listOrder = $this->escape((string) $this->state->get('list.ordering'));
$listDirn = strtolower((string) $this->state->get('list.direction')) === 'asc' ? 'asc' : 'desc';
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=bookings'); ?>" method="post" name="adminForm" id="adminForm">
    <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_($this->activeFilters ? 'JGLOBAL_NO_MATCHING_RESULTS' : 'COM_APPOINTMENTS_BOOKINGS_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_FIELD_EVENTTYPE', 'e.title', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_BOOKINGS_START', 'b.start_utc', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'COM_APPOINTMENTS_BOOKINGS_CONTACT', 'b.invitee_last_name', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'b.state', $listDirn, $listOrder); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_EXPORT'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td><?php echo $this->escape($item->eventtype_title); ?></td>
                    <td><a href="<?php echo Route::_('index.php?option=com_appointments&view=booking&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->start_utc); ?></a></td>
                    <td><?php echo $this->escape((string) $item->invitee_name); ?><br><?php echo $this->escape((string) $item->invitee_email); ?>
                        <?php if (!empty($item->{\Advans\Component\Appointments\Administrator\Helper\BookingData::UNVERIFIED})) : ?>
                            <br><span class="badge bg-warning text-dark"><?php echo Text::_('COM_APPOINTMENTS_TEAM_E_BOOKING_UNVERIFIED'); ?></span>
                        <?php endif; ?></td>
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
