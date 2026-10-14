<?php

namespace Advans\Component\Appointments\Tests\Unit\Conference;

use Advans\Component\Appointments\Administrator\Helper\Conference\BoundedHttpClient;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceException;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceManager;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceProviderInterface;
use Advans\Component\Appointments\Administrator\Helper\Conference\ConferenceUrl;
use Advans\Component\Appointments\Administrator\Helper\Conference\FixedRoomProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\GenericRoomProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\JitsiProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\MeetingRequest;
use Advans\Component\Appointments\Administrator\Helper\Conference\MeetingResult;
use Advans\Component\Appointments\Administrator\Helper\Conference\OutboundConferenceProvider;
use Advans\Component\Appointments\Administrator\Helper\Conference\OutboundHttpRequest;
use Advans\Component\Appointments\Administrator\Helper\Conference\RoomName;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// This isolated suite must also run before the parent adds these files to bootstrap.
$conferenceDirectory = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Helper/Conference';

foreach ([
    'ConferenceProviderInterface', 'MeetingRequest', 'ConferenceException', 'ConferenceUrl',
    'MeetingResult', 'RoomName', 'JitsiProvider', 'GenericRoomProvider', 'FixedRoomProvider',
    'OutboundHttpRequest', 'BoundedHttpClient', 'OutboundConferenceProvider', 'ConferenceManager',
] as $conferenceClass) {
    require_once $conferenceDirectory . '/' . $conferenceClass . '.php';
}

final class ConferenceTest extends TestCase
{
    private function request(string $uid = 'booking-a'): MeetingRequest
    {
        return new MeetingRequest(
            $uid,
            new \DateTimeImmutable('2026-11-10 10:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-11-10 10:30:00', new \DateTimeZone('UTC')),
            'Consultation',
            30
        );
    }

    private function jitsi(array $config = [], string $secret = 'test-site-key'): JitsiProvider
    {
        return new JitsiProvider(new KeyProvider($secret), $config + ['base_url' => 'https://meet.example.com']);
    }

    public function testRequestHasExactlyThePrivacyLimitedPropertySet(): void
    {
        $request = $this->request();
        $properties = array_keys(get_object_vars($request));
        sort($properties);
        self::assertSame(['duration', 'endUtc', 'roomSeed', 'startUtc', 'title'], $properties);
        self::assertNotSame('booking-a', $request->roomSeed);
        self::assertSame(64, strlen($request->roomSeed));
        self::assertSame('UTC', $request->startUtc->getTimezone()->getName());
        self::assertSame('UTC', $request->endUtc->getTimezone()->getName());
        self::assertSame($request->roomSeed, $this->request()->roomSeed);
        self::assertNotSame($request->roomSeed, $this->request('booking-b')->roomSeed);
        $reflection = new \ReflectionClass(MeetingRequest::class);

        foreach ($reflection->getProperties() as $property) {
            self::assertTrue($property->isReadOnly());
        }
    }

    public function testRequestNormalisesUtcAndRejectsInconsistentDuration(): void
    {
        $request = new MeetingRequest(
            'booking-a', new \DateTimeImmutable('2026-11-10T11:00:00+01:00'),
            new \DateTimeImmutable('2026-11-10T11:30:00+01:00'), 'Consultation', 30
        );
        self::assertEquals($this->request()->startUtc, $request->startUtc);
        self::assertEquals($this->request()->endUtc, $request->endUtc);
        $this->expectException(\InvalidArgumentException::class);
        new MeetingRequest('booking-a', $request->startUtc, $request->endUtc, 'Consultation', 31);
    }

