<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\MailHtml;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/MailHtml.php';

final class MailHtmlTest extends TestCase
{
    private const BREAK = ' style="word-break:break-all;overflow-wrap:anywhere"';

    public function testLabelledLinksShowTheLabelAndTakeTheLabelLineIntoTheLink(): void
    {
        $signed = 'https://example.test/index.php?option=com_appointments&view=manage&id=9&action=cancel&signature=' . str_repeat('a', 64);
        $erase  = str_replace('action=cancel', 'action=erase', $signed);
        $html   = MailHtml::textToHtml("Termin absagen:\n$signed\nIhre Daten löschen:\n$erase", [$signed => 'Termin absagen', $erase => 'Ihre Daten löschen']);

        $this->assertSame(
            '<p><a href="' . htmlspecialchars($signed) . '">Termin absagen</a><br>' . "\n"
            . '<a href="' . htmlspecialchars($erase) . '">Ihre Daten löschen</a></p>' . "\n",
            $html
        );
        $this->assertStringNotContainsString('>https://', $html, 'A signed URL is never the visible link text');
    }

    public function testLabelOnTheSameLineAndUrlInsideTextAreTakenIntoTheLink(): void
    {
        $room = 'https://example.test/room?jwt=abc';
        $this->assertSame('<p><a href="https://example.test/room?jwt=abc">Join meeting</a></p>' . "\n", MailHtml::textToHtml("Join meeting: $room", [$room => 'Join meeting']));
        $this->assertSame('<p>Location: Basel<br>' . "\n" . '<a href="https://example.test/room?jwt=abc">Join meeting</a></p>' . "\n",
            MailHtml::textToHtml("Location: Basel\n$room", [$room => 'Join meeting']));
        // Another text before the URL stays, only the label is taken.
        $this->assertStringContainsString('Join here: <a href="https://example.test/room?jwt=abc">Join meeting</a>', MailHtml::textToHtml("Join here: $room", [$room => 'Join meeting']));
    }

