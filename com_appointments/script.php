<?php
/**
 * Installer script for com_appointments.
 *
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

class Com_appointmentsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    /**
     * Hard requirement checks before the files are copied.
     *
     * @param   string  $type    install|update|discover_install
     * @param   mixed   $parent  The installer parent.
     *
     * @return  bool
     */
    public function preflight($type, $parent)
    {
        if (!parent::preflight($type, $parent)) {
            return false;
        }

        // The component ships its schema for the MySQL/MariaDB driver only, and
        // the booking table relies on a generated column to guarantee that one
        // slot can be held by one booking. On any other database server the
        // tables cannot be created and every query would fail, so refuse the
        // install with a clear message instead of leaving a half-installed
        // component behind. An update keeps whatever driver the existing tables
        // were created on.
        if ($type === 'install' || $type === 'discover_install') {
            $db         = Factory::getContainer()->get(DatabaseInterface::class);
            $serverType = method_exists($db, 'getServerType') ? $db->getServerType() : 'mysql';

            if ($serverType !== 'mysql') {
                Factory::getApplication()->enqueueMessage(
                    Text::sprintf('COM_APPOINTMENTS_INSTALL_UNSUPPORTED_DB', $serverType),
                    'error'
                );

                return false;
            }
        }

        return true;
    }

    public function postflight($type, $parent)
    {
        if ($type !== 'install' && $type !== 'update') {
            return true;
        }

        $this->ensureUpdateSite();

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('com_appointments', JPATH_ADMINISTRATOR);

        // An update only gets a short confirmation; the first installation shows
        // where to find the component and how to begin.
        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('COM_APPOINTMENTS_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                'message'
            );

            return true;
        }

        $box = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

        $message  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
        $message .= '<h2 style="margin-bottom:16px">' . Text::_('COM_APPOINTMENTS_POSTINSTALL_TITLE') . '</h2>';
        $message .= '<div style="' . $box . '">';
        $message .= '<p>' . Text::_('COM_APPOINTMENTS_POSTINSTALL_DESC') . '</p>';
        $message .= '</div>';
        $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('COM_APPOINTMENTS_POSTINSTALL_DOCS') . '</p>';
        $message .= '</div>';

        $app->enqueueMessage($message, 'message');

        return true;
    }

    /**
     * Register the update site of this component, so Joomla offers its updates
     * without the administrator adding a URL by hand.
     */
    private function ensureUpdateSite(): void
    {
        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/com_appointments/updates/update.xml';
        $element   = 'com_appointments';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if (!$extensionId) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            $this->linkUpdateSite($db, $siteId, $extensionId);

            return;
        }

        $name  = 'Appointments Updates';
        $sType = 'extension';
        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([
                $db->quoteName('name'),
                $db->quoteName('type'),
                $db->quoteName('location'),
                $db->quoteName('enabled'),
            ])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)
            ->bind(':type', $sType)
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();

        $this->linkUpdateSite($db, (int) $db->insertid(), $extensionId);
    }

    /**
     * Connect an update site with the component's extension row once.
     */
    private function linkUpdateSite(DatabaseInterface $db, int $siteId, int $extensionId): void
    {
        if ($siteId <= 0) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__update_sites_extensions'))
            ->where($db->quoteName('update_site_id') . ' = :siteId')
            ->where($db->quoteName('extension_id') . ' = :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);

        if ((int) $db->loadResult() > 0) {
            return;
        }

        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
