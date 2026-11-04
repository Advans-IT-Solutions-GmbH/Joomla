<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\DeviceService;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\Appointments\Site\View\Mycalendars\HtmlView $this */

$roles = [
    1 => 'COM_APPOINTMENTS_TEAM_D_ROLE_FREEBUSY',
    2 => 'COM_APPOINTMENTS_TEAM_D_ROLE_READ',
    3 => 'COM_APPOINTMENTS_TEAM_D_ROLE_WRITE',
    4 => 'COM_APPOINTMENTS_TEAM_D_ROLE_MANAGE',
];
$states = [
    TeamStates::DEVICE_ACTIVE => 'COM_APPOINTMENTS_TEAM_D_STATE_ACTIVE',
    TeamStates::DEVICE_CLEANUP_EMPTY => 'COM_APPOINTMENTS_TEAM_D_STATE_REMOVING',
    TeamStates::DEVICE_CLEANUP_HIDDEN => 'COM_APPOINTMENTS_TEAM_D_STATE_REMOVING',
];
$errors = $this->form['device'] === 0 ? $this->form['errors'] : [];
$firstError = current(array_intersect(['name', 'client', 'rule'], array_keys($errors))) ?: null;
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key, string $describedBy = '') use ($errors, $firstError): string {
    $ids = trim($describedBy . (isset($errors[$key]) ? ' device-' . $key . '-error' : ''));

    return ($ids !== '' ? ' aria-describedby="' . $ids . '"' : '')
        . (isset($errors[$key]) ? ' aria-invalid="true"' . ($key === $firstError ? ' autofocus' : '') : '');
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="device-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
$date = static fn (?string $utc): string => $utc === null || $utc === ''
    ? Text::_('COM_APPOINTMENTS_TEAM_D_NEVER')
    : HTMLHelper::_('date', $utc, Text::_('DATE_FORMAT_LC2'));
?>
<div class="com-appointments com-appointments-mycalendars">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>

        <?php if ($this->unavailable) : ?>
            <p class="com-appointments-notice"><?php echo Text::_('COM_APPOINTMENTS_MYCALENDARS_NOT_READY'); ?></p>
        <?php endif; ?>

        <section class="com-appointments-mycal-section" aria-labelledby="mycal-calendars">
            <h2 id="mycal-calendars" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_CALENDARS'); ?></h2>
            <?php if ($this->calendars === []) : ?>
                <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_CALENDARS_EMPTY'); ?></p>
            <?php else : ?>
                <ul class="com-appointments-mycal-list">
                    <?php foreach ($this->calendars as $calendar) : ?>
                        <?php $colour = preg_match('/\A#[0-9A-Fa-f]{6}\z/', $calendar->colour) === 1 ? $calendar->colour : ''; ?>
                        <li class="com-appointments-mycal-item">
                            <span class="com-appointments-mycal-swatch"<?php echo $colour !== '' ? ' style="background-color:' . $colour . '"' : ''; ?> aria-hidden="true"></span>
                            <span class="com-appointments-mycal-name"><?php echo $this->escape($calendar->title); ?></span>
                            <span class="com-appointments-mycal-meta"><?php echo Text::_($roles[$calendar->role->value]); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="mycal-devices">
            <h2 id="mycal-devices" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_DEVICES'); ?></h2>
            <?php if ($this->devices === []) : ?>
                <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_DEVICES_EMPTY'); ?></p>
            <?php else : ?>
                <ul class="com-appointments-mycal-devices">
                    <?php foreach ($this->devices as $device) : ?>
                        <li class="com-appointments-card com-appointments-mycal-device">
                            <h3 class="com-appointments-mycal-device-name"><?php echo $this->escape($device->name); ?></h3>
                            <dl class="com-appointments-summary">
                                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_CLIENT'); ?></dt>
                                <dd><?php echo Text::_(DeviceService::CLIENT_LABELS[$device->client] ?? DeviceService::CLIENT_LABELS['other']); ?></dd>
                                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_STATE'); ?></dt>
                                <dd><?php echo Text::_($states[$device->state] ?? 'COM_APPOINTMENTS_TEAM_D_STATE_REMOVING'); ?></dd>
                                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_CREATED'); ?></dt>
                                <dd><?php echo $this->escape($date($device->created_utc)); ?></dd>
                                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_LAST_SYNC'); ?></dt>
                                <dd><?php echo $this->escape($date($device->last_sync_utc)); ?></dd>
                            </dl>
                            <?php if ($device->state === TeamStates::DEVICE_ACTIVE) : ?>
                                <p class="com-appointments-mycal-device-actions">
                                    <a href="<?php echo Route::_('index.php?option=com_appointments&view=mycalendars&layout=device&id=' . (int) $device->id); ?>">
                                        <?php echo Text::_('COM_APPOINTMENTS_TEAM_D_DEVICE_EDIT'); ?><span class="visually-hidden">: <?php echo $this->escape($device->name); ?></span>
                                    </a>
                                </p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="mycal-add">
            <h2 id="mycal-add" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ADD'); ?></h2>
            <?php if ($this->addBlocked !== '') : ?>
                <div class="com-appointments-notice">
                    <p><?php echo Text::_($this->addBlocked); ?></p>
                    <?php if ($this->addBlocked === 'COM_APPOINTMENTS_TEAM_D_ADD_NEEDS_MFA') : ?>
                        <p><a href="<?php echo Route::_('index.php?option=com_users&view=methods'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ADD_MFA_LINK'); ?></a></p>
                    <?php endif; ?>
                </div>
            <?php else : ?>
                <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ADD_INTRO'); ?></p>
                <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=devices.create'); ?>" class="com-appointments-form com-appointments-mycal-form" autocomplete="off" data-appointments-once>
                    <div class="com-appointments-field">
                        <label class="form-label" for="device-name"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_NAME'); ?></label>
                        <input class="form-control<?php echo $invalid('name'); ?>" id="device-name" type="text" name="name" maxlength="<?php echo DeviceService::NAME_MAX; ?>" required autocomplete="off" spellcheck="false" value="<?php echo $this->escape($this->form['name']); ?>"<?php echo $aria('name', 'device-name-hint'); ?>>
                        <p id="device-name-hint" class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_NAME_HINT'); ?></p>
                        <?php echo $error('name'); ?>
                    </div>
                    <div class="com-appointments-field">
                        <label class="form-label" for="device-client"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_CLIENT'); ?></label>
                        <select class="form-select<?php echo $invalid('client'); ?>" id="device-client" name="client" required<?php echo $aria('client', 'device-client-hint'); ?>>
                            <option value=""><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_CLIENT_CHOOSE'); ?></option>
                            <?php foreach (DeviceService::CLIENT_LABELS as $value => $label) : ?>
                                <option value="<?php echo $this->escape($value); ?>"<?php echo $this->form['client'] === $value ? ' selected' : ''; ?>><?php echo Text::_($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="device-client-hint" class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_UNSUPPORTED'); ?></p>
                        <?php echo $error('client'); ?>
                    </div>
                    <fieldset class="com-appointments-field com-appointments-mycal-rule">
                        <legend class="form-label"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RULE_TITLE'); ?></legend>
                        <ul class="com-appointments-mycal-rule-list">
                            <li><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RULE_LOCK'); ?></li>
                            <li><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RULE_FORWARD'); ?></li>
                            <li><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RULE_LOSS'); ?></li>
                        </ul>
                        <p class="form-text"><?php echo Text::sprintf('COM_APPOINTMENTS_TEAM_D_RULE_PRIVATE', $this->escape(TeamStates::DEVICE_RULE_VERSION)); ?></p>
                        <div class="form-check com-appointments-confirm">
                            <input class="form-check-input<?php echo $invalid('rule'); ?>" id="device-rule" type="checkbox" name="rule" value="1" required<?php echo $aria('rule'); ?>>
                            <label class="form-check-label" for="device-rule"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RULE_ACCEPT'); ?></label>
                            <?php echo $error('rule'); ?>
                        </div>
                    </fieldset>
                    <input type="hidden" name="form_nonce" value="<?php echo $this->escape($this->formNonce); ?>">
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <div class="com-appointments-actions">
                        <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ADD_SUBMIT'); ?></button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
</div>
