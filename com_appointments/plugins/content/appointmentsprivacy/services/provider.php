<?php
/**
 * @package     Appointments Privacy Completion Guard
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Advans\Plugin\Content\AppointmentsPrivacy\Extension\AppointmentsPrivacy;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(PluginInterface::class, static function (Container $container): PluginInterface {
            $plugin = new AppointmentsPrivacy((array) PluginHelper::getPlugin('content', 'appointmentsprivacy'));
            $plugin->setApplication(Factory::getApplication());
            $plugin->setDatabase($container->get(DatabaseInterface::class));

            return $plugin;
        });
    }
};
