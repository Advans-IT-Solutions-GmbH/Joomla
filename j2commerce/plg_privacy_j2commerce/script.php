<?php
/**
 * @package     J2Commerce Privacy System Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerScript;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

class Plgprivacyj2commerceInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    /** @var string[] Files copied to template overrides on first install */
    private array $_overridesCopied = [];

    /** @var string[] Files skipped because they already existed */
    private array $_overridesSkipped = [];

    /** @var string[] Files that could not be copied */
    private array $_overridesFailed = [];

    /** Whether the bundled task plugin was installed and enabled in this run */
    private bool $taskPluginReady = false;

    /**
     * First J2Commerce 6 version whose core checkout templates (bootstrap5 and uikit) fire
     * AfterDisplayShippingPayment. The event was added in J2Commerce commit d7992c66
     * (PR #1109, merged 2026-05-28), after the 6.3.3 version bump (2026-05-27); 6.3.4
     * (2026-06-01) is the first version bump that contains it.
     */
    public const MIN_J2COMMERCE_EVENT_VERSION = '6.3.4';

    /**
     * SHA-256 (line endings normalised to LF) of every J2Commerce 6 checkout override
     * (html/com_j2commerce/checkout/default_shipping_payment.php) that earlier versions of this
     * plugin copied into site templates. An unchanged copy is renamed on install/update, see
     * retireBundledCheckoutOverrides().
     */
    private const BUNDLED_CHECKOUT_OVERRIDE_HASHES = [
        'f7f80680b6dad4b6a0644a165a004bfa60b70880167c83f861bd9857926a233f', // d2a8ac3
        'cab562d39f5b02086e7b8c375e2f14840fb2f29f4295642ecbac88e0d093ccff', // 53be1ef
        '6ae2f79cec5572b5b5e359a6088d37f4774f5ce2c2b45aed2167d77446925c93', // 4f2dab5 (1.5.4, 1.5.5)
        'd149bc8879defeea0163364c760a5d1962a360facd2ae54e1b9345dd94bf502d', // event-based sample (pre-release)
    ];

    /** Marker line in the header of every checkout override this plugin shipped */
    private const BUNDLED_OVERRIDE_MARKER = 'Template override for plg_privacy_j2commerce';

    /** Suffix of a retired checkout override (Joomla no longer loads the file) */
    public const RETIRED_OVERRIDE_SUFFIX = '.plg_privacy_j2commerce-disabled';

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
            $packageSource = $parent->getParent()->getPath('source');

            // Load the language first: every message below, including warnings
            // raised while installing the bundled task plugin, is translated.
            $app = Factory::getApplication();
            $lang = $app->getLanguage();
            $lang->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
            $lang->load('plg_privacy_j2commerce', $packageSource);

            // Remove old manifest filename (renamed to j2commerce.xml in 1.2.8)
            $oldManifest = JPATH_PLUGINS . '/privacy/j2commerce/plg_privacy_j2commerce.xml';
            if (file_exists($oldManifest)) {
                @unlink($oldManifest);
            }

            $this->ensureUpdateSite();
            $this->removeLegacyAutoCleanupTaskFile();
            $this->warnIfJ2CommerceMissing();

            // Deploy template overrides on first install (never overwrite). Updates only add
            // override files that older versions did not ship and that are still missing, so an
            // already deployed MyProfile override finds its privacy tab (never overwrite).
            if ($type === 'install') {
                $this->copyTemplateOverrides($packageSource);
            } else {
                $this->copyTemplateOverrides($packageSource, ['myprofile/default_privacy.php']);
            }

            $this->warnIfJ2CommerceTooOld();
            $this->retireBundledCheckoutOverrides();
            $this->warnOutdatedCheckoutOverrides();

            $this->installTaskPlugin($packageSource);
            $this->installConsentSystemPlugin($packageSource);
            $this->migrateLegacySchedulerTasks();

            if (!empty($this->_overridesFailed)) {
                $app->enqueueMessage(
                    Text::sprintf(
                        'PLG_PRIVACY_J2COMMERCE_WARN_OVERRIDES_FAILED',
                        htmlspecialchars(implode(', ', $this->_overridesFailed))
                    ),
                    'warning'
                );
            }

            $enabled = $this->isPluginEnabled();

            // Updates only get a short confirmation, plus a hint while the plugin
            // is disabled; the full setup guide is shown on the first installation
            // and lists only the steps that are still open.
            if ($type === 'update') {
                $manifest = method_exists($parent, 'getManifest') ? $parent->getManifest() : null;
                $version  = $manifest instanceof \SimpleXMLElement ? (string) $manifest->version : '';

                $app->enqueueMessage(
                    Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_UPDATED', htmlspecialchars($version)),
                    'message'
                );

                if (!$enabled) {
                    $app->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_ENABLE_HINT'), 'message');
                }

                return;
            }

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

            // Steps are numbered in the order shown; enabling the plugin is left
            // out when it is already enabled.
            $step = 0;

            // Enable plugin
            if (!$enabled) {
                $message .= '<div style="' . $sInfo . '">';
                $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
                $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_TITLE') . '</h3>';
                $message .= '<p>' . Text::_(
                    $this->taskPluginReady
                        ? 'PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_DESC'
                        : 'PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP1_DESC_TASK_FAILED'
                ) . '</p>';
                $message .= '</div>';
            }

            // Step 2
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
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
            $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP_OPTIONAL_LABEL', ++$step) . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP3_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP3_DESC') . '</p>';

            // SQL: J2Commerce 6 metafields
            $message .= '<p><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_J6_TITLE') . '</strong></p>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_J6_DESC') . '</p>';
            $message .= '<div style="' . $sPre . '">'
                . "INSERT INTO `#__j2commerce_metafields`\n"
                . "  (`owner_id`, `owner_resource`, `metakey`, `metavalue`)\n"
                . "VALUES\n"
                . "  (123, 'product', 'is_lifetime_license', 'yes');"
                . '</div>';
            $message .= '<p><small>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_J6_HINT') . '</small></p>';

            // SQL: J2Commerce 4 / J2Store create table
            $message .= '<p style="margin-top:16px"><strong>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CREATE_TITLE') . '</strong></p>';
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
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_TABLE') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">#__j2commerce_metafields</span> (J2Commerce 6) / <span style="' . $sCode . '">#__j2store_product_customfields</span> (J2Commerce 4)</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_FIELDNAME') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">metakey</span> / <span style="' . $sCode . '">field_name</span>: <span style="' . $sCode . '">is_lifetime_license</span></td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_FIELDVALUE') . '</td><td style="' . $sTd . '"><span style="' . $sCode . '">metavalue</span> / <span style="' . $sCode . '">field_value</span>: <span style="' . $sCode . '">yes</span> ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_CASE_INSENSITIVE') . '</td></tr>';
            $message .= '<tr><td style="' . $sTdL . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_COL_EFFECT') . '</td><td style="' . $sTd . '">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_SQL_EFFECT_DESC') . '</td></tr>';
            $message .= '</table>';
            $message .= '</div>';

            // Step 4
            $message .= '<div style="' . $sInfo . '">';
            $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP_LABEL', ++$step) . '</div>';
            $message .= '<h3 style="margin-top:0">' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP4_TITLE') . '</h3>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP4_NAV') . '</p>';
            $message .= '<ol style="line-height:1.8">';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_TYPE') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_PARAMS') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_SCHEDULE') . '</li>';
            $message .= '<li>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_ENABLE') . '</li>';
            $message .= '</ol>';
            $message .= '<p>' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_TASK_DESC') . '</p>';
            $message .= '</div>';

            // Step 5 — Privacy Request Menu Item
            $message .= '<div style="' . $sWarn . '">';
            $message .= '<div style="' . $sStep . '">' . Text::sprintf('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_STEP_REQUIRED_LABEL', ++$step) . '</div>';
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
            if (!$enabled) {
                $message .= '<li>&#9744; ' . Text::_('PLG_PRIVACY_J2COMMERCE_POSTINSTALL_CHECK_ENABLED') . '</li>';
            }
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

    public function uninstall($parent): void
    {
        Factory::getApplication()->getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

        $this->uninstallTaskPlugin();
        $this->uninstallConsentSystemPlugin();
    }

    /**
     * Install or update the bundled consent system plugin.
     *
     * The privacy plugin group is not imported during the J2Commerce checkout, so checkout
     * consent is validated and recorded by this system plugin. It is enabled on first
     * installation only; an administrator's later choice to disable it survives updates.
     */
    private function installConsentSystemPlugin(string $packageSource): void
    {
        $source = $packageSource . '/plugins/system/j2commerceprivacy';

        if (!is_dir($source) || !is_file($source . '/j2commerceprivacy.xml')) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_WARN_CONSENT_PLUGIN_MISSING'), 'warning');

            return;
        }

        $isNew = $this->getConsentSystemPluginExtensionId() === 0;

        // Dedicated Installer instance, as for the task plugin: the singleton still holds the
        // manifest and state of the privacy plugin installation that is running postflight().
        $installer = new Installer();

        if (method_exists($installer, 'setDatabase')) {
            $installer->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));
        }

        if (!$installer->install($source)) {
            Factory::getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_WARN_CONSENT_PLUGIN_INSTALL_FAILED'), 'warning');

            return;
        }

        $extensionId = $this->getConsentSystemPluginExtensionId();

        if ($isNew && $extensionId) {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('extension_id') . ' = :extensionId')
                ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();
        }
    }

    /**
     * Remove the bundled consent system plugin when the privacy plugin is uninstalled.
     * Recorded consents in #__privacy_consents are Joomla core data and are kept.
     */
    private function uninstallConsentSystemPlugin(): void
    {
        $db          = Factory::getContainer()->get(DatabaseInterface::class);
        $extensionId = $this->getConsentSystemPluginExtensionId();

        if ($extensionId) {
            foreach (['#__schemas', '#__update_sites_extensions', '#__extensions'] as $table) {
                $query = $this->dbQuery($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('extension_id') . ' = :extensionId')
                    ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
        }

        $this->deleteDirectory(JPATH_PLUGINS . '/system/j2commerceprivacy');
    }

    /**
     * Return the bundled consent system plugin extension ID (0 if not installed).
     */
    private function getConsentSystemPluginExtensionId(): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $this->dbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('j2commerceprivacy'));
        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * Installed J2Commerce 6 version from the component manifest cache, '' if unknown or not installed.
     */
    private function getJ2CommerceVersion(): string
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_j2commerce'));
            $db->setQuery($query);
            $cache = json_decode((string) $db->loadResult(), true);

            return is_array($cache) ? trim((string) ($cache['version'] ?? '')) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The consent checkbox is rendered through AfterDisplayShippingPayment, which the J2Commerce 6
     * core checkout templates fire only since MIN_J2COMMERCE_EVENT_VERSION. Advisory only.
     */
    private function warnIfJ2CommerceTooOld(): void
    {
        $version = $this->getJ2CommerceVersion();

        if ($version !== '' && version_compare($version, self::MIN_J2COMMERCE_EVENT_VERSION, '<')) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf(
                    'PLG_PRIVACY_J2COMMERCE_WARN_J2COMMERCE_TOO_OLD',
                    htmlspecialchars($version),
                    self::MIN_J2COMMERCE_EVENT_VERSION
                ),
                'warning'
            );
        }
    }

    /**
     * Retire J2Commerce 6 checkout overrides that earlier versions of this plugin copied into site
     * templates (html/com_j2commerce/checkout/default_shipping_payment.php). Such a copy shadows the
     * core bootstrap5 and uikit templates and lacks later core features (payment-step custom fields,
     * payment descriptions, image URL handling, uikit markup).
     *
     * - Unchanged copy (hash of a shipped version) and J2Commerce fires the event: renamed with
     *   RETIRED_OVERRIDE_SUFFIX, so the core template (which renders the checkbox through the event)
     *   takes over. Renaming instead of deleting keeps the file for comparison and can be undone.
     * - Changed copy (plugin marker, unknown hash): may contain shop changes, left in place with a
     *   warning.
     * - J2Commerce older than MIN_J2COMMERCE_EVENT_VERSION or version unknown: left in place, the
     *   copy is then the only place that renders the checkbox.
     */
    private function retireBundledCheckoutOverrides(): void
    {
        if (!is_dir(JPATH_SITE . '/components/com_j2commerce')) {
            return;
        }

        $version    = $this->getJ2CommerceVersion();
        $hasEvent   = $version !== '' && version_compare($version, self::MIN_J2COMMERCE_EVENT_VERSION, '>=');
        $db         = Factory::getContainer()->get(DatabaseInterface::class);
        $retired    = [];
        $modified   = [];
        $failed     = [];

        foreach ($this->getFrontendTemplates($db) as $template) {
            $relative = $template . '/html/com_j2commerce/checkout/default_shipping_payment.php';
            $file     = JPATH_SITE . '/templates/' . $relative;

            if (!is_file($file)) {
                continue;
            }

            $content = (string) @file_get_contents($file);

            if (!str_contains($content, self::BUNDLED_OVERRIDE_MARKER)) {
                continue;
            }

            $hash = hash('sha256', str_replace("\r\n", "\n", $content));

            if (!in_array($hash, self::BUNDLED_CHECKOUT_OVERRIDE_HASHES, true)) {
                $modified[] = $relative;
                continue;
            }

            if (!$hasEvent) {
                continue;
            }

            $target = $file . self::RETIRED_OVERRIDE_SUFFIX;

            if (file_exists($target)) {
                $target .= '-' . date('YmdHis');
            }

            if (@rename($file, $target)) {
                $retired[] = $relative;
            } else {
                $failed[] = $relative;
            }
        }

        $app = Factory::getApplication();

        if ($retired !== []) {
            $app->enqueueMessage(
                Text::sprintf(
                    'PLG_PRIVACY_J2COMMERCE_CHECKOUT_OVERRIDE_RETIRED',
                    htmlspecialchars(implode(', ', $retired)),
                    self::RETIRED_OVERRIDE_SUFFIX
                ),
                'message'
            );
        }

        if ($modified !== [] || $failed !== []) {
            $app->enqueueMessage(
                Text::sprintf(
                    'PLG_PRIVACY_J2COMMERCE_WARN_CHECKOUT_OVERRIDE_BUNDLED',
                    htmlspecialchars(implode(', ', array_merge($modified, $failed)))
                ),
                'warning'
            );
        }
    }

    /**
     * Warn about J2Commerce 6 checkout overrides (also in the bootstrap5/uikit subfolders) that
     * neither fire the J2Commerce event AfterDisplayShippingPayment, through which the consent
     * system plugin renders the checkbox, nor render a checkbox themselves. With a required consent
     * the checkout cannot be completed with such an override.
     */
    private function warnOutdatedCheckoutOverrides(): void
    {
        if (!is_dir(JPATH_SITE . '/components/com_j2commerce')) {
            return;
        }

        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $outdated = [];

        foreach ($this->getFrontendTemplates($db) as $template) {
            // uikit3/ is the framework folder of J2Commerce before 6.3.7.
            foreach (['', 'bootstrap5/', 'uikit/', 'uikit3/'] as $subfolder) {
                $relative = $template . '/html/com_j2commerce/checkout/' . $subfolder . 'default_shipping_payment.php';
                $file     = JPATH_SITE . '/templates/' . $relative;

                if (!is_file($file)) {
                    continue;
                }

                $content = (string) @file_get_contents($file);

                if (!str_contains($content, 'AfterDisplayShippingPayment') && !str_contains($content, 'j2commerce_privacy_consent')) {
                    $outdated[] = $relative;
                }
            }
        }

        if ($outdated !== []) {
            Factory::getApplication()->enqueueMessage(
                Text::sprintf('PLG_PRIVACY_J2COMMERCE_WARN_CHECKOUT_OVERRIDE_OUTDATED', htmlspecialchars(implode(', ', $outdated))),
                'warning'
            );
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
    private function copyTemplateOverrides(string $packageSource, ?array $onlyFiles = null): void
    {
        $sourceBase = $packageSource . '/overrides';

        $db        = Factory::getContainer()->get(DatabaseInterface::class);
        $templates = $this->getFrontendTemplates($db);

        // checkout/default_shipping_payment.php exists only for com_j2store: J2Commerce 6 renders
        // the consent checkbox through AfterDisplayShippingPayment of its core templates.
        $overrideFiles = $onlyFiles ?? [
            'checkout/default_shipping_payment.php',
            'myprofile/default.php',
            'myprofile/default_addresses.php',
            'myprofile/default_privacy.php',
        ];

        // Deploy overrides for both J2Commerce 4.x (com_j2store) and 6.x (com_j2commerce)
        $components = ['com_j2store', 'com_j2commerce'];

        $copied  = [];
        $skipped = [];
        $failed  = [];

        foreach ($components as $component) {
            $sourcePath = $sourceBase . '/' . $component;

            if (!is_dir($sourcePath)) {
                continue;
            }

            foreach ($templates as $template) {
                $templateHtmlPath = JPATH_SITE . '/templates/' . $template . '/html/' . $component;

                // Update mode ($onlyFiles): only complete an existing MyProfile override of an
                // installed component; do not create overrides anywhere else.
                if ($onlyFiles !== null
                    && (!is_dir(JPATH_SITE . '/components/' . $component)
                        || !is_file($templateHtmlPath . '/myprofile/default.php'))
                ) {
                    continue;
                }

                foreach ($overrideFiles as $file) {
                    $dest = $templateHtmlPath . '/' . $file;
                    $src  = $sourcePath . '/' . $file;

                    if (!file_exists($src)) {
                        continue;
                    }

                    if (file_exists($dest)) {
                        if ($onlyFiles === null) {
                            $skipped[] = $template . '/html/' . $component . '/' . $file;
                        }

                        continue;
                    }

                    $relative = $template . '/html/' . $component . '/' . $file;
                    $destDir  = dirname($dest);

                    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
                        $failed[] = $relative;
                        continue;
                    }

                    if (@copy($src, $dest)) {
                        $copied[] = $relative;
                    } else {
                        $failed[] = $relative;
                    }
                }
            }
        }

        // Store results for display in postflight message
        $this->_overridesCopied  = $copied;
        $this->_overridesSkipped = $skipped;
        $this->_overridesFailed  = $failed;
    }

    /**
     * Warn when neither J2Commerce 6 nor J2Store/J2Commerce 4 is installed.
     */
    private function warnIfJ2CommerceMissing(): void
    {
        try {
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->dbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->whereIn($db->quoteName('element'), ['com_j2store', 'com_j2commerce'], ParameterType::STRING);
            $db->setQuery($query);

            if ((int) $db->loadResult() === 0) {
                Factory::getApplication()->enqueueMessage(
                    Text::_('PLG_PRIVACY_J2COMMERCE_WARN_J2COMMERCE_MISSING'),
                    'warning'
                );
            }
        } catch (\Throwable $e) {
            // Detection is advisory only; never block the installation.
        }
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
                ->where($db->quoteName('folder') . ' = ' . $db->quote('privacy'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('j2commerce'));

            return (int) $db->setQuery($query)->loadResult() === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Return frontend templates that can receive overrides.
     */
    private function getFrontendTemplates(DatabaseInterface $db): array
    {
        $tables = $db->getTableList();

        if (in_array($db->getPrefix() . 'template_styles', $tables, true)) {
            $query = $this->dbQuery($db)
                ->select('DISTINCT ' . $db->quoteName('template'))
                ->from($db->quoteName('#__template_styles'))
                ->where($db->quoteName('client_id') . ' = 0');
            $db->setQuery($query);

            $templates = array_filter($db->loadColumn() ?: []);

            if (!empty($templates)) {
                return array_values(array_unique($templates));
            }
        }

        $query = $this->dbQuery($db)
            ->select([$db->quoteName('element')])
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('template'))
            ->where($db->quoteName('client_id') . ' = 0');
        $db->setQuery($query);

        return array_values(array_unique(array_filter($db->loadColumn() ?: [])));
    }

    /**
     * Register the update site if not already present.
     */
    private function ensureUpdateSite(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $updateUrl = 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/j2commerce/plg_privacy_j2commerce/updates/update.xml';

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

        $this->removeLegacyUpdateSites($db, $extensionId);

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
        $db->setQuery($query);
        $siteIds = array_map('intval', $db->loadColumn() ?: []);

        foreach ($siteIds as $siteId) {
            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->where($db->quoteName('extension_id') . ' = :extId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER)
                ->bind(':extId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $query = $this->dbQuery($db)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('update_site_id') . ' = :siteId')
                ->bind(':siteId', $siteId, ParameterType::INTEGER);
            $db->setQuery($query);

            if ((int) $db->loadResult() > 0) {
                continue;
            }

            foreach (['#__updates', '#__update_sites'] as $table) {
                $query = $this->dbQuery($db)
                    ->delete($db->quoteName($table))
                    ->where($db->quoteName('update_site_id') . ' = :siteId')
                    ->bind(':siteId', $siteId, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();
            }
        }
    }

    /**
     * Install or update the bundled Joomla task plugin.
     *
     * A dedicated Installer instance is used on purpose. The backend installs the
     * privacy plugin through the Installer singleton; installing the task plugin
     * through that same singleton from inside postflight() would replace its
     * manifest and state while the outer installation is still running.
     */
    private function installTaskPlugin(string $packageSource): void
    {
        $source = $packageSource . '/plugins/task/j2commerceprivacy';
        $app    = Factory::getApplication();

        if (!is_dir($source) || !is_file($source . '/j2commerceprivacy.xml')) {
            $app->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_WARN_TASK_PLUGIN_MISSING'), 'warning');

            return;
        }

        $installer = new Installer();

        if (method_exists($installer, 'setDatabase')) {
            $installer->setDatabase(Factory::getContainer()->get(DatabaseInterface::class));
        }

        if (!$installer->install($source)) {
            $app->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_WARN_TASK_PLUGIN_INSTALL_FAILED'), 'warning');

            return;
        }

        $this->setTaskPluginEnabled(true);
        $this->taskPluginReady = $this->getTaskPluginExtensionId() > 0;
    }

    /**
     * Remove the bundled task plugin when the privacy plugin is uninstalled.
     */
    private function uninstallTaskPlugin(): void
    {
        $db          = Factory::getContainer()->get(DatabaseInterface::class);
        $extensionId = $this->getTaskPluginExtensionId();

        $taskType = 'plg_task_j2commerceprivacy.autocleanup';
        $tables   = $db->getTableList();
        $app      = Factory::getApplication();

        if (in_array($db->getPrefix() . 'scheduler_tasks', $tables, true)) {
            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__scheduler_tasks'))
                ->where($db->quoteName('type') . ' = :taskType')
                ->bind(':taskType', $taskType);
            $db->setQuery($query);
            $db->execute();

            $removedTasks = (int) $db->getAffectedRows();

            if ($removedTasks > 0) {
                $app->enqueueMessage(
                    Text::sprintf('PLG_PRIVACY_J2COMMERCE_UNINSTALL_TASKS_REMOVED', $removedTasks),
                    'message'
                );
            }
        }

        // The task plugin is removed directly (database rows and files) because
        // running Joomla's installer from inside uninstall() would reset the
        // state of the installation that is currently in progress.
        if ($extensionId) {
            $app->enqueueMessage(Text::_('PLG_PRIVACY_J2COMMERCE_UNINSTALL_TASK_PLUGIN_REMOVED'), 'message');

            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__schemas'))
                ->where($db->quoteName('extension_id') . ' = :extensionId')
                ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__update_sites_extensions'))
                ->where($db->quoteName('extension_id') . ' = :extensionId')
                ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            $query = $this->dbQuery($db)
                ->delete($db->quoteName('#__extensions'))
                ->where($db->quoteName('extension_id') . ' = :extensionId')
                ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();
        }

        $this->deleteDirectory(JPATH_PLUGINS . '/task/j2commerceprivacy');
    }

    /**
     * Enable or disable the installed task plugin.
     */
    private function setTaskPluginEnabled(bool $enabled): void
    {
        $db          = Factory::getContainer()->get(DatabaseInterface::class);
        $extensionId = $this->getTaskPluginExtensionId();

        if (!$extensionId) {
            return;
        }

        $enabledValue = $enabled ? 1 : 0;

        $query = $this->dbQuery($db)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = :enabled')
            ->where($db->quoteName('extension_id') . ' = :extensionId')
            ->bind(':enabled', $enabledValue, ParameterType::INTEGER)
            ->bind(':extensionId', $extensionId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Return the bundled task plugin extension ID.
     */
    private function getTaskPluginExtensionId(): int
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        $query = $this->dbQuery($db)
            ->select($db->quoteName('extension_id'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('task'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('j2commerceprivacy'));
        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * Move any task entries created from the old non-discoverable routine ID.
     */
    private function migrateLegacySchedulerTasks(): void
    {
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $tables = $db->getTableList();

        if (!in_array($db->getPrefix() . 'scheduler_tasks', $tables, true)) {
            return;
        }

        $query = $this->dbQuery($db)
            ->update($db->quoteName('#__scheduler_tasks'))
            ->set($db->quoteName('type') . ' = ' . $db->quote('plg_task_j2commerceprivacy.autocleanup'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plg_privacy_j2commerce.autocleanup'));
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Remove the old task service file from existing installations.
     */
    private function removeLegacyAutoCleanupTaskFile(): void
    {
        $oldFile = JPATH_PLUGINS . '/privacy/j2commerce/src/Task/AutoCleanupTask.php';

        if (is_file($oldFile)) {
            @unlink($oldFile);
        }

        $oldDir = dirname($oldFile);

        if (is_dir($oldDir) && count(glob($oldDir . '/*') ?: []) === 0) {
            @rmdir($oldDir);
        }
    }

    /**
     * Recursively delete a directory without invoking Joomla's installer from inside uninstall().
     */
    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                $this->deleteDirectory($itemPath);
                continue;
            }

            @unlink($itemPath);
        }

        @rmdir($path);
    }
}
