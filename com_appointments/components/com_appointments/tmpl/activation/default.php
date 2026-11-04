<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\Appointments\Site\View\Activation\HtmlView $this */

$errors = $this->errors;
$firstError = current(array_intersect(['password1', 'password2'], array_keys($errors))) ?: null;
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key, string $describedBy = '') use ($errors, $firstError): string {
    $ids = trim($describedBy . (isset($errors[$key]) ? ' activation-' . $key . '-error' : ''));

    return ($ids !== '' ? ' aria-describedby="' . $ids . '"' : '')
        . (isset($errors[$key]) ? ' aria-invalid="true"' . ($key === $firstError ? ' autofocus' : '') : '');
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="activation-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
?>
<div class="com-appointments com-appointments-mycalendars com-appointments-activation">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_INTRO'); ?></p>
        <dl class="com-appointments-summary">
            <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS_USERNAME'); ?></dt>
            <dd><code translate="no"><?php echo $this->escape($this->account['username']); ?></code></dd>
            <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_EMAIL'); ?></dt>
            <dd><?php echo $this->escape($this->account['email_masked']); ?></dd>
            <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_EXPIRES'); ?></dt>
            <dd><?php echo $this->escape(HTMLHelper::_('date', $this->account['expires_utc'], Text::_('DATE_FORMAT_LC2'))); ?></dd>
        </dl>
        <?php if ($this->signedIn) : ?>
            <p class="com-appointments-notice"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_SIGNED_IN'); ?></p>
        <?php else : ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=activation.complete'); ?>" class="com-appointments-form com-appointments-mycal-form" data-appointments-once>
                <input type="text" name="username" value="<?php echo $this->escape($this->account['username']); ?>" autocomplete="username" readonly hidden>
                <div class="com-appointments-field">
                    <label class="form-label" for="activation-password1"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_PASSWORD'); ?></label>
                    <input class="form-control<?php echo $invalid('password1'); ?>" id="activation-password1" type="password" name="password1" required autocomplete="new-password" maxlength="4096"<?php echo $aria('password1', 'activation-password-hint'); ?>>
                    <p id="activation-password-hint" class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_PASSWORD_HINT'); ?></p>
                    <?php echo $error('password1'); ?>
                </div>
                <div class="com-appointments-field">
                    <label class="form-label" for="activation-password2"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_REPEAT'); ?></label>
                    <input class="form-control<?php echo $invalid('password2'); ?>" id="activation-password2" type="password" name="password2" required autocomplete="new-password" maxlength="4096"<?php echo $aria('password2'); ?>>
                    <?php echo $error('password2'); ?>
                </div>
                <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_MFA'); ?></p>
                <input type="hidden" name="token" value="<?php echo $this->escape($this->token); ?>">
                <?php echo HTMLHelper::_('form.token'); ?>
                <div class="com-appointments-actions">
                    <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACTIVATION_SUBMIT'); ?></button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
