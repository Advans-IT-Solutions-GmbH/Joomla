<?php
/**
 * Library, not a suite: Joomla's own database check for one component.
 *
 * System → Maintenance → Database and `cli/joomla.php maintenance:database` report a
 * component as "not up to date" when its installation manifest cannot be found under
 * the name Joomla expects (administrator/components/com_x/x.xml), when the schema
 * version in #__schemas differs from the newest sql/updates file, or when an update
 * file describes a structure the tables do not have. A release must pass this check
 * right after a fresh installation and after an update, without `--fix`.
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/**
 * @return string[] Problems; empty when Joomla reports the component as up to date.
 */
function joomla_component_database_problems(mysqli $db, string $prefix, string $element): array
{
    $problems = [];
    $root = '/var/www/html/administrator/components/' . $element;
    $row = $db->query(
        "SELECT e.extension_id, e.manifest_cache, s.version_id FROM {$prefix}extensions AS e"
        . " LEFT JOIN {$prefix}schemas AS s ON s.extension_id = e.extension_id"
        . " WHERE e.type = 'component' AND e.element = '" . $db->real_escape_string($element) . "'"
    )->fetch_assoc();

    if (!$row) {
        return ["$element is not installed"];
    }

    // The manifest Joomla's check reads: InstallerHelper::getInstallationXML().
    $manifestPath = $root . '/' . substr($element, 4) . '.xml';
    $manifest = is_file($manifestPath) ? simplexml_load_file($manifestPath) : false;
    $cached = (string) (json_decode((string) $row['manifest_cache'], true)['version'] ?? '');

    if ($manifest === false || $manifest->getName() !== 'extension') {
        $problems[] = "the installation manifest $manifestPath is missing";
    } elseif ((string) $manifest->version === '' || (string) $manifest->version !== $cached) {
        $problems[] = "manifest version '" . (string) $manifest->version . "' differs from the registered version '$cached'";
    }

    if (is_file($root . '/' . $element . '.xml')) {
        $problems[] = "the legacy manifest $element.xml is still installed";
    }

    $updates = glob($root . '/sql/updates/mysql/*.sql') ?: [];
    $versions = array_map(static fn (string $file): string => basename($file, '.sql'), $updates);
    usort($versions, 'version_compare');
    $newest = end($versions) ?: '';

    if ($newest !== '' && (string) $row['version_id'] !== $newest) {
        $problems[] = "schema version '" . (string) $row['version_id'] . "' instead of '$newest'";
    }

    // Joomla's verdict itself, as an administrator or the CLI sees it.
    $output = [];
    exec('COLUMNS=1000 HTTP_HOST=localhost php /var/www/html/cli/joomla.php maintenance:database 2>&1', $output, $exitCode);
    $id = (int) $row['extension_id'];
    $inRow = false;
    $found = false;

    foreach ($output as $line) {
        if (preg_match('/^\s*(\d+)\s{2,}/', $line, $match)) {
            $inRow = (int) $match[1] === $id;
            $found = $found || $inRow;
        } elseif (preg_match('/^\s*-{3,}/', $line)) {
            $inRow = false;
        }

        if (!$inRow) {
            continue;
        }

        // The informational lines only count checked and skipped changes; every other
        // message of the problems column (schema version, update version, missing or
        // wrong table structure, also a column or index a DROP should have removed) is a problem.
        $text = trim(preg_replace('/\d+ database changes (were checked|did not alter table structure and were skipped)\./', '', $line));

        if (preg_match('/does not|doesn\'t|should not|wrong|mismatch|error|not up to date/i', $text)) {
            $problems[] = 'Joomla reports: ' . preg_replace('/\s{2,}/', ' ', $text);
        }
    }

    if ($exitCode !== 0 || !$found) {
        $problems[] = 'maintenance:database did not list the component: ' . implode(' | ', array_slice($output, -5));
    }

    return $problems;
}
