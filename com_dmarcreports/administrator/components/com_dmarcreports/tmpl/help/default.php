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
    ['COM_DMARCREPORTS_HELP_HOW_DASHBOARD_TITLE', 'COM_DMARCREPORTS_HELP_HOW_DASHBOARD_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_DETAIL_TITLE', 'COM_DMARCREPORTS_HELP_HOW_DETAIL_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_MODULE_TITLE', 'COM_DMARCREPORTS_HELP_HOW_MODULE_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_PLUGIN_TITLE', 'COM_DMARCREPORTS_HELP_HOW_PLUGIN_TEXT'],
    ['COM_DMARCREPORTS_HELP_HOW_PRIVACY_TITLE', 'COM_DMARCREPORTS_HELP_HOW_PRIVACY_TEXT'],
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
$settings = [
    ['COM_DMARCREPORTS_CONFIG_BASIC_FIELDSET', 'COM_DMARCREPORTS_HELP_SETTINGS_BASIC'],
    ['COM_DMARCREPORTS_CONFIG_MTASTS_FIELDSET', 'COM_DMARCREPORTS_HELP_SETTINGS_MTASTS'],
    ['COM_DMARCREPORTS_HELP_SETTINGS_PERMISSIONS_TITLE', 'COM_DMARCREPORTS_HELP_SETTINGS_PERMISSIONS'],
    ['COM_DMARCREPORTS_FIELD_EXPECTED_POLICY_LABEL', 'COM_DMARCREPORTS_HELP_SETTINGS_DOMAIN'],
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
            <?php foreach ($settings as [$title, $text]) : ?>
                <h3 class="h5"><?php echo Text::_($title); ?></h3>
                <p><?php echo Text::_($text); ?></p>
            <?php endforeach; ?>
            <?php if ($this->canOptions) : ?>
                <a class="btn btn-primary btn-sm" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_dmarcreports'); ?>"><?php echo Text::_('COM_DMARCREPORTS_HELP_OPEN_OPTIONS'); ?></a>
            <?php endif; ?>
        </div>
    </section>
</div>
