<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/** @var \Advans\Component\Appointments\Administrator\View\Calendars\HtmlView $this */
$action = Route::_('index.php?option=com_appointments&view=calendars');
?>
<?php if (!$this->enabled) : ?>
    <div class="alert alert-info" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_DISABLED'); ?></div>
<?php else : ?>
    <?php if ($this->recoverySecret !== null) : ?>
        <section class="card mb-4 border-warning" aria-labelledby="recovery-secret-heading">
            <div class="card-body">
                <h2 id="recovery-secret-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_SECRET_HEADING'); ?></h2>
                <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_SECRET_NOTE'); ?></p>
                <pre class="p-3 bg-light border" style="white-space: pre-wrap; font-size: 1.1rem;"><?php echo $this->escape($this->recoverySecret); ?></pre>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$this->available) : ?>
        <div class="alert alert-warning" role="status"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE'); ?></div>
    <?php endif; ?>

    <form action="<?php echo $action; ?>" method="post" name="adminForm" id="adminForm">
        <?php if (!$this->items) : ?>
            <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_CALENDARS_EMPTY'); ?></p>
        <?php else : ?>
            <table class="table">
                <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_CALENDARS_TITLE'); ?></caption>
                <thead><tr>
                    <th scope="col"><?php echo Text::_('JGLOBAL_TITLE'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_FIELD_OWNER'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_FIELD_ALLOW_EXTERNAL'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_GRANTS'); ?></th>
                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_COL_YOUR_ROLE'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($this->items as $item) : ?>
                    <tr>
                        <th scope="row">
                            <?php if ($item->colour !== '') : ?>
                                <span aria-hidden="true" style="display:inline-block;width:.8em;height:.8em;border-radius:50%;background:<?php echo $this->escape($item->colour); ?>"></span>
                            <?php endif; ?>
                            <?php if ($item->mac_valid && $this->canEdit) : ?>
                                <a href="<?php echo Route::_('index.php?option=com_appointments&task=calendars.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape($item->title); ?></a>
                            <?php else : ?>
                                <?php echo $this->escape($item->title); ?>
                            <?php endif; ?>
                            <?php if (!$item->mac_valid) : ?>
                                <span class="badge bg-danger"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_INTEGRITY_FAILED'); ?></span>
                            <?php endif; ?>
                            <?php if ((int) $item->rotation_due === 1) : ?>
                                <span class="badge bg-warning text-dark"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ROTATION_DUE'); ?></span>
                            <?php endif; ?>
                        </th>
                        <td><?php echo (int) $item->owner_user_id === 0 ? Text::_('COM_APPOINTMENTS_TEAM_C_NO_OWNER') : $this->escape(trim((string) $item->owner_name . ' (' . (string) $item->owner_username . ')')); ?></td>
                        <td><?php echo Text::_((int) $item->allow_external === 1 ? 'JYES' : 'JNO'); ?></td>
                        <td><?php echo (int) $item->grant_count; ?></td>
                        <td><?php echo Text::_($this->roles[(int) $item->id] ?? 'COM_APPOINTMENTS_TEAM_C_ROLE_NONE'); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <input type="hidden" name="task" value="">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>

    <?php if ($this->canAdmin) : ?>
        <section class="card mt-4" aria-labelledby="recovery-heading">
            <div class="card-body">
                <h2 id="recovery-heading" class="h4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_HEADING'); ?></h2>
                <?php if (!$this->recoveryAvailable) : ?>
                    <p class="alert alert-warning"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE'); ?></p>
                <?php elseif ($this->recoveryFingerprint === null) : ?>
                    <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_NONE'); ?></p>
                    <form action="<?php echo $action; ?>" method="post">
                        <button type="submit" class="btn btn-primary" name="task" value="calendars.recoveryCreate"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_CREATE'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php else : ?>
                    <p><?php echo Text::sprintf('COM_APPOINTMENTS_TEAM_C_RECOVERY_PRESENT', $this->escape($this->recoveryFingerprint)); ?></p>

                    <h3 class="h5 mt-4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_USE'); ?></h3>
                    <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_USE_DESC'); ?></p>
                    <form action="<?php echo $action; ?>" method="post" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label" for="recovery-calendar"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_CALENDAR'); ?></label>
                            <select class="form-select" id="recovery-calendar" name="calendar_id" required>
                                <option value=""><?php echo Text::_('JSELECT'); ?></option>
                                <?php foreach ($this->items as $item) : ?>
                                    <?php if ($item->mac_valid) : ?>
                                        <option value="<?php echo (int) $item->id; ?>"><?php echo $this->escape($item->title); ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="recovery-user"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_USER'); ?></label>
                            <select class="form-select" id="recovery-user" name="user_id" required>
                                <option value=""><?php echo Text::_('JSELECT'); ?></option>
                                <?php foreach ($this->users as $account) : ?>
                                    <option value="<?php echo (int) $account->id; ?>"><?php echo $this->escape($account->name . ' (' . $account->username . ')'); ?> – <?php echo Text::_($account->audience === 'external' ? 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_EXTERNAL' : 'COM_APPOINTMENTS_TEAM_C_AUDIENCE_TEAM'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="recovery-role"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_GRANT_ROLE'); ?></label>
                            <select class="form-select" id="recovery-role" name="role" required>
                                <?php foreach (CalendarRole::cases() as $role) : ?>
                                    <option value="<?php echo $role->value; ?>"<?php echo $role === CalendarRole::Manage ? ' selected' : ''; ?>><?php echo Text::_(GrantService::roleLabelKey($role)); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="recovery-owner" name="make_owner" value="1">
                            <label class="form-check-label" for="recovery-owner"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_MAKE_OWNER'); ?></label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="recovery-secret"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_SECRET'); ?></label>
                            <input class="form-control" type="password" id="recovery-secret" name="recovery_secret" autocomplete="off" spellcheck="false" required>
                        </div>
                        <button type="submit" class="btn btn-primary" name="task" value="calendars.recoveryUse"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_USE_BUTTON'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>

                    <h3 class="h5 mt-4"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_ROTATE'); ?></h3>
                    <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_ROTATE_DESC'); ?></p>
                    <form action="<?php echo $action; ?>" method="post" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label" for="recovery-old"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_OLD_SECRET'); ?></label>
                            <input class="form-control" type="password" id="recovery-old" name="recovery_secret" autocomplete="off" spellcheck="false" required>
                        </div>
                        <button type="submit" class="btn btn-secondary" name="task" value="calendars.recoveryRotate"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_RECOVERY_ROTATE'); ?></button>
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
<?php endif; ?>
