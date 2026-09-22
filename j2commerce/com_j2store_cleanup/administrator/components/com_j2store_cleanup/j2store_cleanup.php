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
use Joomla\Database\DatabaseInterface;

// Defer application bootstrap so this file can be safely included in tests.
// $app, $db, and $task are initialised on first use below.
if (!defined('J2STORE_CLEANUP_FUNCTIONS_ONLY')) {
    $app  = Factory::getApplication();
    $db   = Factory::getContainer()->get(DatabaseInterface::class);
    $task = $app->getInput()->get('task', 'display');
}

/**
 * Create a fresh query object — compatible with Joomla 4/5 (getQuery) and 6 (createQuery).
 *
 * @param \Joomla\Database\DatabaseInterface $db
 * @return \Joomla\Database\QueryInterface
 */
function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
{
    return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
}

/**
 * Uninstall a list of extensions by ID using Joomla's Installer API.
 *
 * Returns an array with keys 'success', 'warning', 'error' counts and
 * a 'messages' array describing any warnings or errors.
 *
 * @param \Joomla\Database\DatabaseInterface $db
 * @param int[] $ids  Extension IDs to remove (already sanitised to positive ints)
 * @return array{success:int, warning:int, error:int, messages:string[]}
 */
function cleanupExtensions(\Joomla\Database\DatabaseInterface $db, array $ids): array
{
    $result = ['success' => 0, 'warning' => 0, 'error' => 0, 'messages' => []];

    if (empty($ids)) {
        return $result;
    }

    $query = createDbQuery($db)
        ->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id']))
        ->from($db->quoteName('#__extensions'))
        ->whereIn($db->quoteName('extension_id'), $ids);
    $db->setQuery($query);
    $extensions = $db->loadObjectList();

    foreach ($extensions as $ext) {
        $uninstalled = false;
        try {
            $installer   = Installer::getInstance();
            $uninstalled = $installer->uninstall($ext->type, $ext->extension_id);
        } catch (\Throwable $e) {
            // Installer threw (e.g. missing manifest) — fall through to DB-only removal.
        }

        if ($uninstalled) {
            $result['success']++;
        } else {
            // Fallback: remove the DB record directly when the installer cannot handle it
            // (files already gone, no manifest, or installer error).
            try {
                $extId = (int) $ext->extension_id;
                $db->setQuery(
                    createDbQuery($db)
                        ->delete($db->quoteName('#__extensions'))
                        ->where($db->quoteName('extension_id') . ' = :extId')
                        ->bind(':extId', $extId, \Joomla\Database\ParameterType::INTEGER)
                );
                $db->execute();
                $result['warning']++;
                $result['messages'][] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_DB_ONLY', $ext->element);
            } catch (\Throwable $e2) {
                $result['error']++;
                $result['messages'][] = $ext->element . ': ' . $e2->getMessage();
            }
        }
    }

    return $result;
}

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
 * Each entry: pattern => ['api' => name of the API, 'removedIn' => Joomla
 * major version]. The page builds the visible text from these values with a
 * language key, so the patterns carry no text of their own. Only patterns
 * relevant to the running Joomla version (and above) are returned.
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
            '/\bJPlugin\b/'              => 'JPlugin',
            '/\bJModel(Legacy)?\b/'      => 'JModel',
            '/\bJTable\b/'               => 'JTable',
            '/\bJView(Legacy)?\b/'       => 'JView',
            '/\bJController(Legacy)?\b/' => 'JController',
            '/\bJForm\b/'               => 'JForm',
        ],
        // Removed in Joomla 6 (deprecated since 4/5, B/C plugin removed in 6)
        6 => [
            '/\bJFactory\b/'             => 'JFactory',
            '/\bJText\b/'               => 'JText',
            '/\bJHtml\b/'               => 'JHtml',
            '/\bJRoute\b/'              => 'JRoute',
            '/\bJUri\b/'                => 'JUri',
            '/\bJSession\b/'            => 'JSession',
            '/Factory::getUser\s*\(/'   => 'Factory::getUser()',
            '/Factory::getDbo\s*\(/'    => 'Factory::getDbo()',
            '/Factory::getSession\s*\(/' => 'Factory::getSession()',
            '/Factory::getDocument\s*\(/' => 'Factory::getDocument()',
            '/\$this->app\b(?!lication)/' => '$this->app',
        ],
    ];

    // Only include patterns for APIs removed in the current or earlier version.
    // On Joomla 5: only J4 removals apply (J6 removals are deprecated but work).
    // On Joomla 6: both J4 and J6 removals apply.
    $joomlaPatterns = [];
    foreach ($joomlaByVersion as $removedIn => $patterns) {
        if ($joomlaMajor >= $removedIn) {
            foreach ($patterns as $pattern => $api) {
                $joomlaPatterns[$pattern] = ['api' => $api, 'removedIn' => $removedIn];
            }
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
 * @return array  List of issues found, each with 'type', 'detail' (API name)
 *                and 'removedIn' (Joomla major version); see describeIssue()
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

        foreach ($patterns['joomla'] as $pattern => $info) {
            if (preg_match($pattern, $stripped)) {
                $issues[] = ['type' => 'joomla', 'detail' => $info['api'], 'removedIn' => $info['removedIn']];
            }
        }

        foreach ($patterns['j2store'] as $pattern => $info) {
            if (preg_match($pattern, $stripped)) {
                $issues[] = ['type' => 'j2store', 'detail' => $info['api'], 'removedIn' => $info['removedIn'] ?? 0];
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
    if ($ext->element === 'com_j2store' || $ext->element === 'com_j2commerce') {
        $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
        return ['status' => 'core', 'reason' => Text::sprintf('COM_J2STORE_CLEANUP_REASON_CORE', $version), 'issues' => []];
    }

    $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
    $author  = is_object($manifest) ? ($manifest->author ?? '?') : '?';
    $info    = Text::sprintf('COM_J2STORE_CLEANUP_REASON_INFO', $author, $version);

    $path = getExtensionPath($ext);

    if ($path === null || !is_dir($path)) {
        return ['status' => 'no-files', 'reason' => Text::sprintf('COM_J2STORE_CLEANUP_REASON_NO_FILES', $info), 'issues' => []];
    }

    $issues = scanForIssues($path, $patterns);

    if (empty($issues)) {
        return ['status' => 'compatible', 'reason' => Text::sprintf('COM_J2STORE_CLEANUP_REASON_COMPATIBLE', $info), 'issues' => []];
    }

    $joomlaIssues = array_filter($issues, fn($i) => $i['type'] === 'joomla');
    $j2Issues     = array_filter($issues, fn($i) => $i['type'] === 'j2store');

    $parts = [];
    if (!empty($j2Issues)) {
        $parts[] = Text::sprintf('COM_J2STORE_CLEANUP_REASON_J2STORE_ISSUES', count($j2Issues));
    }
    if (!empty($joomlaIssues)) {
        $parts[] = Text::sprintf('COM_J2STORE_CLEANUP_REASON_JOOMLA_ISSUES', count($joomlaIssues));
    }

    return [
        'status'  => 'incompatible',
        'reason'  => Text::sprintf('COM_J2STORE_CLEANUP_REASON_INCOMPATIBLE', implode(', ', $parts), $info),
        'issues'  => $issues,
    ];
}

/**
 * Visible text of one scan finding, e.g. "JFactory (removed in Joomla 6)".
 *
 * @param array $issue  One entry of scanForIssues()
 */
function describeIssue(array $issue): string
{
    return Text::sprintf('COM_J2STORE_CLEANUP_ISSUE_REMOVED_IN', $issue['detail'], (int) ($issue['removedIn'] ?? 0));
}

/**
 * Installed version of this component, read from its extension record.
 */
function getCleanupVersion(\Joomla\Database\DatabaseInterface $db): string
{
    try {
        $db->setQuery(
            createDbQuery($db)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_j2store_cleanup'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
        );
        $manifest = json_decode((string) $db->loadResult());

        return is_object($manifest) ? (string) ($manifest->version ?? '') : '';
    } catch (\Throwable $e) {
        return '';
    }
}

// Only run the dispatcher and render when executing as a real Joomla request.
if (defined('J2STORE_CLEANUP_FUNCTIONS_ONLY')) {
    return;
}

// Handle cleanup action
if ($task === 'cleanup' && Session::checkToken()) {
    // Require core.manage on this component — a valid session token alone is
    // not sufficient because any authenticated backend user has one.
    $user = $app->getIdentity();
    if (!$user || !$user->authorise('core.manage', 'com_j2store_cleanup')) {
        $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
        $app->redirect('index.php?option=com_j2store_cleanup');
        return;
    }
    $cids = $app->getInput()->get('cid', [], 'array');
    $cids = array_map('intval', $cids);
    $cids = array_filter($cids); // Remove zeros
    
    if (empty($cids)) {
        $app->enqueueMessage(Text::_('COM_J2STORE_CLEANUP_MSG_SELECT_ONE'), 'warning');
        $app->redirect('index.php?option=com_j2store_cleanup');
        return;
    }
    
    // Reject any IDs that belong to protected/core extensions before passing
    // them to cleanupExtensions(). classifyExtension() marks com_j2store and
    // com_j2commerce as 'core'; we enforce that here so a crafted POST cannot
    // bypass the UI-level protection.
    $protectedElements = ['com_j2store', 'com_j2commerce'];
    $query = createDbQuery($db)
        ->select($db->quoteName(['extension_id', 'element']))
        ->from($db->quoteName('#__extensions'))
        ->whereIn($db->quoteName('extension_id'), $cids);
    $db->setQuery($query);
    $blocked = [];
    foreach ($db->loadObjectList() as $ext) {
        if (in_array($ext->element, $protectedElements, true)) {
            $blocked[] = $ext->element;
        }
    }
    if (!empty($blocked)) {
        $app->enqueueMessage(
            Text::sprintf('COM_J2STORE_CLEANUP_MSG_PROTECTED', implode(', ', $blocked)),
            'error'
        );
        $app->redirect('index.php?option=com_j2store_cleanup');
        return;
    }

    $r = cleanupExtensions($db, $cids);

    if ($r['success'] > 0) {
        $app->enqueueMessage(Text::sprintf('COM_J2STORE_CLEANUP_MSG_REMOVED_SUCCESS', $r['success']), 'success');
    }
    if ($r['warning'] > 0) {
        $app->enqueueMessage(Text::sprintf('COM_J2STORE_CLEANUP_MSG_REMOVED_WARNING', $r['warning'], implode(', ', array_slice($r['messages'], 0, $r['warning']))), 'warning');
    }
    if ($r['error'] > 0) {
        $app->enqueueMessage(Text::sprintf('COM_J2STORE_CLEANUP_MSG_REMOVED_ERROR', $r['error'], implode(', ', array_slice($r['messages'], $r['warning']))), 'error');
    }
    
    $app->redirect('index.php?option=com_j2store_cleanup');
}

// Get all J2Store/J2Commerce extensions
$query = createDbQuery($db)
    ->select('extension_id, name, type, element, folder, enabled, client_id, manifest_cache')
    ->from('#__extensions')
    ->where("(element LIKE '%j2store%' OR element LIKE '%j2commerce%' OR element LIKE 'j2%' OR element LIKE 'mod\_j2%' OR element LIKE 'com\_j2%' OR folder = 'j2store')")
    ->order('type, name');

$db->setQuery($query);
$extensions = $db->loadObjectList();

// Texts the page script needs (confirmation before removal) and the
// version shown in the footer.
Text::script('COM_J2STORE_CLEANUP_CONFIRM_REMOVE');
$cleanupVersion = getCleanupVersion($db);

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
    <h1><?php echo Text::_('COM_J2STORE_CLEANUP'); ?></h1>
    <p style="color: #888; margin-bottom: 20px;"><?php echo Text::_('COM_J2STORE_CLEANUP_SUBTITLE'); ?></p>

    <div class="detection-info">
        <h3><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SCAN_TITLE', JVERSION); ?></h3>
        <p style="margin-bottom: 10px;"><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SCAN_DESC', (int) JVERSION); ?></p>
        <ul>
            <li><span class="badge badge-success"><?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_COMPATIBLE'); ?></span> — <?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_COMPATIBLE_DESC'); ?></li>
            <li><span class="badge badge-danger"><?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_INCOMPATIBLE'); ?></span> — <?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_INCOMPATIBLE_DESC'); ?></li>
            <li><span class="badge badge-secondary"><?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_NO_FILES'); ?></span> — <?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_NO_FILES_DESC'); ?></li>
            <li><span class="badge badge-info"><?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_CORE'); ?></span> — <?php echo Text::_('COM_J2STORE_CLEANUP_BADGE_CORE_DESC'); ?></li>
        </ul>
        <?php if ((int) JVERSION < 6): ?>
        <p style="margin-top: 10px; color: #ffc107;"><?php echo Text::sprintf('COM_J2STORE_CLEANUP_NOTE_DEPRECATED', (int) JVERSION); ?></p>
        <?php endif; ?>
    </div>

    <?php if (empty($extensions)): ?>
        <div class="alert alert-success">
            <strong><?php echo Text::_('COM_J2STORE_CLEANUP_NONE_FOUND_TITLE'); ?></strong>
            <p style="margin: 10px 0 0 0;"><?php echo Text::_('COM_J2STORE_CLEANUP_NONE_FOUND_DESC'); ?></p>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <?php echo Text::_('COM_J2STORE_CLEANUP_BACKUP_WARNING'); ?>
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

            $enabledBadge = static function ($ext): string {
                return $ext->enabled
                    ? '<span class="badge badge-success">' . Text::_('JENABLED') . '</span>'
                    : '<span class="badge badge-warning">' . Text::_('JDISABLED') . '</span>';
            };
            $selectAll = '<input type="checkbox" aria-label="' . htmlspecialchars(Text::_('JGLOBAL_CHECK_ALL'), ENT_QUOTES, 'UTF-8') . '"'
                . ' onclick="this.closest(\'table\').querySelectorAll(\'input[type=checkbox]\').forEach(cb => cb.checked = this.checked)">';
            ?>

            <?php if (!empty($groups['incompatible'])): ?>
            <h2><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SECTION_INCOMPATIBLE', count($groups['incompatible'])); ?></h2>
            <p><?php echo Text::_('COM_J2STORE_CLEANUP_SECTION_INCOMPATIBLE_DESC'); ?></p>

            <table>
                <thead>
                    <tr>
                        <th width="30"><?php echo $selectAll; ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ELEMENT'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ENABLED'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ISSUES'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups['incompatible'] as $ext): ?>
                    <tr class="incompatible">
                        <td><input type="checkbox" name="cid[]" value="<?php echo $ext->extension_id; ?>"></td>
                        <td><?php echo htmlspecialchars($ext->name); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabledBadge($ext); ?></td>
                        <td>
                            <details>
                                <summary><?php echo htmlspecialchars($ext->_reason); ?></summary>
                                <ul style="margin: 5px 0; padding-left: 20px; font-size: 12px;">
                                <?php foreach ($ext->_issues as $issue): ?>
                                    <li>
                                        <span class="badge <?php echo $issue['type'] === 'j2store' ? 'badge-danger' : 'badge-warning'; ?>"><?php echo $issue['type'] === 'j2store' ? 'J2Store' : 'Joomla'; ?></span>
                                        <?php echo htmlspecialchars(describeIssue($issue)); ?>
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
            <h2><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SECTION_NO_FILES', count($groups['no-files'])); ?></h2>
            <p><?php echo Text::_('COM_J2STORE_CLEANUP_SECTION_NO_FILES_DESC'); ?></p>

            <table>
                <thead>
                    <tr>
                        <th width="30"><?php echo $selectAll; ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ELEMENT'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_DETAILS'); ?></th>
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
            <button type="submit" class="btn" onclick="return confirm(Joomla.Text._('COM_J2STORE_CLEANUP_CONFIRM_REMOVE'))">
                <?php echo Text::_('COM_J2STORE_CLEANUP_BUTTON_REMOVE'); ?>
            </button>
            <?php endif; ?>

            <?php if (!empty($groups['compatible']) || !empty($groups['core'])): ?>
            <h2><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SECTION_COMPATIBLE', count($groups['compatible']) + count($groups['core'])); ?></h2>

            <table>
                <thead>
                    <tr>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ELEMENT'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ENABLED'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_DETAILS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_merge($groups['core'], $groups['compatible']) as $ext):
                        $badge = $ext->_status === 'core' ? 'badge-info' : 'badge-success';
                    ?>
                    <tr class="compatible">
                        <td><?php echo htmlspecialchars($ext->name); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabledBadge($ext); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($ext->_reason); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (empty($groups['incompatible']) && empty($groups['no-files'])): ?>
            <div class="alert alert-success">
                <strong><?php echo Text::_('COM_J2STORE_CLEANUP_ALL_COMPATIBLE_TITLE'); ?></strong>
                <p style="margin: 10px 0 0 0;"><?php echo Text::_('COM_J2STORE_CLEANUP_ALL_COMPATIBLE_DESC'); ?></p>
            </div>
            <?php endif; ?>
        </form>
    <?php endif; ?>

    <div class="j2cleanup-footer">
        <div class="logo">Advans IT Solutions GmbH</div>
        <p>
            <strong><?php echo Text::_('COM_J2STORE_CLEANUP'); ?></strong><?php if ($cleanupVersion !== ''): ?> <?php echo Text::sprintf('COM_J2STORE_CLEANUP_FOOTER_VERSION', htmlspecialchars($cleanupVersion)); ?><?php endif; ?><br>
            <?php echo Text::sprintf('COM_J2STORE_CLEANUP_FOOTER_DEVELOPED_BY', '<a href="https://advans.ch" target="_blank">Advans IT Solutions GmbH</a>'); ?>
        </p>
        <p style="margin-top: 15px; font-size: 12px; color: #666;">
            © 2025-2026 Advans IT Solutions GmbH
        </p>
    </div>
</div>
</body>
</html>
