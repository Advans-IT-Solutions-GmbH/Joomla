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
// The entry file defines the runtime class PlgOsmapJ2commerce (global
// namespace) that selects com_j2store vs com_j2commerce at runtime.
require_once dirname(__DIR__) . '/j2commerce.php';

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $pluginData = (array) PluginHelper::getPlugin('osmap', 'j2commerce');
        $dispatcher = $container->get(DispatcherInterface::class);
        $db         = $container->get(DatabaseInterface::class);

        // Register the runtime class (PlgOsmapJ2commerce). It overrides
        // getComponentElement()/getTree() to pick com_j2store or com_j2commerce
        // and the matching products table at runtime — the same class OSMap
        // loads via its own require_once + class-name mechanism.
        $container->set(
            PluginInterface::class,
            function () use ($dispatcher, $pluginData, $db) {
                $plugin = new \PlgOsmapJ2commerce($dispatcher, $pluginData);
                // Resolve the application inside the factory closure so plugin
                // registration does not throw in a console context.
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($db);

                return $plugin;
            }
        );
    }
};
