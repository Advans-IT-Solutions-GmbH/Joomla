<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

defined('_JEXEC') or die;

// Joomla's DI container loads this file before the plugin entry point
// (j2commerce.php), so the PSR-4 autoloader may not have this namespace
// registered yet. Load the classes explicitly to guarantee availability.
require_once dirname(__DIR__) . '/src/Extension/J2Commerce.php';
require_once dirname(__DIR__) . '/src/Extension/J2CommerceNew.php';

use Advans\Plugin\Osmap\J2Commerce\Extension\J2Commerce;
use Advans\Plugin\Osmap\J2Commerce\Extension\J2CommerceNew;
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
        $pluginData = (array) PluginHelper::getPlugin('osmap', 'j2commerce');
        $dispatcher = $container->get(DispatcherInterface::class);
        $db         = Factory::getContainer()->get('DatabaseDriver');
        $app        = Factory::getApplication();

        // Handle com_j2store menu items (J2Store / legacy)
        $container->set(
            PluginInterface::class,
            function () use ($dispatcher, $pluginData, $db, $app) {
                $plugin = new J2Commerce($dispatcher, $pluginData);
                $plugin->setApplication($app);
                $plugin->setDatabase($db);

                return $plugin;
            }
        );

        // Handle com_j2commerce menu items (J2Commerce 4+)
        $container->set(
            J2CommerceNew::class,
            function () use ($dispatcher, $pluginData, $db, $app) {
                $plugin = new J2CommerceNew($dispatcher, $pluginData);
                $plugin->setApplication($app);
                $plugin->setDatabase($db);

                return $plugin;
            }
        );
    }
};
