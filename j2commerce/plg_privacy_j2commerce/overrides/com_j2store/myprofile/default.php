<?php
/**
 * J2Commerce MyProfile — Main Tab View
 * Template override for plg_privacy_j2commerce
 *
 * This file is a copy of the J2Commerce default template with one addition:
 * a "Privacy" tab is rendered via PluginHelper, without any HTML patching.
 *
 * WHY THIS OVERRIDE IS NEEDED
 * J2Commerce's eventWithHtml() only imports plugins in the 'j2store' group.
 * The privacy plugin is in the 'privacy' group (required for Joomla's native
 * com_privacy integration). There is no hook available to a privacy-group
 * plugin inside the J2Commerce MyProfile view — hence this template override.
 *
 * INSTALLATION
 * This file is automatically copied to:
 *   templates/{active-template}/html/com_j2store/myprofile/default.php
 * on first install. On updates it is never overwritten.
 * If you use a custom template, copy this file manually to the path above
 * and adapt it to your template's markup.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */
defined('_JEXEC') or die;

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Language\Text;

$platform = J2Store::platform();
$platform->loadExtra('behavior.modal');
$this->params = J2Store::config();
$plugin_title_html          = J2Store::plugin()->eventWithHtml('AddMyProfileTab');
$plugin_content_html        = J2Store::plugin()->eventWithHtml('AddMyProfileTabContent', [$this->orders]);
$messages_above_profile_html = J2Store::plugin()->eventWithHtml('AddMessagesToMyProfileTop', [$this->orders]);

$J2gridRow         = ($this->params->get('bootstrap_version', 2) == 2) ? 'row-fluid' : 'row';
$J2gridCol         = ($this->params->get('bootstrap_version', 2) == 2) ? 'span' : 'col-md-';
$bootstrap_version = $this->params->get('bootstrap_version', 2);
$app               = $platform->application();
$active_menu       = $app->getMenu()->getActive();

$page_heading         = is_object($active_menu) ? $active_menu->getParams() : $platform->getRegistry('{}');
$page_heading_enabled = $page_heading->get('show_page_heading', 0);
$page_heading_text    = $page_heading->get('page_heading', '');

// Privacy plugin — tab title and content
// Only while the privacy plugin is enabled and "Show Privacy Section" is on.
$_privacyOptions = 'Advans\\Plugin\\Privacy\\J2Commerce\\Frontend\\PrivacyOptions';
$_privacyEnabled = class_exists($_privacyOptions) && $_privacyOptions::showPrivacyTab();
$_privacyTabId   = 'j2commerce-privacy-tab';

if ($_privacyEnabled) {
    // The privacy plugin group is not imported in the frontend, so its language is loaded here.
    $_privacyOptions::loadLanguage();
}
?>
<?php if ($page_heading_enabled) : ?>
    <div class="page-header">
        <h1><?php echo $this->escape($page_heading_text); ?></h1>
    </div>
<?php endif; ?>

<?php if ($this->params->get('show_logout_myprofile', 0)) : ?>
    <?php
    $platform->loadExtra('behavior.keepalive');
    $return_url = $platform->getMyprofileUrl([], false, true);
    $return     = base64_encode($return_url);
    ?>
    <?php $user = JFactory::getUser(); ?>
    <?php if ($user->id > 0) : ?>
        <div class="pull-right">
            <form action="<?php echo JRoute::_('index.php'); ?>" method="post" id="login-form" class="form-vertical">
                <div class="logout-button">
                    <input type="submit" name="Submit" class="btn btn-primary" value="<?php echo JText::_('JLOGOUT'); ?>" />
                    <input type="hidden" name="option" value="com_users" />
                    <input type="hidden" name="task" value="user.logout" />
                    <input type="hidden" name="return" value="<?php echo $return; ?>" />
                    <?php echo JHtml::_('form.token'); ?>
                </div>
            </form>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php echo J2Store::modules()->loadposition('j2store-myprofile-top'); ?>

