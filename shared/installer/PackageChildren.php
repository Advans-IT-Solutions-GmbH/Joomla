<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V3;

defined('_JEXEC') or die;

use Joomla\Database\ParameterType;

/**
 * The extensions a package bundles, as Joomla stores them in #__extensions, and what a package
 * script does with them around Joomla's own installation of each one.
 *
 * Joomla installs every <file> of a package with its own adapter: an extension that is already in
 * place (installed standalone before the package existed, or by an interrupted earlier attempt) is
 * updated in place. Its row, and with it its ID, parameters and enabled state, stays the same, so no
 * duplicate arises. What Joomla does not do is the subject of this class: it sets the package ID
 * only for children whose ID it collected during this run, it never removes the update sites a
 * standalone extension registered, and it does not know which children an operator expects enabled
 * after the first installation.
 *
 * A child is described by [type, element, folder, client_id]: a plugin by its group in folder and
 * client 0, a component or administrator module by client 1. $db is Joomla's DatabaseInterface.
 */
final class PackageChildren
{
    /** @param array{0: string, 1: string, 2: string, 3: int} $child */
    public static function key(array $child): string
    {
        return $child[0] . ':' . $child[1] . ':' . $child[2] . ':' . $child[3];
    }

    /**
     * The installed row of every child, null for a child that is not installed.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $children
     *
     * @return array<string, array{id: int, enabled: int, version: string, package_id: int}|null>
     */
    public static function snapshot(object $db, array $children): array
    {
        $rows = [];

        foreach ($children as $child) {
            [$type, $element, $folder, $client] = $child;
            $query = self::query($db)
                ->select($db->quoteName(['extension_id', 'enabled', 'manifest_cache', 'package_id']))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = :type')
                ->where($db->quoteName('element') . ' = :element')
                ->where($db->quoteName('folder') . ' = :folder')
                ->where($db->quoteName('client_id') . ' = :client')
                ->bind(':type', $type)
                ->bind(':element', $element)
                ->bind(':folder', $folder)
                ->bind(':client', $client, ParameterType::INTEGER);
            $row = $db->setQuery($query)->loadAssoc();
            $cache = is_array($row) ? json_decode((string) $row['manifest_cache'], true) : null;
            $rows[self::key($child)] = is_array($row) ? [
                'id'         => (int) $row['extension_id'],
                'enabled'    => (int) $row['enabled'],
                'version'    => is_array($cache) ? (string) ($cache['version'] ?? '') : '',
                'package_id' => (int) $row['package_id'],
            ] : null;
        }

        return $rows;
    }

    /** Whether any child is already installed. */
    public static function anyInstalled(array $snapshot): bool
    {
        return array_filter($snapshot) !== [];
    }

    /**
     * Children whose installed version is newer than the version the package brings: Joomla would
     * install the older files over them and their schema could not go back.
     *
     * @param  array<string, string>  $bundled  Child key => version in the bundled manifest.
     *
     * @return list<array{key: string, installed: string, bundled: string}>
     */
    public static function newer(array $snapshot, array $bundled): array
    {
        $newer = [];

        foreach ($bundled as $key => $version) {
            $installed = $snapshot[$key]['version'] ?? '';

            if ($installed !== '' && $version !== '' && version_compare($installed, $version, '>')) {
                $newer[] = ['key' => $key, 'installed' => $installed, 'bundled' => $version];
            }
        }

        return $newer;
    }

