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
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function postflight(string $type, object $parent): void
    {
        if ($type !== 'install' && $type !== 'update') {
            return;
        }

        $app  = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('plg_osmap_j2commerce', JPATH_ADMINISTRATOR);
        $lang->load('plg_osmap_j2commerce', $parent->getParent()->getPath('source'));

        $this->warnAboutMissingDependencies();
        $this->patchOsmapFactory();
        $this->disableLegacyPlugin();
        $this->ensureUpdateSite();

        $enabled = $this->isPluginEnabled();

        // Updates only get a short confirmation, plus a hint while the plugin is
        // disabled; the setup guide is shown on the first installation and lists
        // only the steps that are still open.
        if ($type === 'update') {
            $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
            $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

            $app->enqueueMessage(
                Text::sprintf('PLG_OSMAP_J2COMMERCE_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                'message'
            );

            if (!$enabled) {
                $app->enqueueMessage(Text::_('PLG_OSMAP_J2COMMERCE_ENABLE_HINT'), 'message');
            }

            return;
        }

        // Inline styles — Joomla 5 <joomla-alert> strips <style> tags
        $sBox    = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid';
        $sInfo   = $sBox . ';background:#eff6ff;border-color:#2563eb';
        $sWarn   = $sBox . ';background:#fef3c7;border-color:#d97706';
        $sStep   = 'color:#374151;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px';

        $message  = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
        $message .= '<h2 style="margin-bottom:16px">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_TITLE') . '</h2>';

        // Steps are numbered in the order shown; enabling the plugin is left out
        // when it is already enabled.
        $step = 0;

        // Enable plugin
        if (!$enabled) {
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP1_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP1_DESC') . '</p>';
            $message .= '</div>';
        }

        // Step 2 — Configure OSMap
        $message .= '<div style="' . $sInfo . '">';
        $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP2_TITLE') . '</h3>';
        $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP2_DESC') . '</p>';
        $message .= '</div>';

        // Step 3 — Regenerate sitemap
        $message .= '<div style="' . $sInfo . '">';
        $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP3_TITLE') . '</h3>';
        $message .= '<p>' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_STEP3_DESC') . '</p>';
        $message .= '</div>';

        // Checklist
        $message .= '<div style="' . $sWarn . '">';
        $message .= '<h3 style="margin-top:0">' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECKLIST_TITLE') . '</h3>';
        $message .= '<ul style="list-style:none;padding-left:0;line-height:1.8">';
        if (!$enabled) {
            $message .= '<li>&#9744; ' . Text::_('PLG_OSMAP_J2COMMERCE_POSTINSTALL_CHECK_ENABLED') . '</li>';
        }
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
     * Whether this plugin is enabled in #__extensions (Joomla installs plugins
     * disabled; an update keeps the current state).
     */
    private function isPluginEnabled(): bool
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select($db->quoteName('enabled'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('osmap'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('j2commerce'));

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns a fresh query object compatible with Joomla 5 and 6.
     */
    private function dbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Warns when OSMap or J2Store/J2Commerce is missing or disabled. The plugin still
     * installs, but it has nothing to do until both are present.
     */
    private function warnAboutMissingDependencies(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select([$db->quoteName('element'), $db->quoteName('enabled')])
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->whereIn(
                    $db->quoteName('element'),
                    ['com_osmap', 'com_j2store', 'com_j2commerce'],
                    ParameterType::STRING
                );
            $rows = $db->setQuery($query)->loadObjectList('element');
        } catch (\Throwable $e) {
            return;
        }

        $app = Factory::getApplication();

        if (empty($rows['com_osmap'])) {
            $app->enqueueMessage(Text::_('PLG_OSMAP_J2COMMERCE_WARN_OSMAP_MISSING'), 'warning');
        } elseif ((int) $rows['com_osmap']->enabled !== 1) {
            $app->enqueueMessage(Text::_('PLG_OSMAP_J2COMMERCE_WARN_OSMAP_DISABLED'), 'warning');
        }

        $shopEnabled = false;

        foreach (['com_j2store', 'com_j2commerce'] as $element) {
            if (!empty($rows[$element]) && (int) $rows[$element]->enabled === 1) {
                $shopEnabled = true;
            }
        }

        if (!$shopEnabled) {
            $app->enqueueMessage(Text::_('PLG_OSMAP_J2COMMERCE_WARN_J2COMMERCE_MISSING'), 'warning');
        }
    }

    /**
     * Disables the legacy plg_osmap_j2store plugin if still present and enabled.
     *
     * The legacy plugin (element=j2store, folder=osmap) generates incorrect
     * sitemap URLs (index.php?option=com_content&view=article&id=...) for
     * J2Commerce products. This plugin supersedes it and must be the only
     * active osmap plugin handling com_j2store.
     */
    private function disableLegacyPlugin(): void
    {
        $app = Factory::getApplication();

        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);

            $query = $this->dbQuery($db)
                ->select($db->quoteName('extension_id'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote('osmap'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('j2store'))
                ->where($db->quoteName('enabled') . ' = 1');
            $legacyId = (int) $db->setQuery($query)->loadResult();

            if ($legacyId === 0) {
                return;
            }

            $query = $this->dbQuery($db)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 0')
                ->where($db->quoteName('extension_id') . ' = :id')
                ->bind(':id', $legacyId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $app->enqueueMessage(Text::_('PLG_OSMAP_J2COMMERCE_INFO_LEGACY_DISABLED'), 'message');
        } catch (\Throwable $e) {
            $app->enqueueMessage(
                Text::sprintf('PLG_OSMAP_J2COMMERCE_WARN_LEGACY_DISABLE_FAILED', htmlspecialchars($e->getMessage())),
                'warning'
            );
        }
    }

    /**
     * Patches OSMap Factory.php for Joomla 5 / PHP 8.1+ compatibility.
     *
     * OSMap ≤ 5.1.3: Factory::getTable() returns false instead of null,
     * causing a TypeError (return type declared as ?Table).
     * Fix: append ?: null so false is coerced to null.
     * Idempotent — OSMap ≥ 5.1.4 already contains the fix.
     */
    private function patchOsmapFactory(): void
    {
        $file = JPATH_ADMINISTRATOR
            . '/components/com_osmap/library/Alledia/OSMap/Factory.php';

        if (!is_file($file)) {
            return;
        }

        $relative = 'administrator/components/com_osmap/library/Alledia/OSMap/Factory.php';
        $app      = Factory::getApplication();
        $content  = @file_get_contents($file);

        if ($content === false) {
            $app->enqueueMessage(Text::sprintf('PLG_OSMAP_J2COMMERCE_WARN_OSMAP_PATCH_FAILED', $relative), 'warning');

            return;
        }

        $buggy = 'return Table::getInstance($tableName, $prefix);';
        $fixed = 'return Table::getInstance($tableName, $prefix) ?: null;';

        if (str_contains($content, $fixed)) {
            return;
        }

        if (!str_contains($content, $buggy)) {
            return;
        }

        if (@file_put_contents($file, str_replace($buggy, $fixed, $content)) === false) {
            $app->enqueueMessage(Text::sprintf('PLG_OSMAP_J2COMMERCE_WARN_OSMAP_PATCH_FAILED', $relative), 'warning');

            return;
        }

        $app->enqueueMessage(Text::sprintf('PLG_OSMAP_J2COMMERCE_INFO_OSMAP_PATCHED', $relative), 'message');
    }

    /**
     * Remove update sites of this plugin that still point to the repository's
     * former organisation name. Joomla would otherwise keep querying both the
     * old and the new update URL.
     */
    private function removeLegacyUpdateSites(DatabaseInterface $db, int $extensionId): void
    {
        $legacyPattern = '%/advansit/Joomla/%';

        $query = $this->dbQuery($db)
            ->select($db->quoteName('s.update_site_id'))
            ->from($db->quoteName('#__update_sites', 's'))
            ->join(
                'INNER',
                $db->quoteName('#__update_sites_extensions', 'map'),
                $db->quoteName('map.update_site_id') . ' = ' . $db->quoteName('s.update_site_id')
            )
            ->where($db->quoteName('map.extension_id') . ' = :extId')
            ->where($db->quoteName('s.location') . ' LIKE :legacy')
            ->bind(':extId', $extensionId, ParameterType::INTEGER)
            ->bind(':legacy', $legacyPattern);
        $siteIds = array_map('intval', $db->setQuery($query)->loadColumn() ?: []);

        foreach ($siteIds as $siteId) {
            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query)->execute();

            $query = $this->dbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER);

            if ((int) $db->setQuery($query)->loadResult() > 0) {
                continue;
            }

            foreach (['#__updates', '#__update_sites'] as $table) {
                $query = $this->dbQuery($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER);
                $db->setQuery($query)->execute();
            }
        }
    }

    /**
     * Register the update site if not already present.
     */
    private function ensureUpdateSite(): void
    {
        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_osmap_j2commerce/updates/update.xml';
        $element   = 'j2commerce';
        $folder    = 'osmap';

        $query = $this->dbQuery($db)
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

        $this->removeLegacyUpdateSites($db, $extensionId);

        $query = $this->dbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $siteId = (int) $db->setQuery($query)->loadResult();

        if ($siteId) {
            $query = $this->dbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);

            if (!(int) $db->setQuery($query)->loadResult()) {
                $query = $this->dbQuery($db)
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
        $query = $this->dbQuery($db)
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

        $query = $this->dbQuery($db)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }
}
