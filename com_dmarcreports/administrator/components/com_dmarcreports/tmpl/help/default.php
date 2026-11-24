<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\DmarcReports\Administrator\Helper\ActionPresenter;
use Advans\Component\DmarcReports\Administrator\Helper\HelpTopics;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

$contents = [
    'basics'   => 'COM_DMARCREPORTS_HELP_BASICS_TITLE',
    'how'      => 'COM_DMARCREPORTS_HELP_HOW_TITLE',
    'actions'  => 'COM_DMARCREPORTS_HELP_ACTIONS_TITLE',
    'tasks'    => 'COM_DMARCREPORTS_HELP_TASKS_TITLE',
    'settings' => 'COM_DMARCREPORTS_HELP_SETTINGS_TITLE',
    'install'  => 'COM_DMARCREPORTS_HELP_INSTALL_TITLE',
    'trouble'  => 'COM_DMARCREPORTS_HELP_TROUBLE_TITLE',
];

// The protocol names are the same in every language.
$basics = [
    'DMARC'   => 'COM_DMARCREPORTS_HELP_BASICS_DMARC',
    'SPF'     => 'COM_DMARCREPORTS_HELP_BASICS_SPF',
    'DKIM'    => 'COM_DMARCREPORTS_HELP_BASICS_DKIM',
    'MTA-STS' => 'COM_DMARCREPORTS_HELP_BASICS_MTASTS',
    'TLS-RPT' => 'COM_DMARCREPORTS_HELP_BASICS_TLSRPT',
];

$how = [
    ['COM_DMARCREPORTS_HELP_HOW_SOURCES_TITLE', 'COM_DMARCREPORTS_HELP_HOW_SOURCES_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_IMPORT_TITLE', 'COM_DMARCREPORTS_HELP_HOW_IMPORT_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_SCHEDULED_TITLE', 'COM_DMARCREPORTS_HELP_HOW_SCHEDULED_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_DASHBOARD_TITLE', 'COM_DMARCREPORTS_HELP_HOW_DASHBOARD_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_DETAIL_TITLE', 'COM_DMARCREPORTS_HELP_HOW_DETAIL_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_JUDGE_TITLE', 'COM_DMARCREPORTS_HELP_HOW_JUDGE_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_MODULE_TITLE', 'COM_DMARCREPORTS_HELP_HOW_MODULE_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_PLUGIN_TITLE', 'COM_DMARCREPORTS_HELP_HOW_PLUGIN_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_PRIVACY_TITLE', 'COM_DMARCREPORTS_HELP_HOW_PRIVACY_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_RETENTION_TITLE', 'COM_DMARCREPORTS_HELP_HOW_RETENTION_TEXT'],
];

$severities = [
    3 => 'COM_DMARCREPORTS_HELP_SEVERITY_3',
    2 => 'COM_DMARCREPORTS_HELP_SEVERITY_2',
    1 => 'COM_DMARCREPORTS_HELP_SEVERITY_1',
    0 => 'COM_DMARCREPORTS_HELP_SEVERITY_0',
];

$tasks = [
    'mailbox' => ['COM_DMARCREPORTS_HELP_TASK_MAILBOX_TITLE', [
        'COM_DMARCREPORTS_HELP_TASK_MAILBOX_STEP_1', 'COM_DMARCREPORTS_HELP_TASK_MAILBOX_STEP_2',
        'COM_DMARCREPORTS_HELP_TASK_MAILBOX_STEP_3', 'COM_DMARCREPORTS_HELP_TASK_MAILBOX_STEP_4',
    ]],
    'failing' => ['COM_DMARCREPORTS_HELP_TASK_FAILING_TITLE', [
        'COM_DMARCREPORTS_HELP_TASK_FAILING_STEP_1', 'COM_DMARCREPORTS_HELP_TASK_FAILING_STEP_2',
        'COM_DMARCREPORTS_HELP_TASK_FAILING_STEP_3', 'COM_DMARCREPORTS_HELP_TASK_FAILING_STEP_4',
    ]],
    'rollout' => ['COM_DMARCREPORTS_HELP_TASK_ROLLOUT_TITLE', [
        'COM_DMARCREPORTS_HELP_TASK_ROLLOUT_STEP_1', 'COM_DMARCREPORTS_HELP_TASK_ROLLOUT_STEP_2',
        'COM_DMARCREPORTS_HELP_TASK_ROLLOUT_STEP_3', 'COM_DMARCREPORTS_HELP_TASK_ROLLOUT_STEP_4',
    ]],
    'mtasts' => ['COM_DMARCREPORTS_HELP_TASK_MTASTS_TITLE', [
        'COM_DMARCREPORTS_HELP_TASK_MTASTS_STEP_1', 'COM_DMARCREPORTS_HELP_TASK_MTASTS_STEP_2',
        'COM_DMARCREPORTS_HELP_TASK_MTASTS_STEP_3', 'COM_DMARCREPORTS_HELP_TASK_MTASTS_STEP_4',
    ]],
    'noreports' => ['COM_DMARCREPORTS_HELP_TASK_NOREPORTS_TITLE', [
        'COM_DMARCREPORTS_HELP_TASK_NOREPORTS_STEP_1', 'COM_DMARCREPORTS_HELP_TASK_NOREPORTS_STEP_2',
        'COM_DMARCREPORTS_HELP_TASK_NOREPORTS_STEP_3', 'COM_DMARCREPORTS_HELP_TASK_NOREPORTS_STEP_4',
    ]],
];

