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

/**
 * Installer script for the DMARC Reports package.
 *
 * The package bundles the component, the dashboard module, the ingestion task
 * plugin and the MTA-STS system plugin so the administrator installs, updates
 * and removes the whole DMARC solution in one step. Each bundled extension
 * ships its own installer script that handles its own tables and enablement;
 * this script enforces the shared minimum platform requirements and prints a
 * short confirmation from the package language file.
 */
class Pkg_DmarcreportsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

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
