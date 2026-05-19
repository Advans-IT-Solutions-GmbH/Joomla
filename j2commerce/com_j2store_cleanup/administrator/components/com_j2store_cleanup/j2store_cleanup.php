<?php
/**
 * @package     J2Store Cleanup
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     Proprietary
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;

$app = Factory::getApplication();
$db = Factory::getContainer()->get('DatabaseDriver');
$task = $app->input->get('task', 'display');

/**
 * Resolve the filesystem path for an extension.
 *
 * @param object $ext  Extension record from #__extensions
 * @return string|null  Absolute path or null if not found
 */
function getExtensionPath($ext) {
    $base = ($ext->client_id == 1) ? JPATH_ADMINISTRATOR : JPATH_SITE;

    switch ($ext->type) {
        case 'component':
            return $base . '/components/' . $ext->element;
        case 'module':
            return $base . '/modules/' . $ext->element;
        case 'plugin':
            return JPATH_PLUGINS . '/' . $ext->folder . '/' . $ext->element;
        case 'template':
            return $base . '/templates/' . $ext->element;
        case 'library':
            return JPATH_LIBRARIES . '/' . $ext->element;
        case 'file':
            return null; // language packs etc. — no single path
    }
    return null;
}

/**
 * Get deprecated API patterns grouped by the Joomla version that removes them.
 *
 * Each entry: pattern => label. Only patterns relevant to the running
 * Joomla version (and above) are returned.
 *
 * @return array ['joomla' => [...], 'j2store' => [...]]
 */
function getIssuePatterns() {
    $joomlaMajor = (int) JVERSION;

    // Patterns grouped by the Joomla version that REMOVES them.
    // On Joomla 4 these are available; on 5 deprecated; on 6 removed.
    $joomlaByVersion = [
        // Removed in Joomla 4 (J3 legacy classes without namespace)
        4 => [
            '/\bJPlugin\b/'              => 'JPlugin (removed in Joomla 4)',
            '/\bJModel(Legacy)?\b/'      => 'JModel (removed in Joomla 4)',
            '/\bJTable\b/'               => 'JTable (removed in Joomla 4)',
            '/\bJView(Legacy)?\b/'       => 'JView (removed in Joomla 4)',
            '/\bJController(Legacy)?\b/' => 'JController (removed in Joomla 4)',
            '/\bJForm\b/'               => 'JForm (removed in Joomla 4)',
        ],
        // Removed in Joomla 6 (deprecated since 4/5, B/C plugin removed in 6)
        6 => [
            '/\bJFactory\b/'             => 'JFactory (removed in Joomla 6)',
            '/\bJText\b/'               => 'JText (removed in Joomla 6)',
            '/\bJHtml\b/'               => 'JHtml (removed in Joomla 6)',
            '/\bJRoute\b/'              => 'JRoute (removed in Joomla 6)',
            '/\bJUri\b/'                => 'JUri (removed in Joomla 6)',
            '/\bJSession\b/'            => 'JSession (removed in Joomla 6)',
            '/Factory::getUser\s*\(/'   => 'Factory::getUser() (removed in Joomla 6)',
            '/Factory::getDbo\s*\(/'    => 'Factory::getDbo() (removed in Joomla 6)',
            '/Factory::getSession\s*\(/' => 'Factory::getSession() (removed in Joomla 6)',
            '/Factory::getDocument\s*\(/' => 'Factory::getDocument() (removed in Joomla 6)',
            '/\$this->app\b(?!lication)/' => '$this->app property (removed in Joomla 6)',
        ],
    ];

    // Only include patterns for APIs removed in the current or earlier version.
    // On Joomla 5: only J4 removals apply (J6 removals are deprecated but work).
    // On Joomla 6: both J4 and J6 removals apply.
    $joomlaPatterns = [];
    foreach ($joomlaByVersion as $removedIn => $patterns) {
        if ($joomlaMajor >= $removedIn) {
            $joomlaPatterns = array_merge($joomlaPatterns, $patterns);
        }
    }

    // J2Store/J2Commerce patterns — these are NOT version-dependent.
    // J2Commerce 4.x still ships F0F and the old plugin base classes,
    // so these are NOT incompatible with J2Commerce 4.x.
    // Only flag patterns that indicate a plugin predates J2Store 3.x entirely.
    $j2Patterns = [];

    return ['joomla' => $joomlaPatterns, 'j2store' => $j2Patterns];
}

