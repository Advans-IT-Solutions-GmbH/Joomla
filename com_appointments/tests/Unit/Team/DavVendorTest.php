<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use PHPUnit\Framework\TestCase;

/**
 * The bundled, namespace-prefixed sabre/dav copy in lib/: it loads, every
 * class name sabre keeps in a string points into the prefixed copy, and the
 * notices name every library with its version.
 */
final class DavVendorTest extends TestCase
{
    private const PREFIX = 'Advans\\Component\\Appointments\\Vendor\\';

    private static function lib(): string
    {
        return dirname(__DIR__, 3) . '/administrator/components/com_appointments/lib';
    }

    /**
     * @return array{packages: array<string, array{version: string, license: string}>, psr4: array<string, string>, files: string[]}
     */
    private static function map(): array
    {
        return require self::lib() . '/autoload.php';
    }

    public function testAutoloaderRegistersOnceAndLoadsTheServer(): void
    {
        self::assertSame(VendorAutoloader::PREFIX, self::PREFIX);
        self::assertTrue(VendorAutoloader::register());
        self::assertTrue(VendorAutoloader::register());
        self::assertTrue(class_exists(self::PREFIX . 'Sabre\\DAV\\Server'));
        self::assertTrue(class_exists(self::PREFIX . 'Sabre\\VObject\\Component\\VCalendar'));
        self::assertTrue(function_exists(self::PREFIX . 'Sabre\\Uri\\split'));
        self::assertTrue(function_exists(self::PREFIX . 'Sabre\\Xml\\Deserializer\\enum'));
        self::assertTrue(interface_exists(self::PREFIX . 'Psr\\Log\\LoggerInterface'));
    }

    public function testACraftedClassNameNeverLeavesTheBundle(): void
    {
        VendorAutoloader::register();
        $lib = str_replace('\\', '/', self::lib());
        $loaded = static fn (): array => array_values(array_filter(
            array_map(static fn (string $f): string => str_replace('\\', '/', $f), get_included_files()),
            static fn (string $f): bool => str_starts_with($f, $lib)
        ));
        $before = $loaded();

        $crafted = [
            class_exists(self::PREFIX . 'Sabre/../../autoload', true),
            class_exists(self::PREFIX . 'Sabre\\..\\..\\autoload', true),
            class_exists('Sabre\\DAV\\Server', true),
        ];

        self::assertSame([false, false, false], $crafted);
        self::assertSame($before, $loaded());
    }

    public function testTheMapClaimsOnlyThePrefixedNamespace(): void
    {
        $map = self::map();

        self::assertNotEmpty($map['psr4']);

        foreach ($map['psr4'] as $namespace => $path) {
            self::assertStringStartsWith(self::PREFIX, $namespace);
            self::assertDirectoryExists(self::lib() . '/' . $path);
        }

        foreach ($map['files'] as $file) {
            self::assertFileExists(self::lib() . '/' . $file);
        }
    }

    /**
     * Every class file of the bundle loads, so no shipped class depends on a
     * part that was left out of the bundle.
     */
    public function testEveryBundledClassLoads(): void
    {
        VendorAutoloader::register();
        $map = self::map();
        $checked = 0;

        foreach ($map['psr4'] as $namespace => $path) {
            $root = self::lib() . '/' . $path;

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

                // Function files and the time zone tables start with a lower-case letter.
                if (!str_ends_with($relative, '.php') || !ctype_upper(basename($relative)[0])) {
                    continue;
                }

                $class = $namespace . str_replace('/', '\\', substr($relative, 0, -4));

                self::assertTrue(
                    class_exists($class) || interface_exists($class) || trait_exists($class),
                    "Bundled symbol does not load: {$class}"
                );
                $checked++;
            }
        }

        self::assertGreaterThan(250, $checked);
    }

    /**
     * sabre names classes and functions in strings (XML element maps, value
     * maps of vobject, content negotiation callbacks). Each of them must point
     * into the prefixed copy and exist.
     */
    public function testEveryMappedNameExistsInThePrefixedCopy(): void
    {
        VendorAutoloader::register();
        $dav = self::PREFIX . 'Sabre\\DAV\\';
        $server = new ($dav . 'Server')(new ($dav . 'SimpleCollection')('root'));
        $server->addPlugin(new ($dav . 'Auth\\Plugin')());
        $server->addPlugin(new (self::PREFIX . 'Sabre\\DAVACL\\Plugin')());
        $server->addPlugin(new (self::PREFIX . 'Sabre\\CalDAV\\Plugin')());
        $server->addPlugin(new ($dav . 'Sync\\Plugin')());
        $names = array_filter($server->xml->elementMap, 'is_string');

        $vcalendar = self::PREFIX . 'Sabre\\VObject\\Component\\VCalendar';

        foreach (['componentMap', 'valueMap', 'propertyMap'] as $property) {
            foreach ($vcalendar::$$property as $name) {
                $names[] = $name;
            }
        }

        self::assertGreaterThan(50, count($names));

        foreach ($names as $name) {
            self::assertStringStartsWith(self::PREFIX, ltrim($name, '\\'), "Unprefixed name in a map: {$name}");
            self::assertTrue(class_exists($name) || function_exists($name), "Mapped name does not exist: {$name}");
        }
    }

    /**
     * Every file is prefixed and stops at once when the web server calls it directly.
     */
    public function testNoStringStartsWithAnUnprefixedSabreOrPsrName(): void
    {
        $found = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::lib(), FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!str_ends_with($file->getFilename(), '.php')) {
                continue;
            }

            $code = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/[\'"]\\\\{0,2}(?:Sabre|Psr\\\\{1,2}Log)\\\\/', $code, $matches)) {
                $found[] = $file->getFilename() . ': ' . implode(', ', $matches[0]);
            }

            if (preg_match('/^\s*(?:namespace|use)\s+\\\\?(?:Sabre|Psr)\\\\/m', $code)) {
                $found[] = $file->getFilename() . ': unprefixed namespace or import';
            }

            if (preg_match("/^\\\\?defined\('_JEXEC'\) or die;$/m", $code) !== 1) {
                $found[] = $file->getFilename() . ': no direct-access guard';
            }
        }

        self::assertSame([], $found);
    }

    public function testNoticesNameEveryBundledLibraryWithItsVersionAndLicence(): void
    {
        $notices = (string) file_get_contents(dirname(self::lib()) . '/THIRD-PARTY-NOTICES.txt');
        $packages = self::map()['packages'];

        self::assertArrayHasKey('sabre/dav', $packages);
        self::assertSame('4.7.1', $packages['sabre/dav']['version']);

        foreach ($packages as $name => $package) {
            self::assertMatchesRegularExpression(
                '#\| ' . preg_quote($name, '#') . ' +\| ' . preg_quote($package['version'], '#') . ' +\| ' . preg_quote($package['license'], '#') . ' +\|#',
                $notices,
                "THIRD-PARTY-NOTICES.txt lacks {$name} {$package['version']}"
            );
            self::assertFileExists(self::lib() . '/' . $name . '/LICENSE');
        }
    }
}
