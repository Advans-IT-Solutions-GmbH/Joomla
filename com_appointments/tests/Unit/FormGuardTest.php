<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\TestCase;

final class FormGuardTest extends TestCase
{
    public function testTimingSignatureConsentHoneypotAndSessionBinding(): void
    {
        $guard = new FormGuard(new KeyProvider('fixture-secret'));
        $now = new \DateTimeImmutable('2026-11-02 12:00:00', new \DateTimeZone('UTC'));
        $rendered = $now->getTimestamp() - 2;
        $signature = $guard->sign(3, $rendered, 'session');
        self::assertTrue($guard->verify(3, $rendered, 'session', $signature, '', true, $now));
        self::assertFalse($guard->verify(4, $rendered, 'session', $signature, '', true, $now));
        self::assertFalse($guard->verify(3, $rendered, 'other-session', $signature, '', true, $now));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, 'bot', true, $now));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, '', false, $now));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, '', true, $now->modify('-1 second')));
        self::assertFalse($guard->verify(3, $rendered, 'session', $signature, '', true, $now->modify('+3600 seconds')));
        self::assertFalse($guard->verify(3, $rendered + 1, 'session', $signature, '', true, $now));
    }
}
