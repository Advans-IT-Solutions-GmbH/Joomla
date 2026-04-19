<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class PlgosmapJ2commerceInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.0';
    protected $minimumPhp = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $this->ensureUpdateSite();

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('plg_osmap_j2commerce', JPATH_ADMINISTRATOR);
        $lang->load('plg_osmap_j2commerce', $parent->getParent()->getPath('source'));

        // Inline styles — Joomla 5 <joomla-alert> strips <style> tags
        $sBox    = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid';
        $sInfo   = $sBox . ';background:#eff6ff;border-color:#2563eb';
        $sWarn   = $sBox . ';background:#fef3c7;border-color:#d97706';
        $sStep   = 'color:#374151;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px';

        $message  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
        $message .= '<h2 style="margin-bottom:16px">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_TITLE') . '</h2>';

        // Step 1 — Enable plugin
        $message .= '<div style="' . $sInfo . '">';
        $message .= '<div style="' . $sStep . '">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP1_LABEL') . '</div>';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP1_TITLE') . '</h3>';
        $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP1_DESC') . '</p>';
        $message .= '</div>';

        // Step 2 — Configure OSMap
        $message .= '<div style="' . $sInfo . '">';
        $message .= '<div style="' . $sStep . '">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP2_LABEL') . '</div>';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP2_TITLE') . '</h3>';
        $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP2_DESC') . '</p>';
        $message .= '</div>';

        // Step 3 — Regenerate sitemap
        $message .= '<div style="' . $sInfo . '">';
        $message .= '<div style="' . $sStep . '">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP3_LABEL') . '</div>';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP3_TITLE') . '</h3>';
        $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP3_DESC') . '</p>';
        $message .= '</div>';

        // Checklist
        $message .= '<div style="' . $sWarn . '">';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECKLIST_TITLE') . '</h3>';
        $message .= '<ul style="list-style:none;padding-left:0;line-height:1.8">';
        $message .= '<li>&#9744; ' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECK_ENABLED') . '</li>';
        $message .= '<li>&#9744; ' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECK_MENU') . '</li>';
        $message .= '<li>&#9744; ' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECK_SITEMAP') . '</li>';
        $message .= '</ul>';
        $message .= '</div>';

        // Support
        $message .= '<p style="margin-top:20px;color:#6b7280;font-size:13px">';
        $message .= Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_DOCS') . ' &middot; ';
        $message .= Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_SUPPORT');
        $message .= '</p>';

        $message .= '</div>';

        $app->enqueueMessage($message, 'message');
    }

    /**
     * Register the update site if not already present.
     */
    private function ensureUpdateSite(): void
    {
        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/advansit/Joomla/main/j2commerce/plg_osmap_j2commerce/updates/update.xml';
        $element   = 'j2commerce';
        $folder    = 'osmap';

        $query = $db->getQuery(true)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->bind(':element', $element)
            ->bind(':folder', $folder);
        $extensionId = (int) $db->setQuery($query)->loadResult();

        if (!$extensionId) {
            return;
        }

        $query = $db->getQuery(true)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $siteId = (int) $db->setQuery($query)->loadResult();

        if ($siteId) {
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);

            if (!(int) $db->setQuery($query)->loadResult()) {
                $query = $db->getQuery(true)
                    ->insert($db->quoteName('#__update_sites_extensions'))
                    ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
                    ->values(':siteId, :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)
                    ->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }
            return;
        }

        $name = 'OSMap J2Commerce Plugin';
        $type = 'extension';
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([
                $db->quoteName('name'),
                $db->quoteName('type'),
                $db->quoteName('location'),
                $db->quoteName('enabled'),
            ])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)
            ->bind(':type', $type)
            ->bind(':url', $updateUrl);
        $db->setQuery($query)->execute();
        $siteId = (int) $db->insertid();

        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }
}
