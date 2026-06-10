<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Services
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce;
use Advans\Plugin\Privacy\J2Commerce\Task\AutoCleanupTask;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new J2Commerce(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('privacy', 'j2commerce')
                );
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );

        // Register the scheduler task so Joomla's task component can discover it
        $container->set(
            AutoCleanupTask::class,
            function (Container $container) {
                $task = new AutoCleanupTask(
                    $container->get(DispatcherInterface::class)
                );
                $task->setDatabase($container->get(DatabaseInterface::class));

                return $task;
            }
        );
    }
};
