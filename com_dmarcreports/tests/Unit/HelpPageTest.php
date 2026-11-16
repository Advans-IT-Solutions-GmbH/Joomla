<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\HelpTopics;
use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2) . '/administrator/components/com_dmarcreports/src/Helper/HelpTopics.php';

/**
 * The component help page: it renders in every shipped language, it is linked
 * from the submenu, from the toolbar of every view and from the edit screens of
 * the module and the plugins, and it explains every Action Center entry the code
 * can emit. A new Action Center code without a help entry fails here.
 *
 * The template is rendered in a separate PHP process (Fixtures/render-help.php)
 * so its Joomla stand-ins stay out of this process.
 *
 * @since  1.3.0
 */
class HelpPageTest extends TestCase
{
    private const COMPONENT = __DIR__ . '/../../administrator/components/com_dmarcreports';

    private const LANGUAGE = __DIR__ . '/../../administrator/language';

    private const REPO = __DIR__ . '/../../..';

    private const LOCALES = ['de-DE', 'en-GB', 'fr-FR'];

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return ['de-DE' => ['de-DE'], 'en-GB' => ['en-GB'], 'fr-FR' => ['fr-FR']];
    }

    /**
     * @return array<string, string>
     */
    private static function strings(string $file): array
    {
        $strings = parse_ini_file($file, false, INI_SCANNER_RAW);
        self::assertIsArray($strings, $file);

        return $strings;
    }

    /**
     * Every code the Action Center can show, read from the code that emits it:
     * the rollout engine, the MTA-STS/TLS-RPT diagnostics and the DNS diagnostics.
     *
     * @return string[]
     */
    private static function emittedCodes(): array
    {
        $sources = [
            '/src/Helper/ActionCenter.php'      => "/self::item\\(\\s*'([a-z_]+)'/",
            '/src/Helper/MtaStsDiagnostics.php' => "/->finding\\(\\s*'([a-z_]+)'/",
            '/src/Helper/DnsDiagnostics.php'    => "/'code'\\s*=>\\s*'([a-z_]+)'/",
        ];
        $codes = [];

        foreach ($sources as $file => $pattern) {
            $php = (string) file_get_contents(self::COMPONENT . $file);
            preg_match_all($pattern, $php, $matches);
            self::assertNotEmpty($matches[1], "No Action Center codes found in {$file}; the pattern is out of date.");
            $codes = array_merge($codes, $matches[1]);
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    public function testEveryActionCenterCodeHasAHelpEntry(): void
    {
        $documented = HelpTopics::actionCodes();
        sort($documented);

        $this->assertSame([], array_values(array_diff(self::emittedCodes(), $documented)), 'Action Center codes without a help entry in HelpTopics::ACTION_GROUPS');
        $this->assertSame([], array_values(array_diff($documented, self::emittedCodes())), 'Help entries for codes the Action Center never emits');
        $this->assertSame(\count($documented), \count(array_unique($documented)), 'A code is listed in more than one help group');
    }

    public function testEveryHelpEntryHasItsOwnPresenterTitle(): void
    {
        // The help page shows each entry under the title the Action Center uses;
        // a code without its own arm would fall back to the generic title.
        $presenter = (string) file_get_contents(self::COMPONENT . '/src/Helper/ActionPresenter.php');

        foreach (HelpTopics::actionCodes() as $code) {
            $this->assertMatchesRegularExpression("/'{$code}'\\s*=>\\s*Text::_\\('COM_DMARCREPORTS_ACTION_/", $presenter, $code);
        }
    }

    /**
     * @dataProvider locales
     */
    public function testEveryHelpKeyIsTranslated(string $locale): void
    {
        $strings  = self::strings(self::LANGUAGE . "/{$locale}/com_dmarcreports.ini");
        $template = (string) file_get_contents(self::COMPONENT . '/tmpl/help/default.php');
        preg_match_all("/'(COM_DMARCREPORTS_[A-Z0-9_]+)'/", $template, $matches);

        $keys = $matches[1];

        foreach (HelpTopics::ACTION_GROUPS as $group => $entries) {
            $keys[] = $group;
            $keys   = array_merge($keys, array_values($entries));
        }

        foreach (array_unique($keys) as $key) {
            $this->assertArrayHasKey($key, $strings, "{$key} missing in {$locale}");
            $this->assertNotSame('', trim($strings[$key]), "{$key} empty in {$locale}");
        }
    }

    /**
     * Run the render fixture and return its output.
     */
    private function renderFixture(string ...$args): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/Fixtures/render-help.php');

        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $output = [];
        $code   = 0;
        exec($cmd . ' 2>&1', $output, $code);
        $out = implode("\n", $output);

        $this->assertSame(0, $code, "Rendering the help page failed:\n" . $out);

        foreach (['Warning:', 'Notice:', 'Deprecated:', 'Fatal error'] as $problem) {
            $this->assertStringNotContainsString($problem, $out);
        }

        return $out;
    }

    /**
     * @dataProvider locales
     */
    public function testHelpPageRendersEverySectionAndEveryActionEntry(string $locale): void
    {
        $html = $this->renderFixture('page', $locale, '1');

        foreach (['basics', 'how', 'actions', 'tasks', 'settings'] as $section) {
            $this->assertStringContainsString('id="help-' . $section . '"', $html);
        }

        foreach (HelpTopics::actionCodes() as $code) {
            $this->assertStringContainsString('id="help-action-' . $code . '"', $html, "No help entry rendered for {$code}");
        }

        // Every key resolved: no raw language key reaches the page.
        $this->assertDoesNotMatchRegularExpression('/COM_DMARCREPORTS_[A-Z0-9_]+/', $html);
        $this->assertStringContainsString('option=com_config&view=component&component=com_dmarcreports', $html);
    }

    public function testOptionsLinkIsHiddenWithoutThePermission(): void
    {
        $html = $this->renderFixture('page', 'en-GB', '0');

        $this->assertStringNotContainsString('option=com_config', $html);
    }

    public function testSubmenuLinksTheHelpPage(): void
    {
        $xml = simplexml_load_file(self::COMPONENT . '/../../../dmarcreports.xml');
        $this->assertNotFalse($xml);

        $label = null;

        foreach ($xml->xpath('/extension/administration/submenu/menu') as $menu) {
            if ((string) $menu['link'] === 'option=com_dmarcreports&view=help') {
                $label = trim((string) $menu);
            }
        }

        $this->assertNotNull($label, 'The component submenu has no entry for view=help');

        foreach (self::LOCALES as $locale) {
            $this->assertArrayHasKey($label, self::strings(self::LANGUAGE . "/{$locale}/com_dmarcreports.sys.ini"), "{$label} missing in {$locale} sys.ini");
        }
    }

    public function testEveryViewToolbarOffersTheHelpButton(): void
    {
        $views = glob(self::COMPONENT . '/src/View/*/HtmlView.php') ?: [];
        $this->assertNotEmpty($views);

        foreach ($views as $file) {
            if (basename(\dirname($file)) === 'Help') {
                continue;
            }

            $this->assertStringContainsString(
                "->help('COM_DMARCREPORTS_HELP_TITLE', false, '" . HelpTopics::POPUP_URL . "')",
                (string) file_get_contents($file),
                basename(\dirname($file)) . ' view has no Help button that opens the help page'
            );
        }
    }

    public function testHelpButtonAddressOpensTheHelpPageWithoutTheMenu(): void
    {
        parse_str((string) parse_url(HelpTopics::POPUP_URL, PHP_URL_QUERY), $query);
        $this->assertSame(['option' => 'com_dmarcreports', 'view' => 'help', 'tmpl' => 'component'], $query);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function companions(): array
    {
        return [
            'module'        => ['mod_dmarcreports/mod_dmarcreports.xml', 'mod_dmarcreports/language/%s/mod_dmarcreports.ini', 'MOD_DMARCREPORTS_HELP_NOTE'],
            'task plugin'   => ['plg_task_dmarcreports/dmarcreports.xml', 'plg_task_dmarcreports/language/%s/plg_task_dmarcreports.ini', 'PLG_TASK_DMARCREPORTS_HELP_NOTE'],
            'system plugin' => ['plg_system_dmarcmtasts/dmarcmtasts.xml', 'plg_system_dmarcmtasts/language/%s/plg_system_dmarcmtasts.ini', 'PLG_SYSTEM_DMARCMTASTS_HELP_NOTE'],
        ];
    }

    /**
     * @dataProvider companions
     */
    public function testCompanionEditScreensLinkTheHelpPage(string $manifest, string $ini, string $noteKey): void
    {
        $xml = simplexml_load_file(self::REPO . '/' . $manifest);
        $this->assertNotFalse($xml);

        // Toolbar Help button of the module/plugin edit screen.
        // Joomla translates <help url> when it is a language key, as the core
        // module and plugin edit views do.
        $help = $xml->xpath('/extension/help');
        $this->assertCount(1, $help, "{$manifest} has no <help> element");
        $urlKey = (string) $help[0]['url'];

        // A note at the top of the main options tab with a link to the page.
        $fields = $xml->xpath('/extension/config/fields[@name="params"]/fieldset[@name="basic"]/field');
        $this->assertNotEmpty($fields);
        $this->assertSame('note', (string) $fields[0]['type'], "The first option of {$manifest} is not the help note");
        $this->assertSame($noteKey, (string) $fields[0]['description']);
        $this->assertSame('unset', (string) $fields[0]['filter']);

        foreach (self::LOCALES as $locale) {
            $strings = self::strings(self::REPO . '/' . sprintf($ini, $locale));
            $this->assertSame(HelpTopics::POPUP_URL, $strings[$urlKey] ?? null, "{$urlKey} in {$locale}");
            $this->assertArrayHasKey($noteKey, $strings, "{$noteKey} missing in {$locale}");
            $this->assertStringContainsString("href='index.php?option=com_dmarcreports&amp;view=help'", $strings[$noteKey]);
        }
    }

    public function testComponentOptionsLinkTheHelpPage(): void
    {
        $xml = simplexml_load_file(self::COMPONENT . '/config.xml');
        $this->assertNotFalse($xml);

        $first = $xml->xpath('/config/fieldset[@name="basic"]/field')[0] ?? null;
        $this->assertNotNull($first);
        $this->assertSame('note', (string) $first['type']);
        $this->assertSame('unset', (string) $first['filter']);

        foreach (self::LOCALES as $locale) {
            $note = self::strings(self::LANGUAGE . "/{$locale}/com_dmarcreports.ini")[(string) $first['description']] ?? '';
            $this->assertStringContainsString("href='index.php?option=com_dmarcreports&amp;view=help'", $note, $locale);
        }
    }
}