/**
 * Scan PHP files in a directory for deprecated/incompatible patterns.
 *
 * @param string $path      Directory to scan
 * @param array  $patterns  From getIssuePatterns()
 * @return array  List of issues found, each with 'type' and 'detail'
 */
function scanForIssues($path, $patterns) {
    if (!is_dir($path)) {
        return [];
    }

    $issues = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = @file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        // Strip comments to reduce false positives
        $stripped = preg_replace('#/\*.*?\*/#s', '', $content);
        $stripped = preg_replace('#//.*$#m', '', $stripped);

        foreach ($patterns['joomla'] as $pattern => $label) {
            if (preg_match($pattern, $stripped)) {
                $issues[] = ['type' => 'joomla', 'detail' => $label];
            }
        }

        foreach ($patterns['j2store'] as $pattern => $label) {
            if (preg_match($pattern, $stripped)) {
                $issues[] = ['type' => 'j2store', 'detail' => $label];
            }
        }
    }

    // Deduplicate
    $seen = [];
    $unique = [];
    foreach ($issues as $issue) {
        $key = $issue['type'] . ':' . $issue['detail'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $unique[] = $issue;
        }
    }

    return $unique;
}

/**
 * Classify a J2Store/J2Commerce extension's compatibility.
 *
 * Scans extension files for APIs that are removed in the running Joomla version.
 * On Joomla 4/5: most old J2Store plugins will show as compatible (old APIs still work).
 * On Joomla 6+: plugins using JFactory, JText etc. will show as incompatible.
 *
 * J2Commerce 4.x itself ships F0F and the old plugin base classes, so
 * F0FModel/F0FTable usage is NOT flagged as incompatible.
 *
 * @param object $manifest  Decoded manifest_cache from #__extensions
 * @param object $ext       Extension record from database
 * @param array  $patterns  From getIssuePatterns()
 * @return array ['status' => string, 'reason' => string, 'issues' => array]
 */
function classifyExtension($manifest, $ext, $patterns) {
    if ($ext->element === 'com_j2store') {
        $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
        return ['status' => 'core', 'reason' => 'Core component (v' . $version . ')', 'issues' => []];
    }

    $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
    $author  = is_object($manifest) ? ($manifest->author ?? '?') : '?';
    $info    = $author . ', v' . $version;

    $path = getExtensionPath($ext);

    if ($path === null || !is_dir($path)) {
        return ['status' => 'no-files', 'reason' => 'Files not found on disk (' . $info . ')', 'issues' => []];
    }

    $issues = scanForIssues($path, $patterns);

    if (empty($issues)) {
        return ['status' => 'compatible', 'reason' => 'No issues found (' . $info . ')', 'issues' => []];
    }

    $joomlaIssues = array_filter($issues, fn($i) => $i['type'] === 'joomla');
    $j2Issues     = array_filter($issues, fn($i) => $i['type'] === 'j2store');

    $parts = [];
    if (!empty($j2Issues)) {
        $parts[] = count($j2Issues) . ' J2Store API issue(s)';
    }
    if (!empty($joomlaIssues)) {
        $parts[] = count($joomlaIssues) . ' Joomla API issue(s)';
    }

    return [
        'status'  => 'incompatible',
        'reason'  => implode(', ', $parts) . ' (' . $info . ')',
        'issues'  => $issues,
    ];
}

