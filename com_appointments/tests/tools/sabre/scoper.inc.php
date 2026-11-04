<?php

/**
 * php-scoper configuration of the bundled sabre/dav copy.
 *
 * Every class, interface, function and constant of sabre/* and psr/log moves
 * below Advans\Component\Appointments\Vendor\, so the bundled copy can never
 * collide with another extension's sabre/dav or sabre/vobject, nor with the
 * psr/log of Joomla itself. php-scoper rewrites code references; the patchers
 * below rewrite the few class and function names that sabre keeps in plain
 * strings. DavVendorTest checks that no unprefixed sabre or psr name is left.
 *
 * Used by generate.php only; not shipped.
 */

declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

$vendor = __DIR__ . '/vendor';
$prefix = 'Advans\\Component\\Appointments\\Vendor';

/*
 * Code that the CalDAV server never loads and that only adds surface: the SQL
 * backends (the component has its own), CardDAV, the HTML browser plugin (its
 * two interfaces stay, the XML properties implement them), the file system
 * nodes and the command line tool.
 */
$excluded = [
    'sabre/dav/lib/CardDAV',
    'sabre/dav/lib/DAV/Browser/assets',
    'sabre/dav/lib/DAV/Browser/GuessContentType.php',
    'sabre/dav/lib/DAV/Browser/MapGetToPropFind.php',
    'sabre/dav/lib/DAV/Browser/Plugin.php',
    'sabre/dav/lib/DAV/Browser/PropFindAll.php',
    'sabre/dav/lib/CalDAV/Backend/PDO.php',
    'sabre/dav/lib/CalDAV/Backend/SimplePDO.php',
    'sabre/dav/lib/DAV/Auth/Backend/PDO.php',
    'sabre/dav/lib/DAV/Auth/Backend/File.php',
    'sabre/dav/lib/DAV/Auth/Backend/Apache.php',
    'sabre/dav/lib/DAV/Auth/Backend/IMAP.php',
    'sabre/dav/lib/DAV/Locks/Backend/PDO.php',
    'sabre/dav/lib/DAV/Locks/Backend/File.php',
    'sabre/dav/lib/DAV/PropertyStorage/Backend/PDO.php',
    'sabre/dav/lib/DAVACL/PrincipalBackend/PDO.php',
    'sabre/dav/lib/DAVACL/FS',
    'sabre/dav/lib/DAV/FS',
    'sabre/dav/lib/DAV/FSExt',
    'sabre/vobject/lib/Cli.php',
];

$finder = Finder::create()
    ->files()
    ->name('*.php')
    ->in([
        $vendor . '/sabre/dav/lib',
        $vendor . '/sabre/event/lib',
        $vendor . '/sabre/http/lib',
        $vendor . '/sabre/uri/lib',
        $vendor . '/sabre/vobject/lib',
        $vendor . '/sabre/xml/lib',
        $vendor . '/psr/log/src',
    ])
    ->filter(static function (\SplFileInfo $file) use ($vendor, $excluded): bool {
        $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($vendor) + 1));

        foreach ($excluded as $path) {
            if ($relative === $path || str_starts_with($relative, $path . '/')) {
                return false;
            }
        }

        return true;
    })
    ->sortByName();

return [
    'prefix'      => $prefix,
    'output-dir'  => __DIR__ . '/.build/scoped',
    'finders'     => [$finder],
    'patchers'    => [
        /*
         * Class and function names in plain strings: string callables of
         * the XML deserialisers ('Sabre\Xml\Deserializer\enum'), the default
         * element class of sabre/xml, the content negotiation callbacks of
         * sabre/http and the like. A name in a string with one or two
         * backslashes between its parts, starting with Sabre\ or Psr\Log\,
         * is a symbol of this bundle and gets the prefix.
         */
        static function (string $filePath, string $prefix, string $contents): string {
            $quotedPrefix = str_replace('\\', '\\\\', $prefix);

            return (string) preg_replace_callback(
                '/(?<quote>[\'"])(?<lead>\\\\{0,2})(?<name>(?:Sabre|Psr\\\\{1,2}Log)\\\\{1,2}[A-Za-z_][A-Za-z0-9_\\\\]*)/',
                static function (array $m) use ($prefix, $quotedPrefix): string {
                    if (str_starts_with($m['name'], 'Advans')) {
                        return $m[0];
                    }

                    $double = str_contains($m['name'], '\\\\');

                    return $m['quote'] . $m['lead'] . ($double ? $quotedPrefix . '\\\\' : $prefix . '\\') . $m['name'];
                },
                $contents
            );
        },
        /*
         * Joomla's guard against a direct call of a shipped PHP file through the
         * web server: right after the namespace (or the strict types
         * declaration, or the opening tag), so the file ends before it runs
         * anything when Joomla did not load it.
         */
        static function (string $filePath, string $prefix, string $contents): string {
            $guard = "\\defined('_JEXEC') or die;\n";

            if (preg_match('/^namespace [^;{]+;\n/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $at = $match[0][1] + \strlen($match[0][0]);
            } elseif (preg_match('/^declare ?\(strict_types ?= ?1\);\n/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
                $at = $match[0][1] + \strlen($match[0][0]);
            } else {
                $at = strpos($contents, "<?php\n") === 0 ? 6 : 0;
            }

            return substr($contents, 0, $at) . $guard . substr($contents, $at);
        },
    ],
    'exclude-namespaces' => [],
    'exclude-classes'    => [],
    'exclude-functions'  => [],
    'exclude-constants'  => [],
    'expose-global-constants' => false,
    'expose-global-classes'   => false,
    'expose-global-functions' => false,
];
