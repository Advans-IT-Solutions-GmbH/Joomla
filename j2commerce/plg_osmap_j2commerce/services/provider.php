<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

defined('_JEXEC') or die;

// Ensure OSMap's Alledia autoloader is registered before loading the plugin
// class. During Joomla's update/install process the plugin is instantiated
// before com_osmap's include.php has run, causing "Class not found" for
// Alledia\OSMap\Plugin\Base.
$osmapInclude = JPATH_ADMINISTRATOR . '/components/com_osmap/include.php';
if (is_file($osmapInclude) && !defined('OSMAP_LOADED')) {
    include_once $osmapInclude;
}

// Joomla's DI container loads this file before the plugin entry point
// (j2commerce.php), so the PSR-4 autoloader may not have this namespace
// registered yet. Load the class explicitly to guarantee availability.
require_once dirname(__DIR__) . '/src/Extension/J2Commerce.php';

use Advans\Plugin\Osmap\J2Commerce\Extension\J2Commerce;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new J2Commerce(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('osmap', 'j2commerce')
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase(Factory::getContainer()->get('DatabaseDriver'));

                return $plugin;
            }
        );
    }
};