    public function testDefaultRoomsAreDeterministicKeyedAndHaveEntropy(): void
    {
        $provider = $this->jitsi();
        $first = $provider->createMeeting($this->request());
        self::assertSame($first->roomIdentifier, $provider->createMeeting($this->request())->roomIdentifier);
        self::assertMatchesRegularExpression('/\Ameeting-event-[a-f0-9]{24}\z/', $first->roomIdentifier);
        self::assertSame('https://meet.example.com/' . $first->roomIdentifier, $first->joinUrl);
        self::assertNull($first->hostUrl);
        self::assertStringNotContainsString('booking-a', $first->joinUrl);
        self::assertStringNotContainsString('Consultation', $first->joinUrl);
        self::assertNotSame($first->roomIdentifier, $provider->createMeeting($this->request('booking-b'))->roomIdentifier);
        self::assertNotSame($first->roomIdentifier, $this->jitsi([], 'different-key')->createMeeting($this->request())->roomIdentifier);
        $rescheduled = new MeetingRequest(
            'booking-a', new \DateTimeImmutable('2026-11-11T10:00:00Z'),
            new \DateTimeImmutable('2026-11-11T10:30:00Z'), 'Different event title', 30
        );
        self::assertSame($first->roomIdentifier, $provider->createMeeting($rescheduled)->roomIdentifier);
        self::assertFalse($provider->requiresOutboundAccess());
        $provider->destroyMeeting($first->roomIdentifier);
    }

    public function testCustomRoomPatternAndPurposeSeparatedKey(): void
    {
        $keys = new KeyProvider('test-site-key');
        $request = $this->request();
        $room = (new RoomName($keys, '{alias}_{prefix}_{random}', 'team', 'consultation', 16))->forMeeting($request);
        $expected = substr(hash_hmac('sha256', $request->roomSeed, $keys->key('conference-room')), 0, 16);
        self::assertSame('consultation_team_' . $expected, $room);
        self::assertNotSame($expected, substr(hash_hmac('sha256', $request->roomSeed, $keys->key('signed-link')), 0, 16));
        self::assertSame(64, strlen((new RoomName($keys, '{random}', 'a', 'b', 64))->forMeeting($request)));
    }

    public static function invalidRoomConfigurations(): array
    {
        return [
            ['{prefix}-{alias}', 'meeting', 'event', 24],
            ['{random}-{random}', 'meeting', 'event', 24],
            ['{uid}-{random}', 'meeting', 'event', 24],
            ['{random}', 'meeting', 'event', 15],
            ['{random}', 'meeting', 'event', 65],
            ['{random}', 'invalid prefix', 'event', 24],
            ['{random}', 'meeting', '../event', 24],
            ['https://{random}', 'meeting', 'event', 24],
            [str_repeat('a', 240) . '{random}', 'meeting', 'event', 24],
        ];
    }

    #[DataProvider('invalidRoomConfigurations')]
    public function testInvalidRoomConfigurationFailsSafely(string $pattern, string $prefix, string $alias, int $entropy): void
    {
        $this->expectException(ConferenceException::class);
        $this->expectExceptionMessage('Conference configuration is invalid.');
        new RoomName(new KeyProvider('test-site-key'), $pattern, $prefix, $alias, $entropy);
    }

    public function testJwtReferenceSignatureClaimsAndValidityWindows(): void
    {
        $provider = $this->jitsi([
            'auth' => 'jwt', 'jwt_secret' => 'reference-signing-key',
            'jwt_app_id' => 'application', 'jwt_audience' => 'jitsi',
            'jwt_issuer' => 'issuer', 'host_lead_minutes' => 45, 'grace_minutes' => 20,
        ]);
        $request = $this->request();
        $result = $provider->createMeeting($request);
        $guest = $this->decodeAndVerify($result->joinUrl, 'reference-signing-key');
        $host = $this->decodeAndVerify($result->hostUrl, 'reference-signing-key');
        self::assertSame($result->roomIdentifier, $guest['room']);
        self::assertSame($result->roomIdentifier, $host['room']);
        self::assertSame('jitsi', $guest['aud']);
        self::assertSame('issuer', $guest['iss']);
        self::assertSame('meet.example.com', $guest['sub']);
        self::assertFalse($guest['context']['user']['moderator']);
        self::assertTrue($host['context']['user']['moderator']);
        self::assertSame($request->startUtc->getTimestamp() - 900, $guest['nbf']);
        self::assertSame($request->startUtc->getTimestamp() - 2700, $host['nbf']);
        self::assertSame($request->endUtc->getTimestamp() + 1200, $guest['exp']);
        self::assertSame($guest['exp'], $host['exp']);

        foreach ([$guest, $host] as $claims) {
            self::assertFalse($this->validAt($claims, $claims['nbf'] - 1));
            self::assertTrue($this->validAt($claims, $claims['nbf']));
            self::assertTrue($this->validAt($claims, $claims['exp'] - 1));
            self::assertFalse($this->validAt($claims, $claims['exp']));
            self::assertSame(['moderator'], array_keys($claims['context']['user']));
            self::assertStringNotContainsString('Consultation', json_encode($claims));
            self::assertStringNotContainsString('booking-a', json_encode($claims));
        }
    }

