<?php

namespace Advans\Plugin\Privacy\J2Commerce\Tests\Unit;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Advans\Plugin\Privacy\J2Commerce\Newsletter\AcymailingPlaceholders;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests of the matching rules for the consent kinds a privacy request handles besides the
 * checkout consent: the subscriber address field of newsletter form records (current kind and old
 * header-form kind), the order marker of checkout newsletter consents and the placeholder address
 * of a pseudonymised account. Only pure functions, no database (the database paths are covered by
 * tests/scripts/04b-data-isolation.php, 09-autocleanup-task.php and 10-acymailing-integration.php).
 */
class ConsentKindsTest extends TestCase
{
    /** Body of a newsletter form record as the site template writes it (escaped lines). */
    private static function formBody(string $email, string $shown = 'I agree to receive the newsletter.'): string
    {
        $lines = [
            'Einwilligung: Newsletter, Formular «Newsletter» im Kopfbereich der Website.',
            'Listen: 1',
            'Sprache: de-DE',
            'Angezeigter Text: ' . $shown,
            'Datenschutzerklärung: 2026-01-01 00:00:00 (v3)',
            'E-Mail-Adresse: ' . $email,
            'IP-Adresse: 198.51.100.20',
            'Browserkennung: UnitAgent/1.0',
        ];

        return implode("\n", array_map(static fn (string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), $lines))
            . "\n" . ConsentRepository::NEWSLETTER_MARKER . "\n" . ConsentRepository::NEWSLETTER_FORM_MARKER;
    }

    /** Body of an old header-form record (Joomla's registration subject, paragraphs). */
    private static function oldFormBody(string $email): string
    {
        return '<p>Newsletter subscription consent</p><p>Email: ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
            . '</p><p>IP: 198.51.100.21</p><p>User-Agent: UnitAgent/1.0</p>';
    }

    public function testFormRecordNamesTheSubscriberAddress(): void
    {
        $this->assertSame(['anna@example.com'], ConsentRepository::subscriberAddresses(self::formBody('Anna@Example.com')));
    }

    public function testOldHeaderFormRecordNamesTheSubscriberAddress(): void
    {
        $this->assertSame(['anna@example.com'], ConsentRepository::subscriberAddresses(self::oldFormBody('anna@example.com')));
    }

    public function testApostropheAddressIsDecodedAndComplete(): void
    {
        $this->assertSame(["o'brien@example.com"], ConsentRepository::subscriberAddresses(self::formBody("o'brien@example.com")));
        $this->assertSame(["o'brien@example.com"], ConsentRepository::subscriberAddresses(self::oldFormBody("O'Brien@example.com")));
    }

    /**
     * @dataProvider otherAddressProvider
     */
    public function testLongerOrDifferentAddressIsNeverTheRequestAddress(string $stored): void
    {
        $this->assertNotContains('anna@example.com', ConsentRepository::subscriberAddresses(self::formBody($stored)));
        $this->assertNotContains('anna@example.com', ConsentRepository::subscriberAddresses(self::oldFormBody($stored)));
    }

    public static function otherAddressProvider(): array
    {
        return [
            'longer local part'   => ['joanna@example.com'],
            'apostrophe prefix'   => ["o'anna@example.com"],
            'non-ASCII prefix'    => ['éanna@example.com'],
            'emoji prefix'        => ['💡anna@example.com'],
            'longer domain'       => ['anna@example.com.evil.test'],
            'subdomain'           => ['anna@mail.example.com'],
            'accented look-alike' => ['änna@example.com'],
        ];
    }

    public function testAddressInTheConsentTextDoesNotCount(): void
    {
        $body = self::formBody('someone@example.org', 'Questions: anna@example.com');

        $this->assertSame(['someone@example.org'], ConsentRepository::subscriberAddresses($body));
    }

    public function testBodyWithoutAddressFieldHasNoSubscriber(): void
    {
        $this->assertSame([], ConsentRepository::subscriberAddresses('<p>IP Address: 198.51.100.1</p><p>Contact anna@example.com</p>'));
        $this->assertSame([], ConsentRepository::subscriberAddresses('Consent given by anna@example.com (IP 198.51.100.9)'));
    }

    public function testNewsletterOrderMarkerRoundTrip(): void
    {
        $body = "Einwilligung: Newsletter, im Bestellablauf erteilt.\n" . ConsentRepository::NEWSLETTER_MARKER
            . "\n<!-- advans-newsletter-lists:1 -->\n" . ConsentRepository::newsletterOrderMarker('ORD-42');

        $this->assertSame('ORD-42', ConsentRepository::extractNewsletterOrderId($body));
    }

    public function testOrderMarkerWithoutNewsletterMarkerIsNoNewsletterRecord(): void
    {
        $this->assertNull(ConsentRepository::extractNewsletterOrderId(ConsentRepository::newsletterOrderMarker('ORD-42')));
        $this->assertNull(ConsentRepository::extractNewsletterOrderId(ConsentRepository::NEWSLETTER_MARKER . '<!-- j2commerce-order:ORD-42 -->'));
    }

    public function testPendingMarkerHoldsOnlyAHashAndIsRemovedCompletely(): void
    {
        $hash   = hash_hmac('sha256', 'pending-removal|anna@example.com', 'secret');
        $marker = ConsentRepository::pendingMarker($hash);

        $this->assertSame('<!-- j2commerce-removal-pending:' . $hash . ' -->', $marker);
        // Nothing but hexadecimal digits can end up in the comment.
        $this->assertSame('<!-- j2commerce-removal-pending: -->', ConsentRepository::pendingMarker('--> <!-- x'));

        $body = "Einwilligung: Newsletter\n" . ConsentRepository::NEWSLETTER_MARKER . "\n" . ConsentRepository::newsletterOrderMarker('ORD-1');
        $this->assertSame($body, ConsentRepository::withoutPendingMarkers($body . "\n" . $marker . "\n" . ConsentRepository::pendingMarker(str_repeat('a', 64))));
    }

    public function testPlaceholderAddressOfAPseudonymisedAccount(): void
    {
        $this->assertSame('userid42removed@email.invalid', AcymailingPlaceholders::placeholderAddress(42));
        $this->assertSame(
            AcymailingPlaceholders::placeholderAddress(42),
            ConsentRepository::normaliseAddress(' UserID42removed@email.invalid ')
        );
        $this->assertNotSame(
            AcymailingPlaceholders::placeholderAddress(4),
            ConsentRepository::normaliseAddress('UserID42removed@email.invalid')
        );
    }
}
