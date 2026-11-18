<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

/*
 * Every scheduled task (Joomla Scheduler routine) our extensions ship, by routine ID. The catalog
 * names under 'tasks' which routines an extension checks; ScheduledTasks decides what to do.
 *
 * need     'required'  needed for correct or legally required operation: the installer creates a
 *                      missing task switched on and reports a task that is switched off;
 *          'optional'  only listed (the extension keeps its own behaviour, e.g. created switched off);
 *          ['when' => [component, option, test, default]]
 *                      required while the component option passes the test ('filled': not empty,
 *                      'on': 1), otherwise not needed (and not mentioned).
 * plugin   [group, element] of the task plugin that runs the routine.
 * title    Language key of the routine title (task plugin language file), used as task title.
 * language Language file of the task plugin (loaded from the administrator or plugin folder).
 * purpose  Plain-language sentence: what the task does and why it matters.
 * rules    Joomla execution rules; 'exec-time' is UTC, missing day and time mean "now".
 * form     Task form of the plugin (relative to the plugin folder): its field defaults are the
 *          parameters of a created task.
 * inherit  [type, folder, element] of an extension whose stored options of the same names replace
 *          those defaults (the administrator's own settings win).
 * settings [language key, parameter names]: the created task's settings named in the report.
 */
return [
    'plg_task_j2commerceprivacy.autocleanup' => [
        'need'     => 'required',
        'plugin'   => ['task', 'j2commerceprivacy'],
        'title'    => 'PLG_TASK_J2COMMERCEPRIVACY_TASK_AUTOCLEANUP_TITLE',
        'language' => 'plg_task_j2commerceprivacy',
        'purpose'  => 'ADVANS_TASK_PURPOSE_PRIVACY_CLEANUP',
        'rules'    => ['rule-type' => 'interval-days', 'interval-days' => 1, 'exec-time' => '02:15'],
        'form'     => 'forms/autocleanup.xml',
        'inherit'  => ['plugin', 'privacy', 'j2commerce'],
        'settings' => ['ADVANS_TASK_SETTINGS_PRIVACY_CLEANUP', ['retention_years', 'fiscal_year_end']],
    ],
    'plg_task_appointments.reminders' => [
        'need'     => ['when' => ['com_appointments', 'reminder_offsets', 'filled', '1440,60']],
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_REMINDERS_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_REMINDERS',
        'rules'    => ['rule-type' => 'interval-minutes', 'interval-minutes' => 5],
        'form'     => 'forms/batch.xml',
    ],
    'plg_task_appointments.retention' => [
        'need'     => 'required',
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_RETENTION_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_RETENTION',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 24],
        'form'     => 'forms/batch.xml',
    ],
    'plg_task_appointments.close' => [
        'need'     => 'optional',
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_CLOSE_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_CLOSE',
    ],
    'plg_task_appointments.teamcleanup' => [
        'need'     => ['when' => ['com_appointments', 'teamcal_enabled', 'on', '0']],
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_TEAMCLEANUP_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_TEAMCLEANUP',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 24],
    ],
    'plg_task_appointments.teamlog' => [
        'need'     => ['when' => ['com_appointments', 'teamcal_enabled', 'on', '0']],
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_TEAMLOG_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_TEAMLOG',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 24],
    ],
    'plg_task_appointments.teaminvitations' => [
        'need'     => ['when' => ['com_appointments', 'teamcal_enabled', 'on', '0']],
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_TEAMINVITATIONS_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_TEAMINVITATIONS',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 24],
    ],
    'plg_task_appointments.teamretention' => [
        'need'     => ['when' => ['com_appointments', 'teamcal_enabled', 'on', '0']],
        'plugin'   => ['task', 'appointments'],
        'title'    => 'PLG_TASK_APPOINTMENTS_TEAMRETENTION_TITLE',
        'language' => 'plg_task_appointments',
        'purpose'  => 'ADVANS_TASK_PURPOSE_APPOINTMENTS_TEAMRETENTION',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 24],
    ],
    'plg_task_dmarcreports.ingest' => [
        'need'     => 'required',
        'plugin'   => ['task', 'dmarcreports'],
        'title'    => 'PLG_TASK_DMARCREPORTS_TASK_INGEST_TITLE',
        'language' => 'plg_task_dmarcreports',
        'purpose'  => 'ADVANS_TASK_PURPOSE_DMARC_INGEST',
        'rules'    => ['rule-type' => 'interval-hours', 'interval-hours' => 1],
        'form'     => 'forms/ingest.xml',
    ],
];
