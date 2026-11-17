<?php
/**
 * @package     J2Store Cleanup
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/*
 * The help page, included by j2store_cleanup.php for view=help after its access
 * check, inside the same page and styling. It reads $helpCanOptions (the user
 * may open the Options) and $helpIsPopup (shown in Joomla's help popup, where
 * links to other pages would navigate the popup away).
 */
$helpCanOptions = !empty($helpCanOptions);
$helpIsPopup    = !empty($helpIsPopup);

// One card per section. A block is a paragraph (p), a list (ul, ol) or a
// warning (note). Keys are written out in full for the language lint.
$helpSections = [
    'purpose' => ['COM_J2STORE_CLEANUP_HELP_PURPOSE_TITLE', [
        ['p', 'COM_J2STORE_CLEANUP_HELP_PURPOSE_DESC'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_PURPOSE_NOT'],
    ]],
    'when' => ['COM_J2STORE_CLEANUP_HELP_WHEN_TITLE', [
        ['p', 'COM_J2STORE_CLEANUP_HELP_WHEN_USE'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_WHEN_NOT'],
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_WHEN_NOT_PROOF',
            'COM_J2STORE_CLEANUP_HELP_WHEN_NOT_NEEDED',
            'COM_J2STORE_CLEANUP_HELP_WHEN_NOT_BACKUP',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER'],
        ['ol', [
            'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER_1',
            'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER_2',
            'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER_3',
            'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER_4',
            'COM_J2STORE_CLEANUP_HELP_WHEN_ORDER_5',
        ]],
    ]],
    'requirements' => ['COM_J2STORE_CLEANUP_HELP_REQ_TITLE', [
        ['note', 'COM_J2STORE_CLEANUP_HELP_REQ_BACKUP'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REQ_VIEW'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REQ_REMOVE'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REQ_READONLY'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REQ_DEFAULT'],
    ]],
    'detection' => ['COM_J2STORE_CLEANUP_HELP_DETECT_TITLE', [
        ['p', 'COM_J2STORE_CLEANUP_HELP_DETECT_INTRO'],
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_DETECT_COMPONENTS',
            'COM_J2STORE_CLEANUP_HELP_DETECT_PLUGINS',
            'COM_J2STORE_CLEANUP_HELP_DETECT_MODULES',
            'COM_J2STORE_CLEANUP_HELP_DETECT_TEMPLATES',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_DETECT_CORE'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_DETECT_NEVER'],
    ]],
    'scan' => ['COM_J2STORE_CLEANUP_HELP_SCAN_TITLE', [
        ['p', 'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER'],
        ['ol', [
            'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER_CORE',
            'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER_NO_FILES',
            'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER_COMPATIBLE',
            'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER_INCOMPATIBLE',
            'COM_J2STORE_CLEANUP_HELP_SCAN_ORDER_DEPRECATED',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_SCAN_FILES'],
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_SCAN_API_ALIASES',
            'COM_J2STORE_CLEANUP_HELP_SCAN_API_FACTORY',
            'COM_J2STORE_CLEANUP_HELP_SCAN_API_APP',
            'COM_J2STORE_CLEANUP_HELP_SCAN_API_J2STORE',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_SCAN_DETAILS'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_SCAN_SITE_NOTE'],
        ['note', 'COM_J2STORE_CLEANUP_HELP_SCAN_LIMITS'],
    ]],
    'results' => ['COM_J2STORE_CLEANUP_HELP_RESULT_TITLE', [
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_RESULT_INCOMPATIBLE',
            'COM_J2STORE_CLEANUP_HELP_RESULT_NO_FILES',
            'COM_J2STORE_CLEANUP_HELP_RESULT_DEPRECATED',
            'COM_J2STORE_CLEANUP_HELP_RESULT_COMPATIBLE',
            'COM_J2STORE_CLEANUP_HELP_RESULT_ALL',
        ]],
    ]],
    'removal' => ['COM_J2STORE_CLEANUP_HELP_REMOVE_TITLE', [
        ['ol', [
            'COM_J2STORE_CLEANUP_HELP_REMOVE_STEP_1',
            'COM_J2STORE_CLEANUP_HELP_REMOVE_STEP_2',
            'COM_J2STORE_CLEANUP_HELP_REMOVE_STEP_3',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_CHECK'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_INCOMPATIBLE'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_NO_FILES'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_REFUSED'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_EXCEPTION'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_SEPARATE'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_REMOVE_MESSAGES'],
    ]],
    'never' => ['COM_J2STORE_CLEANUP_HELP_NEVER_TITLE', [
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_NEVER_CORE',
            'COM_J2STORE_CLEANUP_HELP_NEVER_PROTECTED',
            'COM_J2STORE_CLEANUP_HELP_NEVER_PACKAGE',
            'COM_J2STORE_CLEANUP_HELP_NEVER_DATA',
            'COM_J2STORE_CLEANUP_HELP_NEVER_EXTRA',
        ]],
    ]],
    'undo' => ['COM_J2STORE_CLEANUP_HELP_UNDO_TITLE', [
        ['note', 'COM_J2STORE_CLEANUP_HELP_UNDO_DESC'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_UNDO_RESTORE'],
    ]],
    'options' => ['COM_J2STORE_CLEANUP_HELP_OPTIONS_TITLE', [
        ['p', 'COM_J2STORE_CLEANUP_HELP_OPTIONS_DESC'],
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_OPTIONS_ADMIN',
            'COM_J2STORE_CLEANUP_HELP_OPTIONS_OPTIONS',
            'COM_J2STORE_CLEANUP_HELP_OPTIONS_MANAGE',
            'COM_J2STORE_CLEANUP_HELP_OPTIONS_DELETE',
        ]],
        ['p', 'COM_J2STORE_CLEANUP_HELP_OPTIONS_INHERIT'],
        ['p', 'COM_J2STORE_CLEANUP_HELP_OPTIONS_UPGRADE'],
    ]],
    'troubleshooting' => ['COM_J2STORE_CLEANUP_HELP_TROUBLE_TITLE', [
        ['ul', [
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_DENIED',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_NO_BUTTON',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_FLAGGED',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_NOT_ELIGIBLE',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_REFUSED',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_PACKAGE',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_DB_ONLY',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_FAILED',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_BROKEN',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_J2COMMERCE',
            'COM_J2STORE_CLEANUP_HELP_TROUBLE_MISSING',
        ]],
    ]],
];

