<?php

defined('_JEXEC') || define('_JEXEC', 1);
require_once __DIR__ . '/appointments-environment.php';
$appointmentsRoot = appointments_test_root();
if (defined('JPATH_BASE') && realpath(JPATH_BASE) !== $appointmentsRoot) {
    throw new RuntimeException('JPATH_BASE does not match APPOINTMENTS_TEST_ROOT.');
}
defined('JPATH_BASE') || define('JPATH_BASE', $appointmentsRoot);
require_once JPATH_BASE . '/includes/defines.php';
$appointmentsUrl = parse_url(appointments_test_base_url());
$_SERVER['HTTP_HOST'] = $appointmentsUrl['host'] . (isset($appointmentsUrl['port']) ? ':' . $appointmentsUrl['port'] : '');
$_SERVER['SCRIPT_NAME'] = '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$application = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $application;
$application->loadLanguage($container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB'));
\Joomla\CMS\Factory::$language = $application->getLanguage();
$application->createExtensionNamespaceMap();
$application->bootComponent('com_appointments');
$joomlaDb = $container->get(\Joomla\Database\DatabaseInterface::class);
