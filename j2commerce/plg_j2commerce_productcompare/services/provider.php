<?php
/**
 * J2Commerce Product Compare Plugin
 * @subpackage  Services
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Advans\Plugin\J2Commerce\ProductCompare\Extension\ProductCompare;

\JLoader::registerNamespace(
    'Advans\\Plugin\\J2Commerce\\ProductCompare',
    __DIR__ . '/../src',
    false,
    false,
    'psr4'
);

return new class implements ServiceProviderInterface
{
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // The plugin group is j2store on J4/J5 and j2commerce on J6,
                // set dynamically by the installer script.
                $pluginData = PluginHelper::getPlugin('j2commerce', 'productcompare')
                    ?: PluginHelper::getPlugin('j2store', 'productcompare');
                // PluginHelper sets the dispatcher when it boots the plugin; passing it to the constructor or calling setDispatcher() here is deprecated since Joomla 5.2.
                $plugin = new ProductCompare((array) $pluginData);
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
