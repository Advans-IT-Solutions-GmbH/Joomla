<?php
/**
 * J2Commerce 4 (J2Store) MyProfile — Privacy tab content
 * Template override for plg_privacy_j2commerce
 *
 * Loaded by myprofile/default.php via $this->loadTemplate('privacy').
 * Shows the consent status from #__privacy_consents and a link to the Joomla
 * privacy request form (logged-in users) or a mailto link (guests; com_privacy
 * redirects guests to the login page). The markup lives in the plugin layout
 * layouts/privacy_tab.php; a template copy in
 *   templates/{template}/html/layouts/plg_privacy_j2commerce/privacy_tab.php
 * takes precedence.
 *
 * Note: checkout consent is recorded automatically only on J2Commerce 6. On
 * J2Commerce 4 the status shows consents stored with the user account (for
 * example Joomla's registration consent).
 *
 * INSTALLATION
 * Automatically copied on first install to
 *   templates/{template}/html/com_j2store/myprofile/default_privacy.php
 * On updates it is only added where that template has a myprofile/default.php
 * override and the file is missing. Never overwritten.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */
defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\FileLayout;
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

// A guest session is valid only with both values J2Store sets for a guest order. The token
// identifies exactly one order; the tab shows nothing beyond that order.
$_guestEmail = $_userId === 0 ? (string) $_session->get('guest_order_email', '', 'j2store') : '';
$_guestToken = $_userId === 0 ? (string) $_session->get('guest_order_token', '', 'j2store') : '';
$_isGuest    = $_guestEmail !== '' && $_guestToken !== '';

$_status = ['consented' => false, 'records' => []];

if (($_userId > 0 || $_isGuest) && class_exists(ConsentRepository::class)) {
    try {
        $_status = (new ConsentRepository())->getStatus($_userId, $_isGuest ? $_guestEmail : '', $_isGuest ? $_guestToken : '');
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

// Template layout first, plugin layout as fallback.
$_template    = $_app->getTemplate(true);
$_layoutPaths = [JPATH_THEMES . '/' . $_template->template . '/html/layouts/plg_privacy_j2commerce'];

if (!empty($_template->parent)) {
    $_layoutPaths[] = JPATH_THEMES . '/' . $_template->parent . '/html/layouts/plg_privacy_j2commerce';
}

$_layoutPaths[] = JPATH_PLUGINS . '/privacy/j2commerce/layouts';

$_layout = new FileLayout('privacy_tab', null, ['component' => 'none']);
$_layout->setIncludePaths($_layoutPaths);

echo $_layout->render([
    'consented'      => $_status['consented'],
    'records'        => $_records,
    'isGuest'        => $_isGuest,
    'showRequest'    => ($_userId > 0 || $_isGuest)
        && ((bool) $_params->get('show_export_data', 1) || (bool) $_params->get('show_delete_all', 1)),
    'requestUrl'     => $_userId > 0 ? Route::_('index.php?option=com_privacy&view=request') : '',
    'contactEmail'   => filter_var($_contactEmail, FILTER_VALIDATE_EMAIL) ? $_contactEmail : '',
    'retentionYears' => (int) $_params->get('retention_years', 10),
]);
