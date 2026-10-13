<?php
/**
 * Installer script for mod_dmarcreports.
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;

/**
 * Minimal install/update script: it enforces the platform requirements and
 * shows a short, translated confirmation. Joomla's native <updateservers>
 * handling creates and removes the update site, so no custom database work is
 * needed here.
 */
class Mod_dmarcreportsInstallerScript extends InstallerScript
{
    /**
     * Minimum Joomla version required to install this module.
     *
     * @var string
     */
    protected $minimumJoomla = '5.4';

    /**
     * Minimum PHP version required to install this module.
     *
     * @var string
     */
    protected $minimumPhp = '8.1';

    /**
     * Run the platform requirement checks before the files are copied.
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

    /**
     * Show a short confirmation (update) or a brief first-run guide (install).
     *
     * @param   string  $type    install|update|discover_install
     * @param   mixed   $parent  The installer parent.
     *
     * @return  bool
     */
    public function postflight($type, $parent)
    {
        if ($type !== 'install' && $type !== 'update') {
            return true;
        }

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('mod_dmarcreports', JPATH_ADMINISTRATOR);

        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('MOD_DMARCREPORTS_POSTINSTALL_UPDATED', htmlspecialchars($version, ENT_QUOTES, 'UTF-8')),
                'message'
            );

            return true;
        }

        $box = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

        $message  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
        $message .= '<h2 style="margin-bottom:16px">' . Text::_('MOD_DMARCREPORTS_POSTINSTALL_TITLE') . '</h2>';
        $message .= '<div style="' . $box . '">';
        $message .= '<p>' . Text::_('MOD_DMARCREPORTS_POSTINSTALL_DESC') . '</p>';
        $message .= '</div>';
        $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('MOD_DMARCREPORTS_POSTINSTALL_DOCS') . '</p>';
        $message .= '</div>';

        $app->enqueueMessage($message, 'message');

        return true;
    }
}
