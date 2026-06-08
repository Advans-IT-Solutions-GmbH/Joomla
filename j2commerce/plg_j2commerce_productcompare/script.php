<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class PlgJ2commerceProductcompareInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '4.0';
    protected $minimumPhp = '7.4';

    public function preflight($type, $parent)
    {
        return parent::preflight($type, $parent);
    }

    public function postflight($type, $parent)
    {
        if ($type === 'install' || $type === 'update') {
            $this->setGroupForInstalledStack();
            $this->ensureUpdateSite();

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('plg_j2commerce_productcompare', JPATH_ADMINISTRATOR);
            $lang->load('plg_j2commerce_productcompare', $parent->getParent()->getPath('source'));

            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid;background:#eff6ff;border-color:#2563eb';

            $message = '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_TITLE') . '</h2>';
            $message .= '<div style="' . $sBox . '">';
            $message .= '<p>' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_ENABLE') . '</p>';
            $message .= '<p>' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_SEARCH') . '</p>';
            $message .= '</div>';
            $message .= '<p style="margin-top:12px;color:#6b7280;font-size:13px">' . Text::_('PLG_J2COMMERCE_PRODUCTCOMPARE_POSTINSTALL_DOCS') . '</p>';
            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    public function uninstall($parent)
    {
        // Remove the J5 mirror directory/symlink if it exists.
        $j5mirror = JPATH_PLUGINS . '/j2store/productcompare';
        if (is_link($j5mirror)) {
            unlink($j5mirror);
        } elseif (is_dir($j5mirror)) {
            $this->removeDir($j5mirror);
        }
    }

    /**
     * Mirror plugin files to plugins/j2store/productcompare/ on J4/J5 installs.
     *
     * Joomla installs plugin files to plugins/{manifest-group}/ — always j2commerce
     * here. J2Store 4's eventWithHtml() only imports the j2store group, so the
     * plugin must also be reachable under plugins/j2store/productcompare/.
     *
     * Strategy: keep the canonical files under j2commerce/, create a symlink (or
     * recursive copy as fallback) at j2store/productcompare/ pointing there, and
     * update #__extensions.folder to j2store so Joomla's plugin loader finds it.
     *
     * On J6 (no com_j2store): nothing to do, folder stays j2commerce.
     */
    private function setGroupForInstalledStack(): void
    {
        // Detect Joomla major version. Joomla 6+ ships with J2Commerce 6 which
        // imports the j2commerce plugin group. Joomla 4/5 uses J2Store 4 which
        // imports the j2store group. Using the Joomla version is more reliable
        // than checking for com_j2commerce in #__extensions, because J2Commerce
        // may not yet be installed when postflight() runs.
        $isJ6 = (int) \Joomla\CMS\Version::MAJOR_VERSION >= 6;

        if ($isJ6) {
            // J6: canonical location j2commerce/ is correct, nothing to do.
            return;
        }

        // J4/J5: mirror files to plugins/j2store/productcompare/ and update DB.
        $src  = JPATH_PLUGINS . '/j2commerce/productcompare';
        $dest = JPATH_PLUGINS . '/j2store/productcompare';

        if (!is_dir($src)) {
            return;
        }

        // Remove stale destination if it exists (e.g. from a previous install).
        if (is_link($dest)) {
            unlink($dest);
        } elseif (is_dir($dest)) {
            $this->removeDir($dest);
        }

        // Prefer symlink (atomic, no duplication); fall back to recursive copy.
        if (!@symlink($src, $dest)) {
            $this->copyDir($src, $dest);
        }

        // Update #__extensions so Joomla's plugin loader resolves the correct path.
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $q  = $this->createDbQuery($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('folder') . ' = ' . $db->quote('j2store'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('productcompare'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($q);
        $db->execute();
    }

    /**
     * Recursively copy a directory tree.
     */
    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $item) {
            $target = $dest . '/' . $iter->getSubPathname();
            $item->isDir() ? mkdir($target, 0755, true) : copy($item->getRealPath(), $target);
        }
    }

    /**
     * Recursively remove a directory tree.
     */
    private function removeDir(string $dir): void
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }
        rmdir($dir);
    }

    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/advansit/Joomla/main/j2commerce/plg_j2commerce_productcompare/updates/update.xml';
        $element = 'productcompare';

        // Resolve the actual installed folder (set by setGroupForInstalledStack)
        $q = $this->createDbQuery($db)
            ->select($db->quoteName('folder'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote($element))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'));
        $db->setQuery($q);
        $folder = $db->loadResult() ?: 'j2commerce';

        $query = $this->createDbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = :element')
            ->where($db->quoteName('folder') . ' = :folder')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->bind(':element', $element)
            ->bind(':folder', $folder);
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
            $query = $this->createDbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            if (!(int) $db->loadResult()) {
                $query = $this->createDbQuery($db)
                    ->insert($db->quoteName('#__update_sites_extensions'))
                    ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
                    ->values(':siteId, :extId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER)
                    ->bind(':extId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
            return;
        }

        $name = 'J2Store Product Compare';
        $type = 'extension';
        $query = $this->createDbQuery($db)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([$db->quoteName('name'), $db->quoteName('type'), $db->quoteName('location'), $db->quoteName('enabled')])
            ->values(':name, :type, :url, 1')
            ->bind(':name', $name)->bind(':type', $type)->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();
        $siteId = (int) $db->insertid();

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
    private function createDbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