// Handle cleanup action
if ($task === 'cleanup' && Session::checkToken()) {
    $cids = $app->input->get('cid', [], 'array');
    $cids = array_map('intval', $cids);
    $cids = array_filter($cids); // Remove zeros
    
    if (empty($cids)) {
        $app->enqueueMessage(Text::_('COM_J2STORE_CLEANUP_MSG_SELECT_ONE'), 'warning');
        $app->redirect('index.php?option=com_j2store_cleanup');
        return;
    }
    
    $successCount = 0;
    $warningCount = 0;
    $errorCount = 0;
    $messages = [];
    
    // Get extension details for proper uninstall
    $query = $db->getQuery(true)
        ->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id']))
        ->from($db->quoteName('#__extensions'))
        ->whereIn($db->quoteName('extension_id'), $cids);
    $db->setQuery($query);
    $extensionsToRemove = $db->loadObjectList();
    
    foreach ($extensionsToRemove as $ext) {
        try {
            // Use Joomla's Installer for proper uninstallation
            $installer = Installer::getInstance();
            
            if ($installer->uninstall($ext->type, $ext->extension_id)) {
                $successCount++;
            } else {
                // Fallback: direct DB delete if uninstall fails
                $extId       = (int) $ext->extension_id;
                $deleteQuery = $db->getQuery(true)
                    ->delete($db->quoteName('#__extensions'))
                    ->where($db->quoteName('extension_id') . ' = :extId')
                    ->bind(':extId', $extId, \Joomla\Database\ParameterType::INTEGER);
                $db->setQuery($deleteQuery);
                $db->execute();
                $warningCount++;
                $messages[] = $ext->element . ' (DB only - files may remain)';
            }
        } catch (Exception $e) {
            $errorCount++;
            $messages[] = $ext->element . ': ' . $e->getMessage();
        }
    }
    
    if ($successCount > 0) {
        $app->enqueueMessage(sprintf(Text::_('COM_J2STORE_CLEANUP_MSG_REMOVED_SUCCESS'), $successCount), 'success');
    }
    if ($warningCount > 0) {
        $app->enqueueMessage(sprintf(Text::_('COM_J2STORE_CLEANUP_MSG_REMOVED_WARNING'), $warningCount, implode(', ', array_slice($messages, 0, $warningCount))), 'warning');
    }
    if ($errorCount > 0) {
        $app->enqueueMessage(sprintf(Text::_('COM_J2STORE_CLEANUP_MSG_REMOVED_ERROR'), $errorCount, implode(', ', array_slice($messages, $warningCount))), 'error');
    }
    
    $app->redirect('index.php?option=com_j2store_cleanup');
}

// Get all J2Store/J2Commerce extensions
$query = $db->getQuery(true)
    ->select('extension_id, name, type, element, folder, enabled, client_id, manifest_cache')
    ->from('#__extensions')
    ->where("(element LIKE '%j2store%' OR element LIKE '%j2commerce%' OR element LIKE 'j2%' OR element LIKE 'mod\_j2%' OR element LIKE 'com\_j2%' OR folder = 'j2store')")
    ->order('type, name');

$db->setQuery($query);
$extensions = $db->loadObjectList();

