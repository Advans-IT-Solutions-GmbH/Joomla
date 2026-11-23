<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/SignedLink.php';

final class SignedLinkTest extends TestCase
{
    public function testKnownVectorUsesTheSignedLinkPurposeAndUnambiguousTuple(): void
    {
        $keys = new KeyProvider('test-site-secret');
        $links = new SignedLink($keys);
        $signature = $links->sign(42, 'erase', '2026-10-14 10:00:00');

        self::assertSame('c0d51212681e9fb8da54ac3f77250cf32c898a48b7758fa20a9fde52d9fdd26e', $signature);
        self::assertSame(
            hash_hmac('sha256', "42\nerase\n2026-10-14 10:00:00", $keys->key(KeyProvider::PURPOSE_SIGNED_LINK)),
            $signature
        );
        self::assertNotSame(
            hash_hmac('sha256', "42\nerase\n2026-10-14 10:00:00", $keys->key(KeyProvider::PURPOSE_RATE_LIMIT)),
            $signature
        );
        self::assertTrue($links->verify(42, 'erase', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verify(43, 'erase', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verify(42, 'cancel', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verify(42, 'reschedule', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verify(42, 'erase', '2026-10-14 10:00:01', $signature));
        self::assertFalse((new SignedLink(new KeyProvider('another-secret')))->verify(42, 'erase', '2026-10-14 10:00:00', $signature));
    }

    public function testEveryAllowedActionHasAnIndependentSignature(): void
    {
        $links = new SignedLink(new KeyProvider('secret'));
        $signatures = [];

        foreach (['cancel', 'reschedule', 'erase'] as $action) {
            $signatures[] = $signature = $links->sign(1, $action, '2026-10-14 10:00');
            self::assertTrue($links->verify(1, $action, '2026-10-14 10:00:00', $signature));
        }

        self::assertCount(3, array_unique($signatures));
    }

    public function testMalformedInputsNeverVerify(): void
    {
        $links = new SignedLink(new KeyProvider('secret'));

        foreach ([
            [0, 'erase', '2026-10-14 10:00:00', ''],
            [1, "erase\ncancel", '2026-10-14 10:00:00', ''],
            [1, 'erase', '2026-02-30 10:00:00', ''],
            [1, 'erase', 'tomorrow', ''],
            [1, 'erase', '2026-10-14 10:00:00', str_repeat('0', 64)],
        ] as $arguments) {
            self::assertFalse($links->verify(...$arguments));
        }
    }

    public function testGuestLinkIsBoundToBookingAddressAndVersionWithItsOwnKey(): void
    {
        $keys = new KeyProvider('test-site-secret');
        $links = new SignedLink($keys);
        $signature = $links->signGuest(42, ' Guest@Example.test ', '2026-10-14 10:00:00');

        self::assertSame(
            hash_hmac('sha256', "42\nremove_guest\nguest@example.test\n2026-10-14 10:00:00", $keys->key(KeyProvider::PURPOSE_GUEST_LINK)),
            $signature
        );
        self::assertTrue($links->verifyGuest(42, 'guest@example.test', '2026-10-14 10:00:00', $signature));
        self::assertTrue($links->verifyGuest(42, 'GUEST@EXAMPLE.TEST', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verifyGuest(43, 'guest@example.test', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verifyGuest(42, 'other@example.test', '2026-10-14 10:00:00', $signature));
        self::assertFalse($links->verifyGuest(42, 'guest@example.test', '2026-10-14 10:00:01', $signature));
        self::assertFalse((new SignedLink(new KeyProvider('another-secret')))->verifyGuest(42, 'guest@example.test', '2026-10-14 10:00:00', $signature));

        // Neither kind of link verifies as the other.
        foreach (['reschedule', 'cancel', 'erase'] as $action) {
            self::assertFalse($links->verify(42, $action, '2026-10-14 10:00:00', $signature));
            self::assertFalse($links->verifyGuest(42, 'guest@example.test', '2026-10-14 10:00:00', $links->sign(42, $action, '2026-10-14 10:00:00')));
        }
    }

    public function testGuestForFindsOnlyTheSignedGuest(): void
    {
        $links = new SignedLink(new KeyProvider('secret'));
        $guests = ['first@example.test', 'Second@example.test', 'third@example.test'];
        $signature = $links->signGuest(7, 'second@example.test', '2026-10-14 10:00:00');

        self::assertSame('Second@example.test', $links->guestFor(7, $guests, '2026-10-14 10:00:00', $signature));
        self::assertNull($links->guestFor(7, ['first@example.test', 'third@example.test'], '2026-10-14 10:00:00', $signature), 'A removed guest cannot replay the link');
        self::assertNull($links->guestFor(7, $guests, '2026-10-14 10:00:01', $signature), 'A changed booking invalidates the link');
        self::assertNull($links->guestFor(7, $guests, '2026-10-14 10:00:00', strrev($signature)), 'A tampered signature matches nobody');
        self::assertNull($links->guestFor(7, $guests, '2026-10-14 10:00:00', ''));
        self::assertNull($links->guestFor(8, $guests, '2026-10-14 10:00:00', $signature));
    }

    public function testGuestSignerRefusesMalformedInput(): void
    {
        $links = new SignedLink(new KeyProvider('secret'));

        // Each guard on its own: the signer refuses the input instead of signing it, so a removed
        // guard makes this test fail. A malformed value therefore never verifies either.
        foreach ([[0, 'guest@example.test', '2026-10-14 10:00:00'], [1, '  ', '2026-10-14 10:00:00'],
            [1, "guest@example.test\nother@example.test", '2026-10-14 10:00:00'], [1, "\xff", '2026-10-14 10:00:00'],
            [1, 'guest@example.test', 'tomorrow']] as $arguments) {
            try {
                $links->signGuest(...$arguments);
                self::fail('Signed malformed input: ' . json_encode($arguments, JSON_INVALID_UTF8_SUBSTITUTE));
            } catch (\InvalidArgumentException $exception) {
                self::assertStringStartsWith('Invalid', $exception->getMessage());
            }

            self::assertFalse($links->verifyGuest(...[...$arguments, str_repeat('0', 64)]));
        }

        // The valid counterpart of every case is signed, so the refusals above come from the guards.
        self::assertSame(64, strlen($links->signGuest(1, 'guest@example.test', '2026-10-14 10:00:00')));
    }

    public function testSignerRefusesInvalidAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SignedLink(new KeyProvider('secret')))->sign(1, 'delete', '2026-10-14 10:00:00');
    }
}
