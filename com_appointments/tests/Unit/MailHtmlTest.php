<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/MailHtml.php';

final class MailHtmlTest extends TestCase
{
    public function testPlainTextSiteKeepsTheTextMail(): void
    {
        $this->assertNull(MailHtml::render("Hello\n\nWorld", 'de-DE', 'Site', ['mail_style' => 'plaintext']));
        $this->assertNull(MailHtml::render("Hello", 'de-DE', 'Site', []));
    }

    public function testTextBecomesEscapedParagraphsWithClickableLinks(): void
    {
        $html = MailHtml::textToHtml("Guten Tag <Erika>,\n\nIhr Termin ist bestätigt.\nVon: 15.10.2026 10:00\n\nTermin absagen: https://example.test/index.php?option=com_appointments&view=manage&id=1");

        $this->assertStringContainsString('<p>Guten Tag &lt;Erika&gt;,</p>', $html);
        $this->assertStringContainsString("<p>Ihr Termin ist bestätigt.<br>\nVon: 15.10.2026 10:00</p>", $html);
        $this->assertStringContainsString(
            '<a href="https://example.test/index.php?option=com_appointments&amp;view=manage&amp;id=1">https://example.test/index.php?option=com_appointments&amp;view=manage&amp;id=1</a>',
            $html
        );
        $this->assertStringNotContainsString('<Erika>', $html);
    }

    public function testNoScriptOrAttributeInjectionThroughLinks(): void
    {
        $html = MailHtml::textToHtml('https://example.test/"onmouseover="x <script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"onmouseover', $html);
    }
}
