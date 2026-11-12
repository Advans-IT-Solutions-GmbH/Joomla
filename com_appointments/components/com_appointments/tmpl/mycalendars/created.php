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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\Appointments\Site\View\Mycalendars\HtmlView $this */

$created = $this->created;
$dav = $this->escape($this->davUrl);
$user = $this->escape($this->username);
$host = $this->escape($this->siteHost);

// Per client: the steps with their addresses, and the refresh interval as the last step.
$guides = [
    'outlook-caldavsynchronizer' => [
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_1'),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_2'),
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_3', $dav, $user),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_4'),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_5'),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_6'),
    ],
    'apple' => [
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_APPLE_1'),
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_APPLE_2', $host, $user),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_APPLE_3'),
    ],
    'davx5' => [
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_1'),
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_2', $dav, $user),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_3'),
    ],
    'thunderbird' => [
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_THUNDERBIRD_1'),
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_THUNDERBIRD_2', $user, $dav),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_THUNDERBIRD_3'),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_THUNDERBIRD_4'),
    ],
    'emclient' => [
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_EMCLIENT_1'),
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_EMCLIENT_2', $dav, $user),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_EMCLIENT_3'),
    ],
    'other' => [
        Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_OTHER_1', $dav, $user),
        Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OTHER_2'),
    ],
];
// Clients that need a program first: a note and the manufacturer's official download page (HTTPS, external).
// The address is a language string, so an operator can change it with a language override. It is only a link
// the visitor clicks; the server never contacts it.
$downloads = [
    'outlook-caldavsynchronizer' => [
        'COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_URL',
        'COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_GET',
        'COM_APPOINTMENTS_TEAM_D_GUIDE_OUTLOOK_LINK',
    ],
    'davx5' => [
        'COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_URL',
        'COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_GET',
        'COM_APPOINTMENTS_TEAM_D_GUIDE_DAVX5_LINK',
    ],
];
$chosen = $created['client'];
$download = static function (string $client) use ($downloads): string {
    if (!isset($downloads[$client])) {
        return '';
    }

    [$url, $note, $label] = $downloads[$client];
    $url = Text::_($url);

    // Only HTTPS addresses, whatever a language override says.
    if (!str_starts_with($url, 'https://')) {
        return '';
    }

    $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    return '<p class="form-text">' . Text::_($note) . ' <a href="' . $url . '" target="_blank" rel="noopener noreferrer">'
        . Text::_($label) . '<span class="visually-hidden"> ' . Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_NEW_WINDOW') . '</span></a></p>';
};
$guide = static fn (array $steps, string $client): string => $download($client) . '<ol class="com-appointments-mycal-steps">'
    . implode('', array_map(static fn (string $step): string => '<li>' . $step . '</li>', $steps)) . '</ol>';
?>
<div class="com-appointments com-appointments-mycalendars">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <?php // The answer to the form itself, not a redirect: autofocus brings the outcome into view and to the screen reader. ?>
        <p class="com-appointments-status" role="status" tabindex="-1" autofocus data-appointments-result><?php echo Text::sprintf('COM_APPOINTMENTS_TEAM_D_CREATED_INTRO', $this->escape($created['name'])); ?></p>
        <?php if (!$created['mail_sent']) : ?>
            <p class="com-appointments-notice"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_CREATED_NO_MAIL'); ?></p>
        <?php endif; ?>

        <section class="com-appointments-card com-appointments-mycal-secret" aria-labelledby="created-password">
            <h2 id="created-password" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_APP_PASSWORD'); ?></h2>
            <p class="com-appointments-mycal-password-line">
                <code id="appointments-app-password" class="com-appointments-mycal-password" translate="no" spellcheck="false"><?php echo $this->escape($created['app_password']); ?></code>
                <button type="button" class="btn btn-secondary com-appointments-mycal-copy" data-appointments-copy="appointments-app-password" data-appointments-copied="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_TEAM_D_COPIED')); ?>" hidden><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_COPY'); ?></button>
                <span class="com-appointments-mycal-copy-status" role="status" aria-live="polite"></span>
            </p>
            <p><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_APP_PASSWORD_ONCE'); ?></p>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="created-access">
            <h2 id="created-access" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS'); ?></h2>
            <dl class="com-appointments-summary">
                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS_SERVER'); ?></dt>
                <dd><code translate="no"><?php echo $dav; ?></code></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS_USERNAME'); ?></dt>
                <dd><code translate="no"><?php echo $user; ?></code></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS_PASSWORD'); ?></dt>
                <dd><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_ACCESS_PASSWORD_ABOVE'); ?></dd>
            </dl>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="created-removal">
            <h2 id="created-removal" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_TITLE'); ?></h2>
            <p><strong><?php echo Text::_(DeviceService::REMOVAL_LABELS[$created['removal_mode']]); ?></strong></p>
            <p><?php echo Text::_($created['removal_mode'] === TeamStates::REMOVAL_BLOCK ? 'COM_APPOINTMENTS_TEAM_D_REMOVAL_BLOCK_DESC' : 'COM_APPOINTMENTS_TEAM_D_REMOVAL_CLEANUP_DESC'); ?></p>
            <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_OUTLOOK'); ?></p>
            <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_REMOVAL_CHANGE'); ?></p>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="created-guide">
            <h2 id="created-guide" class="com-appointments-title"><?php echo Text::sprintf('COM_APPOINTMENTS_TEAM_D_GUIDE_FOR', Text::_(DeviceService::CLIENT_LABELS[$chosen])); ?></h2>
            <?php echo $guide($guides[$chosen], $chosen); ?>
            <p class="form-text"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_AUTOMATIC'); ?></p>
        <p class="form-text" id="created-not-cancel"><strong><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_NOT_CANCEL'); ?></strong></p>
        </section>

        <section class="com-appointments-mycal-section" aria-labelledby="created-others">
            <h2 id="created-others" class="com-appointments-title"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_GUIDE_OTHERS'); ?></h2>
            <?php foreach ($guides as $client => $steps) : ?>
                <?php if ($client !== $chosen) : ?>
                    <details class="com-appointments-mycal-guide">
                        <summary><?php echo Text::_(DeviceService::CLIENT_LABELS[$client]); ?></summary>
                        <?php echo $guide($steps, $client); ?>
                    </details>
                <?php endif; ?>
            <?php endforeach; ?>
            <p class="com-appointments-notice"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_UNSUPPORTED'); ?></p>
        </section>

        <p><a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=mycalendars'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_D_BACK'); ?></a></p>
    </div>
</div>
