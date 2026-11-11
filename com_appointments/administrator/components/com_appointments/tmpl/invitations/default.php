<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/** @var \Advans\Component\Appointments\Administrator\View\Invitations\HtmlView $this */

if (!$this->enabled) : ?>
    <div class="alert alert-info" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?></div>
    <?php return; ?>
<?php endif;

$action = Route::_('index.php?option=com_appointments&view=invitations');
$stateKeys = [
    TeamStates::INVITATION_OPEN      => 'COM_APPOINTMENTS_TEAM_C_INVITATION_STATE_OPEN',
    TeamStates::INVITATION_COMPLETED => 'COM_APPOINTMENTS_TEAM_C_INVITATION_STATE_COMPLETED',
    TeamStates::INVITATION_CLOSED    => 'COM_APPOINTMENTS_TEAM_C_INVITATION_STATE_CLOSED',
];
$date = static fn (?string $utc): string => $utc ? HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC5')) : '–';
?>
<?php if (!$this->available) : ?>
    <div class="alert alert-warning" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE'); ?></div>
<?php endif; ?>

<section class="card mb-4" aria-labelledby="invite-heading">
    <div class="card-body">
        <h2 id="invite-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INVITE_HEADING'); ?></h2>
        <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INVITE_DESC'); ?></p>
        <?php if (!$this->candidates) : ?>
            <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INVITE_NO_ACCOUNT'); ?></p>
        <?php else : ?>
            <form action="<?php echo $action; ?>" method="post" class="row g-2 align-items-end">
                <div class="col-md-8">
                    <label class="form-label" for="invite-user"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_USER'); ?></label>
                    <select class="form-select" id="invite-user" name="user_id" required>
                        <option value=""><?php echo Text::_('JSELECT'); ?></option>
                        <?php foreach ($this->candidates as $account) : ?>
                            <option value="<?php echo (int) $account->id; ?>"><?php echo $this->escape($account->name . ' (' . $account->username . ')'); ?> – <?php echo Text::_($account->audience === 'external' ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary" name="task" value="invitations.invite"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INVITE_BUTTON'); ?></button>
                </div>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        <?php endif; ?>
    </div>
</section>

<?php if (!$this->items) : ?>
    <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INVITATIONS_EMPTY'); ?></p>
<?php else : ?>
    <div class="table-responsive">
    <table class="table">
        <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_INVITATIONS_TITLE'); ?></caption>
        <thead><tr>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_USER'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_EMAIL'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_STATE'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_SENT'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_EXPIRES'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_COMPLETED'); ?></th>
            <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ACTIONS'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($this->items as $item) : ?>
            <tr>
                <th scope="row">
                    <?php echo $this->escape(trim((string) $item->user_name . ' (' . (string) $item->user_username . ')')); ?>
                    <br><small><?php echo Text::_($item->audience === 'external' ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></small>
                    <?php if (!$item->mac_valid) : ?><span class="badge bg-danger"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INTEGRITY_FAILED'); ?></span><?php endif; ?>
                    <?php if ((int) $item->user_block === 1) : ?><span class="badge bg-secondary"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ACCOUNT_BLOCKED'); ?></span><?php endif; ?>
                </th>
                <td><?php echo $this->escape($item->email_masked); ?></td>
                <td><?php echo Text::_($stateKeys[(int) $item->state] ?? 'COM_APPOINTMENTS_TEAM_C_INVITATION_STATE_CLOSED'); ?></td>
                <td><?php echo $date($item->created_utc); ?></td>
                <td><?php echo $date($item->expires_utc); ?></td>
                <td><?php echo $date($item->completed_utc); ?></td>
                <td>
                    <?php if ($item->mac_valid && \in_array((int) $item->state, [TeamStates::INVITATION_OPEN, TeamStates::INVITATION_COMPLETED], true)) : ?>
                        <a class="btn btn-danger btn-sm" href="<?php echo Route::_('index.php?option=com_appointments&view=invitations&layout=revoke&id=' . (int) $item->id); ?>"><?php echo Text::_((int) $item->state === TeamStates::INVITATION_OPEN ? 'COM_APPOINTMENTS_TEAM_C_INVITATION_CLOSE' : 'COM_APPOINTMENTS_TEAM_C_REVOKE_BUTTON'); ?></a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
