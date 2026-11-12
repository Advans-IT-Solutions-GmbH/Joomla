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

$device = $this->device;
$errors = $this->form['device'] === (int) $device->id ? $this->form['errors'] : [];
$firstError = current(array_intersect(['name', 'confirm'], array_keys($errors))) ?: null;
// «When removed or blocked» of this device: an unknown stored value acts as «Clean up».
$removal = $device->removal_mode === TeamStates::REMOVAL_BLOCK ? TeamStates::REMOVAL_BLOCK : TeamStates::REMOVAL_CLEANUP;
$removalChoices = [
    TeamStates::REMOVAL_CLEANUP => ['COM_APPOINTMENTS_TEAM_D_REMOVAL_CLEANUP', 'COM_APPOINTMENTS_TEAM_D_REMOVAL_CLEANUP_DESC'],
    TeamStates::REMOVAL_BLOCK => ['COM_APPOINTMENTS_TEAM_D_REMOVAL_BLOCK', 'COM_APPOINTMENTS_TEAM_D_REMOVAL_BLOCK_DESC'],
];
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key) use ($errors, $firstError): string {
    return isset($errors[$key])
        ? ' aria-invalid="true" aria-describedby="device-' . $key . '-error"' . ($key === $firstError ? ' autofocus' : '')
        : '';
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="device-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
$name = isset($errors['name']) && $this->form['name'] !== '' ? $this->form['name'] : $device->name;
?>
<div class="com-appointments com-appointments-mycalendars">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <p class="com-appointments-mycal-device-name"><?php echo $this->escape($device->name); ?>
            <span class="com-appointments-mycal-meta"><?php echo Text::_(DeviceService::CLIENT_LABELS[$device->client] ?? DeviceService::CLIENT_LABELS['other']); ?></span>
        </p>

        <section class="com-appointments-mycal-section" aria-labelledby="device-rename">
            <h2 id="device-rename" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RENAME'); ?></h2>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=devices.rename'); ?>" class="com-appointments-form" autocomplete="off">
                <div class="com-appointments-field">
                    <label class="form-label" for="device-name"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_FIELD_NAME'); ?></label>
                    <input class="form-control<?php echo $invalid('name'); ?>" id="device-name" type="text" name="name" maxlength="<?php echo DeviceService::NAME_MAX; ?>" required spellcheck="false" value="<?php echo $this->escape($name); ?>"<?php echo $aria('name'); ?>>
                    <?php echo $error('name'); ?>
                </div>
                <input type="hidden" name="id" value="<?php echo (int) $device->id; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_RENAME_SUBMIT'); ?></button>
                </div>
            </form>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="device-removal">
            <h2 id="device-removal" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_TITLE'); ?></h2>
            <p id="device-removal-hint"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_INTRO'); ?></p>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=devices.removalMode'); ?>" class="com-appointments-form">
                <fieldset class="com-appointments-field" aria-describedby="device-removal-hint<?php echo isset($errors['removal']) ? ' device-removal-error' : ''; ?>">
                    <legend class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_TITLE'); ?></legend>
                    <?php foreach ($removalChoices as $value => [$label, $description]) : ?>
                        <div class="form-check">
                            <input class="form-check-input<?php echo $invalid('removal'); ?>" type="radio" name="removal_mode" id="device-removal-<?php echo $value; ?>" value="<?php echo $value; ?>" aria-describedby="device-removal-<?php echo $value; ?>-desc"<?php echo $removal === $value ? ' checked' : ''; ?>>
                            <label class="form-check-label" for="device-removal-<?php echo $value; ?>"><?php echo Text::_($label); ?></label>
                            <p id="device-removal-<?php echo $value; ?>-desc" class="form-text"><?php echo Text::_($description); ?></p>
                        </div>
                    <?php endforeach; ?>
                    <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_OUTLOOK'); ?></p>
                    <?php echo $error('removal'); ?>
                </fieldset>
                <input type="hidden" name="id" value="<?php echo (int) $device->id; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_SUBMIT'); ?></button>
                </div>
            </form>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="device-remove">
            <h2 id="device-remove" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVE'); ?></h2>
            <div class="com-appointments-notice">
                <p><?php echo Text::_($removal === TeamStates::REMOVAL_BLOCK ? 'COM_APPOINTMENTS_TEAM_D_REMOVE_EXPLAIN_BLOCK' : 'COM_APPOINTMENTS_TEAM_D_REMOVE_EXPLAIN'); ?></p>
                <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVE_LOST'); ?></p>
            </div>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=devices.remove'); ?>" class="com-appointments-form">
                <div class="com-appointments-field">
                    <div class="form-check com-appointments-confirm">
                        <input class="form-check-input<?php echo $invalid('confirm'); ?>" id="device-confirm" type="checkbox" name="confirm" value="1" required<?php echo $aria('confirm'); ?>>
                        <label class="form-check-label" for="device-confirm"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVE_CONFIRM'); ?></label>
                        <?php echo $error('confirm'); ?>
                    </div>
                </div>
                <input type="hidden" name="id" value="<?php echo (int) $device->id; ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-danger"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVE_SUBMIT'); ?></button>
                </div>
            </form>
        </section>

        <p><a href="<?php echo Route::_('index.php?option=com_appointments&view=mycalendars'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_BACK'); ?></a></p>
    </div>
</div>
