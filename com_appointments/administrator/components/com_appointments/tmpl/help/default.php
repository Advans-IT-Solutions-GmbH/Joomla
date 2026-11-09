<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

$sections = [
    'administration' => ['COM_APPOINTMENTS_HELP_ADMIN_TITLE', ['COM_APPOINTMENTS_TEAM_C_ADMINISTRATION_HELP']],
    'sharing' => ['COM_APPOINTMENTS_HELP_SHARES_TITLE', ['COM_APPOINTMENTS_HELP_SHARES_DESC']],
    'devices' => ['COM_APPOINTMENTS_HELP_DEVICES_TITLE', ['COM_APPOINTMENTS_HELP_DEVICES_DESC']],
    'retention' => ['COM_APPOINTMENTS_HELP_RETENTION_TITLE', [
        'COM_APPOINTMENTS_CONFIG_RETENTION_TASKS_DESC', 'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION_DESC',
        'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS_DESC', 'COM_APPOINTMENTS_EVENTTYPE_RETENTION_DESC',
        'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION_DESC',
    ]],
    'privacy' => ['COM_APPOINTMENTS_HELP_PRIVACY_TITLE', ['COM_APPOINTMENTS_HELP_PRIVACY_DESC', 'COM_APPOINTMENTS_CONFIG_ERASE_MODE_DESC']],
    'mail' => ['COM_APPOINTMENTS_HELP_MAIL_TITLE', ['COM_APPOINTMENTS_HELP_MAIL_DESC']],
    'troubleshooting' => ['COM_APPOINTMENTS_HELP_TROUBLE_TITLE', [
        'COM_APPOINTMENTS_HELP_TROUBLE_SLOTS_DESC', 'COM_APPOINTMENTS_TEAM_B_ERROR_KEYS_LOCKED',
        'COM_APPOINTMENTS_TEAM_C_ERROR_OWNER_KEY', 'COM_APPOINTMENTS_HELP_TROUBLE_SYNC_DESC',
        'COM_APPOINTMENTS_HELP_TROUBLE_CHECKS_DESC',
    ]],
];
$retentionLabels = [
    'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION_DESC' => 'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION',
    'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS_DESC' => 'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS',
    'COM_APPOINTMENTS_EVENTTYPE_RETENTION_DESC' => 'COM_APPOINTMENTS_EVENTTYPE_RETENTION',
    'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION_DESC' => 'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION',
];
?>
<div id="appointments-help" class="com-appointments-help">
    <p class="lead"><?php echo Text::_('COM_APPOINTMENTS_HELP_INTRO'); ?></p>
    <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_HELP_CONTENTS')); ?>">
        <a class="btn btn-secondary" href="#help-setup"><?php echo Text::_('COM_APPOINTMENTS_HELP_SETUP_TITLE'); ?></a>
        <?php foreach ($sections as $id => [$heading]) : ?>
            <a class="btn btn-secondary" href="#help-<?php echo $id; ?>"><?php echo Text::_($heading); ?></a>
        <?php endforeach; ?>
    </nav>
    <section class="card mb-4" id="help-setup" aria-labelledby="help-setup-heading">
        <div class="card-body">
            <h2 class="h4" id="help-setup-heading"><?php echo Text::_('COM_APPOINTMENTS_HELP_SETUP_TITLE'); ?></h2>
            <ol class="mb-0">
                <li class="mb-3"><?php echo Text::_('COM_APPOINTMENTS_HELP_SETUP_BASE_DESC'); ?></li>
                <li class="mb-3"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_KEY_SETUP_HELP'); ?></li>
                <li class="mb-3"><?php echo Text::_('COM_APPOINTMENTS_HELP_SETUP_SCHEDULE_DESC'); ?></li>
                <li><?php echo Text::_('COM_APPOINTMENTS_HELP_SETUP_BOOKING_DESC'); ?></li>
            </ol>
        </div>
    </section>
    <?php foreach ($sections as $id => [$heading, $paragraphs]) : ?>
        <section class="card mb-4" id="help-<?php echo $id; ?>" aria-labelledby="help-<?php echo $id; ?>-heading">
            <div class="card-body">
                <h2 class="h4" id="help-<?php echo $id; ?>-heading"><?php echo Text::_($heading); ?></h2>
                <?php foreach ($paragraphs as $key) : ?>
                    <?php if (isset($retentionLabels[$key])) : ?>
                        <h3 class="h5"><?php echo Text::_($retentionLabels[$key]); ?></h3>
                    <?php endif; ?>
                    <p><?php echo Text::_($key); ?></p>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_HELP_TITLE')); ?>">
        <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_appointments'); ?>"><?php echo Text::_('COM_APPOINTMENTS_MENU_DASHBOARD'); ?></a>
        <?php if ($this->canOptions) : ?>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_appointments'); ?>"><?php echo Text::_('JOPTIONS'); ?></a>
        <?php endif; ?>
    </nav>
</div>
