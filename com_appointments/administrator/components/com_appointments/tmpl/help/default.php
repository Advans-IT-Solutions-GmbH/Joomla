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
    // What removing or blocking a device does, per «When removed or blocked».
    'removal' => ['COM_APPOINTMENTS_HELP_DEVREMOVE_TITLE', [
        'COM_APPOINTMENTS_HELP_DEVREMOVE_SETTING', 'COM_APPOINTMENTS_HELP_DEVREMOVE_CLEANUP',
        'COM_APPOINTMENTS_HELP_DEVREMOVE_BLOCK', 'COM_APPOINTMENTS_HELP_DEVREMOVE_OUTLOOK',
        'COM_APPOINTMENTS_HELP_DEVREMOVE_TRIGGERS', 'COM_APPOINTMENTS_HELP_DEVREMOVE_CALENDAR',
        'COM_APPOINTMENTS_HELP_DEVREMOVE_DEFAULTS',
    ]],
    'retention' => ['COM_APPOINTMENTS_HELP_RETENTION_TITLE', [
        'COM_APPOINTMENTS_CONFIG_RETENTION_TASKS_DESC', 'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION_DESC',
        'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS_DESC', 'COM_APPOINTMENTS_EVENTTYPE_RETENTION_DESC',
        'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION_DESC',
    ]],
    'privacy' => ['COM_APPOINTMENTS_HELP_PRIVACY_TITLE', ['COM_APPOINTMENTS_HELP_PRIVACY_DESC', 'COM_APPOINTMENTS_CONFIG_ERASE_MODE_DESC']],
    'mail' => ['COM_APPOINTMENTS_HELP_MAIL_TITLE', ['COM_APPOINTMENTS_HELP_MAIL_DESC']],
    // Who receives which booking mail, with and without target calendar (CalendarNotice).
    'notifications' => ['COM_APPOINTMENTS_HELP_NOTIFY_TITLE', [
        'COM_APPOINTMENTS_HELP_NOTIFY_INTRO', 'COM_APPOINTMENTS_HELP_NOTIFY_GUEST', 'COM_APPOINTMENTS_HELP_NOTIFY_NOCALENDAR',
        'COM_APPOINTMENTS_HELP_NOTIFY_MEMBERS', 'COM_APPOINTMENTS_HELP_NOTIFY_CONTENT', 'COM_APPOINTMENTS_HELP_NOTIFY_FREEBUSY',
        'COM_APPOINTMENTS_HELP_NOTIFY_EXTERNAL', 'COM_APPOINTMENTS_HELP_NOTIFY_ONCE', 'COM_APPOINTMENTS_HELP_NOTIFY_LANGUAGE',
        'COM_APPOINTMENTS_HELP_NOTIFY_SWITCH', 'COM_APPOINTMENTS_HELP_NOTIFY_MANDATORY',
    ]],
    'troubleshooting' => ['COM_APPOINTMENTS_HELP_TROUBLE_TITLE', [
        'COM_APPOINTMENTS_HELP_TROUBLE_SLOTS_DESC', 'COM_APPOINTMENTS_TEAM_B_ERROR_KEYS_LOCKED',
        'COM_APPOINTMENTS_TEAM_C_ERROR_OWNER_KEY', 'COM_APPOINTMENTS_HELP_TROUBLE_SYNC_DESC',
        'COM_APPOINTMENTS_HELP_TROUBLE_CHECKS_DESC',
    ]],
];
// Retention and deletion: the rules, what is deleted when, the recommended calendar setup and
// the limits, then the separate settings. Keys are written out in full for the language lint.
$deletionRules = [
    ['COM_APPOINTMENTS_HELP_DEL_RULE_ORIGIN_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_ORIGIN_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_BOOKINGS_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_BOOKINGS_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_OWNER_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_OWNER_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_DEVICE_DELETE_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_DEVICE_DELETE_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_OWN_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_OWN_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_CALRET_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_CALRET_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_CLEANUP_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_CLEANUP_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_RESTORE_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_RESTORE_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_DESC', [
        'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_1', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_2',
        'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_3', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_4',
    ]],
];
$deletionRows = [
    ['COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHERE'],
];
$deletionSetup = [
    'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_1', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_2', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_3',
    'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_4', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_5', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_6',
];
$deletionLists = [
    'COM_APPOINTMENTS_HELP_DEL_NEVER_TITLE' => ['COM_APPOINTMENTS_HELP_DEL_NEVER_1', 'COM_APPOINTMENTS_HELP_DEL_NEVER_2',
        'COM_APPOINTMENTS_HELP_DEL_NEVER_3', 'COM_APPOINTMENTS_HELP_DEL_NEVER_4', 'COM_APPOINTMENTS_HELP_DEL_NEVER_5'],
    'COM_APPOINTMENTS_HELP_DEL_BEYOND_TITLE' => ['COM_APPOINTMENTS_HELP_DEL_BEYOND_1', 'COM_APPOINTMENTS_HELP_DEL_BEYOND_2',
        'COM_APPOINTMENTS_HELP_DEL_BEYOND_3', 'COM_APPOINTMENTS_HELP_DEL_BEYOND_4'],
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
                <?php if ($id === 'retention') : ?>
                    <p><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_INTRO'); ?></p>
                    <h3 class="h5"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_RULES_TITLE'); ?></h3>
                    <?php foreach ($deletionRules as [$ruleTitle, $ruleText, $ruleSteps]) : ?>
                        <h4 class="h6"><?php echo Text::_($ruleTitle); ?></h4>
                        <p><?php echo Text::_($ruleText); ?></p>
                        <?php if ($ruleSteps !== []) : ?>
                            <ol>
                                <?php foreach ($ruleSteps as $step) : ?>
                                    <li><?php echo Text::_($step); ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <h3 class="h5" id="help-retention-table-heading"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_TABLE_TITLE'); ?></h3>
                    <div class="table-responsive">
                        <table class="table table-striped" id="help-retention-table" aria-labelledby="help-retention-table-heading">
                            <thead>
                                <tr>
                                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_COL_WHAT'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_COL_WHEN'); ?></th>
                                    <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_COL_WHERE'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($deletionRows as [$what, $when, $where]) : ?>
                                    <tr>
                                        <th scope="row"><?php echo Text::_($what); ?></th>
                                        <td><?php echo Text::_($when); ?></td>
                                        <td><?php echo Text::_($where); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <h3 class="h5"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_TITLE'); ?></h3>
                    <p><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_DESC'); ?></p>
                    <ul>
                        <li><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_OWN'); ?></li>
                        <li><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STOP'); ?></li>
                    </ul>
                    <h4 class="h6"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEPS_TITLE'); ?></h4>
                    <ol>
                        <?php foreach ($deletionSetup as $step) : ?>
                            <li><?php echo Text::_($step); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <div class="alert alert-warning" role="note"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_OUTLOOK_WHY'); ?></div>
                    <?php foreach ($deletionLists as $listTitle => $items) : ?>
                        <h3 class="h5"><?php echo Text::_($listTitle); ?></h3>
                        <ul>
                            <?php foreach ($items as $item) : ?>
                                <li><?php echo Text::_($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                    <h3 class="h5"><?php echo Text::_('COM_APPOINTMENTS_HELP_DEL_SETTINGS_TITLE'); ?></h3>
                <?php endif; ?>
                <?php foreach ($paragraphs as $key) : ?>
                    <?php if (isset($retentionLabels[$key])) : ?>
                        <h4 class="h6"><?php echo Text::_($retentionLabels[$key]); ?></h4>
                    <?php endif; ?>
                    <p><?php echo Text::_($key); ?></p>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_HELP_TITLE')); ?>">
        <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_appointments'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'); ?></a>
        <?php if ($this->canOptions) : ?>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_appointments'); ?>"><?php echo Text::_('JOPTIONS'); ?></a>
        <?php endif; ?>
    </nav>
</div>
