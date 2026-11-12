<?php
if (PHP_SAPI !== 'cli' || !getenv('PRIVACY_TEST_ROOT')) {
    throw new RuntimeException('An explicit isolated native test root is required.');
}
define('_JEXEC', 1);
define('JPATH_BASE', getenv('PRIVACY_TEST_ROOT'));
require JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/index.php';
require JPATH_BASE . '/includes/framework.php';
$container = \Joomla\CMS\Factory::getContainer();
$container->alias('session', 'session.web.site')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.web.site');
$app = $container->get(\Joomla\CMS\Application\SiteApplication::class);
\Joomla\CMS\Factory::$application = $app;
if ($app->get('sitename') !== 'Privacy Test Fixture') {
    throw new RuntimeException('Refusing to test a non-synthetic site.');
}
$app->createExtensionNamespaceMap();
$app->loadLanguage($container->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)->createLanguage('en-GB'));
$app->getSession()->start();
$db = $container->get(\Joomla\Database\DatabaseInterface::class);

function privacyAssert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
    echo "PASS $label\n";
}
