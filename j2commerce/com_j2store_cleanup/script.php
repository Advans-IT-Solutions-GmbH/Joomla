<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class Com_j2store_cleanupInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '4.0';
    protected $minimumPhp = '7.4';

    public function postflight($type, $parent)
    {
        if ($type === 'install' || $type === 'update') {
            $this->ensureUpdateSite();

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('com_j2store_cleanup', JPATH_ADMINISTRATOR);

            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

            $message = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_TITLE') . '</h2>';
            $message .= '<div style="' . $sBox . '">';
            $message .= '<p>' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_DESC') . '</p>';
            $message .= '</div>';
            $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('COM_J2STORE_CLEANUP_POSTINSTALL_DOCS') . '</p>';
            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/advansit/Joomla/main/j2commerce/com_j2store_cleanup/updates/update.xml';
        $element = 'com_j2store_cleanup';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
            ->bind(':element', $element);
        $db->setQuery($query);
        $extensionId = (int) $db->loadResult();

        if (!$extensionId) { return; }

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            $query = $this->createDbQuery($db)->select('COUNT(*)')->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            if (!(int) $db->loadResult()) {
                $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites_extensions'))
                    ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
                    ->values(':siteId, :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
            return;
        }

        $name = 'J2Store Cleanup';
        $sType = 'extension';
        $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites'))
            ->columns([$db->quoteName('name'), $db->quoteName('type'), $db->quoteName('location'), $db->quoteName('enabled')])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)->bind(':type', $sType)->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();
        $siteId = (int) $db->insertid();

        $query = $this->createDbQuery($db)->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Creates a query object compatible with Joomla 5 (getQuery) and 6 (createQuery).
     */
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
