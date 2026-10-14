<?php

defined('_JEXEC') || define('_JEXEC', 1);
defined('JPATH_BASE') || define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$application = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $application;
$application->createExtensionNamespaceMap();
$application->bootComponent('com_appointments');
$joomlaDb = $container->get(\Joomla\Database\DatabaseInterface::class);
