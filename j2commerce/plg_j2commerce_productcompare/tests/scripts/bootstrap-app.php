<?php
/**
 * Shared CLI test bootstrap.
 *
 * The render/asset/event test scripts run as plain CLI processes
 * (`php tests/scripts/NN-*.php`). In that context Joomla never starts a web
 * application, so the global application singleton is empty and
 * Joomla\CMS\Factory::getApplication() throws "Failed to start application"
 * (true on both Joomla 5.4 and Joomla 6.1 — the method takes no arguments and
 * only returns the already-created singleton).
 *
 * This helper builds a REAL SiteApplication directly (input + config +
 * dispatcher, using the CMS DI container for the services) and registers it as
 * the global application so the plugin's $this->getApplication() resolves to a
 * genuine site application. It deliberately avoids the container's
 * "JApplicationSite" factory, which also requires the HTTP session service that
 * is not registered in a CLI process.
 *
 * It also seeds a lightweight template descriptor so SiteApplication::getTemplate()
 * (called from the plugin's renderLayout() to build the override include path)
 * short-circuits to the bundled 'cassiopeia' template instead of querying the
 * live menu/router/#__template_styles stack, which is not available in CLI.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

if (!function_exists('bootstrapSiteApplication')) {
    /**
     * Build (once) a real SiteApplication and register it as the global app.
     */
    function bootstrapSiteApplication(): SiteApplication
    {
        if (Factory::$application instanceof SiteApplication) {
            return Factory::$application;
        }

        $container = Factory::getContainer();

        // Construct directly (input + config + dispatcher) rather than via the
        // container's "JApplicationSite" factory: that factory also wires the HTTP
        // session service (SessionInterface), which is not registered in a CLI
        // process. The render / asset / event paths exercised here never touch the
        // session, so a session is not required.
        //
        // The input service is registered under different class names across
        // versions (Joomla\CMS\Input\Input on J5, Joomla\Input\Input on J6), so
        // resolve whichever the container actually provides; fall back to the
        // application's own default when neither is registered.
        $input = null;
        foreach (['Joomla\\CMS\\Input\\Input', 'Joomla\\Input\\Input'] as $inputClass) {
            try {
                if ($container->has($inputClass)) {
                    $input = $container->get($inputClass);
                    break;
                }
            } catch (\Throwable $e) {
                // try the next candidate
            }
        }

        $app = new SiteApplication($input, $container->get('config'), null, $container);
        $app->setDispatcher($container->get(DispatcherInterface::class));

        // cassiopeia ships with every Joomla 5/6 install, so the is_file() guard
        // inside getTemplate() passes and it returns the name without touching the
        // menu/router/template-styles stack (unavailable in a CLI process).
        $tpl           = new \stdClass();
        $tpl->id       = 0;
        $tpl->template = 'cassiopeia';
        $tpl->parent   = '';
        $tpl->params   = new Registry();

        $rp = new \ReflectionProperty($app, 'template');
        $rp->setAccessible(true);
        $rp->setValue($app, $tpl);

        Factory::$application = $app;

        return $app;
    }
}
