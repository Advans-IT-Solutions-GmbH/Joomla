<?php
defined('_JEXEC') or die;

return new class implements \Joomla\DI\ServiceProviderInterface {
    public function register(\Joomla\DI\Container $container): void
    {
        $container->set(\Joomla\CMS\Extension\PluginInterface::class, static function (): \Joomla\CMS\Extension\PluginInterface {
            $plugin = new \Advans\Test\PrivacyRequestsMailProbe\Extension\MailProbe(
                (array) \Joomla\CMS\Plugin\PluginHelper::getPlugin('system', 'privacyrequestsmailprobe'));
            $plugin->setApplication(\Joomla\CMS\Factory::getApplication());

            return $plugin;
        });
    }
};
