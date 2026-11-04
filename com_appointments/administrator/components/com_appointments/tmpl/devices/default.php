<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Model\DevicesModel;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\Appointments\Administrator\View\Devices\HtmlView $this */
// The client labels of work package D, when it provides them.
$labels = 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\DeviceService::CLIENT_LABELS';
$clients = \defined($labels) ? (array) \constant($labels) : [];
?>
<?php if (!$this->enabled) : ?>
<div class="alert alert-info" role="status">
    <?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?>
</div>
<?php else : ?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=devices'); ?>" method="post" name="adminForm" id="adminForm">
    <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_INTRO'); ?></p>
    <fieldset class="row g-2 mb-3">
        <legend><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_FILTERS'); ?></legend>
        <div class="col-md-4">
            <label for="filter_search"><?php echo Text::_('JSEARCH_FILTER'); ?></label>
            <input id="filter_search" class="form-control" name="filter_search" maxlength="100" value="<?php echo $this->escape((string) $this->state->get('filter.search')); ?>">
        </div>
        <div class="col-md-3">
            <label for="filter_state"><?php echo Text::_('JSTATUS'); ?></label>
            <select id="filter_state" class="form-select" name="filter_state">
                <option value=""><?php echo Text::_('JALL'); ?></option>
                <?php foreach (DevicesModel::STATES as $value => $label) : ?>
                    <option value="<?php echo (int) $value; ?>" <?php echo (string) $this->state->get('filter.state') === (string) $value ? 'selected' : ''; ?>><?php echo Text::_($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div><button class="btn btn-primary" type="submit"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button></div>
    </fieldset>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_DEVICES_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_DEVICES_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_DEVICE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_USER'); ?></th>
                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_LAST_SYNC'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_CREATED'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_F_RULE'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td><?php echo $this->escape((string) $item->name); ?><br><small><?php echo isset($clients[(string) $item->client]) ? Text::_($clients[(string) $item->client]) : $this->escape((string) $item->client); ?></small></td>
                    <td><?php echo $this->escape((string) ($item->user_name ?? '')); ?><br><small><?php echo $this->escape((string) ($item->user_username ?? '#' . (int) $item->user_id)); ?></small></td>
                    <td><?php echo Text::_(DevicesModel::STATES[(int) $item->state] ?? 'COM_APPOINTMENTS_TEAM_F_DEVICE_REVOKED'); ?>
                        <?php if ($item->cleanup_started_utc !== null && !in_array((int) $item->state, [TeamStates::DEVICE_ACTIVE, TeamStates::DEVICE_REVOKED], true)) : ?>
                            <br><small><?php echo Text::sprintf('COM_APPOINTMENTS_TEAM_F_PHASE_SINCE', $this->escape((string) $item->cleanup_started_utc)); ?></small>
                        <?php endif; ?>
                        <?php if ($item->revoked_utc !== null) : ?>
                            <br><small><?php echo $this->escape((string) $item->revoked_utc . ' ' . (string) $item->revoke_reason); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $this->escape((string) ($item->last_sync_utc ?? Text::_('COM_APPOINTMENTS_TEAM_F_NEVER'))); ?></td>
                    <td><?php echo $this->escape((string) $item->created_utc); ?></td>
                    <td><?php echo $this->escape((string) $item->rule_version . ', ' . (string) $item->rule_accepted_utc); ?></td>
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
<?php endif; ?>