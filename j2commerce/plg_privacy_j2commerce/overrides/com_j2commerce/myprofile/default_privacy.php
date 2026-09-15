<?php
/**
 * J2Commerce 6 MyProfile — Privacy tab content
 * Template override for plg_privacy_j2commerce
 *
 * Loaded by myprofile/default.php via $this->loadTemplate('privacy').
 * Shows the consent status from #__privacy_consents and a link to the Joomla
 * privacy request form (logged-in users) or a mailto link (guests; com_privacy
 * redirects guests to the login page). The markup lives in the plugin layout
 * layouts/privacy_tab.php and can be overridden in
 *   templates/{template}/html/layouts/privacy_tab.php
 *
 * INSTALLATION
 * Automatically copied to:
 *   templates/{active-template}/html/com_j2commerce/myprofile/default_privacy.php
 * when it does not exist yet (install and update). Never overwritten.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

\defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;

$_privacyPlugin = PluginHelper::getPlugin('privacy', 'j2commerce');

if (empty($_privacyPlugin)) {
    return;
}

$_app = Factory::getApplication();
$_app->getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

$_params  = new Registry($_privacyPlugin->params);
$_session = $_app->getSession();
$_userId  = (int) ($this->user->id ?? 0);

// Guest access is valid only with both session values J2Commerce sets for guest orders.
$_guestEmail = $_userId === 0 ? (string) $_session->get('guest_order_email', '', 'j2commerce') : '';
$_isGuest    = $_guestEmail !== '' && (string) $_session->get('guest_order_token', '', 'j2commerce') !== '';

$_status = ['consented' => false, 'records' => []];

if (($_userId > 0 || $_isGuest) && class_exists(ConsentRepository::class)) {
    try {
        $_status = (new ConsentRepository())->getStatus($_userId, $_isGuest ? $_guestEmail : '');
    } catch (\Throwable $e) {
        Log::add('Privacy tab consent lookup failed: ' . $e->getMessage(), Log::WARNING, 'plg_privacy_j2commerce');
    }
}

$_records = [];

foreach ($_status['records'] as $_record) {
    $_records[] = [
        'date'     => HTMLHelper::_('date', $_record->created, Text::_('DATE_FORMAT_LC2')),
        'order_id' => $_record->order_id,
        'source'   => $_record->source,
    ];
}

$_contactEmail = trim((string) $_params->get('support_email', '')) ?: (string) $_app->get('mailfrom', '');

echo LayoutHelper::render(
    'privacy_tab',
    [
        'consented'      => $_status['consented'],
        'records'        => $_records,
        'isGuest'        => $_userId === 0,
        'showRequest'    => (bool) $_params->get('show_export_data', 1) || (bool) $_params->get('show_delete_all', 1),
        'requestUrl'     => $_userId > 0 ? Route::_('index.php?option=com_privacy&view=request') : '',
        'contactEmail'   => filter_var($_contactEmail, FILTER_VALIDATE_EMAIL) ? $_contactEmail : '',
        'retentionYears' => (int) $_params->get('retention_years', 10),
    ],
    JPATH_PLUGINS . '/privacy/j2commerce/layouts'
);
