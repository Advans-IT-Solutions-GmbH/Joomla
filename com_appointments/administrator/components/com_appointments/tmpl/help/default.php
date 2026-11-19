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
    // Statuses and actions of the booking list and the booking details (BookingsController, BookingStore).
    'bookings' => ['COM_APPOINTMENTS_HELP_BOOKINGS_TITLE', []],
    // Joomla's permissions on the component and the event types (access.xml, EventScope, the controllers).
    'permissions' => ['COM_APPOINTMENTS_HELP_PERMISSIONS_TITLE', []],
    'administration' =>['COM_APPOINTMENTS_HELP_ADMIN_TITLE', ['COM_APPOINTMENTS_TEAM_C_ADMINISTRATION_HELP']],
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
    // Optional direct CRM transfer (Service/Close, CloseController, dashboard section).
    'close' => ['COM_APPOINTMENTS_HELP_CLOSE_TITLE', []],
    // The routines of plg_task_appointments and the tasks the installer creates (script.php).
    'tasks' => ['COM_APPOINTMENTS_HELP_TASKS_TITLE', []],
    // The bundled plugins link their Help button and option note to this anchor (#help-plugins).
    'plugins' => ['COM_APPOINTMENTS_HELP_PLUGINS_TITLE', []],
    // The bundled administrator module (modules/mod_appointments); its own help is its «Help» tab.
    'module' => ['COM_APPOINTMENTS_HELP_MODULE_TITLE', ['COM_APPOINTMENTS_HELP_MODULE_DESC']],
    'troubleshooting' =>['COM_APPOINTMENTS_HELP_TROUBLE_TITLE', [
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
    ['COM_APPOINTMENTS_HELP_DEL_RULE_PRIVACY_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_PRIVACY_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_CALRET_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_CALRET_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_CLEANUP_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_CLEANUP_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_RESTORE_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_RESTORE_DESC', []],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_DESC', [
        'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_1', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_2',
        'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_3', 'COM_APPOINTMENTS_HELP_DEL_RULE_BACKUP_STEP_4',
    ]],
    ['COM_APPOINTMENTS_HELP_DEL_RULE_CLOSE_TITLE', 'COM_APPOINTMENTS_HELP_DEL_RULE_CLOSE_DESC', []],
];
$deletionRows = [
    ['COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_BOOKING_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_COPY_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_OWN_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_PRIVACY_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_PRIVACY_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_PRIVACY_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_OFF_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLEANUP_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CALENDAR_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_AUDIT_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CAMPAIGN_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CAMPAIGN_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CAMPAIGN_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_LOG_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_BACKUP_WHERE'],
    ['COM_APPOINTMENTS_HELP_DEL_ROW_CLOSE_WHAT', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLOSE_WHEN', 'COM_APPOINTMENTS_HELP_DEL_ROW_CLOSE_WHERE'],
];
$deletionSetup = [
    'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_1', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_2', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_3',
    'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_4', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_5', 'COM_APPOINTMENTS_HELP_DEL_OUTLOOK_STEP_6',
];
$deletionLists = [
    'COM_APPOINTMENTS_HELP_DEL_NEVER_TITLE' => ['COM_APPOINTMENTS_HELP_DEL_NEVER_CLOSE', 'COM_APPOINTMENTS_HELP_DEL_NEVER_1', 'COM_APPOINTMENTS_HELP_DEL_NEVER_2',
        'COM_APPOINTMENTS_HELP_DEL_NEVER_3', 'COM_APPOINTMENTS_HELP_DEL_NEVER_4', 'COM_APPOINTMENTS_HELP_DEL_NEVER_5',
        'COM_APPOINTMENTS_HELP_DEL_NEVER_6'],
    'COM_APPOINTMENTS_HELP_DEL_BEYOND_TITLE' => ['COM_APPOINTMENTS_HELP_DEL_BEYOND_1', 'COM_APPOINTMENTS_HELP_DEL_BEYOND_2',
        'COM_APPOINTMENTS_HELP_DEL_BEYOND_3', 'COM_APPOINTMENTS_HELP_DEL_BEYOND_4'],
];
$retentionLabels = [
    'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION_DESC' => 'COM_APPOINTMENTS_TEAM_C_FIELD_RETENTION',
    'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS_DESC' => 'COM_APPOINTMENTS_CONFIG_RETENTION_DAYS',
    'COM_APPOINTMENTS_EVENTTYPE_RETENTION_DESC' => 'COM_APPOINTMENTS_EVENTTYPE_RETENTION',
    'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION_DESC' => 'COM_APPOINTMENTS_CONFIG_AUDIT_RETENTION',
];
// Structured content of the sections that need sub-headings, lists or a table:
// ['h3', key], ['p', key], ['ul', [keys]], ['ol', [keys]], ['table', [column keys], [[cell keys], ...]].
$tasksManual = 'COM_APPOINTMENTS_HELP_TASKS_SCHEDULE_MANUAL';
$blocks = [
    'bookings' => [
        ['p', 'COM_APPOINTMENTS_HELP_BOOKINGS_INTRO'],
        ['h3', 'COM_APPOINTMENTS_HELP_BOOKINGS_STATES_TITLE'],
        ['ul', [
            'COM_APPOINTMENTS_HELP_BOOKINGS_STATE_PENDING', 'COM_APPOINTMENTS_HELP_BOOKINGS_STATE_CONFIRMED',
            'COM_APPOINTMENTS_HELP_BOOKINGS_STATE_CANCELLED', 'COM_APPOINTMENTS_HELP_BOOKINGS_STATE_ERASED',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_BOOKINGS_STATE_FLOW'],
        ['h3', 'COM_APPOINTMENTS_HELP_BOOKINGS_ACTIONS_TITLE'],
        ['ul', [
            'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_APPROVE', 'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_CANCEL',
            'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_RESCHEDULE', 'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_RETRY',
            'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_ICS', 'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_CSV',
            'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_ERASE', 'COM_APPOINTMENTS_HELP_BOOKINGS_ACTION_ENCRYPT',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_BOOKINGS_RIGHTS'],
        ['p', 'COM_APPOINTMENTS_HELP_BOOKINGS_MAILFAIL'],
    ],
    'permissions' => [
        ['p', 'COM_APPOINTMENTS_HELP_PERMISSIONS_INTRO'],
        ['h3', 'COM_APPOINTMENTS_HELP_PERMISSIONS_TABLE_TITLE'],
        ['table', ['COM_APPOINTMENTS_HELP_PERMISSIONS_COL_PERMISSION', 'COM_APPOINTMENTS_HELP_PERMISSIONS_COL_ALLOWS'], [
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_MANAGE', 'COM_APPOINTMENTS_HELP_PERMISSIONS_MANAGE_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_EDIT', 'COM_APPOINTMENTS_HELP_PERMISSIONS_EDIT_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_EDITSTATE', 'COM_APPOINTMENTS_HELP_PERMISSIONS_EDITSTATE_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_DELETE', 'COM_APPOINTMENTS_HELP_PERMISSIONS_DELETE_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_CREATE', 'COM_APPOINTMENTS_HELP_PERMISSIONS_CREATE_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_OPTIONS', 'COM_APPOINTMENTS_HELP_PERMISSIONS_OPTIONS_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_ADMIN', 'COM_APPOINTMENTS_HELP_PERMISSIONS_ADMIN_ALLOWS'],
            ['COM_APPOINTMENTS_HELP_PERMISSIONS_EXTERNAL', 'COM_APPOINTMENTS_HELP_PERMISSIONS_EXTERNAL_ALLOWS'],
        ]],
        ['h3', 'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_TITLE'],
        ['ol', [
            'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_1', 'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_2',
            'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_3', 'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_4',
            'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_5', 'COM_APPOINTMENTS_HELP_PERMISSIONS_ACCESS_6',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_PERMISSIONS_READ'],
    ],
    'close' => [
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_INTRO'],
        ['h3', 'COM_APPOINTMENTS_HELP_CLOSE_SETUP_TITLE'],
        ['ol', [
            'COM_APPOINTMENTS_HELP_CLOSE_SETUP_1', 'COM_APPOINTMENTS_HELP_CLOSE_SETUP_2', 'COM_APPOINTMENTS_HELP_CLOSE_SETUP_3',
            'COM_APPOINTMENTS_HELP_CLOSE_SETUP_4', 'COM_APPOINTMENTS_HELP_CLOSE_SETUP_5',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_SETUP_INVALID'],
        ['h3', 'COM_APPOINTMENTS_HELP_CLOSE_SENT_TITLE'],
        ['ul', ['COM_APPOINTMENTS_HELP_CLOSE_SENT_PERSON', 'COM_APPOINTMENTS_HELP_CLOSE_SENT_BOOKING', 'COM_APPOINTMENTS_HELP_CLOSE_SENT_NEVER']],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_WHEN'],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_NOTHING_DELETED'],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_RETENTION'],
        ['h3', 'COM_APPOINTMENTS_HELP_CLOSE_STATUS_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_STATUS_INTRO'],
        ['ul', [
            'COM_APPOINTMENTS_HELP_CLOSE_STATUS_QUEUED', 'COM_APPOINTMENTS_HELP_CLOSE_STATUS_RETRY', 'COM_APPOINTMENTS_HELP_CLOSE_STATUS_DONE',
            'COM_APPOINTMENTS_HELP_CLOSE_STATUS_BLOCKED', 'COM_APPOINTMENTS_HELP_CLOSE_STATUS_UNCERTAIN',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_RETRY_BUTTON'],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_PROFILE_CHANGE'],
        ['h3', 'COM_APPOINTMENTS_HELP_CLOSE_RECONCILE_TITLE'],
        ['ol', [
            'COM_APPOINTMENTS_HELP_CLOSE_RECONCILE_1', 'COM_APPOINTMENTS_HELP_CLOSE_RECONCILE_2', 'COM_APPOINTMENTS_HELP_CLOSE_RECONCILE_3',
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_CLOSE_RECONCILE_LIMIT'],
    ],
    'tasks' => [
        ['p', 'COM_APPOINTMENTS_HELP_TASKS_INTRO'],
        ['p', 'COM_APPOINTMENTS_HELP_TASKS_INSTALL'],
        ['p', 'COM_APPOINTMENTS_HELP_TASKS_CREATE'],
        ['table', ['COM_APPOINTMENTS_HELP_TASKS_COL_TASK', 'COM_APPOINTMENTS_HELP_TASKS_COL_WHAT',
            'COM_APPOINTMENTS_HELP_TASKS_COL_SCHEDULE', 'COM_APPOINTMENTS_HELP_TASKS_COL_MISSING'], [
            ['COM_APPOINTMENTS_HELP_TASKS_REMINDERS_TASK', 'COM_APPOINTMENTS_HELP_TASKS_REMINDERS_WHAT',
                'COM_APPOINTMENTS_HELP_TASKS_REMINDERS_SCHEDULE', 'COM_APPOINTMENTS_HELP_TASKS_REMINDERS_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_RETENTION_TASK', 'COM_APPOINTMENTS_HELP_TASKS_RETENTION_WHAT',
                'COM_APPOINTMENTS_HELP_TASKS_RETENTION_SCHEDULE', 'COM_APPOINTMENTS_HELP_TASKS_RETENTION_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_CLOSE_TASK', 'COM_APPOINTMENTS_HELP_TASKS_CLOSE_WHAT',
                'COM_APPOINTMENTS_HELP_TASKS_CLOSE_SCHEDULE', 'COM_APPOINTMENTS_HELP_TASKS_CLOSE_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_TEAMRETENTION_TASK', 'COM_APPOINTMENTS_HELP_TASKS_TEAMRETENTION_WHAT',
                'COM_APPOINTMENTS_HELP_TASKS_TEAMRETENTION_SCHEDULE', 'COM_APPOINTMENTS_HELP_TASKS_TEAMRETENTION_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_TEAMCLEANUP_TASK', 'COM_APPOINTMENTS_HELP_TASKS_TEAMCLEANUP_WHAT',
                $tasksManual, 'COM_APPOINTMENTS_HELP_TASKS_TEAMCLEANUP_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_TEAMLOG_TASK', 'COM_APPOINTMENTS_HELP_TASKS_TEAMLOG_WHAT',
                $tasksManual, 'COM_APPOINTMENTS_HELP_TASKS_TEAMLOG_MISSING'],
            ['COM_APPOINTMENTS_HELP_TASKS_TEAMINVITATIONS_TASK', 'COM_APPOINTMENTS_HELP_TASKS_TEAMINVITATIONS_WHAT',
                $tasksManual, 'COM_APPOINTMENTS_HELP_TASKS_TEAMINVITATIONS_MISSING'],
        ]],
        ['p', 'COM_APPOINTMENTS_HELP_TASKS_BATCH'],
        ['p', 'COM_APPOINTMENTS_HELP_TASKS_CHECK'],
    ],
    'plugins' => [
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_INTRO'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_PRIVACY_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_PRIVACY_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_PRIVACY_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_PRIVACY_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_PRIVACY_SETTINGS'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_CONTENT_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_CONTENT_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_CONTENT_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_CONTENT_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_CONTENT_SETTINGS'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_TASK_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_TASK_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_TASK_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_TASK_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_TASK_SETTINGS'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_DAV_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_DAV_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_DAV_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_DAV_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_DAV_SETTINGS'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_ATTRIBUTION_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_ATTRIBUTION_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_ATTRIBUTION_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_ATTRIBUTION_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_ATTRIBUTION_SETTINGS'],
        ['h3', 'COM_APPOINTMENTS_HELP_PLUGINS_USER_TITLE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_USER_WHAT'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_USER_STATE'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_USER_OFF'],
        ['p', 'COM_APPOINTMENTS_HELP_PLUGINS_USER_SETTINGS'],
    ],
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
                <?php foreach ($blocks[$id] ?? [] as $block) : ?>
                    <?php [$type, $content] = $block; ?>
                    <?php if ($type === 'h3') : ?>
                        <h3 class="h5"><?php echo Text::_($content); ?></h3>
                    <?php elseif ($type === 'p') : ?>
                        <p><?php echo Text::_($content); ?></p>
                    <?php elseif ($type === 'ul' || $type === 'ol') : ?>
                        <<?php echo $type; ?>>
                            <?php foreach ($content as $item) : ?>
                                <li class="mb-2"><?php echo Text::_($item); ?></li>
                            <?php endforeach; ?>
                        </<?php echo $type; ?>>
                    <?php elseif ($type === 'table') : ?>
                        <div class="table-responsive">
                            <table class="table table-striped" id="help-<?php echo $id; ?>-table" aria-labelledby="help-<?php echo $id; ?>-heading">
                                <thead>
                                    <tr>
                                        <?php foreach ($content as $column) : ?>
                                            <th scope="col"><?php echo Text::_($column); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($block[2] as $cells) : ?>
                                        <tr>
                                            <th scope="row"><?php echo Text::_(array_shift($cells)); ?></th>
                                            <?php foreach ($cells as $cell) : ?>
                                                <td><?php echo Text::_($cell); ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
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
    <?php // In the toolbar's Help popup (tmpl=component) these links would open inside the popup. ?>
    <?php if (!$this->popup) : ?>
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_HELP_TITLE')); ?>">
            <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_appointments'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'); ?></a>
            <?php if ($this->canOptions) : ?>
                <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_appointments'); ?>"><?php echo Text::_('JOPTIONS'); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