// Headings reuse the labels of the Options tabs and the domain form, so the help
// names exactly what the administrator sees there.
// Every option of config.xml: [label key, help text key]. The text names the default.
$optionGroups = [
    ['COM_DMARCREPORTS_CONFIG_BASIC_FIELDSET', 'COM_DMARCREPORTS_HELP_SETTINGS_BASIC', [
        ['COM_DMARCREPORTS_CONFIG_POLICY_TARGET_LABEL', 'COM_DMARCREPORTS_HELP_OPT_POLICY_TARGET'],
        ['COM_DMARCREPORTS_CONFIG_QUARANTINE_THRESHOLD_LABEL', 'COM_DMARCREPORTS_HELP_OPT_QUARANTINE'],
        ['COM_DMARCREPORTS_CONFIG_REJECT_THRESHOLD_LABEL', 'COM_DMARCREPORTS_HELP_OPT_REJECT'],
        ['COM_DMARCREPORTS_CONFIG_IMAP_TIMEOUT_LABEL', 'COM_DMARCREPORTS_HELP_OPT_IMAP_TIMEOUT'],
        ['COM_DMARCREPORTS_CONFIG_MAX_MESSAGES_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_MESSAGES'],
        ['COM_DMARCREPORTS_CONFIG_MAX_RUN_REPORTS_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_RUN_REPORTS'],
        ['COM_DMARCREPORTS_CONFIG_MAX_RUN_UNPACKED_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_RUN_UNPACKED'],
        ['COM_DMARCREPORTS_CONFIG_MAX_MESSAGE_ATTACHMENTS_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_ATTACHMENTS'],
        ['COM_DMARCREPORTS_CONFIG_MAX_MESSAGE_REPORTS_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_MESSAGE_REPORTS'],
        ['COM_DMARCREPORTS_CONFIG_MAX_MESSAGE_UNPACKED_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MAX_MESSAGE_UNPACKED'],
        ['COM_DMARCREPORTS_CONFIG_CSV_DELIMITER_LABEL', 'COM_DMARCREPORTS_HELP_OPT_CSV'],
        ['COM_DMARCREPORTS_CONFIG_DNS_DIAGNOSTICS_LABEL', 'COM_DMARCREPORTS_HELP_OPT_DNS'],
        ['COM_DMARCREPORTS_CONFIG_AUTO_CREATE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_AUTO_CREATE'],
        ['COM_DMARCREPORTS_CONFIG_TASK_AUTO_CREATE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_TASK_AUTO_CREATE'],
    ]],
    ['COM_DMARCREPORTS_CONFIG_MTASTS_FIELDSET', 'COM_DMARCREPORTS_HELP_SETTINGS_MTASTS', [
        ['COM_DMARCREPORTS_CONFIG_MTASTS_ENABLE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MTASTS_ENABLE'],
        ['COM_DMARCREPORTS_CONFIG_MTASTS_MODE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MTASTS_MODE'],
        ['COM_DMARCREPORTS_CONFIG_MTASTS_MX_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MTASTS_MX'],
        ['COM_DMARCREPORTS_CONFIG_MTASTS_MAX_AGE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_MTASTS_MAX_AGE'],
        ['COM_DMARCREPORTS_CONFIG_TLSRPT_ENABLE_LABEL', 'COM_DMARCREPORTS_HELP_OPT_TLSRPT_ENABLE'],
        ['COM_DMARCREPORTS_CONFIG_TLSRPT_RUA_LABEL', 'COM_DMARCREPORTS_HELP_OPT_TLSRPT_RUA'],
    ]],
];

