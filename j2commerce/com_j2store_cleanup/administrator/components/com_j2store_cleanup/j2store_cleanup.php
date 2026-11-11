<?php
/**
 * @package     J2Store Cleanup
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 * @version     1.0.0
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Toolbar\ToolbarHelper;
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

function isShopCoreExtension(object $ext): bool
{
    return ($ext->type ?? '') === 'component'
        && in_array($ext->element ?? '', ['com_j2store', 'com_j2commerce'], true);
}

/**
 * Whether an extension record is within the cleanup component's remit.
 */
function isCleanupCandidate(object $ext): bool
{
    $type      = (string) ($ext->type ?? '');
    $element   = (string) ($ext->element ?? '');
    $folder    = (string) ($ext->folder ?? '');
    // Joomla's uninstaller refuses locked records; a direct deletion must never bypass that.
    $protected = (int) ($ext->protected ?? 0) !== 0 || (int) ($ext->locked ?? 0) !== 0;

    if ($protected || isShopCoreExtension($ext) || $element === 'com_j2store_cleanup' || $element === '') {
        return false;
    }

    // Element and folder become filesystem paths: accept only plain extension
    // names that start with a letter or digit (so never ".", ".." or a traversal),
    // a library may have one sub-folder, and a plugin must name its group.
    $name = '[a-z0-9][a-z0-9_.-]*';
    if (str_contains($element, '..') || str_contains($folder, '..')
        || preg_match('#^' . $name . ($type === 'library' ? '(?:/' . $name . ')?' : '') . '$#', $element) !== 1
        || preg_match('#^(?:' . $name . ')?$#', $folder) !== 1
        || ($type === 'plugin' && $folder === '')) {
        return false;
    }

    switch ($type) {
        case 'component':
            return str_starts_with($element, 'com_j2store_')
                || str_starts_with($element, 'com_j2commerce_');

        case 'plugin':
            return (in_array($folder, ['j2store', 'j2commerce'], true)
                    || preg_match('/^(?:plg_)?j2(?:store|commerce)(?:_|$)/', $element) === 1)
                && preg_match('/^(?:com|mod|tpl|lib|files)_/', $element) !== 1;

        case 'module':
            return preg_match('/^mod_(?:j2store|j2commerce)(?:_|$)/', $element) === 1;

        case 'template':
        case 'library':
            return preg_match('/^(?:j2store|j2commerce)(?:[_\\/]|$)/', $element) === 1;
    }

    // File extensions have no single install path, so missing files cannot be
    // verified and a database-only removal could orphan their payload.
    return false;
}

function isCleanupEligible(object $ext, array $classification): bool
{
    $status = $classification['status'] ?? '';

    // A package child is never removed database-only: its package tracks it and
    // may block a separate uninstall, which only Joomla's installer enforces.
    return isCleanupCandidate($ext)
        && ($status === 'incompatible'
            || ($status === 'no-files' && (int) ($ext->package_id ?? 0) === 0));
}

function canAccessCleanup($user): bool
{
    return is_object($user) && $user->authorise('core.manage', 'com_j2store_cleanup');
}

/**
 * Removing extensions is an uninstall, so the user also needs Joomla's own
 * Extensions permissions (com_installer); this component never widens them.
 */
function canRemoveExtensions($user): bool
{
    return canAccessCleanup($user)
        && $user->authorise('core.delete', 'com_j2store_cleanup')
        && $user->authorise('core.manage', 'com_installer')
        && $user->authorise('core.delete', 'com_installer');
}

function addCleanupPreferences($user): void
{
    if (is_object($user)
        && ($user->authorise('core.admin', 'com_j2store_cleanup')
            || $user->authorise('core.options', 'com_j2store_cleanup'))) {
        ToolbarHelper::preferences('com_j2store_cleanup');
    }
}

/**
 * Accept only positive integer IDs; reject the whole selection if any value is malformed.
 *
 * @return array{ids:int[], invalid:int}
 */
