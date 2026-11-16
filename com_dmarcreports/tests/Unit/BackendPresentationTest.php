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
 * Backend presentation guard found during the 1.2.0 backend validation on a test
 * installation: the first Options tab showed the raw key COM_CONFIG_basic_FIELDSET_LABEL
 * and the page title the raw key com_dmarcreports_configuration, because the "basic"
 * fieldset had no label and the title key was missing. At 375 px the long raw title also
 * pushed the page wider than the screen, and the Systemcheck table was not wrapped in a
 * scroll container.
 *
 * @since  1.2.0
 */
class BackendPresentationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../administrator';

    /**
     * @return array<string, string>
     */
    private static function strings(string $locale): array
    {
        $strings = parse_ini_file(self::ROOT . "/language/{$locale}/com_dmarcreports.ini", false, INI_SCANNER_RAW);
        self::assertIsArray($strings);

        return $strings;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['de-DE' => ['de-DE'], 'en-GB' => ['en-GB'], 'fr-FR' => ['fr-FR']];
    }

    public function testEveryOptionsFieldsetHasATranslatedLabel(): void
    {
        $xml = simplexml_load_file(self::ROOT . '/components/com_dmarcreports/config.xml');
        $this->assertNotFalse($xml);

        $fieldsets = $xml->xpath('//fieldset');
        $this->assertNotEmpty($fieldsets);

        foreach ($fieldsets as $fieldset) {
            $name  = (string) $fieldset['name'];
            $label = (string) $fieldset['label'];
            $this->assertNotSame('', $label, "Options fieldset \"{$name}\" has no label, so Joomla shows a raw COM_CONFIG_..._FIELDSET_LABEL key.");

            if (strpos($label, 'COM_DMARCREPORTS_') !== 0) {
                continue; // Joomla core label such as JCONFIG_PERMISSIONS_LABEL
            }

            foreach (array_keys(self::locales()) as $locale) {
                $this->assertArrayHasKey($label, self::strings($locale), "{$label} missing in {$locale}");
                $this->assertNotSame('', trim(self::strings($locale)[$label]));
            }
        }
    }

    /**
     * @dataProvider locales
     */
    public function testOptionsPageTitleIsTranslated(string $locale): void
    {
        $strings = self::strings($locale);

        $this->assertArrayHasKey('COM_DMARCREPORTS_CONFIGURATION', $strings);
        $this->assertNotSame('', trim($strings['COM_DMARCREPORTS_CONFIGURATION']));
    }

    public function testSystemcheckTableScrollsInsteadOfWideningThePage(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/components/com_dmarcreports/tmpl/systemcheck/default.php');

        $this->assertMatchesRegularExpression(
            '#<div class="table-responsive">\s*<table class="table table-striped">#',
            $template
        );
    }
}