// The Joomla permissions of access.xml are the same in every language.
$permissions = [
    'core.manage'     => 'COM_DMARCREPORTS_HELP_PERM_MANAGE',
    'dmarc.ingest'    => 'COM_DMARCREPORTS_HELP_PERM_INGEST',
    'core.create'     => 'COM_DMARCREPORTS_HELP_PERM_CREATE',
    'core.edit'       => 'COM_DMARCREPORTS_HELP_PERM_EDIT',
    'core.edit.state' => 'COM_DMARCREPORTS_HELP_PERM_EDIT_STATE',
    'core.delete'     => 'COM_DMARCREPORTS_HELP_PERM_DELETE',
    'core.options'    => 'COM_DMARCREPORTS_HELP_PERM_ADMIN',
];

$settings = [
    ['COM_DMARCREPORTS_TOOLBAR_DOMAINS', 'COM_DMARCREPORTS_HELP_SETTINGS_DOMAIN'],
    ['COM_DMARCREPORTS_TOOLBAR_SOURCES', 'COM_DMARCREPORTS_HELP_SETTINGS_SOURCE'],
];

$install = [
    ['COM_DMARCREPORTS_HELP_INSTALL_REQUIREMENTS_TITLE', 'COM_DMARCREPORTS_HELP_INSTALL_REQUIREMENTS_TEXT'],
    ['COM_DMARCREPORTS_HELP_INSTALL_BEHAVIOUR_TITLE', 'COM_DMARCREPORTS_HELP_INSTALL_BEHAVIOUR_TEXT'],
    ['COM_DMARCREPORTS_HELP_INSTALL_PACKAGE_TITLE', 'COM_DMARCREPORTS_HELP_INSTALL_PACKAGE_TEXT'],
    ['COM_DMARCREPORTS_HELP_INSTALL_UPDATE_TITLE', 'COM_DMARCREPORTS_HELP_INSTALL_UPDATE_TEXT'],
    ['COM_DMARCREPORTS_HELP_INSTALL_UNINSTALL_TITLE', 'COM_DMARCREPORTS_HELP_INSTALL_UNINSTALL_TEXT'],
];

$trouble = [
    ['COM_DMARCREPORTS_HELP_TROUBLE_IMAP_TITLE', 'COM_DMARCREPORTS_HELP_TROUBLE_IMAP_TEXT'],
    ['COM_DMARCREPORTS_HELP_TROUBLE_UPLOAD_TITLE', 'COM_DMARCREPORTS_HELP_TROUBLE_UPLOAD_TEXT'],
    ['COM_DMARCREPORTS_HELP_TROUBLE_HELD_TITLE', 'COM_DMARCREPORTS_HELP_TROUBLE_HELD_TEXT'],
    ['COM_DMARCREPORTS_HELP_TROUBLE_ACCESS_TITLE', 'COM_DMARCREPORTS_HELP_TROUBLE_ACCESS_TEXT'],
    ['COM_DMARCREPORTS_HELP_TROUBLE_MTASTS_TITLE', 'COM_DMARCREPORTS_HELP_TROUBLE_MTASTS_TEXT'],
];

