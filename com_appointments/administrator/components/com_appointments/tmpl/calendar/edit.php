<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/** @var \Advans\Component\Appointments\Administrator\View\Calendar\HtmlView $this */

if (!$this->enabled || !$this->available) : ?>
    <div class="alert <?php echo $this->enabled ? 'alert-warning' : 'alert-info'; ?>" role="status"><?php echo Text::_($this->enabled ? 'COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE' : 'COM_APPOINTMENTS_TEAM_DISABLED'); ?></div>
    <form action="<?php echo Route::_('index.php?option=com_appointments'); ?>" method="post" name="adminForm" id="calendar-form" class="form-validate">
        <input type="hidden" name="id" value="<?php echo Factory::getApplication()->getInput()->getInt('id', 0); ?>">
        <input type="hidden" name="task" value="">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
    <?php return; ?>
<?php endif;

$id = (int) ($this->item->id ?? 0);
$action = Route::_('index.php?option=com_appointments&view=calendar&layout=edit&id=' . $id);
$manager = $this->myRole === CalendarRole::Manage;
$me = (int) Factory::getApplication()->getIdentity()->id;
$stateKeys = [
    TeamStates::GRANT_ACTIVE      => 'COM_APPOINTMENTS_TEAM_C_GRANT_STATE_ACTIVE',
    TeamStates::GRANT_PENDING_KEY => 'COM_APPOINTMENTS_TEAM_C_GRANT_STATE_PENDING_KEY',
    TeamStates::GRANT_SUSPENDED   => 'COM_APPOINTMENTS_TEAM_C_GRANT_STATE_SUSPENDED',
];
$roleSelect = function (string $id, ?int $selected, bool $external): string {
    $html = '<select class="form-select" id="' . $id . '" name="role" required>';

    foreach (CalendarRole::cases() as $role) {
        if ($external && !CalendarRole::maximumForExternal()->includes($role)) {
            continue;
        }

        $html .= '<option value="' . $role->value . '"' . ($role->value === $selected ? ' selected' : '') . '>'
            . htmlspecialchars(Text::_(GrantService::roleLabelKey($role)), ENT_QUOTES, 'UTF-8') . '</option>';
    }

    return $html . '</select>';
};
$this->getDocument()->getWebAssetManager()->useScript('form.validate')->useScript('keepalive');
?>
<form action="<?php echo $action; ?>" method="post" name="adminForm" id="calendar-form" class="form-validate">
    <div class="row">
        <div class="col-lg-8">
            <?php if ($this->form) : ?>
                <?php echo $this->form->renderFieldset('details'); ?>
            <?php endif; ?>
            <?php if ($id === 0) : ?>
                <div class="control-group">
                    <div class="control-label"><label for="calendar-owner"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_FIELD_OWNER'); ?></label></div>
                    <div class="controls">
                        <select class="form-select" id="calendar-owner" name="jform[owner_user_id]" required aria-describedby="calendar-owner-desc">
                            <option value=""><?php echo Text::_('JSELECT'); ?></option>
                            <?php foreach ($this->owners as $owner) : ?>
                                <option value="<?php echo (int) $owner->id; ?>"<?php echo (int) $owner->id === $this->ownerChoice ? ' selected' : ''; ?>><?php echo $this->escape($owner->name . ' (' . $owner->username . ')'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="calendar-owner-desc" class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_FIELD_OWNER_DESC'); ?></div>
                    </div>
                </div>
            <?php else : ?>
                <p><strong><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_FIELD_OWNER'); ?>:</strong>
                    <?php echo (int) $this->item->owner_user_id === 0 ? Text::_('COM_APPOINTMENTS_TEAM_C_NO_OWNER') : $this->escape((string) (GrantService::user((int) $this->item->owner_user_id)?->name ?? '')); ?></p>
            <?php endif; ?>
        </div>
        <div class="col-lg-4">
            <?php if ($this->form) : ?>
                <?php echo $this->form->renderFieldset('settings'); ?>
            <?php endif; ?>
        </div>
    </div>
    <input type="hidden" name="task" value="">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>

<?php if ($id > 0) : ?>
    <section class="card mt-4" aria-labelledby="grants-heading">
        <div class="card-body">
            <h2 id="grants-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANTS_HEADING'); ?></h2>
            <p><?php echo Text::_($manager ? 'COM_APPOINTMENTS_TEAM_C_GRANTS_INTRO' : 'COM_APPOINTMENTS_TEAM_C_GRANTS_READONLY'); ?></p>
            <?php if (!$this->showGrants) : ?>
                <?php // Only managers and administrators see the list of grants. ?>
            <?php elseif (!$this->grants) : ?>
                <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANTS_EMPTY'); ?></p>
            <?php else : ?>
                <table class="table">
                    <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANTS_HEADING'); ?></caption>
                    <thead><tr>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_USER'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_AUDIENCE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ROLE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_STATE'); ?></th>
                        <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ACTIONS'); ?></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($this->grants as $index => $grant) :
                        // Changing follows the account's current group (as the
                        // service decides); removing also the stored one.
                        $external = $grant->current_audience === TeamStates::AUDIENCE_EXTERNAL;
                        $wasExternal = $grant->audience === TeamStates::AUDIENCE_EXTERNAL;
                        $mayChange = !$grant->is_owner && (int) $grant->user_id !== $me && $grant->current_audience !== 'none'
                            && ($external ? $this->canShareExternally : $manager);
                        $mayRemove = !$grant->is_owner && (int) $grant->user_id !== $me
                            && ($manager || (($external || $wasExternal) && $this->canRevokeExternal));
                        ?>
                        <tr>
                            <th scope="row">
                                <?php echo $this->escape(trim($grant->user_name . ' (' . $grant->user_username . ')')); ?>
                                <?php if ($grant->is_owner) : ?><span class="badge bg-info text-dark"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_OWNER_BADGE'); ?></span><?php endif; ?>
                                <?php if (!$grant->mac_valid) : ?><span class="badge bg-danger"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INTEGRITY_FAILED'); ?></span><?php endif; ?>
                            </th>
                            <td><?php echo Text::_($external ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></td>
                            <td><?php echo Text::_(GrantService::roleLabelKey(CalendarRole::tryFrom($grant->role))); ?></td>
                            <td>
                                <?php echo Text::_($stateKeys[$grant->state] ?? 'COM_APPOINTMENTS_TEAM_C_GRANT_STATE_SUSPENDED'); ?>
                                <?php if (!$grant->effective) : ?><br><small class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_NOT_EFFECTIVE'); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($mayChange) : ?>
                                    <form action="<?php echo $action; ?>" method="post" class="d-flex gap-2 mb-2">
                                        <label class="visually-hidden" for="grant-role-<?php echo (int) $index; ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ROLE'); ?></label>
                                        <?php echo $roleSelect('grant-role-' . (int) $index, $grant->role, $external); ?>
                                        <input type="hidden" name="user_id" value="<?php echo (int) $grant->user_id; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" name="task" value="calendars.grant"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_CHANGE'); ?></button>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                    </form>
                                <?php endif; ?>
                                <?php if ($grant->is_owner && $manager && !$grant->effective && (int) $grant->user_id !== $me) : ?>
                                    <form action="<?php echo $action; ?>" method="post" class="mb-2">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $grant->user_id; ?>">
                                        <input type="hidden" name="role" value="<?php echo CalendarRole::Manage->value; ?>">
                                        <button type="submit" class="btn btn-secondary btn-sm" name="task" value="calendars.grant"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_OWNER_RESTORE'); ?></button>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                    </form>
                                <?php endif; ?>
                                <?php if ($mayRemove) : ?>
                                    <form action="<?php echo $action; ?>" method="post">
                                        <input type="hidden" name="user_id" value="<?php echo (int) $grant->user_id; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm" name="task" value="calendars.revoke"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_REMOVE'); ?></button>
                                        <?php echo HTMLHelper::_('form.token'); ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ($this->candidates) : ?>
                <h3 class="h5 mt-4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ADD'); ?></h3>
                <form action="<?php echo $action; ?>" method="post" class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="grant-new-user"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_USER'); ?></label>
                        <select class="form-select" id="grant-new-user" name="user_id" required>
                            <option value=""><?php echo Text::_('JSELECT'); ?></option>
                            <?php foreach ($this->candidates as $account) : ?>
                                <option value="<?php echo (int) $account->id; ?>"><?php echo $this->escape($account->name . ' (' . $account->username . ')'); ?> – <?php echo Text::_($account->audience === 'external' ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="grant-new-role"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ROLE'); ?></label>
                        <?php echo $roleSelect('grant-new-role', CalendarRole::Read->value, false); ?>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary" name="task" value="calendars.grant"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ADD_BUTTON'); ?></button>
                    </div>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
                <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_EXTERNAL_NOTE'); ?></p>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($manager) : ?>
        <section class="card mt-4" aria-labelledby="rotate-heading">
            <div class="card-body">
                <h2 id="rotate-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ROTATE_HEADING'); ?></h2>
                <p><?php echo Text::_((int) $this->item->rotation_due === 1 ? 'COM_APPOINTMENTS_TEAM_C_ROTATION_DUE_DESC' : 'COM_APPOINTMENTS_TEAM_C_ROTATE_DESC'); ?></p>
                <form action="<?php echo $action; ?>" method="post">
                    <button type="submit" class="btn btn-secondary" name="task" value="calendars.rotate"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ROTATE_BUTTON'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($this->canDelete) : ?>
        <section class="card mt-4 border-danger" aria-labelledby="delete-heading">
            <div class="card-body">
                <h2 id="delete-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_HEADING'); ?></h2>
                <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_DESC'); ?></p>
                <form action="<?php echo $action; ?>" method="post">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="calendar-delete-confirm" name="confirm" value="1" required>
                        <label class="form-check-label" for="calendar-delete-confirm"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_CONFIRM'); ?></label>
                    </div>
                    <button type="submit" class="btn btn-danger" name="task" value="calendars.delete"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_DELETE_BUTTON'); ?></button>
                    <?php echo HTMLHelper::_('form.token'); ?>
                </form>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
