<?php
/**
 * @package     OSMap J2Commerce Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH
 * @license     GNU GPL v3
 *
 * Shared test bootstrap that loads the REAL OSMap library (Collector, Item,
 * General) that is installed in the test image (see tests/Dockerfile and
 * tests/Dockerfile.joomla6, which install OSMap 5.1.6).
 *
 * Tests use these helpers so that the plugin is exercised against real OSMap
 * classes — its actual getPluginsForComponent() loader, getComponentElement()
 * matching and getTree() dispatch — instead of lightweight stubs.
 *
 * Stubs are only ever used as a last-resort fallback if OSMap cannot be loaded
 * at all (which would itself be a meaningful failure, surfaced by the tests).
 *
 * This file defines functions only; it is required by the individual test
 * scripts and is not part of the TEST_SCRIPTS run list.
 */

defined('_JEXEC') or die;

use Joomla\CMS\Application\SiteApplication;
use Joomla\CMS\Factory;

/**
 * Establish a real Joomla SiteApplication in the CLI context so that OSMap's
 * Factory::getDispatcher() and router work the same way they do when OSMap
 * renders a sitemap through Apache. Returns the application or null.
 */
function osmap_boot_application(): ?object
{
    try {
        $existing = Factory::getApplication();
        if ($existing !== null) {
            return $existing;
        }
    } catch (\Throwable $e) {
        // No application yet — create one below.
    }

    try {
        $app = Factory::getContainer()->get(SiteApplication::class);
        Factory::setApplication($app);
        return $app;
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Load the real OSMap library so that \Alledia\OSMap\Sitemap\Collector,
 * \Alledia\OSMap\Sitemap\Item and \Alledia\OSMap\Helper\General resolve to the
 * actual installed classes.
 *
 * Returns true when the real OSMap Collector class is available.
 */
function osmap_load_real_library(): bool
{
    if (
        class_exists('\\Alledia\\OSMap\\Sitemap\\Collector')
        && class_exists('\\Alledia\\OSMap\\Sitemap\\Item')
    ) {
        return true;
    }

    // Preferred path: OSMap's own bootstrap registers the Alledia autoloader
    // and defines the OSMAP_* constants, exactly as a real request does.
    $include = JPATH_ADMINISTRATOR . '/components/com_osmap/include.php';
    if (is_file($include)) {
        osmap_boot_application();
        try {
            include_once $include;
        } catch (\Throwable $e) {
            // Fall through to the direct-require fallback below.
        }
    }

    // Fallback: register the Joomlashack framework autoloader and require the
    // specific class files directly. Enough to obtain the real Collector/Item
    // classes even if OSMap's full bootstrap could not complete in CLI.
    if (!class_exists('\\Alledia\\OSMap\\Sitemap\\Collector')) {
        $framework = JPATH_SITE . '/libraries/allediaframework/include.php';
        if (is_file($framework)) {
            try {
                include_once $framework;
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $lib = JPATH_ADMINISTRATOR . '/components/com_osmap/library/Alledia/OSMap';
        foreach ([
            $lib . '/Sitemap/Collector.php',
            $lib . '/Sitemap/Item.php',
            $lib . '/Helper/General.php',
        ] as $classFile) {
            if (is_file($classFile)) {
                try {
                    require_once $classFile;
                } catch (\Throwable $e) {
                    // ignore — reported by class_exists() check below
                }
            }
        }
    }

    return class_exists('\\Alledia\\OSMap\\Sitemap\\Collector');
}

/**
 * Define minimal OSMap stubs ONLY if the real classes could not be loaded.
 * Returns true if real classes are in use, false if stubs were defined.
 */
function osmap_ensure_classes(): bool
{
    if (osmap_load_real_library()) {
        return true;
    }

    if (!class_exists('\\Alledia\\OSMap\\Sitemap\\Item')) {
        // @phpstan-ignore-next-line
        eval('namespace Alledia\OSMap\Sitemap; class Item { public $id = 0; public $path = ""; public $link = ""; public $component = ""; public $browserNav = 0; }');
    }
    if (!class_exists('\\Alledia\\OSMap\\Sitemap\\Collector')) {
        // @phpstan-ignore-next-line
        eval('namespace Alledia\OSMap\Sitemap; class Collector { public function printNode(object $node): bool { return true; } }');
    }

    return false;
}

/**
 * Build a real OSMap Item to act as the "parent" menu item passed to getTree(),
 * bypassing the heavy constructor (which routes URLs) while still producing a
 * genuine Item instance that satisfies the plugin's type hint.
 *
 * @param array<string,mixed> $props
 */
function osmap_make_item(array $props): object
{
    $rc   = new \ReflectionClass('\\Alledia\\OSMap\\Sitemap\\Item');
    $item = $rc->newInstanceWithoutConstructor();
    foreach ($props as $key => $value) {
        $item->$key = $value;
    }
    return $item;
}
