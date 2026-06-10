<?php
/**
 * J2Commerce Product Compare Plugin
 * @subpackage  Services
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
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
                $plugin = new ProductCompare(
                    $container->get(DispatcherInterface::class),
                    (array) $pluginData
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
