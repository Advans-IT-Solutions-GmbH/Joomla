<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View;

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\WebAsset\WebAssetItem;

defined('_JEXEC') or die;

/**
 * The stylesheet and the booking script of the site pages, each with the checksum of the file
 * actually served as its version (`?a1b2c3d4`). The version of joomla.asset.json only changes
 * with a release, while a web server may let browsers keep a static file for days: after an
 * installation of a build with the same version number, a browser would keep the old stylesheet
 * and script next to the new markup, and the page would fall apart at the first change of a
 * step. The checksum changes exactly when the file does, also for a template override of the
 * file (templates/<template>/css/com_appointments/appointments.css), which Joomla serves instead.
 */
trait AssetsTrait
{
    protected function useAppointmentsAssets(bool $withScript): void
    {
        $assets = Factory::getApplication()->getDocument()->getWebAssetManager();
        $registry = $assets->getRegistry();
        $registry->addExtensionRegistryFile('com_appointments');
        $wanted = ['style' => 'com_appointments.site'];

        if ($withScript) {
            $wanted['script'] = 'com_appointments.booking';
        }

        foreach ($wanted as $type => $name) {
            $item = $registry->get($type, $name);
            $uri = (string) $item->getUri(false);
            $checksum = $this->assetChecksum($type, $uri);

            if ($checksum !== '') {
                $registry->add($type, new WebAssetItem($name, $uri, ['version' => $checksum], $item->getAttributes(), $item->getDependencies()));
            }

            $type === 'style' ? $assets->useStyle($name) : $assets->useScript($name);
        }
    }

    /** CRC32 of the file Joomla serves for the relative asset address $uri, or '' when it is not a local file. */
    private function assetChecksum(string $type, string $uri): string
    {
        if ($uri === '' || preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $uri)) {
            return '';
        }

        $path = (string) HTMLHelper::_($type === 'style' ? 'stylesheet' : 'script', $uri, ['relative' => true, 'pathOnly' => true]);
        $base = Uri::root(true);
        $path = (string) preg_replace('/[?#].*$/s', '', $path);

        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, strlen($base));
        }

        $file = JPATH_ROOT . '/' . ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || !is_file($file)) {
            return '';
        }

        return (string) hash_file('crc32b', $file);
    }
}
