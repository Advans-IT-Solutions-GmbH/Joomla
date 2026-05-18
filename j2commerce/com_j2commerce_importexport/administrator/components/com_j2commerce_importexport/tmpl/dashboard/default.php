<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\HTML\HTMLHelper;

HTMLHelper::_('bootstrap.collapse');
?>

<div class="j2commerce-importexport">
    <div class="row">
        <!-- Export Section -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h3 class="mb-0"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_EXPORT'); ?></h3>
                </div>
                <div class="card-body">
                    <form action="<?php echo Route::_('index.php?option=com_j2commerce_importexport&task=export.export'); ?>" method="post" id="export-form">
                        
                        <div class="mb-3">
                            <label for="export-type" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_TYPE'); ?></label>
                            <select name="type" id="export-type" class="form-select">
                                <option value="products_full"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRODUCTS_FULL'); ?></option>
                                <option value="products"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRODUCTS_SIMPLE'); ?></option>
                                <option value="categories"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_CATEGORIES'); ?></option>
                                <option value="variants"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_VARIANTS'); ?></option>
                                <option value="prices"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRICES'); ?></option>
                            </select>
                        </div>

                        <div id="export-full-info" class="alert alert-info mb-3">
                            <strong><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_FULL_EXPORT_INCLUDES'); ?>:</strong>
                            <ul class="mb-0 mt-2">
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_JOOMLA_ARTICLE'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_CATEGORY'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_J2STORE_PRODUCT'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_VARIANTS_STOCK_PRICES'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_IMAGES'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_OPTIONS'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_FILTERS'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_TAGS'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_CUSTOM_FIELDS'); ?></li>
                                <li><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_MENU_ITEM'); ?></li>
                            </ul>
                        </div>
                        
                        <div class="mb-3">
                            <label for="export-format" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_FORMAT'); ?></label>
                            <select name="format" id="export-format" class="form-select">
                                <option value="json">JSON</option>
                                <option value="csv">CSV</option>
                                <option value="xml">XML</option>
                            </select>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <span class="icon-download"></span>
                            <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_EXPORT_BUTTON'); ?>
                        </button>
                        
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Import Section -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3 class="mb-0"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_IMPORT'); ?></h3>
                </div>
                <div class="card-body">
                    <form action="<?php echo Route::_('index.php?option=com_j2commerce_importexport&task=import.upload'); ?>" method="post" enctype="multipart/form-data" id="import-form">
                        
                        <div class="mb-3">
                            <label for="import-type" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_TYPE'); ?></label>
                            <select name="type" id="import-type" class="form-select">
                                <option value="products_full"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRODUCTS_FULL'); ?></option>
                                <option value="products"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRODUCTS_SIMPLE'); ?></option>
                                <option value="variants"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_VARIANTS'); ?></option>
                                <option value="prices"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PRICES'); ?></option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="import-file" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_FILE'); ?></label>
                            <input type="file" name="import_file" id="import-file" class="form-control" accept=".csv,.xml,.json" required>
                        </div>

                        <!-- Import Options -->
                        <div class="accordion mb-3" id="importOptions">
                            <div class="accordion-item">
                                <h2 class="accordion-header">
                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#importOptionsCollapse">
                                        <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_OPTIONS'); ?>
                                    </button>
                                </h2>
                                <div id="importOptionsCollapse" class="accordion-collapse collapse" data-bs-parent="#importOptions">
                                    <div class="accordion-body">
                                        
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="options[update_existing]" value="1" id="opt-update" checked>
                                            <label class="form-check-label" for="opt-update">
                                                <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_UPDATE_EXISTING'); ?>
                                            </label>
                                        </div>

                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" name="options[create_menu]" value="1" id="opt-menu">
                                            <label class="form-check-label" for="opt-menu">
                                                <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_CREATE_MENU'); ?>
                                            </label>
                                        </div>

                                        <div id="menu-options" style="display:none;" class="ms-4 mt-2">
                                            <div class="mb-2">
                                                <label for="opt-menutype" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_MENUTYPE'); ?></label>
                                                <select name="options[default_menutype]" id="opt-menutype" class="form-select form-select-sm">
                                                    <?php foreach ($this->menutypes as $mt): ?>
                                                    <option value="<?php echo $this->escape($mt->menutype); ?>"><?php echo $this->escape($mt->title); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="mb-2">
                                                <label for="opt-menu-access" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_MENU_ACCESS'); ?></label>
                                                <select name="options[menu_access]" id="opt-menu-access" class="form-select form-select-sm">
                                                    <?php foreach ($this->viewlevels as $vl): ?>
                                                    <option value="<?php echo (int) $vl->id; ?>"><?php echo $this->escape($vl->title); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>

                                            <div class="form-check mb-2">
                                                <input class="form-check-input" type="checkbox" name="options[menu_published]" value="1" id="opt-menu-pub" checked>
                                                <label class="form-check-label" for="opt-menu-pub">
                                                    <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_MENU_PUBLISHED'); ?>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="mb-2">
                                            <label for="opt-category" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_DEFAULT_CATEGORY'); ?></label>
                                            <select name="options[default_category]" id="opt-category" class="form-select form-select-sm">
                                                <?php foreach ($this->categories as $cat): ?>
                                                <option value="<?php echo $cat->id; ?>"><?php echo str_repeat('- ', $cat->level - 1) . $cat->title; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-2">
                                            <label for="opt-quantity-mode" class="form-label"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_QUANTITY_MODE_LABEL'); ?></label>
                                            <select name="options[quantity_mode]" id="opt-quantity-mode" class="form-select form-select-sm">
                                                <option value="replace"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_QUANTITY_MODE_REPLACE'); ?></option>
                                                <option value="add"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_QUANTITY_MODE_ADD'); ?></option>
                                            </select>
                                            <small class="form-text text-muted"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_QUANTITY_MODE_DESC'); ?></small>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-success">
                            <span class="icon-upload"></span>
                            <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_UPLOAD_BUTTON'); ?>
                        </button>
                        
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                    
                    <div id="import-preview" class="mt-4" style="display:none;">
                        <h4><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PREVIEW'); ?></h4>
                        <div id="preview-content" class="table-responsive"></div>
                        <button type="button" class="btn btn-primary mt-2" id="process-import">
                            <span class="icon-cog"></span>
                            <?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_PROCESS_BUTTON'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const exportType = document.getElementById('export-type');
    const exportInfo = document.getElementById('export-full-info');
    const menuCheckbox = document.getElementById('opt-menu');
    const menuOptions = document.getElementById('menu-options');

    exportType.addEventListener('change', function() {
        exportInfo.style.display = this.value === 'products_full' ? 'block' : 'none';
    });

    menuCheckbox.addEventListener('change', function() {
        menuOptions.style.display = this.checked ? 'block' : 'none';
    });

    // Import form handling
    const importForm = document.getElementById('import-form');
    const importPreview = document.getElementById('import-preview');
    const previewContent = document.getElementById('preview-content');
    const processButton = document.getElementById('process-import');
    
    importForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        
        fetch(this.action, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                importPreview.style.display = 'block';
                displayPreview(data.preview);
            } else {
                alert(data.message || 'Upload failed');
            }
        })
        .catch(error => alert('Error: ' + error.message));
    });
    
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function displayPreview(data) {
        if (!data || !data.rows || !data.rows.length) {
            previewContent.innerHTML = '<p class="text-muted"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_NO_DATA'); ?></p>';
            return;
        }

        let html = '<table class="table table-striped table-sm"><thead><tr>';
        data.headers.forEach(header => {
            html += '<th>' + escapeHtml(header) + '</th>';
        });
        html += '</tr></thead><tbody>';

        data.rows.slice(0, 5).forEach(row => {
            html += '<tr>';
            data.headers.forEach(header => {
                let val = row[header] || '';
                if (typeof val === 'object') val = JSON.stringify(val).substring(0, 50) + '...';
                if (typeof val === 'string' && val.length > 50) val = val.substring(0, 50) + '...';
                html += '<td>' + escapeHtml(val) + '</td>';
            });
            html += '</tr>';
        });

        html += '</tbody></table>';
        html += '<p class="text-muted"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_TOTAL_ROWS'); ?>: ' + escapeHtml(String(data.total)) + '</p>';

        previewContent.innerHTML = html;
    }
    
    processButton.addEventListener('click', function() {
        if (!confirm('<?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_CONFIRM_IMPORT'); ?>')) {
            return;
        }
        
        const type = document.getElementById('import-type').value;
        const formData = new FormData(importForm);
        
        fetch('index.php?option=com_j2commerce_importexport&task=import.process&format=json', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('<?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_IMPORT_SUCCESS'); ?>\n' +
                      '<?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_IMPORTED'); ?>: ' + data.imported + '\n' +
                      '<?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_FAILED'); ?>: ' + data.failed);
                location.reload();
            } else {
                alert(data.message || 'Import failed');
            }
        })
        .catch(error => alert('Error: ' + error.message));
    });
});
</script>
