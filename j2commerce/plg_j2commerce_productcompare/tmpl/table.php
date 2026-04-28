<?php
/**
 * Layout: Comparison table (rendered server-side, returned via AJAX)
 *
 * Available variables:
 *   $products  (array)  Array of product objects with properties:
 *                         ->title       (string)
 *                         ->sku         (string)
 *                         ->price       (float)
 *                         ->stock       (int)
 *                         ->introtext   (string)  raw HTML from Joomla article
 *                         ->options     (array)   key/value pairs of product options
 *
 * Template override path:
 *   templates/{your-template}/html/plg_j2store_productcompare/table.php
 */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;

if (empty($products)) : ?>
    <p><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_NO_PRODUCTS'); ?></p>
<?php return;
endif;
?>
<div class="table-responsive">
    <table class="j2store-comparison-table">
        <thead>
            <tr>
                <th scope="col"><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_ATTRIBUTE'); ?></th>
                <?php foreach ($products as $product) : ?>
                    <th scope="col"><?php echo $this->escape($product->title ?? ''); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <tr>
                <th scope="row"><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_SKU'); ?></th>
                <?php foreach ($products as $product) : ?>
                    <td><?php echo $this->escape($product->sku ?? '-'); ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <th scope="row"><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_PRICE'); ?></th>
                <?php foreach ($products as $product) : ?>
                    <td><?php echo HTMLHelper::_('currency.format', $product->price ?? 0); ?></td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <th scope="row"><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_STOCK'); ?></th>
                <?php foreach ($products as $product) : ?>
                    <td>
                        <?php echo ($product->stock ?? 0) > 0
                            ? Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_IN_STOCK')
                            : Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_OUT_OF_STOCK'); ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <tr>
                <th scope="row"><?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_DESCRIPTION'); ?></th>
                <?php foreach ($products as $product) : ?>
                    <td><?php echo $this->escape(mb_substr(strip_tags($product->introtext ?? ''), 0, 200)); ?>…</td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
</div>
