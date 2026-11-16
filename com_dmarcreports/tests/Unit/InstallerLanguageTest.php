<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Before the installer runs preflight(), Joomla loads only the .sys.ini of the
 * component (InstallerAdapter::doLoadLanguage). A message preflight() shows when
 * it refuses an install must therefore come from that file, or the
 * administrator sees the raw key.
 *
 * @since  1.2.0
 */
class InstallerLanguageTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['de-DE' => ['de-DE'], 'en-GB' => ['en-GB'], 'fr-FR' => ['fr-FR']];
    }

    /**
     * @return list<string>
     */
    private static function preflightKeys(): array
    {
        $script = (string) file_get_contents(self::ROOT . '/script.php');

        self::assertSame(1, preg_match('/function preflight\(.*?\R    }\R/s', $script, $body));
        preg_match_all("/'(COM_DMARCREPORTS_[A-Z0-9_]+)'/", $body[0], $keys);

        return array_values(array_unique($keys[1]));
    }

    /**
     * @dataProvider locales
     */
    public function testPreflightMessagesComeFromTheSystemCatalogue(string $locale): void
    {
        $keys = self::preflightKeys();
        self::assertNotEmpty($keys);

        $system = parse_ini_file(
            self::ROOT . "/administrator/language/{$locale}/com_dmarcreports.sys.ini",
            false,
            INI_SCANNER_RAW
        );
        self::assertIsArray($system);

        foreach ($keys as $key) {
            self::assertNotEmpty($system[$key] ?? '', "{$locale}: {$key} is not in com_dmarcreports.sys.ini");
        }
    }
}