$links = [
    ['index.php?option=com_dmarcreports&view=domains', 'COM_DMARCREPORTS_TOOLBAR_DOMAINS'],
    ['index.php?option=com_dmarcreports&view=sources', 'COM_DMARCREPORTS_TOOLBAR_SOURCES'],
    ['index.php?option=com_dmarcreports&view=systemcheck', 'COM_DMARCREPORTS_TOOLBAR_SYSTEMCHECK'],
];
?>
<div id="dmarcreports-help" class="com-dmarcreports-help">
    <p class="lead"><?php echo Text::_('COM_DMARCREPORTS_HELP_INTRO'); ?></p>

    <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="<?php echo htmlspecialchars(Text::_('COM_DMARCREPORTS_HELP_CONTENTS')); ?>">
        <?php foreach ($contents as $id => $heading) : ?>
            <a class="btn btn-secondary" href="#help-<?php echo $id; ?>"><?php echo Text::_($heading); ?></a>
        <?php endforeach; ?>
    </nav>

    <section class="card mb-4" id="help-basics" aria-labelledby="help-basics-heading">
        <div class="card-body">
            <h2 class="h4" id="help-basics-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_BASICS_TITLE'); ?></h2>
            <dl class="mb-0">
                <?php foreach ($basics as $term => $text) : ?>
                    <dt><?php echo $term; ?></dt>
                    <dd><?php echo Text::_($text); ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>
    </section>

    <section class="card mb-4" id="help-how" aria-labelledby="help-how-heading">
        <div class="card-body">
            <h2 class="h4" id="help-how-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_HOW_TITLE'); ?></h2>
            <?php foreach ($how as [$title, $text]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($text); ?></p>
            <?php endforeach; ?>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($links as [$url, $label]) : ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo Route::_($url); ?>"><?php echo Text::_($label); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section class="card mb-4" id="help-actions" aria-labelledby="help-actions-heading">
        <div class="card-body">
            <h2 class="h4" id="help-actions-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_ACTIONS_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_ACTIONS_INTRO'); ?></p>
            <ul class="list-unstyled">
                <?php foreach ($severities as $severity => $text) : ?>
                    <li class="mb-1">
                        <span class="badge bg-<?php echo ActionPresenter::severityClass($severity); ?>"><?php echo htmlspecialchars(ActionPresenter::severityLabel($severity)); ?></span>
                        <?php echo Text::_($text); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_ACTIONS_STATUS'); ?></p>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_ACTIONS_VIEWS'); ?></p>
            <?php foreach (HelpTopics::ACTION_GROUPS as $group => $entries) : ?>
                <h3 class="h5 mt-4"><?php echo Text::_($group); ?></h3>
                <?php foreach ($entries as $code => $text) : ?>
                    <div class="border-start border-3 ps-3 mb-3" id="help-action-<?php echo $code; ?>">
                        <h4 class="h6 mb-1"><?php echo htmlspecialchars(ActionPresenter::title(['code' => $code])); ?></h4>
                        <p class="mb-0"><?php echo Text::_($text); ?></p>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card mb-4" id="help-tasks" aria-labelledby="help-tasks-heading">
        <div class="card-body">
            <h2 class="h4" id="help-tasks-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_TASKS_TITLE'); ?></h2>
            <?php foreach ($tasks as $id => [$title, $steps]) : ?>
                <h3 class="h5" id="help-task-<?php echo $id; ?>"><?php echo Text::_($title); ?></h3>
                <ol>
                    <?php foreach ($steps as $step) : ?>
                        <li class="mb-1"><?php echo Text::_($step); ?></li>
                    <?php endforeach; ?>
                </ol>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card mb-4" id="help-settings" aria-labelledby="help-settings-heading">
        <div class="card-body">
            <h2 class="h4" id="help-settings-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_SETTINGS_TITLE'); ?></h2>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_SETTINGS_INTRO'); ?></p>
            <?php foreach ($optionGroups as [$title, $lead, $options]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($lead); ?></p>
                <dl>
                    <?php foreach ($options as [$label, $text]) : ?>
                        <dt><?php echo Text::_($label); ?></dt>
                        <dd><?php echo Text::_($text); ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endforeach; ?>
            <h3 class="h5"><?php echo Text::_('COM_DMARCREPORTS_HELP_SETTINGS_PERMISSIONS_TITLE'); ?></h3>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_SETTINGS_PERMISSIONS'); ?></p>
            <dl>
                <?php foreach ($permissions as $action => $text) : ?>
                    <dt><code><?php echo $action; ?></code></dt>
                    <dd><?php echo Text::_($text); ?></dd>
                <?php endforeach; ?>
            </dl>
            <p><?php echo Text::_('COM_DMARCREPORTS_HELP_PERM_HOWTO'); ?></p>
            <?php foreach ($settings as [$title, $text]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($text); ?></p>
            <?php endforeach; ?>
            <?php if ($this->canOptions) : ?>
                <a class="btn btn-primary btn-sm" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_dmarcreports'); ?>"><?php echo Text::_('COM_DMARCREPORTS_HELP_OPEN_OPTIONS'); ?></a>
            <?php endif; ?>
        </div>
    </section>

    <section class="card mb-4" id="help-install" aria-labelledby="help-install-heading">
        <div class="card-body">
            <h2 class="h4" id="help-install-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_INSTALL_TITLE'); ?></h2>
            <?php foreach ($install as [$title, $text]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($text); ?></p>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card mb-4" id="help-trouble" aria-labelledby="help-trouble-heading">
        <div class="card-body">
            <h2 class="h4" id="help-trouble-heading"><?php echo Text::_('COM_DMARCREPORTS_HELP_TROUBLE_TITLE'); ?></h2>
            <?php foreach ($trouble as [$title, $text]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($text); ?></p>
            <?php endforeach; ?>
        </div>
    </section>
</div>
