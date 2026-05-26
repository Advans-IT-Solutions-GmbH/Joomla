<?php
/**
 * J2Commerce 6 MyProfile — Addresses Tab
 * Template override for plg_privacy_j2commerce
 *
 * Adds a "Delete address" button per address row.
 * Based on com_j2commerce/tmpl/myprofile/bootstrap5/default_addresses.php.
 *
 * WHY THIS OVERRIDE IS NEEDED
 * J2Commerce's address template has no plugin hooks. The privacy plugin
 * needs to add a delete button per address row.
 *
 * INSTALLATION
 * Automatically copied to:
 *   templates/{active-template}/html/com_j2commerce/myprofile/default_addresses.php
 * on first install. Never overwritten on updates.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/** @var \J2Commerce\Component\J2commerce\Site\View\Myprofile\HtmlView $this */

$addresses = $this->addresses;

$_privacyPlugin  = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled = !empty($_privacyPlugin);

if ($_privacyEnabled) {
    $lang = Factory::getApplication()->getLanguage();
    $lang->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
    $_deleteUrl  = Uri::base() . 'index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy&format=json&task=deleteAddress';
    $_token      = Session::getFormToken();
    $_confirmMsg = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_CONFIRM');
    $_successMsg = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_SUCCESS');
    $_errorMsg   = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_ERROR');
}
?>

<div class="j2commerce-address-list">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><?php echo Text::_('COM_J2COMMERCE_ADDRESS_LIST'); ?></h4>
        <a href="<?php echo Route::_('index.php?option=com_j2commerce&view=myprofile&layout=address&address_id=0'); ?>" class="btn btn-primary btn-sm">
            <?php echo Text::_('COM_J2COMMERCE_ADDRESS_ADD'); ?>
        </a>
    </div>

    <?php if (empty($addresses)): ?>
    <div class="alert alert-info"><?php echo Text::_('COM_J2COMMERCE_NO_ADDRESSES'); ?></div>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($addresses as $addr): ?>
        <div class="col-md-6" id="j2commerce-address-<?php echo (int) $addr->j2commerce_address_id; ?>">
            <div class="card h-100 rounded-1">
                <div class="card-header d-flex justify-content-between align-items-center border-0">
                    <span class="badge text-bg-info"><?php echo $this->escape(ucfirst($addr->type)); ?></span>
                    <div>
                        <a href="<?php echo Route::_('index.php?option=com_j2commerce&view=myprofile&layout=address&address_id=' . (int) $addr->j2commerce_address_id); ?>" class="btn btn-sm btn-soft-dark me-2" title="<?php echo Text::_('JACTION_EDIT'); ?>">
                            <span class="icon-pencil" aria-hidden="true"></span>
                        </a>
                        <button type="button" class="btn btn-sm btn-soft-danger j2commerce-address-delete" data-address-id="<?php echo (int) $addr->j2commerce_address_id; ?>" title="<?php echo Text::_('JACTION_DELETE'); ?>">
                            <span class="icon-trash" aria-hidden="true"></span>
                        </button>
                        <?php if ($_privacyEnabled): ?>
                        <button type="button"
                                class="btn btn-sm btn-danger j2commerce-delete-address-btn"
                                data-address-id="<?php echo (int) $addr->j2commerce_address_id; ?>"
                                data-delete-url="<?php echo $this->escape($_deleteUrl); ?>"
                                data-token="<?php echo $_token; ?>"
                                data-confirm="<?php echo $this->escape($_confirmMsg); ?>"
                                data-success="<?php echo $this->escape($_successMsg); ?>"
                                data-error="<?php echo $this->escape($_errorMsg); ?>">
                            <?php echo Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_BTN'); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <strong><?php echo $this->escape($addr->first_name . ' ' . $addr->last_name); ?></strong><br>
                    <?php if (!empty($addr->company)): ?><?php echo $this->escape($addr->company); ?><br><?php endif; ?>
                    <?php echo $this->escape($addr->address_1); ?><br>
                    <?php if (!empty($addr->address_2)): ?><?php echo $this->escape($addr->address_2); ?><br><?php endif; ?>
                    <?php echo $this->escape($addr->city); ?>
                    <?php if (!empty($addr->zip)): ?>, <?php echo $this->escape($addr->zip); ?><?php endif; ?><br>
                    <?php if (!empty($addr->zone_name)): ?><?php echo $this->escape($addr->zone_name); ?>, <?php endif; ?>
                    <?php echo $this->escape($addr->country_name ?? ''); ?>
                    <?php if (!empty($addr->phone_1)): ?><br><?php echo $this->escape($addr->phone_1); ?><?php endif; ?>
                    <?php if (!empty($addr->email)): ?><br><?php echo $this->escape($addr->email); ?><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($_privacyEnabled): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.j2commerce-delete-address-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(btn.dataset.confirm)) {
                return;
            }
            var addressId  = btn.dataset.addressId;
            var url        = btn.dataset.deleteUrl + '&address_id=' + encodeURIComponent(addressId) + '&' + btn.dataset.token + '=1';
            var addressRow = btn.closest('[id^="j2commerce-address-"]');

            fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        if (addressRow) {
                            addressRow.remove();
                        }
                        alert(btn.dataset.success);
                    } else {
                        alert(btn.dataset.error);
                    }
                })
                .catch(function () {
                    alert(btn.dataset.error);
                });
        });
    });
});
</script>
<?php endif; ?>
