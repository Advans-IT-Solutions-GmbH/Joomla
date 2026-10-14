<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\FeedToken;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/FeedToken.php';

final class FeedTokenTest extends TestCase
{
    public function testTokensHaveIndependentThirtyTwoByteEntropy(): void
    {
        $tokens = [];

        for ($i = 0; $i < 100; ++$i) {
            $tokens[] = $token = FeedToken::generate();
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
            self::assertSame(32, strlen(hex2bin($token)));
            self::assertTrue(FeedToken::verify($token, $token));
        }

        self::assertCount(100, array_unique($tokens));
    }

    public function testRotationRevokesOnlyTheSelectedFeed(): void
    {
        $host = FeedToken::generate();
        $event = FeedToken::generate();
        $newEvent = FeedToken::rotate($event);
        self::assertFalse(FeedToken::verify($newEvent, $event));
        self::assertTrue(FeedToken::verify($newEvent, $newEvent));
        self::assertTrue(FeedToken::verify($host, $host));
        $newHost = FeedToken::rotate($host);
        self::assertFalse(FeedToken::verify($newHost, $host));
        self::assertTrue(FeedToken::verify($newEvent, $newEvent));
        self::assertFalse(FeedToken::verify($host, $event));
    }

    public function testMalformedOrMissingTokensNeverAuthenticate(): void
    {
        $token = FeedToken::generate();

        foreach (['', '0', str_repeat('g', 64), strtoupper($token), $token . "\n"] as $invalid) {
            self::assertFalse(FeedToken::verify($invalid, $invalid));
            self::assertFalse(FeedToken::verify($token, $invalid));
        }

        self::assertFalse(FeedToken::verify('', str_repeat('0', 64)));
    }
}
