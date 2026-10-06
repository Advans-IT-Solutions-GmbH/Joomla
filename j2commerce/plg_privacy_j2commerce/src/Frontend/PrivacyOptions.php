<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Frontend
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\J2Commerce\Frontend;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
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
        $language = self::currentLanguage();

        $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce')
            || $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
    }

    private static function option(string $name): bool
    {
        $params = self::params();

        return $params !== null && (bool) (int) $params->get($name, 1);
    }

    /**
     * Current language: the application's, or (CLI without application) a language object of the
     * default site language. Factory::getLanguage() is deprecated.
     */
    private static function currentLanguage(): Language
    {
        try {
            $app = Factory::getApplication();

            if (method_exists($app, 'getLanguage')) {
                return $app->getLanguage();
            }
        } catch (\Throwable $e) {
            // No application (CLI script).
        }

        static $fallback = null;

        if ($fallback === null) {
            try {
                $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            } catch (\Throwable $e) {
                $tag = 'en-GB';
            }

            $fallback = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
        }

        return $fallback;
    }
}