    /**
     * Assign every installed child to the package (#__extensions.package_id). Joomla does this for the
     * children it installed in this run; doing it for all of them also covers a child that an
     * interrupted earlier attempt left without the package, and is idempotent.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $children
     */
    public static function adopt(object $db, string $packageElement, array $children): void
    {
        $type = 'package';
        $query = self::query($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $type)
            ->bind(':element', $packageElement);
        $packageId = (int) $db->setQuery($query)->loadResult();

        if ($packageId < 1) {
            throw new \RuntimeException('The package is not registered.');
        }

        $ids = self::ids(self::snapshot($db, $children));

        if ($ids === []) {
            return;
        }

        $db->setQuery(
            self::query($db)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('package_id') . ' = :package')
                ->whereIn($db->quoteName('extension_id'), $ids)
                ->bind(':package', $packageId, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Enable an installed child (used for children that were not installed before this run and are
     * meant to run right away).
     *
     * @param  array{0: string, 1: string, 2: string, 3: int}  $child
     */
    public static function enable(object $db, array $child): void
    {
        $id = (int) (self::snapshot($db, [$child])[self::key($child)]['id'] ?? 0);

        if ($id < 1) {
            return;
        }

        $db->setQuery(
            self::query($db)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('extension_id') . ' = :id')
                ->bind(':id', $id, ParameterType::INTEGER)
        )->execute();
    }

    /**
     * Move the children onto the package's single update channel: remove every update-site link
     * of a child, the update sites left without any link and the pending update rows of those
     * streams. Joomla adds update sites from a manifest but never removes an existing link, so a
     * standalone extension would keep its own stream and could be updated out of band. The
     * package's own update site is linked to the package, not to a child, and stays.
     *
     * Runs in one transaction: a failure leaves every link in place for the next package update
     * and is thrown to the caller (which reports it without failing the installation).
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: int}>  $children
     */
    public static function removeUpdateSites(object $db, array $children): void
    {
        $ids = self::ids(self::snapshot($db, $children));

        if ($ids === []) {
            return;
        }

        $db->transactionStart();

        try {
            $query = self::query($db)
                ->select('DISTINCT ' . $db->quoteName('update_site_id'))
                ->from($db->quoteName('#__update_sites_extensions'))
                ->whereIn($db->quoteName('extension_id'), $ids);
            $siteIds = array_map('intval', (array) $db->setQuery($query)->loadColumn());

            foreach (['#__update_sites_extensions', '#__updates'] as $table) {
                $db->setQuery(
                    self::query($db)
                        ->delete($db->quoteName($table))
                        ->whereIn($db->quoteName('extension_id'), $ids)
                )->execute();
            }

            foreach ($siteIds as $siteId) {
                $query = self::query($db)
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__update_sites_extensions'))
                    ->where($db->quoteName('update_site_id') . ' = :site')
                    ->bind(':site', $siteId, ParameterType::INTEGER);

                if ((int) $db->setQuery($query)->loadResult() > 0) {
                    // Still linked to another extension; not ours to remove.
                    continue;
                }

                foreach (['#__updates', '#__update_sites'] as $table) {
                    $db->setQuery(
                        self::query($db)
                            ->delete($db->quoteName($table))
                            ->where($db->quoteName('update_site_id') . ' = :site')
                            ->bind(':site', $siteId, ParameterType::INTEGER)
                    )->execute();
                }
            }

            $db->transactionCommit();
        } catch (\Throwable $error) {
            $db->transactionRollback();

            throw $error;
        }
    }

    /**
     * Extract every child archive of a package once and read its manifest.
     *
     * @return array<string, array{manifest: \SimpleXMLElement, file: string, directory: string}> Child key => extracted child.
     */
    public static function extract(\SimpleXMLElement $packageManifest, string $source): array
    {
        $children = [];
        $folder = trim(str_replace('\\', '/', (string) $packageManifest->files['folder']), '/');

        foreach ($packageManifest->files->children() as $entry) {
            if ($entry->getName() !== 'file') {
                continue;
            }

            $archive = basename(str_replace('\\', '/', (string) $entry));

            if (preg_match('/^[A-Za-z0-9_.-]+\.zip$/D', $archive) !== 1 || str_contains($folder, '..')) {
                throw new \RuntimeException('Invalid bundled archive name.');
            }

            $directory = $source . '/advans-children/' . substr($archive, 0, -4);

            if (!is_dir($directory) && !(new \Joomla\Archive\Archive())->extract($source . '/' . ($folder === '' ? '' : $folder . '/') . $archive, $directory)) {
                throw new \RuntimeException('Cannot inspect a bundled package archive.');
            }

            $manifest = null;
            $file = '';

            foreach (glob($directory . '/*.xml') ?: [] as $candidate) {
                $xml = simplexml_load_file($candidate);

                if ($xml instanceof \SimpleXMLElement && $xml->getName() === 'extension') {
                    $manifest = $xml;
                    $file = $candidate;
                    break;
                }
            }

            if (!$manifest instanceof \SimpleXMLElement) {
                throw new \RuntimeException('Bundled package manifest is missing.');
            }

            $type = (string) $entry['type'];
            $client = $type === 'plugin' ? 0 : ((string) $entry['client'] === 'site' ? 0 : 1);
            $key = self::key([$type, (string) $entry['id'], $type === 'plugin' ? (string) $entry['group'] : '', $client]);
            $children[$key] = ['manifest' => $manifest, 'file' => $file, 'directory' => $directory];
        }

        return $children;
    }

    /** @return list<int> */
    private static function ids(array $snapshot): array
    {
        return array_values(array_filter(array_map(static fn(?array $row): int => (int) ($row['id'] ?? 0), $snapshot)));
    }

    private static function query(object $db): object
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
