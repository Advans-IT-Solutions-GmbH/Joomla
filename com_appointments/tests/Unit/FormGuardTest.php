<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\TestCase;

final class FormGuardTest extends TestCase
{
    public function testTimingSignatureHoneypotAndSessionBinding(): void
    {
        $guard = new FormGuard(new KeyProvider('fixture-secret'));
        $now = new \DateTimeImmutable('2026-11-02 12:00:00', new \DateTimeZone('UTC'));
        $rendered = $now->getTimestamp() - 2;
        $signature = $guard->sign(3, $rendered, 'session');
        self::assertTrue($guard->verify(3, $rendered, 'session', $signature, '', $now));
        self::assertFalse($guard->verify(4, $rendered, 'session', $signature, '', $now));
        self::assertFalse($guard->verify(3, $rendered, 'other-session', $signature, '', $now));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, 'bot', $now));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, '', $now->modify('-1 second')));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, '', $now->modify('+3600 seconds')));
        self::assertFalse($guard->verify(3, $rendered + 1, 'session', $signature, '', $now));
    }
}
