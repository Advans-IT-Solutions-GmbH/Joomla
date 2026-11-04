<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * Autoloader of the bundled, namespace-prefixed sabre/dav libraries in
 * `administrator/components/com_appointments/lib/` (work package A).
 *
 * The libraries are prefixed with `Advans\Component\Appointments\Vendor\`
 * (php-scoper), so they can never collide with another extension that ships
 * its own copy of sabre/dav or sabre/vobject, nor with Joomla's own vendor
 * folder. Every caller that needs sabre (the CalDAV plugin, the timing index)
 * calls register() first; a second call does nothing.
 *
 * @since  1.4.0
 */
final class VendorAutoloader
{
    /** Namespace prefix of the bundled libraries. */
    public const PREFIX = 'Advans\\Component\\Appointments\\Vendor\\';

    /**
     * Result of the first call, so later calls neither register twice nor
     * load the function files twice.
     *
     * @var  bool|null
     */
    private static $registered = null;

    /**
     * @return  bool  Whether the bundled libraries are present and registered.
     */
    public static function register(): bool
    {
        if (self::$registered !== null) {
            return self::$registered;
        }

        $lib = \dirname(__DIR__, 3) . '/lib';
        $map = is_file($lib . '/autoload.php') ? require $lib . '/autoload.php' : null;

        if (!\is_array($map) || !\is_array($map['psr4'] ?? null) || !\is_array($map['files'] ?? null)) {
            return self::$registered = false;
        }

        $namespaces = [];

        foreach ($map['psr4'] as $namespace => $path) {
            // Only the bundle's own prefix, so a broken map can never claim another namespace.
            if (\is_string($namespace) && \is_string($path) && str_starts_with($namespace, self::PREFIX)) {
                $namespaces[$namespace] = $lib . '/' . $path . '/';
            }
        }

        if ($namespaces === []) {
            return self::$registered = false;
        }

        spl_autoload_register(static function (string $class) use ($namespaces): void {
            // A class name never holds a dot or a slash, so a crafted name cannot leave lib/.
            if (!str_starts_with($class, self::PREFIX) || preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) !== 1) {
                return;
            }

            // The map is ordered longest namespace first.
            foreach ($namespaces as $namespace => $directory) {
                if (str_starts_with($class, $namespace)) {
                    $file = $directory . str_replace('\\', '/', substr($class, \strlen($namespace))) . '.php';

                    if (is_file($file)) {
                        require_once $file;
                    }

                    return;
                }
            }
        });

        foreach ($map['files'] as $file) {
            if (\is_string($file)) {
                require_once $lib . '/' . $file;
            }
        }

        return self::$registered = true;
    }
}