function normalizeCleanupIds(array $ids): array
{
    $normalized = [];
    $invalid    = 0;

    foreach ($ids as $id) {
        if (is_int($id)) {
            $value = $id;
        } elseif (is_string($id) && ctype_digit($id)) {
            $value = (int) $id;
        } else {
            $invalid++;
            continue;
        }

        if ($value <= 0) {
            $invalid++;
            continue;
        }

        $normalized[$value] = $value;
    }

    return ['ids' => array_values($normalized), 'invalid' => $invalid];
}

/**
 * Fetch a fresh extension record before a database-only fallback.
 */
function getCleanupExtension(\Joomla\Database\DatabaseInterface $db, int $id): ?object
{
    $db->setQuery(
        createDbQuery($db)
            ->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id', 'protected', 'locked', 'package_id', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = :extId')
            ->bind(':extId', $id, \Joomla\Database\ParameterType::INTEGER)
    );

    $extension = $db->loadObject();

    return is_object($extension) ? $extension : null;
}

/**
 * Delete only the same unprotected extension record that was just revalidated,
 * and only while its files are verifiably missing.
 */
function removeCleanupExtensionRecord(\Joomla\Database\DatabaseInterface $db, object $ext, array $patterns): bool
{
    $classification = classifyExtension(json_decode((string) $ext->manifest_cache), $ext, $patterns);

    if (($classification['status'] ?? '') !== 'no-files' || !isCleanupEligible($ext, $classification)) {
        return false;
    }

    $extensionId = (int) $ext->extension_id;
    $type        = (string) $ext->type;
    $element     = (string) $ext->element;
    $folder      = (string) ($ext->folder ?? '');
    $clientId    = (int) $ext->client_id;

    $db->setQuery(
        createDbQuery($db)
            ->delete($db->quoteName('#__extensions'))
            ->where($db->quoteName('extension_id') . ' = :extId')
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('client_id') . ' = :clientId')
            ->where($db->quoteName('protected') . ' = 0')
            ->where($db->quoteName('locked') . ' = 0')
            ->where($db->quoteName('package_id') . ' = 0')
            ->bind(':extId', $extensionId, \Joomla\Database\ParameterType::INTEGER)
            ->bind(':type', $type, \Joomla\Database\ParameterType::STRING)
            ->bind(':element', $element, \Joomla\Database\ParameterType::STRING)
            ->bind(':folder', $folder, \Joomla\Database\ParameterType::STRING)
            ->bind(':clientId', $clientId, \Joomla\Database\ParameterType::INTEGER)
    );
    $db->execute();

    return $db->getAffectedRows() > 0;
}

/**
 * Revalidate a record immediately before a database-only removal.
 */
function removeEligibleCleanupRecord(\Joomla\Database\DatabaseInterface $db, int $id, array $patterns): ?object
{
    $ext = getCleanupExtension($db, $id);

    return $ext && removeCleanupExtensionRecord($db, $ext, $patterns) ? $ext : null;
}

/**
 * Uninstall a list of extensions by ID using Joomla's Installer API.
 *
 * Returns an array with keys 'success', 'warning', 'error' counts and
 * a 'messages' array describing any warnings or errors.
 *
 * @param \Joomla\Database\DatabaseInterface $db
 * @param array $ids  Raw extension IDs; malformed values reject the whole selection
 * @return array{success:int, warning:int, error:int, messages:string[]}
 */
