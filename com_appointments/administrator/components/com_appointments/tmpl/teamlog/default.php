<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Service\Team\AuditService;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\Appointments\Administrator\View\Teamlog\HtmlView $this */
?>
<?php if (!$this->enabled) : ?>
<div class="alert alert-info" role="status">
    <?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?>
</div>
<?php else : ?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=teamlog'); ?>" method="post" name="adminForm" id="adminForm">
    <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_INTRO'); ?></p>
    <p><strong><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_CHECKSUM'); ?></strong>
        <code class="text-break"><?php echo $this->escape($this->checksum); ?></code></p>
    <fieldset class="row g-2 mb-3">
        <legend><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_FILTERS'); ?></legend>
        <div class="col-md-4">
            <label for="filter_event"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_EVENT'); ?></label>
            <select id="filter_event" class="form-select" name="filter_event">
                <option value=""><?php echo Text::_('JALL'); ?></option>
                <?php foreach (AuditEvent::all() as $event) : ?>
                    <option value="<?php echo $this->escape($event); ?>" <?php echo (string) $this->state->get('filter.event') === $event ? 'selected' : ''; ?>><?php echo $this->escape($event); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="filter_user_id"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_USER_ID'); ?></label>
            <input id="filter_user_id" class="form-control" name="filter_user_id" inputmode="numeric" pattern="[0-9]{1,10}" maxlength="10" value="<?php echo $this->escape((string) $this->state->get('filter.user_id')); ?>">
        </div>
        <div><button class="btn btn-primary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
    </fieldset>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_LOG_TITLE'); ?></caption>
            <thead><tr>
                <th scope="col">#</th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_TIME'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_EVENT'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_ACTOR'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_SUBJECT'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_CALENDAR'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LOG_DETAILS'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $item) : ?>
                <tr>
                    <td><?php echo (int) $item->id; ?></td>
                    <td><?php echo $this->escape((string) $item->occurred_utc); ?></td>
                    <td><code><?php echo $this->escape((string) $item->event); ?></code></td>
                    <td><?php echo $item->actor_user_id === null ? '' : $this->escape((string) ($item->actor_name ?? '') . ' #' . (int) $item->actor_user_id); ?>
                        <?php echo $item->actor_device_id === null ? '' : '<br><small>' . Text::sprintf('COM_APPOINTMENTS_TEAM_F_LOG_DEVICE', (int) $item->actor_device_id) . '</small>'; ?></td>
                    <td><?php echo $item->subject_user_id === null ? '' : $this->escape((string) ($item->subject_name ?? '') . ' #' . (int) $item->subject_user_id); ?></td>
                    <td><?php echo $item->calendar_id === null ? '' : $this->escape((string) ($item->calendar_title ?? '') . ' #' . (int) $item->calendar_id); ?></td>
                    <td><?php echo $this->escape((string) ($item->object_ref ?? '')); ?>
                        <?php if ($item->details !== null) : ?><br><small><code class="text-break"><?php echo $this->escape(AuditService::decodeDetails($item->details) === null ? (string) $item->details : AuditService::encodeDetails(AuditService::decodeDetails($item->details))); ?></code></small><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
<?php endif; ?>