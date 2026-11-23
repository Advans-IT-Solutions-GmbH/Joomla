<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Advans\Component\J2CommerceImportExport\Administrator\View\Help\HtmlView $this */

// One card per section. A block is a paragraph (p), a sub-heading (h3), a list
// (ul, ol) or a warning (note). Keys are written out in full for the language lint.
$sections = [
    'overview' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_OVERVIEW_TITLE', [
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_OVERVIEW_DESC'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_OVERVIEW_PRODUCT'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_OVERVIEW_SCOPE'],
    ]],
    'requirements' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_TITLE', [
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_SHOP'],
        ['ol', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_SHOP_RULE_1',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_SHOP_RULE_2',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_SHOP_RULE_3',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_SHOP_BOTH'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_ACCESS'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_ACTIONS'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_ARTICLE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_STATE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_CATEGORY',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_MENU',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_TAGS',
        ]],
        ['note', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_TRUST'],
    ]],
    'installation' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_TITLE', [
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_NEW',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_BACKUP',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_CONFLICT',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_UPDATES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_INSTALL_UNINSTALL',
        ]],
    ]],
    'export' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TITLE', [
        ['ol', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_STEP_1',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_STEP_2',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_STEP_3',
        ]],
        ['h3', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPES_TITLE'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPE_FULL',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPE_PRODUCTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPE_CATEGORIES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPE_VARIANTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_TYPE_PRICES',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_NO_FILTER'],
        ['h3', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FORMATS_TITLE'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FORMAT_JSON',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FORMAT_CSV',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FORMAT_FORMULA',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FORMAT_XML',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_EXPORT_FOR_IMPORT'],
    ]],
    'import' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_TITLE', [
        ['ol', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_1',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_2',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_3',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_4',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_5',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_SESSION'],
        ['h3', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPTIONS_TITLE'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_UPDATE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_MENU',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_MENU_DEFAULTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_CATEGORY',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_QUANTITY',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_OPT_NOT_SAVED'],
    ]],
    'file' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_TITLE', [
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_JSON',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_CSV',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_CSV_LISTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_SIZE',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_INVALID'],
        ['h3', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELDS_TITLE'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_TITLE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_ALIAS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_TEXT',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_STATE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_JSON',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_CATEGORY',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_ID',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_PRODUCT',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_VARIANTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_IMAGES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_LISTS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_FIELD_MENU',
        ]],
        ['note', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_FILE_DEFAULTS'],
    ]],
    'matching' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_TITLE', [
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_INTRO'],
        ['ol', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_1',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_2',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_3',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_SHOP_ONLY'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_VARIANTS'],
        ['h3', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_TITLE'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_PRICES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_IMAGES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_OPTIONS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_FILTERS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_TAGS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_FIELDS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_META',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_FILES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_MATCH_REPLACE_STOCK',
        ]],
    ]],
    'categories' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_TITLE', [
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_CATID',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_PATH',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_NEW',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_DEFAULT',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_NEW_STATE'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ART_NEW'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ART_UPDATE'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ART_MOVE'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ART_ALIAS'],
    ]],
    'errors' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_TITLE', [
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_ROWS'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_ORDER'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_UNDO'],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_KEEP'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_KEEP_CATEGORY',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_KEEP_ARTICLE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_KEEP_MENU',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_KEEP_MENU_CHANGED',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_REPORT'],
        ['note', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_ERR_NO_UNDO'],
    ]],
    'options' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_TITLE', [
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_OPEN'],
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_DELIMITER',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_ENCLOSURE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_PERMISSIONS',
        ]],
        ['p', 'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_CSV_IMPORT'],
    ]],
    'troubleshooting' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_TITLE', [
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_FILE_TYPE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_TOO_LARGE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_INVALID',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_DENIED',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_DUPLICATES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_OTHER_SITE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_STOCK',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_IMAGES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_EMPTY',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_APOSTROPHE',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_FILES',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_ALIAS',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_CHANGED',
        ]],
    ]],
    'privacy' => ['COM_J2COMMERCE_IMPORTEXPORT_HELP_PRIVACY_TITLE', [
        ['ul', [
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_PRIVACY_EXPORT',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_PRIVACY_UPLOAD',
            'COM_J2COMMERCE_IMPORTEXPORT_HELP_PRIVACY_LOCAL',
        ]],
    ]],
];

