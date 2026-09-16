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
use Advans\Plugin\Privacy\J2Commerce\Extension\J2Commerce;

return new class implements ServiceProviderInterface
{
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                // PluginHelper sets the dispatcher when it boots the plugin; passing it to the constructor or calling setDispatcher() here is deprecated since Joomla 5.2.
                $plugin = new J2Commerce((array) PluginHelper::getPlugin('privacy', 'j2commerce'));
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
