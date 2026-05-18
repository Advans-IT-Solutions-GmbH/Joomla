<?php
/**
 * Product Comparison Template
 * J2Commerce's view system
 */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Factory;

$app = Factory::getApplication();
$input = $app->input;
$productIds = explode(',', $input->get('products', '', 'string'));

if (empty($productIds)) {
    echo '<p>' . Text::_('PLG_J2STORE_PRODUCTCOMPARE_NO_PRODUCTS') . '</p>';
    return;
}

// Load products — cast all IDs to int to prevent injection
$productIds = array_filter(array_map('intval', $productIds));

if (empty($productIds)) {
    echo '<p>' . Text::_('PLG_J2STORE_PRODUCTCOMPARE_NO_PRODUCTS') . '</p>';
    return;
}

$db = Factory::getContainer()->get('DatabaseDriver');
$query = $db->getQuery(true)
    ->select('*')
    ->from($db->quoteName('#__j2store_products'))
    ->whereIn($db->quoteName('j2store_product_id'), $productIds);

$db->setQuery($query);
$products = $db->loadObjectList();

if (empty($products)) {
    echo '<p>' . Text::_('PLG_J2STORE_PRODUCTCOMPARE_NO_PRODUCTS_FOUND') . '</p>';
    return;
}
?>

<div class="j2store-product-compare">
    <h2><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_TITLE'); ?></h2>
    
    <div class="compare-actions">
        <button type="button" class="btn btn-secondary" onclick="window.print()">
            <?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_PRINT'); ?>
        </button>
        <button type="button" class="btn btn-danger" onclick="J2CommerceProductCompare.clearAll(); window.history.back();">
            <?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_CLEAR_ALL'); ?>
        </button>
    </div>
    
    <div class="table-responsive">
        <table class="table table-bordered compare-table">
            <thead>
                <tr>
                    <th><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_ATTRIBUTE'); ?></th>
                    <?php foreach ($products as $product): ?>
                        <th>
                            <div class="product-header">
                                <h4><?php echo (int) $product->product_source_id; ?></h4>
                                <button type="button" class="btn btn-sm btn-danger"
                                        onclick="J2CommerceProductCompare.removeProduct(<?php echo (int) $product->j2store_product_id; ?>); location.reload();">
                                    <?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_REMOVE'); ?>
                                </button>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_PRODUCT_ID'); ?></strong></td>
                    <?php foreach ($products as $product): ?>
                        <td><?php echo (int) $product->j2store_product_id; ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_PRODUCT_TYPE'); ?></strong></td>
                    <?php foreach ($products as $product): ?>
                        <td><?php echo htmlspecialchars((string) ($product->product_type ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_VISIBILITY'); ?></strong></td>
                    <?php foreach ($products as $product): ?>
                        <td><?php echo (int) $product->visibility; ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_ENABLED'); ?></strong></td>
                    <?php foreach ($products as $product): ?>
                        <td><?php echo $product->enabled ? Text::_('JYES') : Text::_('JNO'); ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <td><strong><?php echo Text::_('PLG_J2STORE_PRODUCTCOMPARE_CREATED'); ?></strong></td>
                    <?php foreach ($products as $product): ?>
                        <td><?php echo htmlspecialchars((string) ($product->created_on ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <?php endforeach; ?>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<style>
@media print {
    .compare-actions {
        display: none;
    }
}
</style>
