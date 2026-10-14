<?php
/**
 * @package     DMARC Reports Package
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;

/**
 * Installer script for the DMARC Reports package.
 *
 * The package bundles the component, the dashboard module, the ingestion task
 * plugin and the MTA-STS system plugin so the administrator installs, updates
 * and removes the whole DMARC solution in one step. Each bundled extension
 * ships its own installer script that handles its own tables and enablement;
 * this script enforces the shared minimum platform requirements, moves the
 * bundled extensions onto the package's single update channel and prints a
 * short confirmation from the package language file.
 */
class Pkg_DmarcreportsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    /**
     * The four bundled extensions, identified as Joomla stores them in
     * #__extensions (type, element, folder, client_id).
     *
     * @var array<int, array{0:string, 1:string, 2:string, 3:int}>
     */
    private const CHILDREN = [
        ['component', 'com_dmarcreports', '', 1],
        ['module', 'mod_dmarcreports', '', 1],
        ['plugin', 'dmarcreports', 'task', 0],
        ['plugin', 'dmarcmtasts', 'system', 0],
    ];

    /**
     * Enforce the declared minimum Joomla and PHP versions before any files are
     * copied. InstallerScript::preflight() compares them against the running
     * platform and aborts with the translated core message when it is too old,
     * so the package (and the extensions it bundles) is never installed below
     * its supported baseline.
     *
     * @param   string  $type    install|update|discover_install
     * @param   mixed   $parent  The installer parent.
     *
     * @return  bool
     */
    public function preflight($type, $parent)
    {
        return parent::preflight($type, $parent);
    }

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('pkg_dmarcreports', JPATH_ADMINISTRATOR);
        $lang->load('pkg_dmarcreports', $parent->getParent()->getPath('source'));

        $this->installLicense($parent);
        $this->removeChildUpdateSites();

        // An update only confirms the new version; the first installation shows
        // the short orientation message that points at the next steps.
        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('PKG_DMARCREPORTS_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                'message'
            );

            return;
        }

        $app->enqueueMessage(Text::_('PKG_DMARCREPORTS_POSTINSTALL_INTRO'), 'message');
    }

    /**
     * Move the bundled extensions onto the package's single update channel.
     *
     * The build strips <updateservers> from every bundled child manifest, but
     * that only prevents registration on a fresh install: Joomla's update-site
     * handling adds sites from a manifest and never removes an existing link
     * when the new manifest has none. A child installed standalone before the
     * package would therefore keep its own update stream and could be updated
     * out of band, drifting from the package. This removes every update-site
     * link of the four children, the update sites left without any link and
     * the pending update rows of those streams. The package's own update site
     * is linked to the package, not to a child, so it is never touched.
     *
     * A failure here must not fail the installation; the stale link only
     * affects update notifications and is retried on the next package update.
     */
    private function removeChildUpdateSites(): void
    {
        try {
            $db  = Factory::getContainer()->get(DatabaseInterface::class);
            $ids = [];

            foreach (self::CHILDREN as [$type, $element, $folder, $clientId]) {
                $query = $this->newQuery($db)
                    ->select($db->quoteName('extension_id'))
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = :type')
                    ->where($db->quoteName('element') . ' = :element')
                    ->where($db->quoteName('folder') . ' = :folder')
                    ->where($db->quoteName('client_id') . ' = :client')
                    ->bind(':type', $type)
                    ->bind(':element', $element)
                    ->bind(':folder', $folder)
                    ->bind(':client', $clientId, ParameterType::INTEGER);

                foreach ((array) $db->setQuery($query)->loadColumn() as $id) {
                    $ids[] = (int) $id;
                }
            }

            $ids = array_values(array_unique(array_filter($ids)));

            if ($ids === []) {
                return;
            }

            $query = $this->newQuery($db)
                ->select('DISTINCT ' . $db->quoteName('update_site_id'))
                ->from($db->quoteName('#__update_sites_extensions'))
                ->whereIn($db->quoteName('extension_id'), $ids);
            $siteIds = array_map('intval', (array) $db->setQuery($query)->loadColumn());

            $db->setQuery(
                $this->newQuery($db)
                    ->delete($db->quoteName('#__update_sites_extensions'))
                    ->whereIn($db->quoteName('extension_id'), $ids)
            )->execute();

            $db->setQuery(
                $this->newQuery($db)
                    ->delete($db->quoteName('#__updates'))
                    ->whereIn($db->quoteName('extension_id'), $ids)
            )->execute();

            foreach ($siteIds as $siteId) {
                $query = $this->newQuery($db)
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
                        $this->newQuery($db)
                            ->delete($db->quoteName($table))
                            ->where($db->quoteName('update_site_id') . ' = :site')
                            ->bind(':site', $siteId, ParameterType::INTEGER)
                    )->execute();
                }
            }
        } catch (\Throwable $e) {
            // Not fatal: see the method description.
        }
    }

    private function newQuery(DatabaseInterface $db): QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Install the package's GPL-3.0 text next to its stored manifest.
     *
     * The package installer copies only the manifest and this script into
     * administrator/manifests/packages/dmarcreports/; it never installs a plain
     * file from the <files> list (that node only references the bundled
     * sub-extension archives). Copying LICENSE.txt here is the package-aware way
     * to guarantee the installed package carries the unmodified licence the
     * manifest and every source header point at. It is removed automatically on
     * uninstall when the package folder is deleted.
     */
    private function installLicense(object $parent): void
    {
        $installer = $parent->getParent();
        $source    = (string) $installer->getPath('source');
        $root      = (string) $installer->getPath('extension_root');

        if ($source === '' || $root === '') {
            return;
        }

        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        $license = $source . '/LICENSE.txt';

        if (is_file($license) && is_dir($root)) {
            copy($license, $root . '/LICENSE.txt');
        }
    }
}