// Texts that name a label of Joomla or of the dashboard: the label is inserted
// from its own language key, so the help always quotes the label as it is shown.
$labels = [
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_ACCESS'             => ['JACTION_MANAGE', 'JTOOLBAR_HELP'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_ACTIONS'            => ['JOPTIONS', 'JCONFIG_PERMISSIONS_LABEL'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_ARTICLE'    => ['JACTION_CREATE', 'JACTION_EDIT', 'JACTION_EDITOWN'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_STATE'      => ['JACTION_EDITSTATE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_CATEGORY'   => ['JACTION_CREATE', 'JACTION_EDITSTATE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_MENU'       => ['JACTION_CREATE', 'JACTION_EDIT', 'JACTION_EDITSTATE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_REQ_CONTENT_TAGS'       => ['JACTION_CREATE', 'JACTION_EDITVALUE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_IMPORT_STEP_3'          => ['COM_J2COMMERCE_IMPORTEXPORT_OPTIONS'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_CAT_NEW_STATE'          => ['JACTION_EDITSTATE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_OPEN'           => ['JOPTIONS', 'JACTION_ADMIN', 'JACTION_OPTIONS'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_OPTIONS_PERMISSIONS'    => ['JCONFIG_PERMISSIONS_LABEL', 'JACTION_MANAGE'],
    'COM_J2COMMERCE_IMPORTEXPORT_HELP_TROUBLE_DENIED'         => ['JERROR_ALERTNOAUTHOR'],
];

$text = static function (string $key) use ($labels): string {
    if (!isset($labels[$key])) {
        return Text::_($key);
    }

    return Text::sprintf($key, ...array_map(static fn (string $label): string => htmlspecialchars(Text::_($label), ENT_QUOTES, 'UTF-8'), $labels[$key]));
};
?>
<div id="importexport-help" class="com-j2commerce-importexport-help">
    <p class="lead"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_HELP_INTRO'); ?></p>
    <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="<?php echo $this->escape(Text::_('COM_J2COMMERCE_IMPORTEXPORT_HELP_CONTENTS')); ?>">
        <?php foreach ($sections as $id => [$heading]) : ?>
            <a class="btn btn-secondary" href="#help-<?php echo $id; ?>"><?php echo Text::_($heading); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php foreach ($sections as $id => [$heading, $blocks]) : ?>
        <section class="card mb-4" id="help-<?php echo $id; ?>" aria-labelledby="help-<?php echo $id; ?>-heading">
            <div class="card-body">
                <h2 class="h4" id="help-<?php echo $id; ?>-heading"><?php echo Text::_($heading); ?></h2>
                <?php foreach ($blocks as [$type, $content]) : ?>
                    <?php if ($type === 'h3') : ?>
                        <h3 class="h5 mt-4"><?php echo $text($content); ?></h3>
                    <?php elseif ($type === 'ul' || $type === 'ol') : ?>
                        <<?php echo $type; ?>>
                            <?php foreach ($content as $item) : ?>
                                <li class="mb-2"><?php echo $text($item); ?></li>
                            <?php endforeach; ?>
                        </<?php echo $type; ?>>
                    <?php elseif ($type === 'note') : ?>
                        <div class="alert alert-warning mb-0" role="note"><?php echo $text($content); ?></div>
                    <?php else : ?>
                        <p><?php echo $text($content); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$this->isPopup) : ?>
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="<?php echo $this->escape(Text::_('COM_J2COMMERCE_IMPORTEXPORT_HELP_TITLE')); ?>">
            <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_j2commerce_importexport'); ?>"><?php echo Text::_('COM_J2COMMERCE_IMPORTEXPORT_HELP_BACK'); ?></a>
            <?php if ($this->canOptions) : ?>
                <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_config&view=component&component=com_j2commerce_importexport'); ?>"><?php echo Text::_('JOPTIONS'); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
