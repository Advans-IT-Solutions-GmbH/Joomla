<?php
/**
 * Layout: Compare bar (fixed bottom bar)
 *
 * Available variables:
 *   $maxProducts (int)    Maximum number of products to compare
 *   $ajaxUrl     (string) URL for the AJAX comparison endpoint
 *
 * Template override path:
 *   templates/{your-template}/html/plg_j2store_productcompare/bar.php
 */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
?>
<div id="j2store-compare-bar" class="j2store-compare-bar" style="display:none;" aria-live="polite">
    <div class="compare-bar-content">
        <div class="compare-bar-title">
            <?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_COMPARE_PRODUCTS'); ?>
        </div>
        <div class="compare-bar-products" id="compare-bar-products" role="list"></div>
        <div class="compare-bar-actions">
            <button type="button" class="btn btn-primary" id="compare-bar-view">
                <?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_VIEW_COMPARISON'); ?>
            </button>
            <button type="button" class="btn btn-secondary" id="compare-bar-clear">
                <?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_CLEAR_ALL'); ?>
            </button>
        </div>
    </div>
</div>
