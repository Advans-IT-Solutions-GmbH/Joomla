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

    public function testSignedLinkInAngleBracketsKeepsItsExactTarget(): void
    {
        $html = MailHtml::textToHtml('Termin absagen: <https://example.test/manage?id=1&signature=abc>');

        $this->assertStringContainsString('<a href="https://example.test/manage?id=1&amp;signature=abc">', $html);
        $this->assertStringContainsString('&lt;<a href=', $html);
        $this->assertStringContainsString('</a>&gt;', $html);
    }

    public function testTrailingProsePunctuationStaysOutsideTheLink(): void
    {
        $html = MailHtml::textToHtml('Join (https://example.test/room?token=abc).');
        $this->assertStringContainsString('(<a href="https://example.test/room?token=abc">https://example.test/room?token=abc</a>).', $html);

        $html = MailHtml::textToHtml('See https://example.test/wiki/A_(b), then https://example.test/x?y=1!');
        $this->assertStringContainsString('<a href="https://example.test/wiki/A_(b)">', $html);
        $this->assertStringContainsString('<a href="https://example.test/x?y=1">https://example.test/x?y=1</a>!', $html);
    }

    public function testKnownUrlsAreLinkedExactly(): void
    {
        $room = "https://example.test/room/O'Brien?token=secret!";
        $html = MailHtml::textToHtml("Join: $room", [$room]);

        $this->assertStringContainsString('<a href="https://example.test/room/O&#039;Brien?token=secret!">', $html);
        $this->assertStringNotContainsString("<script", MailHtml::textToHtml('<script>', [$room]));
    }

    public function testKnownUrlDoesNotCutALongerUrlWithTheSamePrefix(): void
    {
        $html = MailHtml::textToHtml('Help: https://example.test/room/help and join https://example.test/room.', ['https://example.test/room']);

        $this->assertStringContainsString('<a href="https://example.test/room/help">https://example.test/room/help</a>', $html);
        $this->assertStringContainsString('<a href="https://example.test/room">https://example.test/room</a>.', $html);
    }

    public function testRelativeLayoutUrlsResolveAgainstThePublicSiteUrl(): void
    {
        $html = MailHtml::absolutize('<img src="images/logo.png"><a href="/de/">x</a><a href="https://a.test/">y</a><a href="mailto:a@b.test">z</a>', 'https://shop.example.test/sub/');

        $this->assertStringContainsString('src="https://shop.example.test/sub/images/logo.png"', $html);
        $this->assertStringContainsString('href="https://shop.example.test/de/"', $html);
        $this->assertStringContainsString('href="https://a.test/"', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
    }

    public function testNoScriptOrAttributeInjectionThroughLinks(): void
    {
        $html = MailHtml::textToHtml('https://example.test/"onmouseover="x <script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"onmouseover', $html);
    }
}