    public function testLabelIsEscaped(): void
    {
        $room = 'https://example.test/room';
        $this->assertStringContainsString('>&lt;b&gt;Join&lt;/b&gt;</a>', MailHtml::textToHtml($room, [$room => '<b>Join</b>']));
    }

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
            '<a href="https://example.test/index.php?option=com_appointments&amp;view=manage&amp;id=1"' . self::BREAK . '>https://example.test/index.php?option=com_appointments&amp;view=manage&amp;id=1</a>',
            $html
        );
        $this->assertStringNotContainsString('<Erika>', $html);
    }

    public function testSignedLinkInAngleBracketsKeepsItsExactTarget(): void
    {
        $html = MailHtml::textToHtml('Termin absagen: <https://example.test/manage?id=1&signature=abc>');

        $this->assertStringContainsString('<a href="https://example.test/manage?id=1&amp;signature=abc"' . self::BREAK . '>', $html);
        $this->assertStringContainsString('&lt;<a href=', $html);
        $this->assertStringContainsString('</a>&gt;', $html);
    }

    public function testTrailingProsePunctuationStaysOutsideTheLink(): void
    {
        $html = MailHtml::textToHtml('Join (https://example.test/room?token=abc).');
        $this->assertStringContainsString('(<a href="https://example.test/room?token=abc"' . self::BREAK . '>https://example.test/room?token=abc</a>).', $html);

        $html = MailHtml::textToHtml('See https://example.test/wiki/A_(b), then https://example.test/x?y=1!');
        $this->assertStringContainsString('<a href="https://example.test/wiki/A_(b)"' . self::BREAK . '>', $html);
        $this->assertStringContainsString('<a href="https://example.test/x?y=1"' . self::BREAK . '>https://example.test/x?y=1</a>!', $html);
    }

    public function testKnownUrlsAreLinkedExactly(): void
    {
        $room = "https://example.test/room/O'Brien?token=secret!";
        $html = MailHtml::textToHtml("Join: $room", [$room]);

        $this->assertStringContainsString('<a href="https://example.test/room/O&#039;Brien?token=secret!"' . self::BREAK . '>', $html);
        $this->assertStringNotContainsString("<script", MailHtml::textToHtml('<script>', [$room]));
    }

    public function testKnownUrlDoesNotCutALongerUrlWithTheSamePrefix(): void
    {
        $html = MailHtml::textToHtml('Help: https://example.test/room/help and join https://example.test/room.', ['https://example.test/room']);

        $this->assertStringContainsString('<a href="https://example.test/room/help"' . self::BREAK . '>https://example.test/room/help</a>', $html);
        $this->assertStringContainsString('<a href="https://example.test/room"' . self::BREAK . '>https://example.test/room</a>.', $html);
    }

    public function testRelativeLayoutUrlsResolveAgainstThePublicSiteUrl(): void
    {
        $html = MailHtml::absolutize('<img src="images/logo.png"><a href="/de/">x</a><a href="https://a.test/">y</a><a href="mailto:a@b.test">z</a>', 'https://shop.example.test/sub/');

        $this->assertStringContainsString('src="https://shop.example.test/sub/images/logo.png"', $html);
        $this->assertStringContainsString('href="https://shop.example.test/de/"', $html);
        $this->assertStringContainsString('href="https://a.test/"', $html);
        $this->assertStringContainsString('href="mailto:a@b.test"', $html);
    }

    public function testLayoutLinksOnTheCliRootMoveToThePublicSiteUrl(): void
    {
        $layout = '<a href="http://localhost/">site</a><img src="http://localhost/images/logo.png"><a href="http://localhost.example/x">other</a>';
        $html   = MailHtml::rebase($layout, 'http://localhost/', 'https://www.example.test/');

        $this->assertStringContainsString('href="https://www.example.test/"', $html);
        $this->assertStringContainsString('src="https://www.example.test/images/logo.png"', $html);
        $this->assertStringContainsString('href="http://localhost.example/x"', $html);
        $this->assertSame('<a href="https://www.example.test/">https://www.example.test/</a>', MailHtml::rebase('<a href="http://localhost/">http://localhost/</a>', 'http://localhost/', 'https://www.example.test/'));
        $this->assertSame($layout, MailHtml::rebase($layout, 'https://www.example.test/', 'https://www.example.test'));
    }

    public function testOnlyTheLayoutIsRebasedTheBodyStaysExact(): void
    {
        $marker = '<!--m-->';
        $layout = '<img src="https://example.test/images/logo.png"><a href="https://example.test/">https://example.test/</a>' . $marker;
        $body   = '<a href="https://example.test/room?token=secret">room</a>';
        $html   = MailHtml::assemble($layout, $marker, $body, 'https://example.test/sub/', 'https://example.test/');

        $this->assertStringContainsString('src="https://example.test/sub/images/logo.png"', $html);
        $this->assertStringContainsString('<a href="https://example.test/sub/">https://example.test/sub/</a>', $html);
        $this->assertStringContainsString($body, $html);
    }

    public function testPublicBaseAboveTheRequestRoot(): void
    {
        $html = MailHtml::rebase('<img src="https://example.test/internal/images/x.png">', 'https://example.test/internal/', 'https://example.test/');

        $this->assertSame('<img src="https://example.test/images/x.png">', $html);
    }

    public function testKnownUrlAfterALabelWithoutSpace(): void
    {
        $room = "https://example.test/room/O'Brien?token=secret!";
        $html = MailHtml::textToHtml("Join:$room", [$room]);

        $this->assertStringContainsString('<a href="https://example.test/room/O&#039;Brien?token=secret!"' . self::BREAK . '>', $html);
    }
    public function testKnownUrlInsideAnotherUrlIsNotSplitOut(): void
    {
        $html = MailHtml::textToHtml('Login: https://example.test/login?redirect=https://example.test/room', ['https://example.test/room']);

        $this->assertStringContainsString('<a href="https://example.test/login?redirect=https://example.test/room"' . self::BREAK . '>', $html);
    }

    public function testNoScriptOrAttributeInjectionThroughLinks(): void
    {
        $html = MailHtml::textToHtml('https://example.test/"onmouseover="x <script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('"onmouseover', $html);
    }
}