    private function decodeAndVerify(string $url, string $key): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        [$header, $payload, $signature] = explode('.', $query['jwt']);
        $decode = static fn (string $part): string => base64_decode(strtr($part, '-_', '+/'), true);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], json_decode($decode($header), true));
        self::assertTrue(hash_equals(hash_hmac('sha256', $header . '.' . $payload, $key, true), $decode($signature)));
        self::assertFalse(hash_equals(hash_hmac('sha256', $header . '.' . $payload, 'wrong-key', true), $decode($signature)));

        return json_decode($decode($payload), true, 512, JSON_THROW_ON_ERROR);
    }

    private function validAt(array $claims, int $timestamp): bool
    {
        return $timestamp >= $claims['nbf'] && $timestamp < $claims['exp'];
    }

    public static function invalidJitsiConfigurations(): array
    {
        return [
            [['auth' => 'oauth']],
            [['base_url' => 'https://meet.example.com?jwt=credential']],
            [['base_url' => 'https://meet.example.com#fragment']],
            [['auth' => 'jwt']],
            [['host_lead_minutes' => -1]],
            [['grace_minutes' => 1441]],
            [['grace_minutes' => 'invalid']],
        ];
    }

    #[DataProvider('invalidJitsiConfigurations')]
    public function testInvalidJitsiConfigurationsFail(array $config): void
    {
        $this->expectException(ConferenceException::class);
        $this->jitsi($config);
    }

    public function testGenericRoomSubstitutionDoesNotMakeOutboundRequests(): void
    {
        $provider = new GenericRoomProvider(new KeyProvider('test-site-key'), [
            'url_pattern' => 'https://meet.example.com/rooms/{room}?mode=guest',
            'alias' => 'consultation',
        ]);
        $result = $provider->createMeeting($this->request());
        self::assertSame('https://meet.example.com/rooms/' . $result->roomIdentifier . '?mode=guest', $result->joinUrl);
        self::assertFalse($provider->requiresOutboundAccess());
        self::assertNull($result->hostUrl);
        $provider->destroyMeeting($result->roomIdentifier);
    }

    public static function invalidGenericPatterns(): array
    {
        return [
            ['https://meet.example.com/fixed'],
            ['https://meet.example.com/{room}/{room}'],
            ['https://{room}.example.com/'],
            ['https://meet.example.com/{unknown}/{room}'],
            ['javascript:{room}'],
        ];
    }

    #[DataProvider('invalidGenericPatterns')]
    public function testGenericRoomRejectsInvalidPatterns(string $pattern): void
    {
        $this->expectException(ConferenceException::class);
        new GenericRoomProvider(new KeyProvider('test-site-key'), ['url_pattern' => $pattern]);
    }

    public function testFixedRoomsReuseThePermanentUrlAndWarnAboutCollisions(): void
    {
        $provider = new FixedRoomProvider('https://meet.example.com/permanent');
        $first = $provider->createMeeting($this->request());
        $second = $provider->createMeeting($this->request('booking-b'));
        self::assertSame('https://meet.example.com/permanent', $first->joinUrl);
        self::assertSame($first->joinUrl, $second->joinUrl);
        self::assertSame($first->roomIdentifier, $second->roomIdentifier);
        self::assertStringContainsString('overlapping appointments can collide', $provider->getConfigForm());
        self::assertFalse($provider->requiresOutboundAccess());
        $override = new FixedRoomProvider('https://meet.example.com/permanent', 'https://meet.example.com/event-room');
        self::assertSame('https://meet.example.com/event-room', $override->createMeeting($this->request())->joinUrl);
        self::assertNotSame($first->roomIdentifier, $override->createMeeting($this->request())->roomIdentifier);
        $provider->destroyMeeting($first->roomIdentifier);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['http://meet.example.com/room'], ['javascript:alert(1)'], ['//meet.example.com/room'],
            ['https://user:password@meet.example.com/room'],
            ["https://meet.example.com/room\r\nInjected: header"],
            ['https://meet.example.com/%0a'], ['https://meet.example.com/%250d'],
            ['https://meet.example.com/%252500'], ['https://meet.example.com/%5croom'],
            ['https://meet.example.com/<script>'], ['https://meet.example.com/%zz'],
            ['https://meet.example.com/' . str_repeat('x', 8192)],
            ['https://meet.example.com/%' . str_repeat('25', 500000) . '00'],
            ['https://meet.example.com/%' . str_repeat('25', 100) . '00'],
        ];
    }

    public function testUrlSizeBoundaryAndOrdinaryEncodingRemainSupported(): void
    {
        $prefix = 'https://meet.example.com/';
        $url = $prefix . str_repeat('x', 8192 - strlen($prefix));
        self::assertSame($url, ConferenceUrl::validate($url));
        $encoded = $prefix . 'room?name=%C3%A9&next=%252Froom';
        self::assertSame($encoded, ConferenceUrl::validate($encoded));
    }

    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlsHaveRedactedConfigurationErrors(string $url): void
    {
        try {
            ConferenceUrl::validate($url);
            self::fail('Unsafe URL accepted.');
        } catch (ConferenceException $error) {
            self::assertSame('Conference configuration is invalid.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testProviderConfigurationFormsAreValidAndContainNoConfiguredSecrets(): void
    {
        foreach ([
            $this->jitsi(['jwt_secret' => 'private-test-secret']),
            new GenericRoomProvider(new KeyProvider('test-site-key'), ['url_pattern' => 'https://meet.example.com/{room}']),
            new FixedRoomProvider('https://meet.example.com/private-room'),
        ] as $provider) {
            self::assertNotFalse(simplexml_load_string($provider->getConfigForm()));
            self::assertStringNotContainsString('private-test-secret', $provider->getConfigForm());
            self::assertStringNotContainsString('private-room', $provider->getConfigForm());
        }
    }

    public function testManagerRegistrySupportsInjectionAndRedactsFailures(): void
    {
        $failure = new class implements ConferenceProviderInterface {
            public function getName(): string { return 'failure'; }
            public function getConfigForm(): string { return '<fieldset />'; }
            public function createMeeting(MeetingRequest $request): MeetingResult
            {
                throw new \RuntimeException('https://meet.example.com/?jwt=secret-token password=credential');
            }
            public function destroyMeeting(string $roomIdentifier): void
            {
                throw new \RuntimeException('cleanup failed for https://meet.example.com/?jwt=secret-token');
            }
            public function requiresOutboundAccess(): bool { return false; }
        };
        $manager = new ConferenceManager([$this->jitsi(), $failure]);
        self::assertSame(['jitsi', 'failure'], $manager->getProviderNames());
        self::assertSame('<fieldset />', $manager->getConfigForm('failure'));
        self::assertInstanceOf(MeetingResult::class, $manager->createMeeting('jitsi', $this->request()));

        try {
            $manager->createMeeting('failure', $this->request());
            self::fail('Forced provider failure was swallowed.');
        } catch (ConferenceException $error) {
            self::assertSame('creation_failed', $error->reason);
            self::assertSame('Conference link creation failed. Please retry.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('jwt', $error->getMessage());
            self::assertStringNotContainsString('credential', $error->getMessage());
            self::assertStringNotContainsString('https', $error->getMessage());
        }

        try {
            $manager->destroyMeeting('failure', 'room');
            self::fail('Forced teardown failure was swallowed.');
        } catch (ConferenceException $error) {
            self::assertSame('teardown_failed', $error->reason);
            self::assertSame('Conference room cleanup failed. Please retry.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function testUnknownProvidersAndDuplicateRegistrationsAreExplicitFailures(): void
    {
        $manager = new ConferenceManager([$this->jitsi()]);

        try {
            $manager->createMeeting('unknown', $this->request());
            self::fail('Unknown provider accepted.');
        } catch (ConferenceException $error) {
            self::assertSame('unknown_provider', $error->reason);
        }

        $this->expectException(ConferenceException::class);
        $manager->register($this->jitsi());
    }

    public function testManagerRejectsUnboundedRemoteProvidersEvenWhenOptedIn(): void
    {
        $provider = new class implements ConferenceProviderInterface {
            public bool $called = false;
            public function getName(): string { return 'remote'; }
            public function getConfigForm(): string { return '<fieldset />'; }
            public function createMeeting(MeetingRequest $request): MeetingResult
            {
                $this->called = true;
                return new MeetingResult('https://meet.example.com/room', 'room');
            }
            public function destroyMeeting(string $roomIdentifier): void { $this->called = true; }
            public function requiresOutboundAccess(): bool { return true; }
        };

        foreach ([false => 'outbound_disabled', true => 'outbound_unsupported'] as $allow => $reason) {
            $manager = new ConferenceManager([$provider], (bool) $allow);

            try {
                $manager->createMeeting('remote', $this->request());
                self::fail('Unbounded outbound provider accepted.');
            } catch (ConferenceException $error) {
                self::assertSame($reason, $error->reason);
            }

            self::assertFalse($provider->called);
        }
    }

    public function testOptionalOutboundBoundaryIsFinalAndHasAHardFiveSecondPolicy(): void
    {
        self::assertSame(5, BoundedHttpClient::TIMEOUT_SECONDS);
        self::assertSame(1, BoundedHttpClient::RETRIES);
        self::assertTrue((new \ReflectionClass(BoundedHttpClient::class))->isFinal());

        foreach (['createMeeting', 'destroyMeeting', 'requiresOutboundAccess'] as $method) {
            self::assertTrue((new \ReflectionMethod(OutboundConferenceProvider::class, $method))->isFinal());
        }

        if (extension_loaded('curl')) {
            $options = BoundedHttpClient::options(new OutboundHttpRequest('https://meet.example.com/api'));
            self::assertSame(5000, $options[CURLOPT_TIMEOUT_MS]);
            self::assertSame(5000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
            self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
            self::assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
            self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
            self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        }
    }

    public function testOutboundHeadersCannotInjectAdditionalHeaders(): void
    {
        $this->expectException(ConferenceException::class);
        new OutboundHttpRequest('https://meet.example.com/api', 'POST', '', ["Authorization: token\r\nInjected: bad"]);
    }

    public function testJwtIssuerDefaultsToAppIdAndSubjectCanBeConfigured(): void
    {
        $result = $this->jitsi([
            'auth' => 'jwt', 'jwt_secret' => 'reference-signing-key', 'jwt_app_id' => 'application',
            'jwt_subject' => 'configured-subject',
        ])->createMeeting($this->request());
        $claims = $this->decodeAndVerify($result->joinUrl, 'reference-signing-key');
        self::assertSame('application', $claims['iss']);
        self::assertSame('jitsi', $claims['aud']);
        self::assertSame('configured-subject', $claims['sub']);
    }

    public function testBoundedClientRetriesExactlyOnceAndDoesNotRetrySuccess(): void
    {
        if (!extension_loaded('curl')) {
            // cURL is an optional runtime dependency, never required by the local providers.
            self::assertSame(1, BoundedHttpClient::RETRIES);
            return;
        }

        try {
            CurlDouble::$responses = [[false, 0, ''], [true, 200, 'meeting-result']];
            CurlDouble::$options = [];
            $request = new OutboundHttpRequest('https://meet.example.com/api');
            self::assertSame('meeting-result', (new BoundedHttpClient())->request($request));
            self::assertCount(2, CurlDouble::$options);

            foreach (CurlDouble::$options as $options) {
                self::assertSame(5000, $options[CURLOPT_TIMEOUT_MS]);
                self::assertSame(5000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
            }

            CurlDouble::$responses = [[true, 200, 'immediate-success']];
            CurlDouble::$options = [];
            self::assertSame('immediate-success', (new BoundedHttpClient())->request($request));
            self::assertCount(1, CurlDouble::$options);

            CurlDouble::$responses = [[true, 503, 'private-response'], [false, 0, 'private-response']];
            CurlDouble::$options = [];

            try {
                (new BoundedHttpClient())->request($request);
                self::fail('Two failed attempts accepted.');
            } catch (ConferenceException $error) {
                self::assertSame('outbound_failed', $error->reason);
                self::assertSame('Conference service did not respond successfully.', $error->getMessage());
                self::assertNull($error->getPrevious());
                self::assertCount(2, CurlDouble::$options);
            }
        } finally {
            CurlDouble::$responses = null;
            CurlDouble::$options = [];
        }
    }

    public function testBoundedAdaptersAreOptInAndUseTheEnforcedTransport(): void
    {
        $provider = new class extends OutboundConferenceProvider {
            public bool $built = false;
            public function getName(): string { return 'bounded'; }
            public function getConfigForm(): string { return '<fieldset />'; }
            protected function buildCreateRequest(MeetingRequest $request): OutboundHttpRequest
            {
                $this->built = true;
                return new OutboundHttpRequest('https://meet.example.com/api');
            }
            protected function parseCreateResponse(string $response): MeetingResult
            {
                return new MeetingResult('https://meet.example.com/room', $response);
            }
        };

        try {
            (new ConferenceManager([$provider]))->createMeeting('bounded', $this->request());
            self::fail('Remote adapter ran without opt-in.');
        } catch (ConferenceException $error) {
            self::assertSame('outbound_disabled', $error->reason);
            self::assertFalse($provider->built);
        }

        if (!extension_loaded('curl')) {
            return;
        }

        try {
            CurlDouble::$responses = [[true, 200, 'room']];
            CurlDouble::$options = [];
            $manager = new ConferenceManager([$provider], true);
            self::assertSame('room', $manager->createMeeting('bounded', $this->request())->roomIdentifier);
            self::assertTrue($provider->built);
            self::assertCount(1, CurlDouble::$options);
            $manager->destroyMeeting('bounded', 'room');
            self::assertCount(1, CurlDouble::$options);
        } finally {
            CurlDouble::$responses = null;
            CurlDouble::$options = [];
        }
    }
}

/** Namespaced cURL doubles exercise policy and retry without making network calls. */
final class CurlDouble
{
    public static ?array $responses = null;
    public static array $options = [];
}

namespace Advans\Component\Appointments\Administrator\Helper\Conference;

use Advans\Component\Appointments\Tests\Unit\Conference\CurlDouble;

function curl_init(?string $url = null)
{
    return CurlDouble::$responses === null ? \curl_init($url) : new \stdClass();
}

function curl_setopt_array($handle, array $options): bool
{
    if (CurlDouble::$responses === null) {
        return \curl_setopt_array($handle, $options);
    }

    CurlDouble::$options[] = $options;
    $handle->options = $options;

    return true;
}

function curl_exec($handle)
{
    if (CurlDouble::$responses === null) {
        return \curl_exec($handle);
    }

    $response = array_shift(CurlDouble::$responses);
    $handle->status = $response[1];
    $handle->options[CURLOPT_WRITEFUNCTION]($handle, $response[2]);

    return $response[0];
}

function curl_getinfo($handle, ?int $option = null)
{
    return CurlDouble::$responses === null ? \curl_getinfo($handle, $option) : $handle->status;
}

function curl_close($handle): void
{
    if (CurlDouble::$responses === null) {
        \curl_close($handle);
    }
}