// Texts that name a Joomla label: the label is inserted from Joomla's own
// language key, so the help quotes it as Joomla shows it.
$helpLabels = [
    'COM_J2STORE_CLEANUP_HELP_REQ_VIEW'         => ['JACTION_MANAGE', 'JTOOLBAR_HELP'],
    'COM_J2STORE_CLEANUP_HELP_REQ_REMOVE'       => ['JACTION_DELETE', 'JACTION_MANAGE'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_DESC'     => ['JOPTIONS', 'JCONFIG_PERMISSIONS_LABEL', 'JACTION_ADMIN', 'JACTION_OPTIONS'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_ADMIN'    => ['JACTION_ADMIN'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_OPTIONS'  => ['JACTION_OPTIONS'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_MANAGE'   => ['JACTION_MANAGE'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_DELETE'   => ['JACTION_DELETE'],
    'COM_J2STORE_CLEANUP_HELP_OPTIONS_UPGRADE'  => ['JACTION_DELETE', 'JACTION_ADMIN'],
    'COM_J2STORE_CLEANUP_HELP_TROUBLE_DENIED'   => ['JERROR_ALERTNOAUTHOR', 'JACTION_MANAGE', 'JCONFIG_PERMISSIONS_LABEL'],
];

$helpText = static function (string $key) use ($helpLabels): string {
    if (!isset($helpLabels[$key])) {
        return Text::_($key);
    }

    return Text::sprintf($key, ...array_map(static fn (string $label): string => htmlspecialchars(Text::_($label), ENT_QUOTES, 'UTF-8'), $helpLabels[$key]));
};
?>
<div id="j2cleanup-help">
    <h1><?php echo Text::_('COM_J2STORE_CLEANUP_HELP_TITLE'); ?></h1>
    <p class="lead"><?php echo Text::_('COM_J2STORE_CLEANUP_HELP_INTRO'); ?></p>
    <nav class="d-flex flex-wrap gap-2 mb-4" aria-label="<?php echo htmlspecialchars(Text::_('COM_J2STORE_CLEANUP_HELP_CONTENTS'), ENT_QUOTES, 'UTF-8'); ?>">
        <?php foreach ($helpSections as $helpId => [$helpHeading]) : ?>
            <a class="btn btn-secondary" href="#help-<?php echo $helpId; ?>"><?php echo Text::_($helpHeading); ?></a>
        <?php endforeach; ?>
    </nav>
    <?php foreach ($helpSections as $helpId => [$helpHeading, $helpBlocks]) : ?>
        <section class="card mb-4" id="help-<?php echo $helpId; ?>" aria-labelledby="help-<?php echo $helpId; ?>-heading">
            <div class="card-body">
                <h2 class="h4" id="help-<?php echo $helpId; ?>-heading"><?php echo Text::_($helpHeading); ?></h2>
                <?php foreach ($helpBlocks as [$helpType, $helpContent]) : ?>
                    <?php if ($helpType === 'ul' || $helpType === 'ol') : ?>
                        <<?php echo $helpType; ?>>
                            <?php foreach ($helpContent as $helpItem) : ?>
                                <li class="mb-2"><?php echo $helpText($helpItem); ?></li>
                            <?php endforeach; ?>
                        </<?php echo $helpType; ?>>
                    <?php elseif ($helpType === 'note') : ?>
                        <div class="alert alert-warning" role="note"><?php echo $helpText($helpContent); ?></div>
                    <?php else : ?>
                        <p><?php echo $helpText($helpContent); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
    <?php if (!$helpIsPopup) : ?>
        <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="<?php echo htmlspecialchars(Text::_('COM_J2STORE_CLEANUP_HELP_TITLE'), ENT_QUOTES, 'UTF-8'); ?>">
            <?php // The page styles every .btn red (removal); the grey variant suits navigation. ?>
            <a class="btn btn-secondary" href="index.php?option=com_j2store_cleanup"><?php echo Text::_('COM_J2STORE_CLEANUP_HELP_BACK'); ?></a>
            <?php if ($helpCanOptions) : ?>
                <a class="btn btn-secondary" href="index.php?option=com_config&amp;view=component&amp;component=com_j2store_cleanup"><?php echo Text::_('JOPTIONS'); ?></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
