<?php
/**
 * J2Commerce MyProfile — Addresses Tab
 * Template override for plg_privacy_j2commerce
 *
 * This file is a copy of the J2Commerce default template with one addition:
 * a "Delete address" button is rendered per address row via PluginHelper,
 * without any HTML patching or inline JavaScript injection.
 *
 * WHY THIS OVERRIDE IS NEEDED
 * J2Commerce's address template has no plugin hooks. The privacy plugin
 * needs to add a delete button per address row. Without this override the
 * plugin would have to inject the button by patching the rendered HTML body,
 * which is fragile and breaks with custom templates.
 *
 * INSTALLATION
 * This file is automatically copied to:
 *   templates/{active-template}/html/com_j2store/myprofile/default_addresses.php
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
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

// get addresses
$addresses     = $this->addresses ?? [];
$J2gridCol     = ($this->params->get('bootstrap_version', 2) == 2) ? 'span' : 'col-md-';

$_privacyPlugin  = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled = !empty($_privacyPlugin);

if ($_privacyEnabled) {
    $lang = \Joomla\CMS\Factory::getApplication()->getLanguage();
    $lang->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
    $_deleteUrl  = Uri::base() . 'index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy&format=json&task=deleteAddress';
    $_token      = Session::getFormToken();
    $_confirmMsg = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_CONFIRM');
    $_successMsg = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_SUCCESS');
    $_errorMsg   = Text::_('PLG_PRIVACY_J2COMMERCE_DELETE_ADDRESS_ERROR');
}
?>
<div class="j2store-myprofile-addresses">

    <?php if (empty($addresses)) : ?>
        <p><?php echo JText::_('J2STORE_NO_ADDRESSES_FOUND'); ?></p>
    <?php else : ?>
        <div class="<?php echo $J2gridCol; ?>12">
            <?php foreach ($addresses as $address) : ?>
                <div class="j2store-address-item mb-3 p-3 border rounded" data-address-id="<?php echo (int) $address->j2store_address_id; ?>">

                    <address>
                        <?php echo $this->escape($address->firstname ?? ''); ?> <?php echo $this->escape($address->lastname ?? ''); ?><br>
                        <?php if (!empty($address->company)) : ?>
                            <?php echo $this->escape($address->company); ?><br>
                        <?php endif; ?>
                        <?php echo $this->escape($address->address_1 ?? ''); ?><br>
                        <?php if (!empty($address->address_2)) : ?>
                            <?php echo $this->escape($address->address_2); ?><br>
                        <?php endif; ?>
                        <?php echo $this->escape($address->city ?? ''); ?>
                        <?php echo $this->escape($address->zip ?? ''); ?><br>
                        <?php echo $this->escape($address->country_name ?? ''); ?>
                    </address>

                    <div class="j2store-address-actions">
                        <a href="<?php echo $this->escape($address->edit_url ?? '#'); ?>" class="btn btn-sm btn-secondary">
                            <?php echo JText::_('J2STORE_EDIT'); ?>
                        </a>

                        <?php if ($_privacyEnabled) : ?>
                            <button type="button"
                                    class="btn btn-sm btn-danger j2commerce-delete-address-btn"
                                    data-address-id="<?php echo (int) $address->j2store_address_id; ?>"
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
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>

<?php if ($_privacyEnabled) : ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.j2commerce-delete-address-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!confirm(btn.dataset.confirm)) {
                return;
            }
            var addressId  = btn.dataset.addressId;
            var url        = btn.dataset.deleteUrl + '&address_id=' + encodeURIComponent(addressId) + '&' + btn.dataset.token + '=1';
            var addressRow = btn.closest('[data-address-id]');

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
