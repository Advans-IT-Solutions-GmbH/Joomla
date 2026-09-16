<?php
/**
 * @package     J2Commerce Privacy Consent System Plugin
 * @subpackage  Services
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

defined('_JEXEC') or die;

use Advans\Plugin\System\J2CommercePrivacy\Extension\J2CommercePrivacy;
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
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new J2CommercePrivacy(
                    (array) PluginHelper::getPlugin('system', 'j2commerceprivacy')
                );
                $plugin->setDispatcher($container->get(DispatcherInterface::class));
                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
