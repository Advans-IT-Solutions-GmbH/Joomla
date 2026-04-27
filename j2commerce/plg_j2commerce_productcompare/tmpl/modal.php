<?php
/**
 * Layout: Comparison modal
 *
 * No variables — content is loaded via AJAX into #compare-modal-body.
 *
 * Template override path:
 *   templates/{your-template}/html/plg_j2store_productcompare/modal.php
 */
defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
?>
<div id="j2store-compare-modal"
     class="j2store-compare-modal"
     style="display:none;"
     role="dialog"
     aria-modal="true"
     aria-labelledby="compare-modal-title">
    <div class="modal-overlay" aria-hidden="true"></div>
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="compare-modal-title">
                <?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_COMPARISON_TITLE'); ?>
            </h3>
            <button type="button" class="modal-close" aria-label="<?php echo Text::_('JCLOSE'); ?>">
                &times;
            </button>
        </div>
        <div class="modal-body" id="compare-modal-body">
            <div class="loading">
                <?php echo Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_LOADING'); ?>
            </div>
        </div>
    </div>
</div>
