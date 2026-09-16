<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Frontend
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\Privacy\J2Commerce\Frontend;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Registry\Registry;

/**
 * Frontend options of the privacy plugin, evaluated for the J2Commerce template overrides.
 * Every method returns false while the privacy plugin is not installed or not enabled.
 */
final class PrivacyOptions
{
    /**
     * Params of the enabled privacy plugin, or null.
     */
    public static function params(): ?Registry
    {
        $plugin = PluginHelper::getPlugin('privacy', 'j2commerce');

        return empty($plugin) ? null : new Registry($plugin->params ?? '{}');
    }

    /** "Show Privacy Section": Privacy tab in MyProfile. */
    public static function showPrivacyTab(): bool
    {
        return self::option('show_privacy_section');
    }

    /** "Show Delete Address Buttons": delete button per saved address. */
    public static function showDeleteAddress(): bool
    {
        return self::option('show_delete_address');
    }

    /** "Show Export Data": data export request button in the Privacy tab. */
    public static function showExportRequest(): bool
    {
        return self::option('show_export_data');
    }

    /** "Show Delete All Data": data deletion request button in the Privacy tab. */
    public static function showDeletionRequest(): bool
    {
        return self::option('show_delete_all');
    }

    /**
     * Load the plugin language (the privacy group is not imported in the frontend).
     */
    public static function loadLanguage(): void
    {
        try {
            $language = Factory::getApplication()->getLanguage();
        } catch (\Throwable $e) {
            $language = Factory::getLanguage();
        }

        $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce')
            || $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
    }

    private static function option(string $name): bool
    {
        $params = self::params();

        return $params !== null && (bool) (int) $params->get($name, 1);
    }
}