?>
<!DOCTYPE html>
<html style="background: #000 !important;">
<head>
    <style>
        html, body, #wrapper, #content, .com_j2store_cleanup {
            background: #000 !important;
            margin: 0;
            padding: 0;
        }
        body {
            background: #000 !important;
        }
        .j2cleanup { 
            padding: 20px; 
            background: #000;
            color: #e0e0e0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            min-height: 100vh;
        }
        .j2cleanup h1 { 
            margin-bottom: 10px; 
            color: #fff;
            font-size: 28px;
            text-shadow: 0 0 10px rgba(0, 123, 255, 0.5);
        }
        .j2cleanup h2 {
            color: #8ec5fc;
            font-size: 18px;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #333;
        }
        .j2cleanup .alert { 
            padding: 15px; 
            margin: 15px 0; 
            border-radius: 4px; 
        }
        .j2cleanup .alert-info { 
            background: #1a3a52; 
            border: 1px solid #2c5f8d; 
            color: #8ec5fc; 
        }
        .j2cleanup .alert-warning { 
            background: #4a3c1a; 
            border: 1px solid #8d6e2c; 
            color: #ffc107; 
        }
        .j2cleanup .alert-success { 
            background: #1a4d2e; 
            border: 1px solid #2c8d5f; 
            color: #4ade80; 
        }
        .j2cleanup .alert-danger {
            background: #3d1a1a;
            border: 1px solid #8d2c2c;
            color: #fc8e8e;
        }
        .j2cleanup table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0; 
            background: #1a1a1a;
            border: 1px solid #333;
        }
        .j2cleanup th, .j2cleanup td { 
            padding: 10px; 
            text-align: left; 
            border: 1px solid #333; 
            color: #e0e0e0;
        }
        .j2cleanup th { 
            background: #007bff; 
            color: #fff !important;
            font-weight: bold;
        }
        .j2cleanup tr:nth-child(even) { 
            background: #0d0d0d; 
        }
        .j2cleanup tr:hover { 
            background: #2a2a2a; 
        }
        .j2cleanup .incompatible { 
            background: #3d1a1a !important; 
            border-left: 3px solid #dc3545;
        }
        .j2cleanup .compatible {
            background: #1a3d1a !important;
            border-left: 3px solid #28a745;
        }
        .j2cleanup .btn { 
            padding: 10px 20px; 
            background: #dc3545; 
            color: #fff !important; 
            border: none; 
            border-radius: 4px; 
            cursor: pointer; 
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s;
            margin-right: 10px;
        }
        .j2cleanup .btn:hover { 
            background: #c82333; 
            box-shadow: 0 0 15px rgba(220, 53, 69, 0.5);
        }
        .j2cleanup .btn-secondary {
            background: #6c757d;
        }
        .j2cleanup .btn-secondary:hover {
            background: #5a6268;
            box-shadow: 0 0 15px rgba(108, 117, 125, 0.5);
        }
        .j2cleanup code {
            background: #2a2a2a;
            padding: 2px 6px;
            border-radius: 3px;
            color: #8ec5fc;
            font-family: monospace;
            border: 1px solid #333;
        }
        .j2cleanup strong {
            color: #fff;
        }
        .j2cleanup p {
            color: #e0e0e0;
        }
        .j2cleanup .detection-info {
            background: #1a1a2e;
            border: 1px solid #333;
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        .j2cleanup .detection-info h3 {
            color: #8ec5fc;
            margin: 0 0 10px 0;
            font-size: 16px;
        }
        .j2cleanup .detection-info ul {
            margin: 0;
            padding-left: 20px;
        }
        .j2cleanup .detection-info li {
            margin: 5px 0;
            color: #ccc;
        }
        .j2cleanup .detection-info .criterion {
            color: #4ade80;
            font-weight: bold;
        }
        .j2cleanup .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
        }
        .j2cleanup .badge-danger {
            background: #dc3545;
            color: #fff;
        }
        .j2cleanup .badge-success {
            background: #28a745;
            color: #fff;
        }
        .j2cleanup .badge-warning {
            background: #ffc107;
            color: #000;
        }
        .j2cleanup .badge-info {
            background: #17a2b8;
            color: #fff;
        }
        .j2cleanup .badge-secondary {
            background: #6c757d;
            color: #fff;
        }
        .j2cleanup details summary {
            cursor: pointer;
        }
        .j2cleanup-footer {
            margin-top: 40px;
            padding: 20px;
            border-top: 2px solid #333;
            text-align: center;
            color: #888;
        }
        .j2cleanup-footer a {
            color: #007bff;
            text-decoration: none;
            transition: color 0.3s;
        }
        .j2cleanup-footer a:hover {
            color: #0056b3;
            text-decoration: underline;
        }
        .j2cleanup-footer .logo {
            font-size: 20px;
            font-weight: bold;
            color: #007bff;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<div class="j2cleanup">
    <h1>J2Store Extension Cleanup</h1>
    <p style="color: #888; margin-bottom: 20px;">Migration tool for transitioning from J2Store to J2Commerce 4.x</p>
    
    <div class="detection-info">
        <h3>Compatibility scan for Joomla <?php echo JVERSION; ?></h3>
        <p style="margin-bottom: 10px;">Each extension's PHP files are scanned for APIs that are <strong>removed in Joomla <?php echo (int) JVERSION; ?></strong>. J2Commerce 4.x ships its own F0F framework and plugin base classes, so those are not flagged.</p>
        <ul>
            <li><span class="badge badge-success">Compatible</span> — No removed APIs found in code</li>
            <li><span class="badge badge-danger">Incompatible</span> — Uses APIs removed in this Joomla version (details shown)</li>
            <li><span class="badge badge-secondary">No files</span> — Extension registered in database but files missing from disk</li>
            <li><span class="badge badge-info">Core</span> — J2Store/J2Commerce core component</li>
        </ul>
        <?php if ((int) JVERSION < 6): ?>
        <p style="margin-top: 10px; color: #ffc107;"><strong>Note:</strong> On Joomla <?php echo (int) JVERSION; ?>, deprecated APIs (JFactory, JText, etc.) still work via the backward compatibility plugin. Extensions using them will show as compatible here but will break on Joomla 6+.</p>
        <?php endif; ?>
    </div>
    
    <?php if (empty($extensions)): ?>
        <div class="alert alert-success">
            <strong>No J2Store/J2Commerce extensions found!</strong>
            <p style="margin: 10px 0 0 0;">Your installation has no J2Store-related extensions.</p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <strong>Warning:</strong> Always create a full backup (Akeeba Backup) before removing extensions!
            Removal uses Joomla's uninstaller which also deletes files and runs uninstall scripts.
        </div>
        
        <form action="index.php?option=com_j2store_cleanup" method="post">
            <?php
            $groups = ['incompatible' => [], 'no-files' => [], 'compatible' => [], 'core' => []];
            $patterns = getIssuePatterns();
            
            foreach ($extensions as $ext) {
                $manifest = json_decode($ext->manifest_cache);
                $result = classifyExtension($manifest, $ext, $patterns);
                $ext->_status  = $result['status'];
                $ext->_reason  = $result['reason'];
                $ext->_issues  = $result['issues'];
                $ext->_manifest = $manifest;
                $groups[$result['status']][] = $ext;
            }
            ?>
            
            <?php if (!empty($groups['incompatible'])): ?>
            <h2>Incompatible Extensions (<?php echo count($groups['incompatible']); ?>)</h2>
            <p>These extensions use deprecated Joomla or J2Store APIs. They will likely not work with J2Commerce 4.x and/or Joomla 5+.</p>
            
            <table>
                <thead>
                    <tr>
                        <th width="30"><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = this.checked)"></th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Element</th>
                        <th>Enabled</th>
                        <th>Issues found</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups['incompatible'] as $ext): 
                        $enabled = $ext->enabled ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-warning">Disabled</span>';
                    ?>
                    <tr class="incompatible">
                        <td><input type="checkbox" name="cid[]" value="<?php echo $ext->extension_id; ?>"></td>
                        <td><?php echo htmlspecialchars($ext->name); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabled; ?></td>
                        <td>
                            <details>
                                <summary><?php echo htmlspecialchars($ext->_reason); ?></summary>
                                <ul style="margin: 5px 0; padding-left: 20px; font-size: 12px;">
                                <?php foreach ($ext->_issues as $issue): ?>
                                    <li>
                                        <span class="badge <?php echo $issue['type'] === 'j2store' ? 'badge-danger' : 'badge-warning'; ?>"><?php echo $issue['type'] === 'j2store' ? 'J2Store' : 'Joomla'; ?></span>
                                        <?php echo htmlspecialchars($issue['detail']); ?>
                                    </li>
                                <?php endforeach; ?>
                                </ul>
                            </details>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php if (!empty($groups['no-files'])): ?>
            <h2>No Files Found (<?php echo count($groups['no-files']); ?>)</h2>
            <p>Extension is registered in the database but files are missing from disk. Safe to remove.</p>
            
            <table>
                <thead>
                    <tr>
                        <th width="30"><input type="checkbox" onclick="this.closest('table').querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = this.checked)"></th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Element</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups['no-files'] as $ext): ?>
                    <tr style="background: #2a2a2a !important; border-left: 3px solid #6c757d;">
                        <td><input type="checkbox" name="cid[]" value="<?php echo $ext->extension_id; ?>"></td>
                        <td><?php echo htmlspecialchars($ext->name); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo htmlspecialchars($ext->_reason); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php if (!empty($groups['incompatible']) || !empty($groups['no-files'])): ?>
            <input type="hidden" name="task" value="cleanup">
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="btn" onclick="return confirm('Remove selected extensions?\n\nThis will:\n- Run uninstall scripts\n- Delete extension files\n- Remove database entries\n\nThis cannot be undone!')">
                Remove Selected Extensions
            </button>
            <?php endif; ?>
            
            <?php if (!empty($groups['compatible']) || !empty($groups['core'])): ?>
            <h2>Compatible Extensions (<?php echo count($groups['compatible']) + count($groups['core']); ?>)</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Element</th>
                        <th>Enabled</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_merge($groups['core'], $groups['compatible']) as $ext): 
                        $enabled = $ext->enabled ? '<span class="badge badge-success">Enabled</span>' : '<span class="badge badge-warning">Disabled</span>';
                        $badge = $ext->_status === 'core' ? 'badge-info' : 'badge-success';
                    ?>
                    <tr class="compatible">
                        <td><?php echo htmlspecialchars($ext->name); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabled; ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($ext->_reason); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <?php if (empty($groups['incompatible']) && empty($groups['no-files'])): ?>
            <div class="alert alert-success">
                <strong>All extensions are compatible!</strong>
                <p style="margin: 10px 0 0 0;">No deprecated APIs found. Your extensions are ready for J2Commerce 4.x and Joomla 5+.</p>
            </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>
    
    <div class="j2cleanup-footer">
        <div class="logo">Advans IT Solutions GmbH</div>
        <p>
            <strong>J2Store Extension Cleanup</strong> v1.1.0<br>
            Developed by <a href="https://advans.ch" target="_blank">Advans IT Solutions GmbH</a>
        </p>
        <p style="margin-top: 15px; font-size: 12px; color: #666;">
            © 2025 Advans IT Solutions GmbH. All rights reserved.
        </p>
    </div>
</div>
</body>
</html>
