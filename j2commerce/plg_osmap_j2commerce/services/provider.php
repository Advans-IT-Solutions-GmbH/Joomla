<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 */

defined('_JEXEC') or die;

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