function cleanupExtensions(\Joomla\Database\DatabaseInterface $db, array $ids): array
{
    $result = ['success' => 0, 'warning' => 0, 'error' => 0, 'messages' => []];

    if (empty($ids)) {
        return $result;
    }

    $normalized = normalizeCleanupIds($ids);

    if ($normalized['invalid'] > 0) {
        $result['error']    = $normalized['invalid'];
        $result['messages'] = array_fill(0, $normalized['invalid'], Text::_('COM_J2STORE_CLEANUP_MSG_INVALID_SELECTION'));

        return $result;
    }

    $ids = $normalized['ids'];

    if (empty($ids)) {
        $result['error']    = 1;
        $result['messages'] = [Text::_('COM_J2STORE_CLEANUP_MSG_INVALID_SELECTION')];

        return $result;
    }

    $query = createDbQuery($db)
        ->select($db->quoteName(['extension_id', 'type', 'element', 'folder', 'client_id', 'protected', 'locked', 'package_id', 'manifest_cache']))
        ->from($db->quoteName('#__extensions'))
        ->whereIn($db->quoteName('extension_id'), $ids);
    $db->setQuery($query);
    $extensions = $db->loadObjectList() ?: [];
    $byId       = [];

    foreach ($extensions as $ext) {
        $byId[(int) $ext->extension_id] = $ext;
    }

    $patterns = getIssuePatterns();
    $rejected = [];
    $warnings = [];
    $errors   = [];

    foreach ($ids as $id) {
        $ext = $byId[$id] ?? null;

        if (!$ext) {
            $rejected[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_EXTENSION_NOT_FOUND', $id);
            continue;
        }

        $classification = classifyExtension(json_decode((string) $ext->manifest_cache), $ext, $patterns);

        if (!isCleanupEligible($ext, $classification)) {
            $rejected[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_NOT_ELIGIBLE', $id);
        }
    }

    if ($rejected) {
        $result['error']    = count($rejected);
        $result['messages'] = $rejected;

        return $result;
    }

    foreach ($ids as $id) {
        $ext = getCleanupExtension($db, $id);

        if (!$ext) {
            $result['error']++;
            $errors[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_EXTENSION_NOT_FOUND', $id);
            continue;
        }

        // Missing files are deliberately handled as database-only cleanup: Joomla
        // cannot run an uninstall script without the extension's files.
        $classification = classifyExtension(json_decode((string) $ext->manifest_cache), $ext, $patterns);

        if (!isCleanupEligible($ext, $classification)) {
            $result['error']++;
            $errors[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_NOT_ELIGIBLE', $id);
            continue;
        }

        if (($classification['status'] ?? '') === 'no-files') {
            try {
                $removed = removeEligibleCleanupRecord($db, $id, $patterns);
                $fallbackError = null;
            } catch (\Throwable $e) {
                $removed = null;
                $fallbackError = $e;
            }

            if ($removed) {
                $result['warning']++;
                $warnings[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_DB_ONLY', htmlspecialchars((string) $removed->element));
            } else {
                $result['error']++;
                $errors[] = $fallbackError
                    ? htmlspecialchars($ext->element . ': ' . $fallbackError->getMessage())
                    : Text::sprintf('COM_J2STORE_CLEANUP_MSG_NOT_ELIGIBLE', $id);
            }

            continue;
        }

        try {
            $installer   = Installer::getInstance();
            $uninstalled = $installer->uninstall($ext->type, $ext->extension_id);

            if ($uninstalled) {
                $result['success']++;
            } else {
                // A false return is Joomla's refusal; do not bypass it with a DB delete.
                $result['error']++;
                $errors[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_UNINSTALL_REFUSED', $id);
            }
        } catch (\Throwable $e) {
            // An exception is a failed uninstall. The record is removed
            // database-only only if the extension's files are verifiably gone
            // afterwards; otherwise it stays, so no code or data is orphaned.
            try {
                $removed = removeEligibleCleanupRecord($db, $id, $patterns);
                $fallbackError = null;
            } catch (\Throwable $fallbackException) {
                $removed = null;
                $fallbackError = $fallbackException;
            }

            if ($removed) {
                $result['warning']++;
                $warnings[] = Text::sprintf('COM_J2STORE_CLEANUP_MSG_DB_ONLY', htmlspecialchars((string) $removed->element));
            } else {
                $result['error']++;
                $details = $fallbackError
                    ? Text::sprintf('COM_J2STORE_CLEANUP_MSG_FALLBACK_FAILED',
                        htmlspecialchars($e->getMessage()), htmlspecialchars($fallbackError->getMessage()))
                    : htmlspecialchars($e->getMessage());
                $errors[] = htmlspecialchars((string) $ext->element) . ': ' . $details;
            }
        }
    }

    $result['messages'] = array_merge($warnings, $errors);

    return $result;
}

/**
 * The display name of an extension.
 *
 * `#__extensions.name` holds a language key for almost every extension
 * (`COM_J2STORE_CLEANUP`, `PLG_...`), and each extension carries the
 * translation in its own `.sys.ini`. Printing the column unchanged put raw
 * language keys on an otherwise German or English page, so the extension's
 * system language file is loaded first — the same way Joomla's own extension
 * manager does it. An extension that ships no such file keeps its stored name,
 * exactly as Joomla shows it.
 *
 * @param object $ext  Extension record from #__extensions
 */
function getExtensionName($ext): string
{
    $name = (string) ($ext->name ?? '');

    if ($name === '') {
        return '';
    }

    $language = Factory::getApplication()->getLanguage();
    $client   = empty($ext->client_id) ? JPATH_SITE : JPATH_ADMINISTRATOR;

    switch ($ext->type) {
        case 'component':
            $language->load($ext->element . '.sys', JPATH_ADMINISTRATOR)
                || $language->load($ext->element . '.sys', JPATH_ADMINISTRATOR . '/components/' . $ext->element);
            break;

        case 'plugin':
            $key = 'plg_' . $ext->folder . '_' . $ext->element;
            $language->load($key . '.sys', JPATH_ADMINISTRATOR)
                || $language->load($key . '.sys', JPATH_PLUGINS . '/' . $ext->folder . '/' . $ext->element);
            break;

        case 'module':
            $language->load($ext->element . '.sys', $client)
                || $language->load($ext->element . '.sys', $client . '/modules/' . $ext->element);
            break;

        case 'template':
            $key = 'tpl_' . $ext->element;
            $language->load($key . '.sys', $client)
                || $language->load($key . '.sys', $client . '/templates/' . $ext->element);
            break;

        case 'library':
            $language->load('lib_' . str_replace('/', '_', (string) $ext->element) . '.sys', $client);
            break;

        case 'file':
            $language->load('files_' . $ext->element . '.sys', JPATH_SITE);
            break;

        default:
            $language->load($ext->element . '.sys', JPATH_SITE);
            break;
    }

    return Text::_($name);
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
 * All directories that can hold an extension's code.
 *
 * @param object $ext  Extension record from #__extensions
 * @return string[]
 */
function getExtensionCodePaths($ext): array
{
    if (($ext->type ?? '') === 'component') {
        return [
            JPATH_ADMINISTRATOR . '/components/' . $ext->element,
            JPATH_SITE . '/components/' . $ext->element,
            (defined('JPATH_API') ? JPATH_API : JPATH_SITE . '/api') . '/components/' . $ext->element,
        ];
    }

    $path = getExtensionPath($ext);

    return $path === null ? [] : [$path];
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

    return uniqueIssues($issues);
}

/**
 * Keep the first finding per issue type and API, across any number of scans.
 */
function uniqueIssues(array $issues): array
{
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
    if (isShopCoreExtension($ext)) {
        $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
        return ['status' => 'core', 'reason' => Text::sprintf('COM_J2STORE_CLEANUP_REASON_CORE', $version), 'issues' => []];
    }

    if (!isCleanupCandidate($ext)) {
        return ['status' => 'protected', 'reason' => '', 'issues' => []];
    }

    $version = is_object($manifest) ? ($manifest->version ?? '?') : '?';
    $author  = is_object($manifest) ? ($manifest->author ?? '?') : '?';
    $info    = Text::sprintf('COM_J2STORE_CLEANUP_REASON_INFO', $author, $version);

    // A component has code in the administrator, site and API directories.
    // Files count as missing only when every directory is gone, so a
    // database-only removal can never orphan surviving code.
    $paths = array_values(array_filter(getExtensionCodePaths($ext), static fn (string $path): bool => is_dir($path)));

    if ($paths === []) {
        return ['status' => 'no-files', 'reason' => Text::sprintf('COM_J2STORE_CLEANUP_REASON_NO_FILES', $info), 'issues' => []];
    }

    $issues = [];
    foreach ($paths as $path) {
        $issues = array_merge($issues, scanForIssues($path, $patterns));
    }
    $issues = uniqueIssues($issues);

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

// Joomla's legacy component dispatcher does not check core.manage, so every
// request (scan view and cleanup task) enforces it here.
if (!canAccessCleanup($app->getIdentity())) {
    throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
}

// Handle cleanup action
if ($task === 'cleanup' && Session::checkToken()) {
    $user = $app->getIdentity();
    if (!canRemoveExtensions($user)) {
        $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
        $app->redirect('index.php?option=com_j2store_cleanup');
        return;
    }
    $cids = $app->getInput()->get('cid', [], 'array');

    if (!$cids) {
        $app->enqueueMessage(Text::_('COM_J2STORE_CLEANUP_MSG_SELECT_ONE'), 'warning');
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

// The same candidate predicate is used here and by the mutation path.
$query = createDbQuery($db)
    ->select($db->quoteName(['extension_id', 'name', 'type', 'element', 'folder', 'enabled', 'client_id', 'protected', 'locked', 'package_id', 'manifest_cache']))
    ->from('#__extensions')
    ->order('type, name');

$db->setQuery($query);
$extensions = array_values(array_filter(
    $db->loadObjectList(),
    static fn ($ext) => isCleanupCandidate($ext) || isShopCoreExtension($ext)
));
$canRemoveExtensions = canRemoveExtensions($app->getIdentity());
addCleanupPreferences($app->getIdentity());

// Texts the page script needs (confirmation before removal) and the
// version shown in the footer.
Text::script('COM_J2STORE_CLEANUP_CONFIRM_REMOVE');
$cleanupVersion = getCleanupVersion($db);

?>
<!DOCTYPE html>
<html>
<head>
    <style>
        .j2cleanup {
            box-sizing: border-box;
            width: 100%;
            min-width: 0;
            max-width: 100%;
            padding: clamp(1rem, 3vw, 1.25rem);
            background: var(--body-bg, var(--bs-body-bg, #fff));
            color: var(--body-color, var(--bs-body-color, #212529));
            font-family: inherit;
            min-height: 100vh;
        }
        .j2cleanup *,
        .j2cleanup *::before,
        .j2cleanup *::after {
            box-sizing: border-box;
        }
        .j2cleanup h1 { 
            margin-bottom: 10px; 
            color: var(--emphasis-color, var(--bs-emphasis-color, #212529));
            font-size: 28px;
        }
        .j2cleanup h2 {
            color: var(--primary, var(--bs-primary, #0d6efd));
            font-size: 18px;
            margin: 25px 0 15px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color, var(--bs-border-color, #dee2e6));
        }
        .j2cleanup .alert { 
            padding: 15px; 
            margin: 15px 0; 
            border-radius: 4px; 
        }
        .j2cleanup .alert-info { 
            background: var(--info-bg-subtle, var(--bs-info-bg-subtle, #cff4fc));
            border: 1px solid var(--info-border-subtle, var(--bs-info-border-subtle, #9eeaf9));
            color: var(--info-text-emphasis, var(--bs-info-text-emphasis, #055160));
        }
        .j2cleanup .alert-warning { 
            background: var(--warning-bg-subtle, var(--bs-warning-bg-subtle, #fff3cd));
            border: 1px solid var(--warning-border-subtle, var(--bs-warning-border-subtle, #ffe69c));
            color: var(--warning-text-emphasis, var(--bs-warning-text-emphasis, #664d03));
        }
        .j2cleanup .alert-success { 
            background: var(--success-bg-subtle, var(--bs-success-bg-subtle, #d1e7dd));
            border: 1px solid var(--success-border-subtle, var(--bs-success-border-subtle, #a3cfbb));
            color: var(--success-text-emphasis, var(--bs-success-text-emphasis, #0a3622));
        }
        .j2cleanup .alert-danger {
            background: var(--danger-bg-subtle, var(--bs-danger-bg-subtle, #f8d7da));
            border: 1px solid var(--danger-border-subtle, var(--bs-danger-border-subtle, #f1aeb5));
            color: var(--danger-text-emphasis, var(--bs-danger-text-emphasis, #58151c));
        }
        .j2cleanup .table-responsive {
            width: 100%;
            min-width: 0;
            max-width: 100%;
            overflow-x: auto;
            overscroll-behavior-x: contain;
        }
        .j2cleanup table {
            width: 100%; 
            min-width: 40rem;
            border-collapse: collapse; 
            margin: 20px 0; 
            background: var(--body-bg, var(--bs-body-bg, #fff));
            border: 1px solid var(--border-color, var(--bs-border-color, #dee2e6));
        }
        .j2cleanup th, .j2cleanup td { 
            padding: 10px; 
            text-align: left; 
            border: 1px solid var(--border-color, var(--bs-border-color, #dee2e6));
            color: var(--body-color, var(--bs-body-color, #212529));
        }
        .j2cleanup th { 
            background: #007bff; 
            color: #fff !important;
            font-weight: bold;
        }
        .j2cleanup tr:nth-child(even) { 
            background: var(--tertiary-bg, var(--bs-tertiary-bg, #f8f9fa));
        }
        .j2cleanup tr:hover { 
            background: var(--secondary-bg, var(--bs-secondary-bg, #e9ecef));
        }
        .j2cleanup .incompatible { 
            background: var(--danger-bg-subtle, var(--bs-danger-bg-subtle, #f8d7da)) !important;
            border-left: 3px solid #dc3545;
        }
        .j2cleanup .compatible {
            background: var(--success-bg-subtle, var(--bs-success-bg-subtle, #d1e7dd)) !important;
            border-left: 3px solid #28a745;
        }
        .j2cleanup .no-files {
            background: var(--secondary-bg, var(--bs-secondary-bg, #e9ecef));
            border-left: 3px solid #6c757d;
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
            background: var(--secondary-bg, var(--bs-secondary-bg, #e9ecef));
            padding: 2px 6px;
            border-radius: 3px;
            color: var(--emphasis-color, var(--bs-emphasis-color, #212529));
            font-family: monospace;
            border: 1px solid var(--border-color, var(--bs-border-color, #dee2e6));
        }
        .j2cleanup strong {
            color: var(--emphasis-color, var(--bs-emphasis-color, #212529));
        }
        .j2cleanup p {
            color: var(--body-color, var(--bs-body-color, #212529));
        }
        .j2cleanup .detection-info {
            background: var(--info-bg-subtle, var(--bs-info-bg-subtle, #cff4fc));
            border: 1px solid var(--border-color, var(--bs-border-color, #dee2e6));
            border-radius: 4px;
            padding: 15px;
            margin: 20px 0;
        }
        .j2cleanup .detection-info h3 {
            color: var(--info-text-emphasis, var(--bs-info-text-emphasis, #055160));
            margin: 0 0 10px 0;
            font-size: 16px;
        }
        .j2cleanup .detection-info ul {
            margin: 0;
            padding-left: 20px;
        }
        .j2cleanup .detection-info li {
            margin: 5px 0;
            color: var(--body-color, var(--bs-body-color, #212529));
        }
        .j2cleanup .detection-info .criterion {
            color: var(--success-text-emphasis, var(--bs-success-text-emphasis, #0a3622));
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
            border-top: 2px solid var(--border-color, var(--bs-border-color, #dee2e6));
            text-align: center;
            color: var(--secondary-color, var(--bs-secondary-color, #6c757d));
        }
        .j2cleanup-footer a {
            color: var(--link-color, var(--bs-link-color, #0d6efd));
            text-decoration: none;
            transition: color 0.3s;
        }
        .j2cleanup-footer a:hover {
            color: var(--link-hover-color, var(--bs-link-hover-color, #0a58ca));
            text-decoration: underline;
        }
        .j2cleanup-footer .logo {
            font-size: 20px;
            font-weight: bold;
            color: var(--primary, var(--bs-primary, #0d6efd));
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<div class="j2cleanup">
    <h1><?php echo Text::_('COM_J2STORE_CLEANUP'); ?></h1>
    <p style="color: var(--secondary-color, var(--bs-secondary-color, #6c757d)); margin-bottom: 20px;"><?php echo Text::_('COM_J2STORE_CLEANUP_SUBTITLE'); ?></p>

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
        <p style="margin-top: 10px; color: var(--warning-text-emphasis, var(--bs-warning-text-emphasis, #664d03));"><?php echo Text::sprintf('COM_J2STORE_CLEANUP_NOTE_DEPRECATED', (int) JVERSION); ?></p>
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
                if ($result['status'] === 'core') {
                    $groups['core'][] = $ext;
                } elseif (isCleanupEligible($ext, $result)) {
                    $groups[$result['status']][] = $ext;
                } elseif ($result['status'] === 'compatible') {
                    $groups['compatible'][] = $ext;
                }
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

            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th width="30"><?php echo $canRemoveExtensions ? $selectAll : ''; ?></th>
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
                        <td><?php if ($canRemoveExtensions): ?><input type="checkbox" name="cid[]" value="<?php echo $ext->extension_id; ?>"><?php endif; ?></td>
                        <td><?php echo htmlspecialchars(getExtensionName($ext)); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabledBadge($ext); ?></td>
                        <td>
                            <details>
                                <summary><?php echo htmlspecialchars($ext->_reason); ?></summary>
                                <ul style="margin: 5px 0; padding-left: 20px; font-size: 12px;">
                                <?php foreach ($ext->_issues as $issue): ?>
                                    <li>
                                        <span class="badge <?php echo $issue['type'] === 'j2store' ? 'badge-danger' : 'badge-warning'; ?>"><?php echo Text::_($issue['type'] === 'j2store' ? 'COM_J2STORE_CLEANUP_ISSUE_ORIGIN_J2STORE' : 'COM_J2STORE_CLEANUP_ISSUE_ORIGIN_JOOMLA'); ?></span>
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
            </div>
            <?php endif; ?>

            <?php if (!empty($groups['no-files'])): ?>
            <h2><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SECTION_NO_FILES', count($groups['no-files'])); ?></h2>
            <p><?php echo Text::_('COM_J2STORE_CLEANUP_SECTION_NO_FILES_DESC'); ?></p>

            <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th width="30"><?php echo $canRemoveExtensions ? $selectAll : ''; ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_NAME'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_TYPE'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_ELEMENT'); ?></th>
                        <th><?php echo Text::_('COM_J2STORE_CLEANUP_COL_DETAILS'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups['no-files'] as $ext): ?>
                    <tr class="no-files">
                        <td><?php if ($canRemoveExtensions): ?><input type="checkbox" name="cid[]" value="<?php echo $ext->extension_id; ?>"><?php endif; ?></td>
                        <td><?php echo htmlspecialchars(getExtensionName($ext)); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo htmlspecialchars($ext->_reason); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>

            <?php if ($canRemoveExtensions && (!empty($groups['incompatible']) || !empty($groups['no-files']))): ?>
            <input type="hidden" name="task" value="cleanup">
            <?php echo HTMLHelper::_('form.token'); ?>
            <button type="submit" class="btn" onclick="return confirm(Joomla.Text._('COM_J2STORE_CLEANUP_CONFIRM_REMOVE'))">
                <?php echo Text::_('COM_J2STORE_CLEANUP_BUTTON_REMOVE'); ?>
            </button>
            <?php endif; ?>

            <?php if (!empty($groups['compatible']) || !empty($groups['core'])): ?>
            <h2><?php echo Text::sprintf('COM_J2STORE_CLEANUP_SECTION_COMPATIBLE', count($groups['compatible']) + count($groups['core'])); ?></h2>

            <div class="table-responsive">
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
                        <td><?php echo htmlspecialchars(getExtensionName($ext)); ?></td>
                        <td><?php echo htmlspecialchars($ext->type); ?></td>
                        <td><code><?php echo htmlspecialchars($ext->element); ?></code></td>
                        <td><?php echo $enabledBadge($ext); ?></td>
                        <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($ext->_reason); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
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
        <p style="margin-top: 15px; font-size: 12px; color: var(--secondary-color, var(--bs-secondary-color, #6c757d));">
            <?php echo Text::sprintf('COM_J2STORE_CLEANUP_FOOTER_COPYRIGHT', '2025-2026', 'Advans IT Solutions GmbH'); ?>
        </p>
    </div>
</div>
</body>
</html>
