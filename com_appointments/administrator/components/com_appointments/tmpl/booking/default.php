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
$item = $this->item;
if ($item === null) {
    echo '<p class="alert alert-info">' . Text::_('COM_APPOINTMENTS_BOOKINGS_SELECT') . '</p>';
    return;
}
$user = Factory::getApplication()->getIdentity();
$asset = 'com_appointments.eventtype.' . (int) $item->eventtype_id;
$labels = [
    'invitee_name' => 'COM_APPOINTMENTS_DETAIL_INVITEE_NAME', 'invitee_email' => 'COM_APPOINTMENTS_DETAIL_INVITEE_EMAIL',
    'invitee_phone' => 'COM_APPOINTMENTS_DETAIL_INVITEE_PHONE', 'guest_emails' => 'COM_APPOINTMENTS_DETAIL_GUEST_EMAILS',
    'start_utc' => 'COM_APPOINTMENTS_DETAIL_START_UTC', 'end_utc' => 'COM_APPOINTMENTS_DETAIL_END_UTC',
    'invitee_timezone' => 'COM_APPOINTMENTS_DETAIL_INVITEE_TIMEZONE', 'cancel_reason' => 'COM_APPOINTMENTS_DETAIL_CANCEL_REASON',
    'uid' => 'COM_APPOINTMENTS_DETAIL_UID', 'sequence' => 'COM_APPOINTMENTS_DETAIL_SEQUENCE',
    'anonymised_utc' => 'COM_APPOINTMENTS_DETAIL_ANONYMISED_UTC',
];
?>
<h2><?php echo $this->escape($item->eventtype_title); ?></h2>
<dl class="row">
    <?php foreach ($labels as $field => $label) : ?>
        <dt class="col-sm-3"><?php echo Text::_($label); ?></dt>
        <dd class="col-sm-9"><?php echo nl2br($this->escape((string) ($item->$field ?? ''))); ?></dd>
    <?php endforeach; ?>
    <?php foreach ($this->answers as $answer) : ?>
        <dt class="col-sm-3"><?php echo $this->escape($answer['label']); ?></dt>
        <dd class="col-sm-9"><?php echo nl2br($this->escape($answer['value'])); ?></dd>
    <?php endforeach; ?>
</dl>
<?php if ($item->conference_state === 'failed') : ?>
    <p class="alert alert-warning"><?php echo Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'); ?><br><?php echo $this->escape((string) $item->conference_error); ?></p>
<?php elseif ($this->hostUrl !== null) : ?>
    <p><a href="<?php echo $this->escape($this->hostUrl); ?>" rel="noreferrer noopener"><?php echo Text::_('COM_APPOINTMENTS_MAIL_JOIN'); ?></a></p>
<?php endif; ?>
<p><a href="<?php echo Route::_('index.php?option=com_appointments&task=feeds.export&id=' . (int) $item->id); ?>"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_EXPORT'); ?></a></p>
<?php if ($item->anonymised_utc === null && in_array((int) $item->state, [0, 1], true)) : ?>
    <form method="post" action="<?php echo Route::_('index.php?option=com_appointments'); ?>" class="mb-3">
        <input type="hidden" name="id" value="<?php echo (int) $item->id; ?>">
        <label for="booking-start"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_START_UTC'); ?></label>
        <input id="booking-start" class="form-control" type="datetime-local" name="start" required step="60" value="<?php echo $this->escape(substr(str_replace(' ', 'T', $item->start_utc), 0, 16)); ?>">
        <button type="submit" class="btn btn-primary mt-2" name="task" value="bookings.reschedule"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_RESCHEDULE'); ?></button>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
    <form method="post" action="<?php echo Route::_('index.php?option=com_appointments'); ?>" class="mb-3">
        <input type="hidden" name="cid[]" value="<?php echo (int) $item->id; ?>">
        <?php if ($user->authorise('core.edit.state', $asset)) : ?>
            <?php if ((int) $item->state === 0) : ?><button class="btn btn-success" name="task" value="bookings.approve"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_APPROVE'); ?></button><?php endif; ?>
            <button class="btn btn-warning" name="task" value="bookings.cancel"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_CANCEL'); ?></button>
        <?php endif; ?>
        <?php if ($item->conference_state === 'failed') : ?><button class="btn btn-secondary" name="task" value="bookings.retryConference"><?php echo Text::_('COM_APPOINTMENTS_CONFERENCE_RETRY'); ?></button><?php endif; ?>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>
<?php if ($item->anonymised_utc === null && $user->authorise('core.delete', $asset)) : ?>
    <form method="post" action="<?php echo Route::_('index.php?option=com_appointments'); ?>">
        <input type="hidden" name="cid[]" value="<?php echo (int) $item->id; ?>">
        <label><input type="checkbox" required name="confirm" value="1"> <?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_ERASE_CONFIRM'); ?></label>
        <button class="btn btn-danger" name="task" value="bookings.erase"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_ERASE'); ?></button>
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>
