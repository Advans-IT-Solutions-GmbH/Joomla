<?php
/**
 * @package     DMARC Reports Dashboard Module Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The module editor of com_modules loads only the main catalogue
 * (ModuleModel::preprocessForm) and prints the manifest <name> as the module
 * type and in the toolbar title. The name key therefore has to be defined in
 * the main .ini as well, not only in the .sys.ini, or the editor shows the raw
 * key MOD_DMARCREPORTS.
 */
final class LanguageCatalogueTest extends TestCase
{
    public static function languageTags(): array
    {
        return [['de-DE'], ['en-GB'], ['fr-FR']];
    }

    #[DataProvider('languageTags')]
    public function testManifestNameIsInTheMainCatalogue(string $tag): void
    {
        $root     = \dirname(__DIR__, 2);
        $manifest = simplexml_load_file($root . '/mod_dmarcreports.xml');

        self::assertNotFalse($manifest);

        $key    = strtoupper((string) $manifest->name);
        $main   = parse_ini_file($root . '/language/' . $tag . '/mod_dmarcreports.ini', false, INI_SCANNER_RAW);
        $system = parse_ini_file($root . '/language/' . $tag . '/mod_dmarcreports.sys.ini', false, INI_SCANNER_RAW);

        self::assertSame('MOD_DMARCREPORTS', $key);
        self::assertIsArray($main);
        self::assertIsArray($system);
        self::assertNotEmpty($main[$key] ?? '', "$tag main catalogue lacks $key");
        self::assertSame($system[$key] ?? null, $main[$key], "$tag: $key differs between .ini and .sys.ini");
    }
}
