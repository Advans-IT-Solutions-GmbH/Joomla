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

    public function testSignerRefusesInvalidAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SignedLink(new KeyProvider('secret')))->sign(1, 'delete', '2026-10-14 10:00:00');
    }
}
