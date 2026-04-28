<?php
/**
 * Layout: Compare button
 *
 * Available variables:
 *   $productId   (int)    J2Store product ID
 *   $buttonText  (string) Translated button label
 *   $buttonClass (string) CSS classes from plugin params
 *
 * Template override path:
 *   templates/{your-template}/html/plg_j2store_productcompare/button.php
 */
defined('_JEXEC') or die;
?>
<button type="button"
        class="j2store-compare-btn <?php echo $this->escape($buttonClass); ?>"
        data-product-id="<?php echo (int) $productId; ?>">
    <?php echo $this->escape($buttonText); ?>
</button>