<div class="j2store">
    <div class="j2store-order j2store-myprofile">
        <h3><?php echo JText::_('J2STORE_MYPROFILE'); ?></h3>

        <?php if ($messages_above_profile_html !== '') : ?>
            <div class="j2store-myprofile-addtional_messages">
                <?php echo $messages_above_profile_html; ?>
            </div>
        <?php endif; ?>

        <div class="tabbable tabs">

            <?php // ── Bootstrap 2 / 3 ──────────────────────────────────── ?>
            <?php if (in_array($bootstrap_version, [2, 3])) : ?>
                <ul class="nav nav-tabs">
                    <li class="active">
                        <a href="#orders-tab" data-toggle="tab">
                            <i class="fa fa-th-large"></i> <?php echo JText::_('J2STORE_MYPROFILE_ORDERS'); ?>
                        </a>
                    </li>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <li>
                            <a href="#downloads-tab" data-toggle="tab">
                                <i class="fa fa-cloud-download"></i> <?php echo JText::_('J2STORE_MYPROFILE_DOWNLOADS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <li>
                            <a href="#address-tab" data-toggle="tab">
                                <i class="fa fa-globe"></i> <?php echo JText::_('J2STORE_MYPROFILE_ADDRESS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <li>
                            <a href="#<?php echo $_privacyTabId; ?>" data-toggle="tab">
                                <i class="fa fa-shield"></i> <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php echo $plugin_title_html; ?>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane active" id="orders-tab">
                        <?php echo J2Store::modules()->loadposition('j2store-myprofile-order'); ?>
                        <div class="table-responsive">
                            <?php echo $this->loadTemplate('orders'); ?>
                        </div>
                    </div>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <div class="tab-pane" id="downloads-tab">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-download'); ?>
                            <div class="<?php echo $J2gridCol; ?>12">
                                <div class="table-responsive">
                                    <?php echo $this->loadTemplate('downloads'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <div class="tab-pane" id="address-tab">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-address'); ?>
                            <div class="<?php echo $J2gridCol; ?>12">
                                <?php echo $this->loadTemplate('addresses'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <div class="tab-pane" id="<?php echo $_privacyTabId; ?>">
                            <?php echo $this->loadTemplate('privacy'); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo $plugin_content_html; ?>
                </div>

            <?php // ── Bootstrap 4 ───────────────────────────────────────── ?>
            <?php elseif (in_array($bootstrap_version, [4])) : ?>
                <ul class="nav nav-tabs" id="myProfileTab" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" data-toggle="tab" href="#orders-tab" role="tab">
                            <i class="fa fa-th-large"></i> <?php echo JText::_('J2STORE_MYPROFILE_ORDERS'); ?>
                        </a>
                    </li>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#downloads-tab" role="tab">
                                <i class="fa fa-cloud-download"></i> <?php echo JText::_('J2STORE_MYPROFILE_DOWNLOADS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#address-tab" role="tab">
                                <i class="fa fa-globe"></i> <?php echo JText::_('J2STORE_MYPROFILE_ADDRESS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <li class="nav-item">
                            <a class="nav-link" data-toggle="tab" href="#<?php echo $_privacyTabId; ?>" role="tab">
                                <i class="fa fa-shield"></i> <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php echo $plugin_title_html; ?>
                </ul>
                <div class="tab-content" id="myProfileTabContent">
                    <div class="tab-pane fade show active" id="orders-tab" role="tabpanel">
                        <?php echo J2Store::modules()->loadposition('j2store-myprofile-order'); ?>
                        <div class="table-responsive">
                            <?php echo $this->loadTemplate('orders'); ?>
                        </div>
                    </div>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <div class="tab-pane fade" id="downloads-tab" role="tabpanel">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-download'); ?>
                            <div class="<?php echo $J2gridCol; ?>12">
                                <div class="table-responsive">
                                    <?php echo $this->loadTemplate('downloads'); ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <div class="tab-pane fade" id="address-tab" role="tabpanel">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-address'); ?>
                            <div class="<?php echo $J2gridCol; ?>12">
                                <?php echo $this->loadTemplate('addresses'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <div class="tab-pane fade" id="<?php echo $_privacyTabId; ?>" role="tabpanel">
                            <?php echo $this->loadTemplate('privacy'); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo $plugin_content_html; ?>
                </div>

            <?php // ── Bootstrap 5 (Joomla 4+) ───────────────────────────── ?>
            <?php else : ?>
                <ul class="nav nav-tabs" id="myProfileTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <a class="nav-link active" data-bs-toggle="tab" data-bs-target="#orders-tab" type="button" role="tab">
                            <i class="fa fa-th-large"></i> <?php echo JText::_('J2STORE_MYPROFILE_ORDERS'); ?>
                        </a>
                    </li>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" data-bs-toggle="tab" data-bs-target="#downloads-tab" type="button" role="tab">
                                <i class="fa fa-cloud-download"></i> <?php echo JText::_('J2STORE_MYPROFILE_DOWNLOADS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" data-bs-toggle="tab" data-bs-target="#address-tab" type="button" role="tab">
                                <i class="fa fa-globe"></i> <?php echo JText::_('J2STORE_MYPROFILE_ADDRESS'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <li class="nav-item" role="presentation">
                            <a class="nav-link" data-bs-toggle="tab" data-bs-target="#<?php echo $_privacyTabId; ?>" type="button" role="tab">
                                <i class="fa fa-shield"></i> <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE'); ?>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php echo $plugin_title_html; ?>
                </ul>
                <div class="tab-content" id="myProfileTabContent">
                    <div class="tab-pane fade show active" id="orders-tab" role="tabpanel">
                        <?php echo J2Store::modules()->loadposition('j2store-myprofile-order'); ?>
                        <div class="table-responsive">
                            <?php echo $this->loadTemplate('orders'); ?>
                        </div>
                    </div>
                    <?php if ($this->params->get('download_area', 1)) : ?>
                        <div class="tab-pane fade" id="downloads-tab" role="tabpanel">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-download'); ?>
                            <div class="table-responsive">
                                <?php echo $this->loadTemplate('downloads'); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($this->user->id) : ?>
                        <div class="tab-pane fade" id="address-tab" role="tabpanel">
                            <?php echo J2Store::modules()->loadposition('j2store-myprofile-address'); ?>
                            <?php echo $this->loadTemplate('addresses'); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($_privacyEnabled) : ?>
                        <div class="tab-pane fade" id="<?php echo $_privacyTabId; ?>" role="tabpanel">
                            <?php echo $this->loadTemplate('privacy'); ?>
                        </div>
                    <?php endif; ?>
                    <?php echo $plugin_content_html; ?>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php echo J2Store::modules()->loadposition('j2store-myprofile-bottom'); ?>
