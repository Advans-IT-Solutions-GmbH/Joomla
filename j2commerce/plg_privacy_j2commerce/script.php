<?php
/**
 * @package     J2Commerce Privacy System Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class Plgprivacyj2commerceInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.0';
    protected $minimumPhp = '8.1';

    /** @var string[] Files copied to template overrides on first install */
    private array $_overridesCopied = [];

    /** @var string[] Files skipped because they already existed */
    private array $_overridesSkipped = [];

    /**
     * Returns a fresh query object compatible with Joomla 5 and 6.
     * Joomla 6 introduced DatabaseInterface::createQuery(); Joomla 5 uses getQuery(true).
     */
    private function dbQuery(\Joomla\Database\DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    public function postflight($type, $parent)
    {
        if ($type === 'install' || $type === 'update') {
            // Remove old manifest filename (renamed to j2commerce.xml in 1.2.8)
            $oldManifest = JPATH_PLUGINS . '/privacy/j2commerce/plg_privacy_j2commerce.xml';
            if (file_exists($oldManifest)) {
                @unlink($oldManifest);
            }

            $this->ensureUpdateSite();

            // Deploy template overrides on first install only (never overwrite)
            if ($type === 'install') {
                $this->copyTemplateOverrides($parent);
            }

            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
            $lang->load('plg_privacy_j2commerce', $parent->getParent()->getPath('source'));

            // Inline styles — Joomla 5 <joomla-alert> strips <style> tags
            $sBox = 'padding:16px 20px;margin:16px 0;border-radius:4px;border-left:4px solid';
            $sInfo = $sBox . ';background:#eff6ff;border-color:#2563eb';
            $sAdvans = $sBox . ';background:#f5f3ff;border-color:#7c3aed';
            $sWarn = $sBox . ';background:#fef3c7;border-color:#d97706';
            $sStep = 'color:#374151;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px';
            $sTbl = 'width:100%;border-collapse:collapse;margin:12px 0';
            $sTd = 'padding:8px 10px;border:1px solid #d1d5db;vertical-align:top';
            $sTdL = $sTd . ';font-weight:600;width:35%;background:#f9fafb';
            $sCode = 'background:#e5e7eb;padding:2px 6px;border-radius:3px;font-size:13px';
            $sPre = 'background:#1e293b;color:#e2e8f0;padding:12px 16px;border-radius:4px;font-size:13px;overflow-x:auto;white-space:pre;font-family:monospace';

            $message = '';
            $message .= '<div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:860px">';
            $message .= '<h2 style="margin-bottom:16px">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TITLE') . '</h2>';

            // Step 1
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_LABEL') . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_DESC') . '</p>';
            $message .= '</div>';

            // Step 2
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP2_LABEL') . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP2_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP2_NAV') . '</p>';
            $message .= '<table style="' . $sTbl . '">';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_RETENTION_YEARS_LABEL') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_RETENTION_HINT') . '</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_LEGAL_BASIS_LABEL') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_LEGAL_BASIS_HINT') . '</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_SUPPORT_EMAIL_LABEL') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SUPPORT_EMAIL_HINT') . '</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_SHOW_CONSENT_LABEL') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CONSENT_HINT') . '</td></tr>';
            $message .= '</table>';
            $message .= '</div>';

            // Step 3 — Advans IT Solutions GmbH Licensing Configuration
            $message .= '<div style="' . $sAdvans . '">';
            $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP3_LABEL') . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP3_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP3_DESC') . '</p>';

            // SQL: Create table
            $message .= '<p><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CREATE_TITLE') . '</strong></p>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CREATE_DESC') . '</p>';
            $message .= '<div style="' . $sPre . '">'
                . "CREATE TABLE IF NOT EXISTS `#__j2store_product_customfields` (\n"
                . "  `j2store_customfield_id` int(11) NOT NULL AUTO_INCREMENT,\n"
                . "  `product_id` int(11) NOT NULL,\n"
                . "  `field_name` varchar(255) NOT NULL,\n"
                . "  `field_value` text,\n"
                . "  PRIMARY KEY (`j2store_customfield_id`)\n"
                . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
                . '</div>';

            $message .= '<p style="margin-top:8px;padding:8px 12px;background:#1a1a2e;border-left:3px solid #f0ad4e;color:#f0ad4e"><strong>&#9888;</strong> ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CREATE_NOTE') . '</p>';

            // SQL: Assign to product
            $message .= '<p style="margin-top:16px"><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_ASSIGN_TITLE') . '</strong></p>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_ASSIGN_DESC') . '</p>';
            $message .= '<div style="' . $sPre . '">'
                . "INSERT INTO `#__j2store_product_customfields`\n"
                . "  (`product_id`, `field_name`, `field_value`)\n"
                . "VALUES\n"
                . "  (123, 'is_lifetime_license', 'Yes');"
                . '</div>';
            $message .= '<p><small>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_ASSIGN_HINT') . '</small></p>';

            // SQL: Query explanation
            $message .= '<p style="margin-top:16px"><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_QUERY_TITLE') . '</strong></p>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_QUERY_DESC') . '</p>';
            $message .= '<table style="' . $sTbl . '">';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_TABLE') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">#__j2store_product_customfields</span></td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_FIELDNAME') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">is_lifetime_license</span></td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_FIELDVALUE') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">Yes</span> ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CASE_INSENSITIVE') . '</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_EFFECT') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_EFFECT_DESC') . '</td></tr>';
            $message .= '</table>';
            $message .= '</div>';

            // Step 4
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP4_LABEL') . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP4_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP4_NAV') . '</p>';
            $message .= '<ol style="line-height:1.8">';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_TYPE') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_SCHEDULE') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_ENABLE') . '</li>';
            $message .= '</ol>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_DESC') . '</p>';
            $message .= '</div>';

            // Step 5 — Privacy Request Menu Item
            $message .= '<div style="' . $sWarn . '">';
            $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_LABEL') . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_DESC') . '</p>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_NAV') . '</p>';
            $message .= '<ol style="line-height:1.8">';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_TYPE') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_ACCESS') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_HIDDEN') . '</li>';
            $message .= '</ol>';
            $message .= '<p><small>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP5_NOTE') . '</small></p>';
            $message .= '</div>';

            // Template overrides deployment result (first install only)
            if ($type === 'install' && (!empty($this->_overridesCopied) || !empty($this->_overridesSkipped))) {
                $sOverride = $sBox . ';background:#f0fdf4;border-color:#16a34a';
                $message .= '<div style="' . $sOverride . '">';
                $message .= '<div style="' . $sStep . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_LABEL') . '</div>';
                $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_TITLE') . '</h3>';
                $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_DESC') . '</p>';

                if (!empty($this->_overridesCopied)) {
                    $message .= '<p><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_COPIED') . '</strong></p>';
                    $message .= '<ul style="font-family:monospace;font-size:13px">';
                    foreach ($this->_overridesCopied as $f) {
                        $message .= '<li>templates/' . htmlspecialchars($f) . '</li>';
                    }
                    $message .= '</ul>';
                }

                if (!empty($this->_overridesSkipped)) {
                    $sSkipWarn = $sBox . ';background:#fef3c7;border-color:#d97706;margin-top:12px';
                    $message .= '<div style="' . $sSkipWarn . '">';
                    $message .= '<p style="margin:0 0 8px"><strong>&#9888; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_SKIPPED') . '</strong></p>';
                    $message .= '<ul style="font-family:monospace;font-size:13px;margin:0 0 8px">';
                    foreach ($this->_overridesSkipped as $f) {
                        $message .= '<li>templates/' . htmlspecialchars($f) . '</li>';
                    }
                    $message .= '</ul>';
                    $message .= '<p style="margin:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_OVERRIDES_SKIPPED_NOTE') . '</p>';
                    $message .= '</div>';
                }

                $message .= '</div>';
            }

            // Checklist
            $message .= '<div style="' . $sWarn . '">';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECKLIST_TITLE') . '</h3>';
            $message .= '<ul style="list-style:none;padding-left:0;line-height:1.8">';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_ENABLED') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_EMAIL') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_RETENTION') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_CONSENT') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_OVERRIDES') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_MENUITEM') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_TASK') . '</li>';
            $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_TEST') . '</li>';
            $message .= '</ul>';
            $message .= '</div>';

            // Support
            $message .= '<p style="margin-top:20px;color:#6b7280;font-size:13px">';
            $message .= Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_DOCS') . ' &middot; ';
            $message .= Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SUPPORT');
            $message .= '</p>';

            $message .= '</div>';

            $app->enqueueMessage($message, 'message');
        }
    }

    /**
     * Copy template overrides to all active frontend templates on first install.
     *
     * Only copies files that do not already exist — never overwrites customisations.
     * Overrides for both com_j2store (J2Commerce 4.x) and com_j2commerce (J2Commerce 6.x)
     * are deployed so the plugin works regardless of which version is installed.
     *
     * WHY OVERRIDES ARE NEEDED
     * J2Commerce's eventWithHtml() only imports plugins in the 'j2store' group.
     * This plugin is in the 'privacy' group (required for Joomla's native
     * com_privacy integration). There is no hook available to a privacy-group
     * plugin inside J2Commerce's checkout or MyProfile views. Template overrides
     * are the only way to integrate without patching rendered HTML.
     */
    private function copyTemplateOverrides($parent): void
    {
        $sourceBase = $parent->getParent()->getPath('source') . '/overrides';

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->dbQuery($db)
            ->select([$db->quoteName('element')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('enabled') . ' = 1');

        $db->setQuery($query);
        $templates = $db->loadColumn() ?: [];

        $overrideFiles = [
            'checkout/default_shipping_payment.php',
            'myprofile/default.php',
            'myprofile/default_addresses.php',
        ];

        // Deploy overrides for both J2Commerce 4.x (com_j2store) and 6.x (com_j2commerce)
        $components = ['com_j2store', 'com_j2commerce'];

        $copied  = [];
        $skipped = [];

        foreach ($components as $component) {
            $sourcePath = $sourceBase . '/' . $component;

            if (!is_dir($sourcePath)) {
                continue;
            }

            foreach ($templates as $template) {
                $templateHtmlPath = JPATH_SITE . '/templates/' . $template . '/html/' . $component;

                foreach ($overrideFiles as $file) {
                    $dest = $templateHtmlPath . '/' . $file;
                    $src  = $sourcePath . '/' . $file;

                    if (!file_exists($src)) {
                        continue;
                    }

                    if (file_exists($dest)) {
                        $skipped[] = $template . '/html/' . $component . '/' . $file;
                        continue;
                    }

                    $destDir = dirname($dest);
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0755, true);
                    }

                    if (copy($src, $dest)) {
                        $copied[] = $template . '/html/' . $component . '/' . $file;
                    }
                }
            }
        }

        // Store results for display in postflight message
        $this->_overridesCopied  = $copied;
        $this->_overridesSkipped = $skipped;
    }

    /**
     * Register the update site if not already present.
     */
    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/advansit/Joomla/main/j2commerce/plg_privacy_j2commerce/updates/update.xml';

        $element = 'j2commerce';
        $folder = 'privacy';
        $query = $this->dbQuery($db)
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

        $query = $this->dbQuery($db)
            ->select($db->quoteName('update_site_id'))
            ->from($db->quoteName('#__update_sites'))
            ->where($db->quoteName('location') . ' = :url')
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $siteId = (int) $db->loadResult();

        if ($siteId) {
            $query = $this->dbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);

            if (!(int) $db->loadResult()) {
                $query = $this->dbQuery($db)
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

        $query = $this->dbQuery($db)
            ->insert($db->quoteName('#__update_sites'))
            ->columns([
                $db->quoteName('name'),
                $db->quoteName('type'),
                $db->quoteName('location'),
                $db->quoteName('enabled'),
            ])
            ->values(':name, :type, :url, 1');
        $name = 'J2Commerce Privacy Plugin';
        $type = 'extension';
        $query->bind(':name', $name)
            ->bind(':type', $type)
            ->bind(':url', $updateUrl);
        $db->setQuery($query);
        $db->execute();
        $siteId = (int) $db->insertid();

        $query = $this->dbQuery($db)
            ->insert($db->quoteName('#__update_sites_extensions'))
            ->columns([$db->quoteName('update_site_id'), $db->quoteName('extension_id')])
            ->values(':siteId, :extId')
            ->bind(':siteId', $siteId, ParameterType::INTEGER)
            ->bind(':extId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }
}
