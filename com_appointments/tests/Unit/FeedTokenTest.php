<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\FeedToken;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/FeedToken.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/KeyProvider.php';

final class FeedTokenTest extends TestCase
{
    private const SECRET = 'feed-token-test-secret';

    private static function key(string $secret = self::SECRET): string
    {
        return (new KeyProvider($secret))->key(KeyProvider::PURPOSE_FEED_TOKEN);
    }

    public function testTokensHaveIndependentThirtyTwoByteEntropy(): void
    {
        $tokens = [];

        for ($i = 0; $i < 100; ++$i) {
            $tokens[] = $token = FeedToken::generate();
            self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $token);
            self::assertSame(32, strlen(hex2bin($token)));
            self::assertTrue(FeedToken::verify(self::key(), 'event', 7, FeedToken::digest(self::key(), 'event', 7, $token), $token));
        }

        self::assertCount(100, array_unique($tokens));
    }

    public function testOnlyAKeyedDigestIsStoredAndItIsNotACredential(): void
    {
        $token = FeedToken::generate();
        $stored = FeedToken::digest(self::key(), 'event', 7, $token);

        self::assertSame('h1:' . hash_hmac('sha256', 'event:7:' . $token, hash_hmac('sha256', 'com_appointments:feed-token', self::SECRET, true)), $stored);
        self::assertTrue(FeedToken::isDigest($stored));
        self::assertSame(67, strlen($stored));
        self::assertStringNotContainsString($token, $stored);
        // A copy of the database alone opens nothing: neither the digest nor the clear-text
        // token that the column held before 1.5.0 is accepted as stored value or as token.
        self::assertFalse(FeedToken::verify(self::key(), 'event', 7, $stored, substr($stored, 3)));
        self::assertFalse(FeedToken::verify(self::key(), 'event', 7, $token, $token));
    }

    public function testADigestWrittenWithoutTheSiteSecretMatchesNoToken(): void
    {
        $token = FeedToken::generate();

        self::assertFalse(FeedToken::verify(self::key(), 'event', 7, FeedToken::digest(self::key('another secret'), 'event', 7, $token), $token));
        self::assertFalse(FeedToken::verify(self::key(), 'event', 7, 'h1:' . hash('sha256', $token), $token));
        self::assertFalse(FeedToken::verify(self::key(), 'event', 7, FeedToken::unissued(), $token));
    }

    public function testADigestCopiedToAnotherFeedMatchesNothingThere(): void
    {
        $token = FeedToken::generate();
        $stored = FeedToken::digest(self::key(), 'event', 7, $token);

        self::assertTrue(FeedToken::verify(self::key(), 'event', 7, $stored, $token));
        self::assertFalse(FeedToken::verify(self::key(), 'host', 7, $stored, $token));
        self::assertFalse(FeedToken::verify(self::key(), 'event', 8, $stored, $token));
        self::assertFalse(FeedToken::verify(self::key(), 'host', 42, $stored, $token));
    }

    public function testRotationRevokesOnlyTheSelectedFeed(): void
    {
        $key = self::key();
        $host = FeedToken::generate();
        $event = FeedToken::generate();
        $hostStored = FeedToken::digest($key, 'host', 3, $host);
        $eventStored = FeedToken::digest($key, 'event', 7, $event);
        $newEvent = FeedToken::rotate($key, 'event', 7, $eventStored);
        $newEventStored = FeedToken::digest($key, 'event', 7, $newEvent);
        self::assertNotSame($eventStored, $newEventStored);
        self::assertFalse(FeedToken::verify($key, 'event', 7, $newEventStored, $event));
        self::assertTrue(FeedToken::verify($key, 'event', 7, $newEventStored, $newEvent));
        self::assertTrue(FeedToken::verify($key, 'host', 3, $hostStored, $host));
        $newHost = FeedToken::rotate($key, 'host', 3, $hostStored);
        self::assertFalse(FeedToken::verify($key, 'host', 3, FeedToken::digest($key, 'host', 3, $newHost), $host));
        self::assertTrue(FeedToken::verify($key, 'event', 7, $newEventStored, $newEvent));
        self::assertFalse(FeedToken::verify($key, 'host', 3, $hostStored, $event));
    }

    public function testMalformedOrMissingTokensNeverAuthenticate(): void
    {
        $key = self::key();
        $token = FeedToken::generate();
        $stored = FeedToken::digest($key, 'event', 7, $token);

        foreach (['', 'abc', '0', str_repeat('g', 64), strtoupper($token), $token . "\n", str_repeat('0', 63), str_repeat('0', 65)] as $invalid) {
            self::assertFalse(FeedToken::verify($key, 'event', 7, FeedToken::digest($key, 'event', 7, $invalid), $invalid));
            self::assertFalse(FeedToken::verify($key, 'event', 7, $stored, $invalid));
            self::assertFalse(FeedToken::verify($key, 'event', 7, $invalid, $token));
        }

        self::assertFalse(FeedToken::verify($key, 'event', 7, '', str_repeat('0', 64)));
        self::assertFalse(FeedToken::verify($key, 'event', 7, strtoupper($stored), $token));
        self::assertFalse(FeedToken::isDigest($token));
    }
}
